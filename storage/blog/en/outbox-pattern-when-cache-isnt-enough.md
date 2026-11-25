---
title: "Outbox pattern ... when cache isn't enough"
date: "2026-08-25"
version: "1.0.0"
tags: ["security-series", "php", "architecture", "outbox", "domain-events", "async"]
excerpt: "Cache stores responses. Outbox stores events. When you need to send an email, share a chunk via P2P, or fire a webhook ... and you don't want a server crash to mean a lost message ... you need Outbox."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "outbox-pattern"
published: true
---

## Why not just cache?

Cache is a great tool. But cache stores **responses** ... results of reads. When you need to **send something out** (email, webhook, P2P broadcast), cache doesn't help. You need something else: Outbox.

```
Cache = "What did you tell me?"  → READ
Outbox = "What should I tell you?" → WRITE
```

## What is the Outbox pattern

Outbox is a simple idea: when you do a business operation (order, registration, upload), you store a **domain event** in the same table, in the **same transaction**. You don't process it immediately ... you leave it for a background process.

```php
// In one transaction ... either BOTH are saved or NOTHING
$uow->transaction(function (PDO $pdo) use ($order) {
    // 1. Business data
    $pdo->prepare('INSERT INTO orders (id, total) VALUES (?, ?)')
        ->execute([$order->id(), $order->total()]);

    // 2. Domain event ... for async processing
    $pdo->prepare('INSERT INTO outbox (event_type, payload) VALUES (?, ?)')
        ->execute(['OrderCreated', json_encode(['order_id' => $order->id()])]);
});

// Later ... background process
$processor->process(100); // Process up to 100 pending events
```

Without ACID this wouldn't work ... the event could exist without the order. With ACID it's **atomic**.

## How it works in our project

We have a complete Outbox module ... `Modules\Outbox`. It consists of three parts:

### 1. OutboxMessage ... immutable domain event

```php
final readonly class OutboxMessage
{
    // State: pending → processing → processed | failed
    public static function create(
        string $eventType,
        array $payload,
        ?array $metadata = null,
    ): self { ... }

    // State transitions ... immutable, returns new instance
    public function withProcessing(): self { ... }
    public function withProcessed(): self { ... }
    public function withFailed(string $error): self { ... }
    public function withRetry(): self { ... }
}
```

Every state change returns a **new instance** ... no mutable fields, no concurrent access bugs.

### 2. OutboxProcessor ... event processing

```php
$processor = new OutboxProcessor($repository);

// Register handlers for specific events
$processor->registerHandler('OrderCreated', function (OutboxMessage $msg) {
    $orderId = $msg->payload()['order_id'];
    $this->emailService->sendConfirmation($orderId);
});

$processor->registerHandler('ChunkShared', function (OutboxMessage $msg) {
    $this->p2pNetwork->broadcast($msg->payload());
});

// Process pending events
$result = $processor->process(100);
// ['processed' => 42, 'failed' => 3]
```

### 3. SqliteOutboxRepository ... persistence

```php
$repo = new SqliteOutboxRepository($pdo);

// Save new event
$repo->save(OutboxMessage::create('UserRegistered', [
    'user_id' => $user->id(),
    'email' => $user->email(),
]));

// Find pending events
$pending = $repo->findPending(100);

// Update status
$repo->update($message->withProcessed());
```

## When to use Outbox

| Scenario | Use Outbox? | Reason |
|----------|-------------|--------|
| Email notification |  Yes | External system, retry |
| Webhook call |  Yes | External system, may fail |
| P2P broadcast |  Yes | Network operation, retry |
| Audit log (internal) |  Depends | If sync needed, no |
| Cache invalidation |  Yes | Redis/Memcached = external |
| Payment |  No | Needs synchronous response |
| Inventory reservation |  No | Must be atomic with order |
| Data reads |  No | Just query directly |

## Outbox vs direct calls

```php
//  Direct calls ... synchronous, fragile
$order->save();
$email->sendConfirmation($order);  // If fails, order exists but email doesn't
$webhook->notify($order);          // If fails, silently ... no retry

//  Outbox ... asynchronous, resilient
$uow->transaction(function (PDO $pdo) use ($order) {
    $order->save($pdo);
    OutboxMessage::create('OrderCreated', $order->toArray())->save($pdo);
});
// Email and webhook sent later, with retry, no risk of partial state
```

## Outbox as small-talk

Outbox is "small-talk" for your architecture:

- **Asynchronous** ... no one waits for immediate response
- **Fault-tolerant** ... retry on failure
- **Decoupled** ... producer doesn't know consumer
- **Observable** ... append-only audit trail

Exactly like small-talk between people: "Hey, when you have time, process this."

## Outbox and P2P

When you combine Outbox with a P2P network, every node becomes both producer and consumer:

```php
// Local node generates a chunk
$uow->transaction(function (PDO $pdo) use ($chunk) {
    $chunk->save($pdo);
    OutboxMessage::create('ChunkShared', [
        'chunk_id' => $chunk->id(),
        'hash' => $chunk->hash(),
    ])->save($pdo);
});

// Background process distributes to network
$processor->registerHandler('ChunkShared', function (OutboxMessage $msg) {
    $this->p2pNetwork->broadcast($msg->payload());
});
```

Cache taught you that answers don't always need to be new, just close. Outbox teaches you that sending doesn't always need to be immediate, just certain.

## Evolution stage

```
Cache wrappers → store responses (reading)
Outbox pattern → store events (writing)
P2P cache      → share both responses and events (distribution)
```

Outbox isn't a replacement for cache. It's the next stage ... for the write path instead of the read path.

## The lesson

**Outbox is insurance for side effects.**

Just like ACID is insurance for data, Outbox is insurance for things that should happen *after* data is saved. Email, webhook, P2P broadcast ... anything that can fail and anything you want to be certain happens.

And when you combine it with a plugin architecture (Cordis/DeepSeek Harness), every side effect becomes a plugin ... replaceable, testable, monitorable.

→ [ACID for beginners](/en/blog/acid-for-beginners) — why transactions aren't boring
→ [Cache wrappers ... good servant, bad master](/en/blog/cache-wrappers-good-servant-bad-master) — when cache helps and when it hurts
→ [The fridge as a P2P server](/en/blog/the-fridge-as-a-p2p-server) — how Outbox works in a decentralized network

## Sources

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) — agent framework with plugin architecture
- [Cordis](https://github.com/deepseek-ai/deepseek-harness/tree/master/vendor/cordis) — TypeScript plugin framework (Outbox as plugin)
