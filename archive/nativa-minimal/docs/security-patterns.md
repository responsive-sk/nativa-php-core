# Security patterns — UnitOfWork, the security outbox, and what neither gives you

This file exists because both patterns in it are half-remembered, and the half
that gets remembered is always the flattering one.

- `UnitOfWork` is often described as "transactional consistency across
  repositories". It is **atomicity within one SQLite file**, with savepoint
  semantics when scopes nest.
- The security outbox is often described as "atomic audit trail". It is **not
  atomic at all** — it is at-least-once delivery plus a handler that is safe
  to run twice.

Both statements are true, and the second one in each pair is the one that has
to be repeated. The failures this prevents are all the same shape: an access
change that succeeded and left no trace, or a bulk delete that removed a
columnist's post while every single-article route was returning 403.

## UnitOfWork — atomicity inside one file

`Modules\Database\Infrastructure\Persistence\UnitOfWork` wraps repository calls
in a single SQLite transaction. On success it commits; on any throwable from
the callback it rolls back and rethrows.

### Nesting is the normal case

Every caller constructs its own `UnitOfWork`, so an inner repository's
`transaction()` runs inside the action's one:

```
BulkDeleteAction::deleteDbEntities()   ← BEGIN
  UserRepository::delete()             ← SAVEPOINT uow_2
  RoleRepository::grant()              ← SAVEPOINT uow_3
```

Nesting depth is therefore tracked **per PDO handle** in a `WeakMap`, not per
`UnitOfWork` instance. The previous per-instance `$transactionStarted` flag
could not see the second instance, so `beginTransaction()` threw
`PDOException: There is already an active transaction` — and a privileged bulk
delete 500'd half-done, with the audit log showing a refusal that never
happened. Keying on the handle is the only place that information exists.

`depth()` falls back to `$pdo->inTransaction() ? 1 : 0` for a transaction this
class never opened (a raw `beginTransaction()` further up the stack, or a
leftover from a crashed request). That is the conservative reading: the next
call opens a savepoint instead of asking PDO to begin twice.

### A nested throw rolls back only its own scope

A failing nested scope issues `ROLLBACK TO SAVEPOINT uow_N` followed by
`RELEASE SAVEPOINT uow_N` (the release is not optional — `ROLLBACK TO` leaves
the savepoint on the stack, and the next scope at that depth would reuse one
holding rolled-back state). The outer transaction survives and can still
commit the work it had already done. A failure therefore has the blast radius
of the scope that caused it, rather than discarding unrelated good work further
out. The outermost scope is still strictly all-or-nothing.

Guarded by `tests/Unit/UnitOfWorkNestingTest.php` (depth, savepoint naming,
nested rollback, outermost rollback, `reset()`).

### What it does NOT give you

**Nothing spans two SQLite files.** `users.db`, `audit.db` and `outbox.db` are
separate databases; a transaction in one is invisible to the others. So a
privileged write in `users.db` and its audit row in `audit.db` are **never
atomic together**. Wrapping both in a `UnitOfWork` does not make them atomic —
it would only be a lie about the guarantee.

## The security outbox — at-least-once, not atomic

### The gap it closes

`AuditServices::log()` writes to `audit.db`. A user or role change writes to
`users.db`. A crash in between keeps the privilege change and loses the record
of it — the wrong direction to fail in, because the thing you would want to
investigate is the thing that vanished.

### What actually happens

1. The write to `users.db` succeeds.
2. `SecurityEventServices::*` records an `outbox_messages` row in
   `outbox.db` and calls `OutboxDrain::defer()`.
3. At the end of the request (or via `bin/cms outbox:process`), the drain
   dispatches pending messages to `SecurityEventHandler`, which writes one
   `audit_log` row and marks the message processed.

### What it does NOT give you

**No atomicity, and no exactly-once.** `outbox.db` is a *third* separate
file. Three failure windows exist and none of them is closed by this pattern:

| Crash between | Result |
|---|---|
| the `users.db` write and the outbox insert | the change stands, no record |
| the outbox insert and the audit insert | the change stands, the record is pending and lands on the next drain |
| the audit insert and marking the message processed | the change stands, **and** the row is written again on replay |

The third window is why the handler must be idempotent, and it is handled by
identity rather than by content: the audit row's **primary key is the outbox
message id** (`AuditEntry::createWithId()` / `AuditService::logWithId()`). A
replay collides on the primary key and is discarded.

Content-based dedupe — "same path, same user, within the same second" — is the
tempting alternative and it is **wrong**: two genuinely distinct denials would
collapse into one, and the second attempt is exactly what an investigation
wants to see. Guessed identity is not identity.

### The failing-handler rule

A handler that throws must leave the message **recoverable**, never marked
delivered. `OutboxProcessor` records `status = failed` with the error and
leaves `attempts` under the ceiling, so the record survives instead of
vanishing. Note the honest limit: nothing in `src/` calls
`OutboxMessage::withRetry()` automatically — re-queueing a failed message is a
deliberate step, not an automatic retry loop. A permanently failing handler
therefore leaves the event failed until someone re-queues it, which is
preferable to silently dropping a security record, but is not a retry queue.

### What the emitters are

| Type | Emitted by |
|---|---|
| `security.authz.denied` | `AuthMiddleware::recordDenial()`, `BulkEntityGate` refusals via `SecurityEventServices::bulkRefused()` |
| `security.user.changed` | `UsersAction` (create/update/delete) |
| `security.role.changed` | `RolesAction::createRole()` / `deleteRole()` / `savePermissions()` — granting permissions to a role is the most consequential write in the panel, and it is also the emitter that stayed dead longest: the type was declared, handled, tested and documented here while `RolesAction` emitted nothing. See “A declared event nobody emits” below. |
| `security.auth.attempt` | `LoginAction`, `LogoutAction`, `AuthMiddleware::resumeFromCookie()` |
| `security.ratelimit.tripped` | `RateLimitMiddleware::recordTrip()` |

`SecurityEvents` owns the type names and the payload keys, so no emit site
invents one the handler will not read. `SecurityEventHandler` lives in the
**Audit** module — its entire output is an audit row — and is registered from
the composition root (`public/index.php`), which is the only place allowed to
know that both modules exist.

Guarded by `tests/Integration/SecurityOutboxTest.php` (every type enqueues,
one row per event, replay writes no second row, two distinct denials are two
rows, a failing handler leaves the message recoverable and deliverable on
re-queue).

### A declared event nobody emits

Those tests exercise the emitters **directly**, which proves the plumbing and
says nothing about whether the application calls it. That gap is not
hypothetical: `security.role.changed` was in `SecurityEvents::all()`, handled,
deliverable and asserted here — and no line of `RolesAction` emitted it. Every
test in the repo passed, including the one that claims the event is emittable.

Two guards now cover the gap, and they answer different questions:

- `tests/Architecture/SecurityEventWiringTest.php` scans `src/` for the
  emitter **call sites**, so a helper nobody calls fails the build. It does not
  search for the event-type string, because a call site writes
  `SecurityEventServices::roleChanged(…)`, never `'security.role.changed'` — a
  string scan would have reported a clean bill of health.
- `tests/Integration/SecurityEventEmissionTest.php` drives the actions and
  reads the queue, so deleting **one** of several call sites fails too. The
  wiring guard cannot see that case: remove the permission-grant call and the
  helper still has two callers, which is exactly how this slipped through.

The general rule: a test that calls the thing it is testing proves the thing
works. Only a test that goes through the caller proves it happens.

## Authorization — two questions, not one

A bulk POST names its entity in the **body**, so the path's permission row is
not the entity's gate. `BulkEntityGate` applies the second question before any
id is read:

1. *May this user reach a bulk endpoint at all?* — `AdminRoutePolicy`'s path
   table (`content.manage` for both bulk routes).
2. *May they run it on THIS entity?* — `AdminRoutePolicy::permissionForEntity()`.
   An unclassified entity returns `null` and is **refused**, so a new bulk form
   cannot be added without also being classified.

Per-article **ownership** is a third question, and it is separate from
permission: `content.manage` opens the whole articles area, which is wrong for
a site with several writers. `ArticleWriteGuard` is the single place an article
write is authorized — actions and bulk handlers both call it, so a new caller
gets ownership by construction rather than by remembering. A mixed selection is
**skipped and counted**, not refused wholesale (refusing everything would punish
the author for a row the UI should never have shown them), and the count is
reported in the toast, because a silently ignored checkbox reads as a bug.

Pages carry no per-page owner and stay group-editable by design; an article
with no `author_email` is admin-only, because "nobody claimed it" is not a
claim.

Guarded by `tests/Integration/BulkActionAuthorizationTest.php`,
`tests/Architecture/BulkAuthorizationContractTest.php` and
`bin/checks/author-check.mjs` (which drives the same cases over real HTTP).

## The rule of thumb

If the two things must both happen or neither may, and they live in different
SQLite files, **this kit cannot do it**. Put them in one database, or accept
at-least-once plus an idempotent handler and say so in the design.
