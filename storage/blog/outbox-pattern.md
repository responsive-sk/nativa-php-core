---
title: "Outbox pattern ... keď cache nestihne všetko"
date: "2026-08-25"
version: "1.0.1"
tags: ["security-series", "php", "architecture", "outbox", "domain-events", "async"]
excerpt: "Cache ukladá odpovede. Outbox ukladá udalosti. Keď potrebujete odoslať email, zdieľať chunk cez P2P alebo poslať webhook ... a nechcete aby padnutie servera znamenalo stratenú správu ... potrebujete Outbox."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "outbox-pattern-when-cache-isnt-enough"
published: true
---

## Prečo nie len cache?

Cache je skvelý nástroj. Ale cache ukladá **odpovede** ... výsledky čítania.
Keď potrebujete **poslať niečo von** (email, webhook, P2P broadcast), cache
nepomôže. Potrebujete niečo iné: Outbox.

```
Cache = "Čo si mi povedal?" → READ
Outbox = "Čo ti mám povedať?" → WRITE
```

## Čo je Outbox pattern

Outbox je jednoduchý nápad: keď robíte biznis operáciu (objednávka,
registrácia, upload), uložíte **domain event** do rovnakej tabuľky v
**rovnakej transakcii**. Nespracujete ho hneď ... necháte to na background
proces.

```php
// V jednej transakcii ... buď sa uloží OBOJE alebo NIČ
$uow->transaction(function (PDO $pdo) use ($order) {
    // 1. Biznis dáta
    $pdo->prepare('INSERT INTO orders (id, total) VALUES (?, ?)')
        ->execute([$order->id(), $order->total()]);

    // 2. Domain event ... pre async spracovanie
    $pdo->prepare('INSERT INTO outbox (event_type, payload) VALUES (?, ?)')
        ->execute(['OrderCreated', json_encode(['order_id' => $order->id()])]);
});

// Neskôr ... background proces
$processor->process(100); // Spracuje až 100 čakajúcich eventov
```

Bez ACID by to nefungovalo ... event by mohol vzniknúť bez objednávky.
S ACID je to **atomické**.

## Ako to funguje v našom projekte

Máme kompletný Outbox modul ... `Modules\Outbox`. Pozostáva z troch častí.

### 1. OutboxMessage ... immutable domain event

```php
final readonly class OutboxMessage
{
    // Stav: pending → processing → processed | failed

    public static function create(
        string $eventType,
        array $payload,
        ?array $metadata = null,
    ): self { ... }

    // State transitions ... immutable, vracia novú inštanciu
    public function withProcessing(): self { ... }
    public function withProcessed(): self { ... }
    public function withFailed(string $error): self { ... }
    public function withRetry(): self { ... }
}
```

Každá zmena stavu vracia **novú inštanciu** ... žiadne mutable polia, žiadne
bugy z concurrent prístupu.

### 2. OutboxProcessor ... spracovanie eventov

```php
$processor = new OutboxProcessor($repository);

// Registrujeme handlery pre jednotlivé eventy
$processor->registerHandler('OrderCreated', function (OutboxMessage $msg) {
    $orderId = $msg->payload()['order_id'];
    $this->emailService->sendConfirmation($orderId);
});

$processor->registerHandler('ChunkShared', function (OutboxMessage $msg) {
    $this->p2pNetwork->broadcast($msg->payload());
});

// Spracujeme čakajúce eventy
$result = $processor->process(100);
// ['processed' => 42, 'failed' => 3]
```

### 3. SqliteOutboxRepository ... persistencia

```php
$repo = new SqliteOutboxRepository($pdo);

// Uložiť nový event
$repo->save(OutboxMessage::create('UserRegistered', [
    'user_id' => $user->id(),
    'email'   => $user->email(),
]));

// Nájsť pending eventy
$pending = $repo->findPending(100);

// Aktualizovať stav
$repo->update($message->withProcessed());
```

## Kedy použiť Outbox

| Scenár | Použiť Outbox? | Prečo |
|--------|---------------|-------|
| Email notifikácia | Áno | Vonkajší systém, retry |
| Webhook volanie | Áno | Vonkajší systém, môže zlyhať |
| P2P broadcast | Áno | Sieťová operácia, retry |
| Audit log (interný) | Záleží | Ak potrebujete sync, nie |
| Cache invalidation | Áno | Redis/Memcached = externý |
| Platba | Nie | Potrebujete synchronnú odpoveď |
| Rezervácia skladu | Nie | Musí byť atomická s objednávkou |
| Čítanie dát | Nie | query |

## Outbox vs priame volanie

```php
// Priame volanie ... synchronné, krehké
$order->save();
$email->sendConfirmation($order); // Ak zlyhá, objednávka je v DB ale email nie je
$webhook->notify($order);         // Ak zlyhá, ticho ... žiadny retry

// Outbox ... asynchronné, odolné
$uow->transaction(function (PDO $pdo) use ($order) {
    $order->save($pdo);
    OutboxMessage::create('OrderCreated', $order->toArray())->save($pdo);
});
// Email a webhook sa odošlú neskôr, s retry, bez rizika čiastočného stavu
```

## Outbox a small-talk

Outbox je „small-talk“ pre vašu architektúru:

- **Asynchronný** ... nikto nečaká na okamžitú odpoveď
- **Fault-tolerant** ... retry pri zlyhaní
- **Decoupled** ... producent nepozná spotrebiteľa
- **Observable** ... append-only audit trail

Presne ako small-talk medzi ľuďmi: „Hej, keď budeš mať čas, spracuj to.“

## Outbox a P2P

Keď spojíte Outbox s P2P sieťou, každý uzol sa stáva producentom aj
spotrebiteľom:

```php
// Lokálny uzol generuje chunk
$uow->transaction(function (PDO $pdo) use ($chunk) {
    $chunk->save($pdo);
    OutboxMessage::create('ChunkShared', [
        'chunk_id' => $chunk->id(),
        'hash'     => $chunk->hash(),
    ])->save($pdo);
});

// Background proces distribuuje do siete
$processor->registerHandler('ChunkShared', function (OutboxMessage $msg) {
    $this->p2pNetwork->broadcast($msg->payload());
});
```

Cache vám naučila, že odpoveď nemusí byť vždy nová, len blízka. Outbox vás
naučí, že odoslanie nemusí byť vždy okamžité, len isté.

## Evolučná fáza

```
Cache wrappers → ukladáte odpovede (čítanie)
Outbox pattern → ukladáte udalosti (zápis)
P2P cache → zdieľate odpovede aj udalosti (distribúcia)
```

Outbox nie je náhrada za cache. Je ďalšie štádium ... pre write path namiesto
read path.

## Ponaučenie

**Outbox je poistenie pre side effects.** Rovnako ako ACID je poistenie pre
dáta, Outbox je poistenie pre veci, ktoré sa majú stať *po* uložení dát.

Email, webhook, P2P broadcast ... všetko čo môže zlyhať a všetko čo chcete
mať isté, že sa stane.

A keď to spojíte s pluginovou architektúrou (Cordis/DeepSeek Harness), každý
side effect sa stáva pluginom ... vymeniteľným, testovateľným,
monitorovateľným.

## Zdroje

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) ... agentný framework s pluginovou architektúrou
- [Outbox pattern — Microservices.io](https://microservices.io/patterns/data/outbox-pattern.html) ... pôvodný popis patternu
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
