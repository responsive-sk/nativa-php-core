---
title: "Outbox Pattern ... Domain Events bez porušenia ACID"
date: "2026-08-18"
version: "1.0.1"
tags: ["php", "architecture", "database", "events", "outbox"]
excerpt: "Ako publikovať domain events bez porušenia ACID ... Outbox pattern pre Nativa Minimal."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "uow-outbox"
published: true
---

Pošleš email. Potom uložíš objednávku do databázy.
Email sa pošle, ale databáza zlyhá.

Výsledok: email odoslaný, objednávka neexistuje.

To je problém, ktorý Outbox pattern rieši.

## Problém: Side efekty bez ACID

```php
// BEZ Outbox
$mailer->send($email);  //  Email odoslaný
$pdo->prepare('INSERT INTO orders ...')->execute([...]); //  Zlyhalo
// Výsledok: Email odoslaný, ale objednávka neexistuje
```

**Prečo sa to stáva:**
- Email je vonkajší systém (SMTP server)
- DB je vnútorný systém (SQLite)
- Nemôžeš dať oba do jednej transakcie

## Riešenie: Outbox

Outbox pattern hovorí: **ulož event do outbox tabuľky, spracuj neskôr.**

```php
$uow = new UnitOfWork($pdo);
$uow->transaction(function (\PDO $pdo) use ($order, $email): void {
    // 1. Uložiť objednávku
    $pdo->prepare('INSERT INTO orders ...')->execute([...]);

    // 2. Uložiť event do outbox tabuľky
    $pdo->prepare('INSERT INTO outbox_events ...')->execute([
        'event_type' => 'OrderCreated',
        'payload' => json_encode(['order_id' => $orderId]),
    ]);
    // Oboje v jednej transakcii ... buď obe, alebo ani jedno
});
```

**Neskôr (async):** OutboxProcessor spracuje eventy:
- Načíta pending events z outbox
- Pošle email
- Označí event ako processed

## Implementácia v Nativa

### 1. OutboxMessage ... immutable entity

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

**Kľúčové vlastnosti:**
- **Immutable** ... každá zmena vytvorí novú inštanciu
- **Status transitions** ... pending → processing → processed/failed
- **Retry logic** ... maxAttempts, withRetry()

### 2. OutboxRepository ... persistence

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

### 3. OutboxProcessor ... background job

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

### 4. Integrácia s UnitOfWork

```php
// V akcii ... uložiť event v jednej transakcii
$uow = new UnitOfWork($pdo);
$uow->transaction(function (\PDO $pdo) use ($order): void {
    // Business operácia
    $pdo->prepare('INSERT INTO orders ...')->execute([...]);

    // Domain event
    OutboxServices::repository()->save(OutboxMessage::create(
        eventType: 'OrderCreated',
        payload: ['order_id' => $order->id()],
    ));
});

// Neskôr (cron, queue worker) ... spracovať eventy
$processor = OutboxServices::processor();
$processor->registerHandler('OrderCreated', function (OutboxMessage $msg) {
    $payload = $msg->payload();
    // Poslať email
});
$processor->process(100);
```

## Kedy použiť Outbox

| Scénár | Použiť Outbox? | Prečo |
|---|---|---|
| Poslať email po registrácii |  Áno | Email = vonkajší systém |
| Notifikácia po objednávke |  Áno | Notifikácia = vonkajší systém |
| Audit log |  Nie | Audit je vnútorný (môže bežať v transakcii) |
| Update používateľa |  Nie | Len DB operácia |

**Pravidlo:** Ak side efekt zahŕňa vonkajší systém (email, API, webhook), použi Outbox.

## Outbox vs Priame volanie

| | Priame volanie | Outbox |
|---|---|---|
| **Konzistencia** |  Porušuje ACID |  ACID konzistentné |
| **Retry** |  Manuálny |  Automatický |
| **Visibility** |  Neviem čo zlyhalo |  Status v DB |
| **Complexity** | Jednoduché | väčšia réžia |

- **Problém:** Side efekty bez ACID
- **Riešenie:** Outbox = ulož event, spracuj neskôr
- **Integrácia:** UnitOfWork + Outbox = transakčná konzistencia
- **Filozofia:** Vonkajšie systémy cez outbox, nie na priamo

> **Nepotrebujeme message queue pre každý side efekt.
> Potrebujeme pattern, ktorý funguje s SQLite.**

---


## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
