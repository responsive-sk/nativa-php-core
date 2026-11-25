---
title: "Prečo UnitOfWork? ... Filozofia transakčnej konzistencie"
date: "2026-08-18"
version: "1.0.2"
tags: ["php", "architecture", "database", "transactions"]
excerpt: "Prečo potrebujeme UnitOfWork? Problém orphan data, filozofia „všetko alebo nič“, a ako SQLite WAL mení pravidlá."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "uow-philosophy"
published: true
---

Keď mažeš používateľa z databázy, mažeš ho z piatich tabuliek.
Ak jedno z tých piatich zlyhá ... zostanú ti orphan data.

To nie je teoretický problém. Stáva sa to každý deň.

## Problém: orphan data

Predstav si admin panel. Klikneš „Delete user“. Systém:

```php
// 5 samostatných operácií
$this->pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
$this->pdo->prepare('DELETE FROM password_reset_tokens WHERE email = ?')->execute([$email]);
$this->pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$id]);
$this->pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
$this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
```

**Scenár 1:** Prvý DELETE prejde. Druhý zlyhá (disk plný, timeout, čokoľvek).
Výsledok: user existuje, ale jeho role sú preč. Pri ďalšom prihlásení sa systém pokúsi načítať role → chyba.

**Scenár 2:** Prvé tri DELETE prejdú. Štvrtý zlyhá.
Výsledok: user je preč, ale jeho sessions zostali. „Ghost session“ ... niekto sa môže prihlásiť cez starý session cookie.

**Scenár 3:** Všetko prejde, ale medzi tretím a štvrtým DELETE sa niekto prihlásil.
Výsledok: race condition ... session sa vytvorila pre usera, ktorého systém práve maže.

## Filozofia: všetko alebo nič

Unit of Work hovorí jednoduchú vec: **ak jedna operácia zlyhá, vráť všetko späť.**

```php
$uow = new UnitOfWork($pdo);
$uow->transaction(function (\PDO $pdo) use ($id, $email): void {
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM password_reset_tokens WHERE email = ?')->execute([$email]);
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
});
// Buď sa vymaže všetko, alebo zostane všetko.
```

Žiadne orphan data. Žiadne ghost sessions. Žiadne race conditions.

## UnitOfWork vs Outbox

Ľudia si často mýlia tieto dva patterny. Nie sú to isté.

| | UnitOfWork | Outbox |
|---|---|---|
| **Otázka** | „Ako spraviť viac operácií v jednej transakcii?“ | „Ako spustiť side efekty bez porušenia ACID?“ |
| **Typ** | DB transakcia | Domain event publishing |
| **Príklad** | INSERT + UPDATE + DELETE v jednej transakcii | Uložiť event + poslať email v jednej transakcii |

**UnitOfWork** = „všetko alebo nič“ pre DB operácie.
**Outbox** = „ulož event, spracuj neskôr“ pre side efekty.

Spolu fungujú takto:

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

## SQLite a WAL mode

Nativa používa SQLite. A SQLite má svoje vlastné pravidlá pre transakcie.

**WAL (Write-Ahead Logging)** je štandardný režim v Native:

```php
$pdo->exec('PRAGMA journal_mode = WAL');
```

**Čo WAL robí:**
- Súčasné čítanie + zapisovanie (lepší concurrent behavior)
- Lepší výkon pod FPM workers
- Automatické checkpointovanie

**Dôležité:** WAL neznamená, že môžeš ignorovať transakcie. Stále potrebuješ UnitOfWork pre:
- Konzistenciu (všetko alebo nič)
- Race conditions (súčasné prístupy)
- Error recovery (rollback pri chybe)

## Kedy potrebuješ UnitOfWork

| Scénár | Potrebuješ? | Prečo |
|---|---|---|
| DELETE user (5 tabuliek) |  Áno | Orphan data |
| UPDATE role + permissions |  Áno | Inconsistent state |
| INSERT user + assign role |  Áno | User bez role |
| Single INSERT |  Nie | Jednoduchá operácia |
| Single UPDATE |  Nie | Jednoduchá operácia |

**Pravidlo:** Ak robíš viac ako jednu DB operáciu, použi UnitOfWork.

## Nativa prístup

Nativa Minimal je zero-dependency projekt. Žiadne frameworky, žiadne ORM.

Ale UnitOfWork nie je framework. Je to **pattern**.

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

42 riadkov kódu. Žiadne závislosti. Funguje s akýmkoľvek PDO.

- **Problém:** 5 samostatných DELETEs = orphan data
- **Riešenie:** UnitOfWork = všetko alebo nič
- **Filozofia:** Transakčná konzistencia bez závislostí
- **SQLite:** WAL pomáha, ale UnitOfWork je stále potrebný

> **Nepotrebujeme ORM, ktorý generuje transakcie.
> Potrebujeme pattern, ktorý funguje s čistým PDO.**

---


## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
