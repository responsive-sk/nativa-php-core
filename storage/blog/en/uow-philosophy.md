---
title: "Why UnitOfWork? ... The Philosophy of Transactional Consistency"
date: "2026-08-18"
version: "1.0.0"
tags: ["php", "architecture", "database", "transactions"]
excerpt: "Why do we need UnitOfWork? The orphan data problem, the 'all or nothing' philosophy, and how SQLite WAL changes the rules."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "uow-philosophy"
published: true
---

When you delete a user from the database, you're deleting them from five tables.
If one of those five fails ... you're left with orphan data.

This isn't a theoretical problem. It happens every day.

## The Problem: Orphan Data

Picture an admin panel. You click "Delete user". The system:

```php
// 5 separate operations
$this->pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
$this->pdo->prepare('DELETE FROM password_reset_tokens WHERE email = ?')->execute([$email]);
$this->pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$id]);
$this->pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
$this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
```

**Scenario 1:** First DELETE succeeds. Second fails (disk full, timeout, anything).
Result: user exists, but their roles are gone. On next login, the system tries to load roles → error.

**Scenario 2:** First three DELETEs succeed. Fourth fails.
Result: user is gone, but their sessions remain. "Ghost session" ... someone can log in via an old session cookie.

**Scenario 3:** Everything succeeds, but between the third and fourth DELETE someone logs in.
Result: race condition ... a session was created for a user the system is currently deleting.

## Philosophy: All or Nothing

Unit of Work says one simple thing: **if one operation fails, roll everything back.**

```php
$uow = new UnitOfWork($pdo);
$uow->transaction(function (\PDO $pdo) use ($id, $email): void {
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE email = ?')->execute([$email]);
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
});
// Either everything is deleted, or everything remains.
```

No orphan data. No ghost sessions. No race conditions.

## UnitOfWork vs Outbox

People often confuse these two patterns. They're not the same.

| | UnitOfWork | Outbox |
|---|---|---|
| **Question** | "How to do multiple operations in one transaction?" | "How to trigger side effects without breaking ACID?" |
| **Type** | DB transaction | Domain event publishing |
| **Example** | INSERT + UPDATE + DELETE in one transaction | Save event + send email in one transaction |

**UnitOfWork** = "all or nothing" for DB operations.
**Outbox** = "save event, process later" for side effects.

Together they work like this:

```
┌─────────────────────────────────────────────────┐
│                UnitOfWork                        │
│  ┌─────────────────────────────────────────┐   │
│  │  1. INSERT INTO users ...               │   │
│  │  2. INSERT INTO user_roles ...          │   │
│  │  3. INSERT INTO outbox_events ...       │   │
│  └─────────────────────────────────────────┘   │
│                commit()                          │
└─────────────────────────────────────────────────┘
                       │
                       ▼
          ┌────────────────────────┐
          │   OutboxProcessor      │
          │   (background job)     │
          │                        │
          │   - READ outbox_events │
          │   - SEND email         │
          │   - UPDATE status      │
          └────────────────────────┘
```

## SQLite and WAL Mode

Nativa uses SQLite. And SQLite has its own rules for transactions.

**WAL (Write-Ahead Logging)** is the standard mode in Nativa:

```php
$pdo->exec('PRAGMA journal_mode = WAL');
```

**What WAL does:**
- Concurrent read + write (better concurrent behavior)
- Better performance under FPM workers
- Automatic checkpointing

**Important:** WAL doesn't mean you can ignore transactions. You still need UnitOfWork for:
- Consistency (all or nothing)
- Race conditions (concurrent access)
- Error recovery (rollback on failure)

## When Do You Need UnitOfWork?

| Scenario | Need it? | Why |
|---|---|---|
| DELETE user (5 tables) |  Yes | Orphan data |
| UPDATE role + permissions |  Yes | Inconsistent state |
| INSERT user + assign role |  Yes | User without role |
| Single INSERT |  No | Simple operation |
| Single UPDATE |  No | Simple operation |

**Rule:** If you're doing more than one DB operation, use UnitOfWork.

## The Nativa Approach

Nativa Minimal is a zero-dependency project. No frameworks, no ORM.

But UnitOfWork isn't a framework. It's a **pattern**.

```php
final class UnitOfWork
{
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
```

42 lines of code. No dependencies. Works with any PDO.

## Summary

- **Problem:** 5 separate DELETEs = orphan data
- **Solution:** UnitOfWork = all or nothing
- **Philosophy:** Transactional consistency without dependencies
- **SQLite:** WAL helps, but UnitOfWork is still needed

> **We don't need an ORM that generates transactions.
> We need a pattern that works with raw PDO.**

---

**Related articles:**
- [DI Evolution](/en/di-evolution) ... the philosophy behind patterns
- [UnitOfWork Implementation](/en/uow-implementation) ... how we built it

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
