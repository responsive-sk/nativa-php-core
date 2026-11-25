---
title: "Testovanie transakcií ... Ako otestovať rollback"
date: "2026-08-18"
version: "1.0.2"
tags: ["php", "testing", "database", "transactions", "phpunit"]
excerpt: "Ako testovať UnitOfWork ... commit, rollback, nested transactions, a common pitfalls."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Testing"
views: "0"
translation: "uow-testing"
published: true
---

Testuješ, že sa dá zapísať do databázy.
Ale testuješ, že sa rollbackne pri chybe?

To je rozdiel medzi testovaním „happy path“ a testovaním „error path“.

## Čo testujeme

### 1. Transaction commits on success

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

    // Overiť že dáta zostali
    $stmt = $pdo->query("SELECT name FROM test_table WHERE name = 'test'");
    self::assertSame('test', $stmt->fetchColumn());
}
```

**Čo overujeme:**
- Callback sa spustí
- Return value sa prenesie
- Dáta zostanú v DB

### 2. Transaction rolls back on exception

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

    // Overiť že dáta zmizli
    $stmt = $pdo->query("SELECT COUNT(*) FROM test_table WHERE name = 'before_error'");
    self::assertSame('0', $stmt->fetchColumn());
}
```

**Čo overujeme:**
- Exception sa rethrowne
- Dáta sa rollbacknu
- Po rollbacku je DB v pôvodnom stave

### 3. Nested transactions with savepoints

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

### 4. InTransaction state tracking

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

## Testovacie stratégie

### Stratégia 1: In-memory SQLite

```php
// V setUp()
$pdo = new \PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT)');
```

**Výhody:**
- Rýchle (žiadny disk I/O)
- Čisté (každý test má svoju DB)
- Izolované (žiadne side effects)

### Stratégia 2: Scratch DB v /tmp

```php
// V setUp()
$this->tmpDir = sys_get_temp_dir() . '/nativa-test-' . bin2hex(random_bytes(6));
mkdir($this->tmpDir, 0o775, true);
putenv('DB_USERS=' . $this->tmpDir . '/users.db');
```

**Výhody:**
- Realistické (SQLite na disku)
- Testovateľné (môžeš inspektovať DB)
- Cache-priateľské (OPcache funguje)

### Stratégia 3: Transaction rollback v tearDown

```php
protected function tearDown(): void
{
    // Rollback všetkých zmien
    if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
    }
    // Vymazať dočasné súbory
    @unlink($this->dbPath);
}
```

## Common Pitfalls

### Pitfall 1: Zabudnutý rollback

```php
//  ZLE ... test nechá dáta v DB
public function testInsert(): void
{
    $pdo->exec("INSERT INTO users (name) VALUES ('test')");
    // Chýba rollback v tearDown
}

//  DOBRE ... test čistí za sebou
protected function tearDown(): void
{
    if ($this->pdo->inTransaction()) {
        $this->pdo->rollBack();
    }
}
```

### Pitfall 2: Zdieľaná DB medzi testami

```php
//  ZLE ... všetky testy používajú rovnakú DB
private static \PDO $pdo; // static = zdieľané

//  DOBRE ... každý test má svoju DB
private \PDO $pdo; // instance = izolované
```

### Pitfall 3: Testovanie len happy path

```php
//  ZLE ... testujem len úspech
public function testDelete(): void
{
    $uow->transaction(function (\PDO $pdo) {
        $pdo->exec("DELETE FROM users WHERE id = 1");
    });
    // Neoverujem že sa rollbackne pri chybe
}

//  DOBRE ... testujem aj error path
public function testDeleteRollbackOnFailure(): void
{
    try {
        $uow->transaction(function (\PDO $pdo) {
            $pdo->exec("DELETE FROM users WHERE id = 1");
            throw new \RuntimeException('error');
        });
        self::fail('Expected exception');
    } catch (\RuntimeException $e) {
        // Overiť že dáta zostali
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

    // Originál zostáva nezmenený
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
    self::assertTrue($msg->canRetry()); // ešte má attempts < maxAttempts

    // Simulovať max attempts
    for ($i = 0; $i < 3; $i++) {
        $msg = $msg->withRetry();
    }
    self::assertFalse($msg->canRetry());
}
```

- **Testuj error path** ... nie len happy path
- **Izoluj testy** ... každý test svoju DB
- **Rollback v tearDown** ... čistí za sebou
- **Testuj immutable state** ... originál zostáva nezmenený

> **Testovanie transakcií = testovanie rollbacku.
> Ak testuješ len commit, netestuješ nič.**

---

→ [UnitOfWork implementácia](/blog/uow-implementation) — ako to funguje
→ [UnitOfWork filozofia](/blog/uow-philosophy) — prečo UnitOfWork
→ [UnitOfWork outbox](/blog/uow-outbox) — async spracovanie

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
