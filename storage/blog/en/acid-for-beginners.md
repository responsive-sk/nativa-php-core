---
title: "ACID for beginners ... why transactions aren't boring"
date: "2026-08-24"
version: "1.0.0"
tags: ["security-series", "php", "acid", "transactions", "architecture", "database"]
excerpt: "Atomicity, consistency, isolation, durability. Sounds like a textbook, but it's actually protection against losing data from your database. How SQLite transactions work and why you need them even on shared hosting."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "acid-pre-deti"
published: true
---

## What is ACID

ACID is four letters that protect your data:

- **A**tomicity ... all or nothing
- **C**onsistency ... data stays valid
- **I**solation ... concurrent operations don't interfere
- **D**urability ... once written, it stays written

Sounds abstract? Let's look at a real example.

## Example without ACID: How data disappears

Imagine registering a user and assigning them a role at the same time:

```php
//  Without transaction ... dangerous!
$pdo->prepare('INSERT INTO users (id, name, email) VALUES (?, ?, ?)')
    ->execute([$id, $name, $email]);

// Meanwhile someone deletes the role from the database
$pdo->prepare('DELETE FROM roles WHERE id = ?')
    ->execute([$roleId]);

// This fails ... foreign key constraint
$pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
    ->execute([$id, $roleId]);

// RESULT: User exists but has no role. Partial state.
```

The user is in the database but without a role. Now what? You have no admin access and no way to figure out what happened.

## Example with ACID: All or nothing

```php
//  With transaction ... safe
$uow->transaction(function (PDO $pdo) use ($id, $name, $email, $roleId) {
    $pdo->prepare('INSERT INTO users (id, name, email) VALUES (?, ?, ?)')
        ->execute([$id, $name, $email]);

    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
        ->execute([$id, $roleId]);
});
// Either BOTH are saved, or NOTHING is saved.
// No partial state.
```

When the second `INSERT` fails (because the role was deleted in between), the first `INSERT` **automatically rolls back**. The user doesn't exist halfway.

## How it works in SQLite

Our project uses **per-module SQLite databases** ... each module has its own file:

```
storage/data/users.db    ... users, roles, permissions
storage/data/config.db   ... site settings
storage/data/locales.db  ... translations
storage/data/audit.db    ... audit log
storage/data/media.db    ... media
```

Each module has its own `UnitOfWork` class that wraps operations in transactions:

```php
// DatabaseConnectionManager ... per-module connection
$pdo = DatabaseConnectionManager::instance()->connection('users');
$uow = new UnitOfWork($pdo);

// Transaction ... all or nothing
$uow->transaction(function (PDO $pdo) use ($user, $role) {
    $pdo->prepare('INSERT INTO users ...')->execute([...]);
    $pdo->prepare('INSERT INTO user_roles ...')->execute([...]);
});
```

`UnitOfWork` is a simple class ... just 80 lines:

```php
public function transaction(callable $fn): mixed
{
    $this->beginTransaction();
    try {
        $result = $fn($this->pdo);
        $this->commit();
        return $result;
    } catch (\Throwable $e) {
        $this->rollback();
        throw $e;
    }
}
```

## Why ACID on shared hosting?

On shared hosting (WebSupport.sk) you get **128-256 MB RAM** for a PHP process. When an FPM worker crashes (like when argon2id took down our server ... [article about that](/en/blog/how-sha-hash-algorithm-crashed-shared-hosting)), everything in memory vanishes.

Without transactions:
1. You write a user to `users.db`
2. FPM worker crashes
3. You try to write the role ... but the worker is dead
4. `users.db` has an orphaned record without a role

With transactions:
1. You start a transaction
2. You write the user
3. FPM worker crashes
4. SQLite automatically rolls back the incomplete transaction
5. `users.db` is clean ... no partial state

**Atomicity isn't a luxury ... it's insurance against server crashes.**

## Three letters you might have overlooked

### Isolation

When two requests write to the same table simultaneously, isolation ensures they don't see each other's uncommitted changes.

SQLite has implicit isolation ... when you start a transaction, other connections see only the data from before the transaction. Only after you `commit` do changes become visible.

```php
// Request A: starts a transaction
$uow->transaction(function (PDO $pdo) {
    // Sees database state BEFORE the transaction
    $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')
        ->execute(['New name', 1]);
    // Other connections don't see the change yet!
});

// Request B: reads from the table
$name = $pdo->query('SELECT name FROM users WHERE id = 1')->fetch();
// Returns OLD name ... transaction A hasn't committed yet
```

### Durability

When you `commit`, SQLite writes data to disk. If the server crashes, data is recovered from disk on restart. Nothing is lost.

But beware ... on shared hosting, disk I/O can be slow. That's why you shouldn't make huge transactions (thousands of rows at once).

## ACID and the Outbox pattern

Once you understand ACID, the Outbox pattern makes sense:

```php
// Both operations in one transaction
$uow->transaction(function (PDO $pdo) use ($order) {
    // 1. Business data ... must be atomic
    $pdo->prepare('INSERT INTO orders ...')->execute([...]);

    // 2. Domain event ... for async processing
    $pdo->prepare('INSERT INTO outbox (event_type, payload) VALUES (?, ?)')
        ->execute(['OrderCreated', json_encode($order->toArray())]);
});
```

Without ACID, Outbox wouldn't work ... the event could exist without the order, or vice versa. ACID ensures it's both or neither.

## The lesson

**ACID isn't theory ... it's a safety net.**

On shared hosting, where servers crash, FPM workers die, and disks are slow, transactions are the only thing preventing partial database states.

And UnitOfWork? It just kills boilerplate. Instead of `beginTransaction()` / `commit()` / `rollBack()` in every action, you get one function that handles it for you.

```php
// Without UnitOfWork ... 6 extra lines
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT ...')->execute([...]);
    $pdo->prepare('INSERT ...')->execute([...]);
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

// With UnitOfWork ... 1 line
$uow->transaction(function (PDO $pdo) {
    $pdo->prepare('INSERT ...')->execute([...]);
    $pdo->prepare('INSERT ...')->execute([...]);
});
```

Less code, same safety. And that's exactly what you want on a project where every extra line is a place for a bug.

→ [How argon2id crashed our shared hosting](/en/blog/how-sha-hash-algorithm-crashed-shared-hosting): another reason infrastructure limits choices
→ [Cache wrappers ... good servant, bad master](/en/blog/cache-wrappers-good-servant-bad-master): when cache helps and when it hurts
→ [Outbox pattern ... when cache isn't enough](/en/blog/outbox-pattern-when-cache-isnt-enough): ACID for async processing

## Sources

- [W3C, ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance): ACID definition
- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [SQLite Documentation](https://www.sqlite.org/docs.html): SQLite reference manual
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
