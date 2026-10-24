# Booleans — when `true`/`false` is the whole answer

A `bool` is honest when the question has two values and the reason for `no` is
either not needed or has somewhere else to live. Everything below is that
sentence, unpacked against this tree.

The rule is not a style preference. It is the answer to a measurable failure:
this repository has **238 methods returning `: bool` in 128 production files**
(measured 2026-10-23, `src/` + `modules/`, tests excluded), and exactly **one
`enum`**. If a boolean were an anti-pattern, 238 of them would be 238 bugs.
They are not — 98 are predicates of the shape `is`/`has`/`can`, and the rest
are decisions whose reasons are kept elsewhere. The interesting part is how to
tell which is which before writing the signature.

## The question behind the type

Not "how many values does this have". The question is:

> When the answer is `no`, who needs to know **why**, and where does that
> reason live after this process ends?

Rust's `Result<T, E>` answers that by putting the reason in the type. That is
the right answer when every caller can act on the reason. It is the wrong
answer when the caller can only act on `no`, and the reason is for a log, a
panel, or an operator three hours later — a `Result` there offers a branch the
caller has no way to handle, and the compiler is satisfied by code that throws
it away.

## Three questions, in order

Ask these before a method returns `: bool`:

| # | Question | If yes | If no |
|---|---|---|---|
| 1 | Does the caller **decide differently by the reason**? | `enum` / `SubmitResult` | continue |
| 2 | Does the value **outlive the process** (DB, queue, HTTP, session)? | a named vocabulary + validation + `match` with a `default` | continue |
| 3 | Are two answers **the whole universe** of the question? | `bool` | `enum` |

Only the third answer produces a boolean. The first two are not about how
many values the type has; they are about where the reason goes.

## A predicate is a complete statement

These are booleans and should stay booleans:

- `DocsCatalog::has(string $key): bool` — a key is published or it is not; the
  caller either serves the guide or answers 404.
- `HandlerRegistry::has(string $handler): bool` — the worker either knows the
  handler or parks the job.
- `ThemePreference::isResolvable(array $settings): bool` — the stored vote
  either resolves in this tree or falls back to the public theme.

Each is a yes/no question whose two answers are the entire vocabulary. An enum
would add an arm nobody writes and a `match` nobody needs. Rust agrees:
`str::contains`, `Vec::is_empty` and `Option::is_some` all return `bool`.

## When the reason is needed: it must outlive the call

The boundary case is `Modules\Users\Domain\Contracts\PasswordResetMailer`:

```php
public function sendResetLink(string $email, string $token, int $ttlHours): bool;
```

Three things can really happen — the transport accepted the message, the
transport refused it, or there is nowhere to send it (the null transport). All
three answer `false`, and the reason goes to `error_log`. That is only honest
because the reason has a place to survive the request; a boolean whose reason
exists nowhere is a lie.

The same shape is in the queue: `Modules\Users\Application\Services\AdminNotificationHandler`
returns a boolean, and the worker **discards** it. What the worker acts on is
whether the handler threw. The return value is for a human reading the code,
not for control flow — so converting it to a `Result` would give the worker a
branch it must not use.

**That is the condition the argument stands on.** If `error_log` or
`failed_jobs` becomes optional, the boolean at that site starts lying, because
the reason it delegates has lost its home.

## When the value is stored: give it a name

Nothing that crosses a process boundary should be a PHP `bool`. Not because
the database cannot store it — SQLite will happily store `1` — but because a
stored boolean is a fact with no vocabulary, and every reader has to remember
which `1` it is.

The tree's examples:

- `Modules\Comments\Domain\Entities\Comment::STATUS_PENDING`,
  `STATUS_APPROVED`, `STATUS_REJECTED`. Three states, one of which means "the
  moderation queue", stored as text in `comments.status`.
- Settings rows typed `boolean` (`registration_enabled`, `maintenance_mode`)
  store `'1'`/`'0'` **with** a `value_type` column that says what the digits
  mean. `SettingsRepository` reads the pair back through that declaration.
- A `notify.admin` payload is built by
  `Modules\Users\Application\Services\NotificationPayload` and carries strings
  only: `kind`, `target`, `author`, `excerpt`, `panel`. `kind` is the
  vocabulary (`comment`, `question`), not a flag.
- `Modules\Outbox\Domain\SecurityEvents::payloadKeys()` and `all()` are closed
  vocabularies for the same reason: an event type is a name, and an unknown
  name must fail a contract test rather than look like a valid `false`.
- `Modules\Queue\Application\Services\DatabaseQueue::work()` returns
  **counts**, not a flag:

  ```php
  ['processed' => 2, 'failed' => 0]
  ```

  The panel renders them as `Processed 2 job(s) · 0 failed.` A `bool $ok`
  would lose the number the operator uses to decide whether to click again.

Where a stored value has exactly two states and no third is plausible, a
narrower type than a free string is still the goal — `Period` in Cardboard is
the tree's only `enum` today, and it exists because a billing period is a
closed set.

## The one place `bool` was not enough

`CommentsService::submit()` used to return
`array{ok: bool, errors: list<string>}` — a `Result` wearing a coat. The
invariant "`ok === true` ⇒ `errors` is empty" lived in the callers' heads, and
two keys could disagree. It is now
`Modules\Core\Domain\SubmitResult`:

```php
$result = SubmitResult::failure('Comment body is required.');

if (!$result->ok) {
    $request->setAttribute('comment_errors', $result->errors);
}
```

The only ways to build one are `success()` and `failure()`, and `failure()`
refuses to exist without a message. Facts the caller needs besides the verdict
— a clean folder path, how many rows a rename moved, the retry window —
travel in a typed `context`:

```php
/** @return SubmitResult<array{retryAfter: int}> */
public function requestReset(string $email, string $ip): SubmitResult
```

A `SubmitResult` is never stored. Its `ok` is a decision about a moment; the
moment it is persisted it starts describing a past nobody can re-read.

## What enforces this

Three checks, each refusing a different way to reintroduce the problem:

| Check | Refuses |
|---|---|
| `tests/Architecture/PersistedBooleanContractTest` | a new `BOOLEAN` column, a new settings row typed `boolean`, and a boolean in a queued job's payload or payload-builder shape. The four columns and two rows that exist today are named with reasons; adding a fifth is an edit to that ledger and the conversation that comes with it. |
| `tests/Architecture/NameOwnershipContractTest` | a new `const` vocabulary grown without a decision — every name has one owner, and the ceilings only fall. |
| `tests/Architecture/KernelShapeTest` | a new concrete class in the kernel that nobody approved. `SubmitResult` is a `contract` row in [the shape inventory](kernel-shape.md). |

The first one is the mechanical half of this page. It reads migration sources
and payload builders as text, so it can see a column declaration or a `bool`
in a declared payload shape — and it says so in its own docblock — but it
cannot see intent. A boolean nested inside an untyped array escapes it. That
gap is why the rule is written down here as well as tested there.

## What this rule does not say

- **It does not demand a `Result` everywhere.** Most callers of a boolean have
  nothing to do with the reason; making them acknowledge it is ceremony.
- **It does not ask for a migration of the four boolean columns.** They are the
  ledger, not a debt: `users.is_active`, `menu_items.is_active`,
  `menu_items.is_public` and `menu_items.external` are read by code that
  already agrees on what `1` means. The gate is for the *next* one.
- **It does not make strings safe.** `Comment::STATUS_*` is a convention plus a
  contract test, not exhaustiveness. The `default` arm that logs an unknown
  value is a runtime safety net; an `enum` would be a compile-time one. This
  tree has one enum on purpose, and the trade is recorded rather than hidden.

## The short version

**`bool` on the boundary, vocabulary inside.** If the caller decides by the
reason, return the reason. If the reason must survive the process, give the
value a name and validate it. If neither is true, two answers are the whole
universe of the question — and `bool` is the smallest honest type.

The long version, with the measurements this page borrows and the argument for
why a FreeBSD-shaped tree is allowed to answer like this, is the article
[True/false nestačí. A predsa stačí](/blog/boolean-nestaci-a-predsa-staci).
Related guides: [the module contract](modules.md), [the kernel shape](kernel-shape.md),
[one name, one owner](name-ownership.md).
