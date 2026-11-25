---
title: "Member bonus ... UnitOfWork and the outbox: when \"all or nothing\" does not hold"
date: "2026-10-03"
version: "1.0.1"
tags: ["transactions", "outbox", "architecture", "sqlite", "security", "php"]
excerpt: "How this repository represents a user account and records who changed it ... and why one transaction is not enough for that. Concrete code: UnitOfWork with a savepoint, an outbox with an idempotent handler, and a table of the three windows where it can go wrong."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-outbox-a-unit-of-work-pre-deti"
published: true
---

> This **member bonus** covers code that came into being in this repository
> first ... not as a plan, but as the fix for a live incident. Earlier
> parts: [ACID for beginners](/en/blog/acid-for-beginners),
> [the audit log](/en/blog/bonus-audit-log),
> [members-only](/en/blog/bonus-members-only) and
> [shipping](/en/blog/bonus-shipping).
> Today: two things that look like one ... and do entirely different jobs.

You are a PHP developer. You build an application with users and roles. You
add an audit log so you know who changed what. Everything works, until the
moment you ask: "what if the process dies between the write and the record?"

The answer is: **you lose everything except the change you least wanted to
lose.**

## Two databases, one transaction

Let us start with the fact that decides everything else.

This kit has one database per module. Users and their roles live in
`users.db`. The audit log lives in `audit.db`. Both are SQLite.

And SQLite **has no transaction across two files**. That is not a setting
you can turn on. It is a boundary of the engine.

So when `UsersAction` changes a role and then writes the audit entry, those
two things are not in one transaction:

```php
// src/Modules/Cardboard/Interfaces/HTTP/Actions/UsersAction.php
CardboardServices::users()->update($id, $data);   // users.db
CacheInvalidator::flush();
AuditServices::log('users.update', 'user:' . $id, $detail, $ip);  // audit.db
SecurityEventServices::userChanged($id, SecurityEventServices::OP_UPDATE, $ip);
```

Line three is comfort: `AuditServices::log()` is an inline write, and if the
process dies between line one and line three, the change in `users.db`
survives and the record of it does not.

Honestly: **that is the wrong direction to fail in.** The thing you would
want to investigate is exactly the thing that vanished.

See [`acid-for-beginners`](/en/blog/acid-for-beginners) ... there a
transaction is used for what it is for. This article is about what happens
when two things must happen together and live in different files.

## One endpoint, two questions

Before the mechanics, let us look at the bug that caused all of this work.
It was live, not theoretical.

You were in `/mark` as an author ... a role with `mark.access` and
`content.manage`, and nothing more. Every single-article path refused a
colleague's article with `403`.

And then this happened:

```
POST /mark/bulk-delete   entity=articles   ids[]=a-colleagues-slug
POST /mark/bulk-publish  entity=articles   ids[]=a-colleagues-slug
```

The file was deleted. `published: true` became `false`.

Every individual path worked. The bug was not in authorizing per action. It
was in authorizing per operation. And the endpoint had a single permission
row for the whole content area, so there was nowhere to record that "this is
content" and "this is not".

| What was checked | Result |
|---|---|
| the path `/mark/articles/{slug}/delete` | 403, correct |
| the path `/mark/bulk-delete` with a foreign slug | deleted the file |
| a forged `entity=users` on that same path | passed, nobody checked `users.manage` |

That third row is the important one. `/mark/bulk-delete` is one path, but
what it deletes arrives in the **request body**. And the request body is data
an attacker picks.

```php
// src/Modules/Users/Domain/AdminRoutePolicy.php
public static function permissionForEntity(string $entity): ?string
{
    return match ($entity) {
        'articles', 'pages'     => Permissions::CONTENT,
        'users'                 => Permissions::USERS,
        'roles'                 => Permissions::ROLES,
        default                 => null,
    };
}
```

`default => null` matters. An entity nobody classified is not quietly
allowed ... it is refused. Otherwise a new bulk form could ship without
anyone approving it.

## Ownership is not permission

The second layer is a different thing. `content.manage` opens the whole
articles area, which is right for one author and wrong for three. An author
could rewrite a colleague's post and the audit log would faithfully record
that they did.

So the rule moved into one place that everyone calls, bulk handlers
included:

```php
// src/Modules/Blog/Application/Services/ArticleWriteGuard.php
public function partition(array $ids, string $locale, ?User $user): array
{
    $allowed = [];
    $refused = [];

    foreach ($ids as $id) {
        $slug = (string) $id;

        if ($this->owns($slug, $locale, $user)) {
            $allowed[] = $slug;

            continue;
        }

        $refused[] = $slug;
    }

    return ['allowed' => $allowed, 'refused' => $refused];
}
```

A selection of two rows where one is not yours is not refused wholesale. It
is skipped, counted, and **the result says how many were skipped**:

```
Deleted 1 articles, 1 skipped (not yours).
```

A silently ignored checkbox looks like a bug. A counted one does not.

## A UnitOfWork that thought it was the only one

Now the one line that held everything up.

Every caller built its own `UnitOfWork` instance. Nesting was not an edge
case, it was the normal shape: an action opens a transaction and the
repository it calls opens another over the same connection.

```php
// src/Modules/Cardboard/Interfaces/HTTP/Actions/BulkDeleteAction.php
private function deleteDbEntities(string $entity, array $ids): int
{
    $pdo = DatabaseConnectionManager::instance()->connection('users');
    $uow = new UnitOfWork($pdo);

    $uow->transaction(function (PDO $_pdo) use ($entity, $ids, &$deleted): void {
        foreach ($ids as $id) {
            if ($this->deleteItem($entity, (string) $id, '')) {
                $deleted++;
            }
        }
    });

    return $deleted;
}
```

Nesting was normal. The class did not know. The old implementation had a
per-instance flag meaning "I already began" ... and the second object had no
way of knowing something was already running.

The result was not a refusal. It was a `PDOException: There is already an
active transaction` halfway through a delete.

```php
// src/Modules/Database/Infrastructure/Persistence/UnitOfWork.php
private static ?WeakMap $depth = null;

public function beginTransaction(): void
{
    $depth = $this->depth();

    if (0 === $depth) {
        self::stack()[$this->pdo] = 1;
        $this->pdo->beginTransaction();

        return;
    }

    self::stack()[$this->pdo] = $depth + 1;
    $this->pdo->exec('SAVEPOINT ' . self::savepointName($depth + 1));
}
```

Depth lives in a `WeakMap` keyed by the **connection**, not by the object.
The only thing that knows two instances share one stack is the PDO handle
itself.

And `depth()` has a fallback for a transaction this class never opened:

```php
public function depth(): int
{
    return self::stack()[$this->pdo] ?? ($this->pdo->inTransaction() ? 1 : 0);
}
```

That is the conservative reading. The next `beginTransaction()` opens a
savepoint instead of asking PDO to begin twice.

### What happens when a nested scope fails

A savepoint has exactly the property we need: rollback of **its own** scope,
while the outer transaction survives.

```php
public function rollback(): void
{
    $depth = $this->depth();

    if (1 === $depth) {
        unset(self::stack()[$this->pdo]);
        $this->pdo->rollBack();

        return;
    }

    self::stack()[$this->pdo] = $depth - 1;
    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::savepointName($depth));
    // ROLLBACK TO leaves the savepoint on the stack. RELEASE removes it ...
    // otherwise the next scope at that depth inherits rolled-back state.
    $this->pdo->exec('RELEASE SAVEPOINT ' . self::savepointName($depth));
}
```

The `RELEASE` line is not decoration. Without it, the next scope at the same
depth inherits something that was already undone.

The outer scope is still all or nothing. A nested one is its own scope.

## What UnitOfWork does NOT guarantee

This is the part that gets forgotten, and that is the problem.

`UnitOfWork` guarantees atomicity **inside one SQLite file**. Full stop.

Not between `users.db` and `audit.db`. Not between `users.db` and
`outbox.db`. Nowhere a second database is involved.

| What you do | What it gives you |
|---|---|
| wrap the `users.db` write in `UnitOfWork` | all or nothing in `users.db` |
| wrap the `users.db` write and the audit write in it | exactly the same thing, the audit is another file |
| wrap both and call it atomic | a lie, discovered at the first crash |

Somebody will say "I'll put it in a transaction" and mean precisely that
lie. It is the most expensive sentence in this area, because it sounds
responsible.

## The outbox: a record that survives the fall

When two things cannot be joined by a transaction, they can be joined by
order. The outbox does not claim "it is atomic". It claims what it actually
has: **the event is recorded after the write succeeded, delivery happens at
least once, and the handler is safe to run twice.**

Five event types, all owned in one place:

| Event type | When |
|---|---|
| `security.authz.denied` | a signed-in user received a 403 |
| `security.user.changed` | a user was created, changed or deleted |
| `security.role.changed` | a role or its permissions changed |
| `security.auth.attempt` | login, logout, remember-me, or a failed password |
| `security.ratelimit.tripped` | the rate limiter refused a caller |

`AuthMiddleware` writes to the queue directly, `RolesAction` calls the helper:

```php
// src/Modules/Cardboard/Interfaces/HTTP/Actions/RolesAction.php
SecurityEventServices::roleChanged(
    $id,
    SecurityEventServices::OP_PERMISSIONS,
    $request->getClientIp(),
);
```

Granting `roles.manage` to a role is the most consequential write in the
panel. It is where someone acquires access to everything the role names.

### Idempotency by identity, not by content

This is easy to get wrong. How do you know an event was already recorded?

Not by content. By identity. The audit row's primary key **is** the queue
message id:

```php
// src/Modules/Audit/Application/Services/SecurityEventHandler.php
try {
    $this->audit->logWithId(
        $message->id(),      // <-- this is the key
        $message->eventType(),
        self::detail($message->eventType(), $payload),
        self::actor($payload, $message),
        self::target($message->eventType(), $payload),
        self::ip($payload),
    );
} catch (PDOException $e) {
    if (self::isDuplicate($e)) {
        return;              // a replay ... discarded
    }

    throw $e;
}
```

A replay collides on the key and is discarded.

The tempting alternative, which everyone writes first, is content dedupe:
"same user, same path, same second". It is **wrong**. Two genuinely distinct
denials collapse into one ... and the second attempt is precisely what an
investigation wants to see.

Untrustworthy identity is not identity.

## The three windows where it can break

This is the table I would put on a wall, if walls were a thing:

| A crash between | Result |
|---|---|
| the `users.db` write and the queue insert | the change stands, the record does not |
| the queue insert and the audit write | the change stands, the record waits and lands on the next drain |
| the audit write and marking the message processed | the change stands **and** the record is written again |

The third window is why the handler has to be idempotent. Not because we
believe in exactly-once delivery, but because at-least-once is the contract.

A replay can also be done on purpose, via `bin/cms outbox:process`.

### The honest part about what is missing

Nothing in `src/` calls `OutboxMessage::withRetry()`. A message whose handler
threw stays in the `failed` state until somebody puts it back in the queue
by hand.

That is not a retry queue. It is "the record survived, somebody will look at
it" ... which is better than silently dropping a security record, but it is
not automatic retrying. We say so, because documentation claiming "delivery
retries" is a lie somebody discovers a year from now.

## When this is the wrong answer

If two things must happen together or not at all, and they live in two
database files, **this kit cannot do it**.

Full stop.

You have two options and both are clean:

- put them in the same database, where the transaction works;
- accept at-least-once delivery with an idempotent handler and say so out
  loud in the design.

The third option, wrapping both in a `UnitOfWork` and calling it atomic, is
not an option. It is a bug waiting for a crash.

And one more thing worth saying: the outbox here is not the source of truth.
The write to `users.db` is the truth. The queue is how a record reaches it.
When the queue insert fails, the write stands and the caller gets `false` ...
deliberately, because a failure must not kill what already succeeded.

## What to take from this

1. **Atomicity is a property of a file, not of a formula.** `UnitOfWork`
   holds inside one SQLite file and nowhere else.
2. **Nested scopes get a savepoint.** A failure has the blast radius of its
   own scope; the outer transaction survives and can commit what it finished.
3. **Depth belongs to the connection, not the object.** Two instances over
   one PDO are one stack, and nothing else knows that.
4. **Idempotency by identity.** The audit key is the message id, not a hash
   of the content ... otherwise two distinct break-in attempts collapse.
5. **A test that calls what it tests does not prove it happens.**
   `SecurityEvents` had five types, the handler covered them and a test
   pulled them off the wire ... and `RolesAction` emitted not one. What was
   missing was a layer that goes through the real caller.

One more thing worth saying on its own: that fifth point was not found by a
test. It was found by a `grep` over `src/`. And it was fixed with two guards
that answer different questions ... one looks for an emitter nobody calls,
the other runs the action and reads the queue.

→ [ACID for beginners](/en/blog/acid-for-beginners) — why transactions are not boring
→ [The audit log](/en/blog/bonus-audit-log) — an unbreakable record
→ [Members-only](/en/blog/bonus-members-only) — who owns an article

## Sources

- [SQLite — Transactions](https://www.sqlite.org/lang_transaction.html) — `BEGIN`, `SAVEPOINT`, `RELEASE`, and why it is all in one file
- [SQLite — Atomic Commit](https://www.sqlite.org/atomiccommit.html) — how SQLite makes a written row durable
- [PHP Manual — PDO::beginTransaction](https://www.php.net/manual/en/pdo.begintransaction.php) — the exception the old code left mid-work
- [microservices.io — Transactional Outbox](https://microservices.io/patterns/data/transactional-outbox.html) — the canonical write-up of the pattern and its limits
- [Nativa Minimal Documentation](/docs/getting-started) — the official guide
