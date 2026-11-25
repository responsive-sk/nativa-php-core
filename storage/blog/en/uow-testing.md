---
title: "Testing Transactions ... How to Test Rollback"
date: "2026-08-18"
version: "1.0.0"
tags: ["php", "testing", "database", "transactions", "phpunit"]
excerpt: "How to test UnitOfWork ... commit, rollback, nested transactions, and common pitfalls."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Testing"
views: "0"
translation: "uow-testing"
published: true
---

You test that data can be written to the database.
But do you test that it rolls back on failure?

That's the difference between testing the "happy path" and testing the "error path."

## What We Test

### 1. Transaction Commits on Success

```php
public function testTransactionCommitsOnSuccess(): void
{
    $pdo = DatabaseConnectionManager::instance()->connection('users');
    $uow = new UnitOfWork($pdo);

    $result = $uow->transaction(function (\PDO $pdo): string {
        $pdo->exec("INSERT INTO test_table (name) VALUES ('test')");
        return 'ok';
    });

    self::assertSame('ok', $result);

    // Verify data persisted
    $stmt = $pdo->query("SELECT name FROM test_table WHERE name = 'test'");
    self::assertSame('test', $stmt->fetchColumn());
}
```

**What we verify:**
- Callback executes
- Return value is passed through
- Data remains in DB

### 2. Transaction Rolls Back on Exception

```php
public function testTransactionRollsBackOnException(): void
{
    $pdo = DatabaseConnectionManager::instance()->connection('users');
    $uow = new UnitOfWork($pdo);

    try {
        $uow->transaction(function (\PDO $pdo): void {
            $pdo->exec("INSERT INTO test_table (name) VALUES ('before_error')");
            throw new \RuntimeException('Simulated error');
        });
        self::fail('Exception expected');
    } catch (\RuntimeException $e) {
        self::assertSame('Simulated error', $e->getMessage());
    }

    // Verify data was rolled back
    $stmt = $pdo->query("SELECT COUNT(*) FROM test_table WHERE name = 'before_error'");
    self::assertSame('0', $stmt->fetchColumn());
}
```

**What we verify:**
- Exception is rethrown
- Data is rolled back
- DB is in original state after rollback

### 3. Nested Transactions with Savepoints

```php
public function testNestedTransactions(): void
{
    $pdo = DatabaseConnectionManager::instance()->connection('users');
    $uow = new UnitOfWork($pdo);

    $uow->transaction(function (\PDO $pdo) use ($uow): void {
        $pdo->exec("INSERT INTO test_table (name) VALUES ('outer')");

        // Nested transaction
        $uow->transaction(function (\PDO $pdo): void {
            $pdo->exec("INSERT INTO test_table (name) VALUES ('inner')");
        });

        // Both should exist
        $stmt = $pdo->query("SELECT COUNT(*) FROM test_table");
        self::assertSame('2', $stmt->fetchColumn());
    });
}
```

### 4. InTransaction State Tracking

```php
public function testInTransactionStateTracking(): void
{
    $pdo = DatabaseConnectionManager::instance()->connection('users');
    $uow = new UnitOfWork($pdo);

    self::assertFalse($uow->inTransaction());

    $uow->transaction(function (\PDO $pdo) use ($uow): void {
        self::assertTrue($uow->inTransaction());
    });

    self::assertFalse($uow->inTransaction());
}
```

## Testing Strategies

### Strategy 1: In-Memory SQLite

```php
// In setUp()
$pdo = new \PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');
```

**Benefits:**
- Fast (no disk I/O)
- Clean (each test has its own DB)
- Isolated (no side effects)

### Strategy 2: Scratch DB in /tmp

```php
// In setUp()
$this->tmpDir = sys_get_temp_dir() . '/nativa-test-' . bin2hex(random_bytes(6));
mkdir($this->tmpDir, 0o775, true);
putenv('DB_USERS=' . $this->tmpDir . '/users.db');
```

**Benefits:**
- Realistic (SQLite on disk)
- Inspectable (can examine DB)
- Cache-friendly (OPcache works)

### Strategy 3: Transaction Rollback in tearDown

```php
protected function tearDown(): void
{
    // Rollback all changes
    if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
    }
    // Delete temp files
    @unlink($this->dbPath);
}
```

## Common Pitfalls

### Pitfall 1: Forgetting Rollback

```php
//  BAD ... test leaves data in DB
public function testInsert(): void
{
    $pdo->exec("INSERT INTO users (name) VALUES ('test')");
    // Missing rollback in tearDown
}

//  GOOD ... test cleans up after itself
protected function tearDown(): void
{
    if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
    }
}
```

### Pitfall 2: Shared DB Between Tests

```php
//  BAD ... all tests use same DB
private static \PDO $pdo; // static = shared

//  GOOD ... each test has its own DB
private \PDO $pdo; // instance = isolated
```

### Pitfall 3: Testing Only Happy Path

```php
//  BAD ... only testing success
public function testDelete(): void
{
    $uow->transaction(function (\PDO $pdo) {
        $pdo->exec("DELETE FROM users WHERE id = 1");
    });
    // Don't verify rollback on failure
}

//  GOOD ... testing error path too
public function testDeleteRollbackOnFailure(): void
{
    try {
        $uow->transaction(function (\PDO $pdo) {
            $pdo->exec("DELETE FROM users WHERE id = 1");
            throw new \RuntimeException('error');
        });
        self::fail('Expected exception');
    } catch (\RuntimeException $e) {
        // Verify data remained
    }
}
```

## OutboxMessageTest

### Status Transitions

```php
public function testStatusTransitions(): void
{
    $msg = OutboxMessage::create('UserCreated', ['user_id' => '123']);

    self::assertSame('pending', $msg->status());
    self::assertSame(0, $msg->attempts());

    $msg = $msg->withProcessing();
    self::assertSame('processing', $msg->status());
    self::assertSame(1, $msg->attempts());

    $msg = $msg->withProcessed();
    self::assertSame('processed', $msg->status());
    self::assertNotNull($msg->processedAt());
}
```

### Immutable State

```php
public function testImmutableStateTransitions(): void
{
    $original = OutboxMessage::create('UserCreated', ['user_id' => '123']);
    $processed = $original->withProcessed();

    // Original remains unchanged
    self::assertSame('pending', $original->status());
    self::assertSame('processed', $processed->status());
}
```

### Retry Logic

```php
public function testCanRetryUnderMaxAttempts(): void
{
    $msg = OutboxMessage::create('UserCreated', ['user_id' => '123']);

    self::assertTrue($msg->canRetry());

    $msg = $msg->withFailed('error');
    self::assertTrue($msg->canRetry()); // still attempts < maxAttempts

    // Simulate max attempts
    for ($i = 0; $i < 3; $i++) {
        $msg = $msg->withRetry();
    }
    self::assertFalse($msg->canRetry());
}
```

## Summary

- **Test error path** ... not just happy path
- **Isolate tests** ... each test its own DB
- **Rollback in tearDown** ... clean up after yourself
- **Test immutable state** ... original remains unchanged

> **Testing transactions = testing rollback.
> If you only test commit, you're not testing anything.**

---

**Related articles:**
- [UnitOfWork Implementation](/en/uow-implementation) ... the implementation
- [Why UnitOfWork?](/en/uow-philosophy) ... the philosophy

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
