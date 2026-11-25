---
title: "ACID pre deti ... prečo transakcie nie sú nudné"
date: "2026-08-24"
version: "1.0.3"
tags: ["security-series", "php", "acid", "transactions", "architecture", "database"]
excerpt: "Atomicita, konzistencia, izolácia, trvanlivosť. Znie to ako učebnica, ale v skutočnosti je to ochrana pred tým, aby vám mizli dáta z databázy. Ako fungujú transakcie v SQLite a prečo ich potrebujete aj na shared hostingu."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "acid-for-beginners"
published: true
---

Keď niekto vraví „dáta sú v bezpečí“, myslí tým štyri veci. ACID, Atomicita, Konzistencia, Izolácia, Trvanlivosť. Ale čo tie písmená znamenajú v praxi?

## Čo je ACID

ACID sú štyri písmená, ktoré chránia vaše dáta:

- **A**tomicita ... buď všetko, alebo nič
- **C**onsistency ... dáta zostávajú validné
- **I**solation ... súbežné operácie sa navzájom nerušia
- **D**urability ... keď je zapísané, zostane zapísané

Znie to abstraktne? Poďme na to cez reálny príklad.

## Príklad bez ACID: Ako miznú dáta

Predstavte si, že registrujete používateľa a zároveň mu priradíte rolu:

```php
//  Bez transakcie ... nebezpečné!
$pdo->prepare('INSERT INTO users (id, name, email) VALUES (?, ?, ?)')
    ->execute([$id, $name, $email]);

// Medzitým niekto vymaže rolu z databázy
$pdo->prepare('DELETE FROM roles WHERE id = ?')
    ->execute([$roleId]);

// Toto zlyhá ... foreign key constraint
$pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
    ->execute([$id, $roleId]);

// VÝSLEDOK: Používateľ existuje, ale nemá rolu. Čiastočný stav.
```

Používateľ je v databáze, ale bez role. Čo teraz? Nemáte ani admin prístup, ani spôsob, ako zistiť, čo sa stalo.

## Príklad s ACID: Všetko alebo nič

```php
//  S transakciou ... bezpečné
$uow->transaction(function (PDO $pdo) use ($id, $name, $email, $roleId) {
    $pdo->prepare('INSERT INTO users (id, name, email) VALUES (?, ?, ?)')
        ->execute([$id, $name, $email]);

    $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')
        ->execute([$id, $roleId]);
});
// Buď sa uloží OBOJE, alebo sa uloží NIČ.
// Žiadny čiastočný stav.
```

Keď druhý `INSERT` zlyhá (pretože rola medzitým zmizla), prvý `INSERT` sa **automaticky vráti späť**. Používateľ nevznikne napoly.

## Ako to funguje v SQLite

Náš projekt používa **per-module SQLite databázy** ... každý modul má vlastný súbor:

```
storage/data/users.db    ... používatelia, roly, oprávnenia
storage/data/config.db   ... nastavenia stránky
storage/data/locales.db  ... preklady
storage/data/audit.db    ... auditný log
storage/data/media.db    ... média
```

Každý modul má svoju `UnitOfWork` triedu, ktorá obaľuje operácie do transakcií:

```php
// DatabaseConnectionManager ... per-module pripojenie
$pdo = DatabaseConnectionManager::instance()->connection('users');
$uow = new UnitOfWork($pdo);

// Transakcia ... všetko alebo nič
$uow->transaction(function (PDO $pdo) use ($user, $role) {
    $pdo->prepare('INSERT INTO users ...')->execute([...]);
    $pdo->prepare('INSERT INTO user_roles ...')->execute([...]);
});
```

`UnitOfWork` je jednoduchá trieda ... len 80 riadkov:

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

## Prečo ACID na shared hostingu?

Na shared hostingu (WebSupport.sk) máte **128–256 MB RAM** pre PHP proces. Keď FPM worker spadne (ako keď nám spadol argon2id ... [článok o tom](/blog/ako-sha-hash-algorytm-zrazil-shared-hosting)), všetko čo bolo v pamäti, zmizne.

Bez transakcie:
1. Zapíšete používateľa do `users.db`
2. FPM worker spadne
3. Pokúsite sa zapísať rolu ... ale worker je mŕtvy
4. V `users.db` zostane osamotený záznam bez role

S transakciou:
1. Začnete transakciu
2. Zapíšete používateľa
3. FPM worker spadne
4. SQLite automaticky rollbackne rozbehnutú transakciu
5. V `users.db` je čisto ... žiadny čiastočný stav

**Atomicita nie je luxus ... je to poistenie proti pádom servera.**

## Tri písmená, ktoré ste možno prehliadli

### Isolation (Izolácia)

Keď dva requesty súčasne zapisujú do rovnakej tabuľky, izolácia zabezpečí, že sa navzájom nevidia rozbehnuté zmeny.

SQLite má implicitnú izoláciu ... keď začnete transakciu, iné pripojenia vidia len dáta pred transakciou. Až keď `commitnete`, zmeny sa stanú viditeľnými.

```php
// Request A: začína transakciu
$uow->transaction(function (PDO $pdo) {
    // Vidí stav DATABÁZY pred transakciou
    $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')
        ->execute(['Nové meno', 1]);
    // Ostatné pripojenia ešte nevidia zmenu!
});

// Request B: číta z tabuľky
$name = $pdo->query('SELECT name FROM users WHERE id = 1')->fetch();
// Vráti STARÉ meno ... transakcia A ešte nie je commitnutá
```

### Durability (Trvanlivosť)

Keď `commitnete`, SQLite zapíše dáta na disk. Pri páde servera sa po reštarte znovu načítajú z disku. Dáta sa nestratia.

Ale pozor ... na shared hostingu môže byť diskový I/O pomalší. Preto je dôležité nerobiť veľké transakcie (tisíce riadkov naraz).

## ACID a Outbox pattern

Keď pochopíte ACID, Outbox pattern dáva zmysel:

```php
// Obe operácie sú v jednej transakcii
$uow->transaction(function (PDO $pdo) use ($order) {
    // 1. Biznis dáta ... musí byť atomické
    $pdo->prepare('INSERT INTO orders ...')->execute([...]);

    // 2. Domain event ... pre async spracovanie
    $pdo->prepare('INSERT INTO outbox (event_type, payload) VALUES (?, ?)')
        ->execute(['OrderCreated', json_encode($order->toArray())]);
});

// Pozri: Outbox pattern článok pre detaily
```

Bez ACID by Outbox nefungoval ... event by mohol vzniknúť bez objednávky, alebo naopak. ACID zabezpečí, že buď sú obe, alebo ani jedno.

**ACID nie je teória ... je to poistka.**

Na shared hostingu, kde servery padajú, FPM workers umierajú a disky sú pomalé, sú transakcie jediná vec, ktorá bráni čiastočným stavom v databáze.

A UnitOfWork? Ten len zabíja boilerplate. Namiesto `beginTransaction()` / `commit()` / `rollBack()` v každej akcii, máte jednu funkciu, ktorá to vyrieši za vás.

```php
// Bez UnitOfWork ... 6 riadkov navyše
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT ...')->execute([...]);
    $pdo->prepare('INSERT ...')->execute([...]);
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

// S UnitOfWork ... 1 riadok
$uow->transaction(function (PDO $pdo) {
    $pdo->prepare('INSERT ...')->execute([...]);
    $pdo->prepare('INSERT ...')->execute([...]);
});
```

Menej kódu, rovnaká bezpečnosť. A to je presne to, čo chcete na projekte, kde každý riadok navyše je miesto pre bug.

→ [Ako argon2id zrazil shared hosting](/blog/ako-sha-hash-algorytm-zrazil-shared-hosting): bezpečnosť vs infraštruktúra
→ [Cache wrappers](/blog/cache-wrappers-dobry-slucha-zly-pan): kedy cache pomáha a kedy škodí
→ [Outbox pattern](/blog/outbox-pattern): keď cache nestihne všetko

## Zdroje

- [W3C, ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance): ACID definícia
- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [SQLite Documentation](https://www.sqlite.org/docs.html): SQLite referenčná príručka
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
