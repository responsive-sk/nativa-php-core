---
title: "Outbox Pattern ... Domain Events Without Breaking ACID"
date: "2026-08-18"
version: "1.0.0"
tags: ["php", "architecture", "database", "events", "outbox"]
excerpt: "How to publish domain events without breaking ACID ... the Outbox pattern for Nativa Minimal."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "uow-outbox"
published: true
---

You send an email. Then you save the order to the database.
The email sends, but the database fails.

Result: email sent, order doesn't exist.

That's the problem the Outbox pattern solves.

## The Problem: Side Effects Without ACID

```php
// WITHOUT Outbox
$mailer->send($email);  //  Email sent
$pdo->prepare('INSERT INTO orders ...')->execute([...]); //  Failed
// Result: Email sent, but order doesn't exist
```

**Why this happens:**
- Email is an external system (SMTP server)
- DB is an internal system (SQLite)
- You can't put both in one transaction

## The Solution: Outbox

The Outbox pattern says: **save the event to an outbox table, process later.**

```php
$uow = new UnitOfWork($pdo);
$uow->transaction(function (\PDO $pdo) use ($order, $email): void {
    // 1. Save the order
    $pdo->prepare('INSERT INTO orders ...')->execute([...]);

    // 2. Save event to outbox table
    $pdo->prepare('INSERT INTO outbox_events ...')->execute([
        'event_type' => 'OrderCreated',
        'payload' => json_encode(['order_id' => $orderId]),
    ]);
    // Both in one transaction ... either both, or neither
});
```

**Later (async):** OutboxProcessor processes events:
- Loads pending events from outbox
- Sends email
- Marks event as processed

## Implementation in Nativa

### 1. OutboxMessage ... Immutable Entity

```php
final readonly class OutboxMessage
{
    public function __construct(
        private string $id,
        private string $eventType,
        private array $payload,
        private string $status,
        private int $attempts,
        private int $maxAttempts,
        private string $createdAt,
        private ?string $processedAt = null,
        private ?string $errorMessage = null,
    ) {
    }

    public static function create(string $eventType, array $payload): self
    {
        return new self(
            id: bin2hex(random_bytes(16)),
            eventType: $eventType,
            payload: $payload,
            status: 'pending',
            attempts: 0,
            maxAttempts: 3,
            createdAt: date('Y-m-d H:i:s'),
        );
    }

    public function withProcessing(): self
    {
        return new self(
            ...array_merge((array) $this, [
                'status' => 'processing',
                'attempts' => $this->attempts + 1,
            ]),
        );
    }

    public function withProcessed(): self
    {
        return new self(
            ...array_merge((array) $this, [
                'status' => 'processed',
                'processedAt' => date('Y-m-d H:i:s'),
            ]),
        );
    }

    public function withFailed(string $error): self
    {
        return new self(
            ...array_merge((array) $this, [
                'status' => 'failed',
                'errorMessage' => $error,
            ]),
        );
    }
}
```

**Key features:**
- **Immutable** ... every change creates a new instance
- **Status transitions** ... pending → processing → processed/failed
- **Retry logic** ... maxAttempts, withRetry()

### 2. OutboxRepository ... Persistence

```php
final readonly class SqliteOutboxRepository
{
    public function __construct(private \PDO $pdo) {}

    public function save(OutboxMessage $message): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO outbox_messages (id, event_type, payload, status, attempts, max_attempts, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $message->id(),
            $message->eventType(),
            json_encode($message->payload()),
            $message->status(),
            $message->attempts(),
            $message->maxAttempts(),
            $message->createdAt(),
        ]);
    }

    public function findPending(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM outbox_messages
            WHERE status = \'pending\' AND attempts < max_attempts
            ORDER BY created_at ASC
            LIMIT ?
        ');
        $stmt->execute([$limit]);
        // ...
    }
}
```

### 3. OutboxProcessor ... Background Job

```php
final class OutboxProcessor
{
    /** @var array<string, callable(OutboxMessage): void> */
    private array $handlers = [];

    public function registerHandler(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType] = $handler;
    }

    public function process(int $batchSize = 100): int
    {
        $messages = $this->repository->findPending($batchSize);
        $processed = 0;

        foreach ($messages as $message) {
            try {
                $message = $message->withProcessing();
                $this->repository->save($message);

                $handler = $this->handlers[$message->eventType()] ?? null;
                if (null !== $handler) {
                    $handler($message);
                }

                $message = $message->withProcessed();
                $this->repository->save($message);
                $processed++;
            } catch (\Throwable $e) {
                $message = $message->withFailed($e->getMessage());
                $this->repository->save($message);
            }
        }

        return $processed;
    }
}
```

### 4. UnitOfWork Integration

```php
// In action ... save event in one transaction
$uow = new UnitOfWork($pdo);
$uow->transaction(function (\PDO $pdo) use ($order): void {
    // Business operation
    $pdo->prepare('INSERT INTO orders ...')->execute([...]);

    // Domain event
    OutboxServices::repository()->save(OutboxMessage::create(
        eventType: 'OrderCreated',
        payload: ['order_id' => $order->id()],
    ));
});

// Later (cron, queue worker) ... process events
$processor = OutboxServices::processor();
$processor->registerHandler('OrderCreated', function (OutboxMessage $msg) {
    $payload = $msg->payload();
    // Send email
});
$processor->process(100);
```

## When to Use Outbox

| Scenario | Use Outbox? | Why |
|---|---|---|
| Send email after registration |  Yes | Email = external system |
| Notification after order |  Yes | Notification = external system |
| Audit log |  No | Audit is internal (can be in transaction) |
| Update user |  No | DB operation only |

**Rule:** If the side effect involves an external system (email, API, webhook), use Outbox.

## Outbox vs Direct Calls

| | Direct Calls | Outbox |
|---|---|---|
| **Consistency** |  Breaks ACID |  ACID consistent |
| **Retry** |  Manual |  Automatic |
| **Visibility** |  Don't know what failed |  Status in DB |
| **Complexity** | Simple | more complex |

## Summary

- **Problem:** Side effects without ACID
- **Solution:** Outbox = save event, process later
- **Integration:** UnitOfWork + Outbox = transactional consistency
- **Philosophy:** External systems via outbox, not directly

> **We don't need a message queue for every side effect.
> We need a pattern that works with SQLite.**

---

**Related articles:**
- [UnitOfWork Implementation](/en/uow-implementation) ... transactional consistency
- [Why UnitOfWork?](/en/uow-philosophy) ... the philosophy behind the pattern

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
