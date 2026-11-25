---
title: "Member bonus ... audit log: an unbreakable record of every change"
date: "2026-08-13"
version: "1.0.0"
tags: ["audit", "sqlite", "security", "database", "admin"]
excerpt: "The second registered-only bonus. How the admin panel knows who changed what and when ... and why that record can't be deleted or rewritten. Two layers of immutability: a repository without UPDATE/DELETE and SQLite triggers that block even a direct hit on the database."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-audit-log-pre-deti"
published: true
---

> This is the second **member bonus** ... a record that can't be deleted,
> about an article that can't be changed. The admin audit log lives in
> this repo: every write to `/mark` (creating a user, changing roles,
> saving settings, moderating comments) is written to `audit.db`. And
> that record is **immutable** ... at the application level and at the
> database level. Let's look at how it's done.

## Why an audit log at all

An admin panel has the right to change things: users, roles,
permissions, settings, translations, comments. With the right to change
comes the question: **who changed it, what exactly, and when?**

Without an answer to that question you're "administering the system"
blind. Something broke? Someone deleted a comment? Someone changed a
role without asking? Without a record it's your word against theirs.

The audit log answers five questions:

1. **Who** ... which admin (or `system`, when it ran without a login)
2. **What** ... the action: `users.create`, `settings.save`, `comments.approve`...
3. **On what** ... the target: `user:u1`, `role:r1`, `comment:c5`...
4. **Detail** ... a human-readable description: "Created user editor@example.test"
5. **When and from where** ... a timestamp + IP address

And above all: **the record cannot be changed.** Because an audit that
can be rewritten is just another document ... not evidence.

## Two layers of immutability

The lock works on two levels at once. The first lives in code, the
second in the database. Either one without the other would be a hole:

| Layer | Where | What blocks |
|---|---|---|
| Application | `AuditLogRepository` | No update/delete method exists ... the only write path is `insert()` |
| Database | SQLite triggers | `BEFORE UPDATE`/`BEFORE DELETE` → `RAISE(ABORT)` |

Why both? Because code can be bypassed, a database can't. If the lock
lived only in the repository, it would be enough to write a new SQL
statement directly against `audit.db` (or use another tool, not this
app) ... and the record would be gone. Triggers live **inside the
database itself** and block any attempt to change it, no matter who
runs it.

## First layer: a repository without deletion

`AuditLogRepository` is deliberately poor. It has exactly three
methods: `insert()`, `recent()`, `count()`. Nothing more.

```php
final readonly class AuditLogRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** Append an entry ... the only write path. */
    public function insert(AuditEntry $entry): void
    {
        // INSERT INTO audit_log (...) VALUES (...)
    }

    public function recent(int $limit): array
    {
        return $this->entries($this->pdo->prepare(
            'SELECT * FROM audit_log ORDER BY created_at DESC, rowid DESC LIMIT :limit',
        ));
    }

    public function count(): int
    {
        // SELECT COUNT(*) FROM audit_log
    }
}
```

Notice that `recent()` sorts by `created_at DESC, rowid DESC`.
`created_at` has second-level granularity ... two actions in the same
second could otherwise come back in a random order. The monotonic
`rowid` (insertion order) is the guarantee that records keep their
true ordering.

This layer is purely about discipline: **when the API has no delete
method, the code can't call it.** It's "best effort" ... which is why
the second layer comes in.

## Second layer: triggers in the database

This is where "discipline" becomes a physical law. The migration
creates two triggers:

```sql
CREATE TRIGGER audit_log_no_update
BEFORE UPDATE ON audit_log
BEGIN
    SELECT RAISE(ABORT, 'audit_log is immutable');
END;

CREATE TRIGGER audit_log_no_delete
BEFORE DELETE ON audit_log
BEGIN
    SELECT RAISE(ABORT, 'audit_log is immutable');
END;
```

Try anything ... `UPDATE audit_log SET detail = 'deleted'` or
`DELETE FROM audit_log` ... and SQLite answers with an error:

```
SQLSTATE[HY000]: General error: 1 audit_log is immutable
```

It doesn't matter whether the statement came from this application,
from `sqlite3` in a terminal, or from another process. The trigger
runs **before** the operation and stops it. That's why this is called
an immutable log: records can only be appended and read, never
modified or deleted.

## What exactly gets logged

The audit log isn't "log everything" ... it's a record of
**administrative writes**. Every write action in `/mark` ends in one
call:

```php
AuditServices::log(
    'users.create',                          // action
    'user:' . $user->id(),                   // target
    'Created user ' . $email,                // detail
    $request->getClientIp(),                 // from where
);
```

Who is the `actor`? It's resolved from the session ... if an admin is
signed in, their name and id are recorded. If the action ran without a
login (e.g. a seed), the stand-in `system` is recorded. The detail is
**denormalized at write time** ... the admin's name is copied into the
record. If the admin later renames, the record still says what they
were called at the moment of the action. That's exactly what you want
from an audit: a snapshot of the moment, not a reference to mutable
state.

The list of logged actions today:

| Area | Actions |
|---|---|
| Users | `users.create`, `users.update`, `users.delete` |
| Roles | `roles.create`, `roles.delete`, `roles.permissions` |
| Settings | `settings.save`, `settings.tokens`, `settings.nav`, `settings.nav.delete` |
| Translations | `locales.create`, `locales.update`, `locales.delete`, `locales.import` |
| Comments | `comments.approve`, `comments.reject`, `comments.delete` |

## How you look at it

Admins see the records at `/mark/audit` ... newest first. Each row shows
the time, name, action, target, detail and IP. And because this is a
public HTML surface, the detail is **escaped** ... an article with
`<script>` in its title can't take down the admin panel with XSS. The
test checks it literally: `assertStringNotContainsString('<script>', $html)`.

## A rule without a test isn't followed

As everywhere in this repo: a lock you don't test doesn't exist.
`AuditLogTest` tries to break the log ... and expects to fail:

```php
public function testUpdateIsBlockedByImmutabilityTrigger(): void
{
    AuditServices::log('roles.create', 'role:r1', 'Created role editor');

    $pdo = DatabaseConnectionManager::instance()->connection('audit');

    $this->expectException(\PDOException::class);
    $this->expectExceptionMessage('immutable');

    $pdo->exec("UPDATE audit_log SET detail = 'tampered'");
}
```

The test deliberately runs an `UPDATE` directly against the database ...
not through the repository, which doesn't even offer it. If someone
removed or bypassed the trigger, this test starts failing. And
`testDeleteIsBlockedByImmutabilityTrigger` does the same for `DELETE`.
Beyond that, the tests verify that the `/mark` actions (users, locales,
comments) really write, that the ordering is correct, and that the
`system` fallback works without a session.

## What it all means

The nicest thing about this pattern is that **a physical lock costs
less than a procedural one**. No "we must be careful nobody deletes
the log." Instead of an agreement there's a trigger ... and a trigger
has no bad days, makes no typos and doesn't cave to pressure. If
someone wants to change a record, they have to change the database
(disable the trigger) ... and that already leaves traces.

## What to take away

1. **An audit log answers five questions** ... who, what, on what,
   detail, when and from where. Nothing more is needed.
2. **Immutability has two layers** ... a repository without
   update/delete (discipline) + database triggers (physical law).
3. **The trigger runs before the operation** ... `BEFORE UPDATE`/
   `BEFORE DELETE` with `RAISE(ABORT)` stops even direct SQL from a
   terminal.
4. **Denormalize the actor at write time** ... the record is a snapshot
   of the moment, not a reference to mutable state.
5. **Test the lock by attack** ... the test deliberately tries to break
   the log and expects to fail.

And that's the whole bonus article ... unbreakable, like the record it
talks about. If you have questions, I'll be waiting in the comments. 

## Sources

- [W3C, ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance): ACID definition
- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [SQLite Documentation](https://www.sqlite.org/docs.html): SQLite reference manual
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
