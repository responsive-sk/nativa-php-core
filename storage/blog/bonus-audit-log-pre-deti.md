---
title: "Bonus pre členov ... audit log: nezničiteľný záznam o každej zmene"
date: "2026-08-13"
version: "1.0.3"
tags: ["audit", "sqlite", "security", "database", "admin"]
excerpt: "Druhý bonus len pre registrovaných. Ako admin panel vie, kto zmenil čo a kedy ... a prečo sa záznam nedá vymazať ani prepísať. Dve vrstvy immutability: repozitár bez UPDATE/DELETE a SQLite triggery, ktoré zablokujú aj priamy zásah do databázy."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-audit-log"
published: true
---

> Toto je druhý **bonus pre členov** ... záznam, ktorý sa nedá vymazať,
> o článku, ktorý sa nedá zmeniť. V tomto repe žije admin audit log:
> každý zápis do `/mark` (vytvorenie používateľa, zmena rolí, uloženie
> nastavení, moderácia komentárov) sa zapíše do `audit.db`. A ten
> záznam je **immutable** ... na úrovni aplikácie aj na úrovni databázy.
> Poďme sa pozrieť, ako sa to robí.

Administrátor zmenil rolu používateľa. O mesiac neskôr niekto sa spýta: kto to urobil? Kedy? Prečo? Ak nemáte audit log, odpoveď je: stopa neexistuje.

Admin panel má právo meniť veci: používateľov, rolí, nastavení, komentárov. Každá zmena je dôležitá. Ale keď niekto zmení rolu z admin na user, a vy sa o tom dozviete o týždeň, kde hľadať stopy?

## Prečo vôbec audit log


Bez odpovede na túto otázku máš „správu systému“ naslepo. Niečo sa
rozbilo? Niekto zmazal komentár? Niekto zmenil rolu bez opýtania?
Bez záznamu je to tvoja domnienka proti jeho slovu.

Audit log odpovedá na päť otázok:

1. **Kto** ... ktorý admin (alebo `system`, keď to bežalo bez prihlásenia)
2. **Čo** ... akcia: `users.create`, `settings.save`, `comments.approve`...
3. **Na čom** ... cieľ: `user:u1`, `role:r1`, `comment:c5`...
4. **Detail** ... ľudsky čitateľný opis: „Created user editor@example.test“
5. **Kedy a odkiaľ** ... časová pečiatka + IP adresa

A hlavne: **záznam sa nedá zmeniť.** Pretože audit, ktorý sa dá
prepísať, je len ďalší dokument ... nie dôkaz.

## Dve vrstvy immutability

Zámok funguje na dvoch úrovniach naraz. Prvá je v kóde, druhá
v databáze. Jedna bez druhej by bola diera:

| Vrstva | Kde | Čo bráni |
|---|---|---|
| Aplikácia | `AuditLogRepository` | Neexistuje metóda update/delete ... jediný write path je `insert()` |
| Databáza | SQLite triggery | `BEFORE UPDATE`/`BEFORE DELETE` → `RAISE(ABORT)` |

Prečo obe? Pretože kód sa dá obísť, databáza nie. Ak by zámok žil
len v repozitári, stačilo by napísať nový SQL priamo proti
`audit.db` (alebo použiť iný nástroj, nie túto aplikáciu) ... a záznam
by bol preč. Triggery žijú **v samotnej databáze** a zablokujú
akýkoľvek pokus o zmenu, bez ohľadu na to, kto ho spustí.

## Prvá vrstva: repozitár bez mazania

`AuditLogRepository` je zámerne chudobný. Má presne tri metódy:
`insert()`, `recent()`, `count()`. Nič viac.

```php
final readonly class AuditLogRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /** Append an entry ... the only write path. */
    public function insert(AuditEntry $entry): void
    {
        // INSERT INTO audit_log (...) VALUES (...)
    }

    public function recent(int $limit): array
    {
        return $this->entries($this->pdo->prepare(
            'SELECT * FROM audit_log ORDER BY created_at DESC, rowid DESC LIMIT :limit',
        ));
    }

    public function count(): int
    {
        // SELECT COUNT(*) FROM audit_log
    }
}
```

Povšimni si, že `recent()` radí podľa `created_at DESC, rowid DESC`.
`created_at` má sekundovú granularitu ... dve akcie v tej istej sekunde
by bez `rowid` mohli prísť v náhodnom poradí. Monotónny `rowid`
(poradie vkladania) je poistka, že záznamy držia skutočné poradie.

Táto vrstva je čisto o disciplíne: **keď API nemá metódu mazania,
kód ju nemôže volať.** Je to „najlepšie úsilie“ ... a preto prichádza
vrstva druhá.

## Druhá vrstva: triggery v databáze

Tu sa z „disciplíny“ stáva fyzikálny zákon. Migrácia vytvorí dva
triggery:

```sql
CREATE TRIGGER audit_log_no_update
BEFORE UPDATE ON audit_log
BEGIN
    SELECT RAISE(ABORT, 'audit_log is immutable');
END;

CREATE TRIGGER audit_log_no_delete
BEFORE DELETE ON audit_log
BEGIN
    SELECT RAISE(ABORT, 'audit_log is immutable');
END;
```

Skús čokoľvek ... `UPDATE audit_log SET detail = 'vymazané'` alebo
`DELETE FROM audit_log` ... a SQLite odpovie chybou:

```
SQLSTATE[HY000]: General error: 1 audit_log is immutable
```

Nezáleží na tom, či príkaz prišiel z tejto aplikácie, z `sqlite3`
v termináli, alebo z iného procesu. Trigger beží **pred** operáciou
a zastaví ju. Preto sa tomuto hovorí immutable log: záznamy sa dajú
len pridávať a čítať, nikdy meniť ani mazať.

## Čo presne sa loguje

Audit log nie je „loguj všetko“ ... je záznam **administratívnych
zápisov**. Každá write akcia v `/mark` končí jedným volaním:

```php
AuditServices::log(
    'users.create',                          // akcia
    'user:' . $user->id(),                   // cieľ
    'Created user ' . $email,                // detail
    $request->getClientIp(),                 // odkiaľ
);
```

Kto je `actor`? Zistí sa zo session ... ak je admin prihlásený,
zapíše sa jeho meno a id. Ak akcia bežala bez prihlásenia (napr.
seed), zapíše sa zástupca `system`. Detail sa **denormalizuje pri
zápise** ... meno admina sa skopíruje do záznamu. Keby sa admin
neskôr premenoval, záznam stále hovorí, ako sa volal v momente
akcie. To je presne to, čo od auditu chceš: snímku okamihu, nie
odkaz na premenlivý stav.

Zoznam logovaných akcií dnes:

| Oblasť | Akcie |
|---|---|
| Používatelia | `users.create`, `users.update`, `users.delete` |
| Roly | `roles.create`, `roles.delete`, `roles.permissions` |
| Nastavenia | `settings.save`, `settings.tokens`, `settings.nav`, `settings.nav.delete` |
| Preklady | `locales.create`, `locales.update`, `locales.delete`, `locales.import` |
| Komentáre | `comments.approve`, `comments.reject`, `comments.delete` |

## Ako sa to pozerá

Admin vidí záznamy na `/mark/audit` ... najnovšie hore. Každý riadok
ukazuje čas, meno, akciu, cieľ, detail a IP. A pretože je to
verejné rozhranie pre HTML, detail sa **escapuje** ... článok s
`<script>` v názve nemôže zneškodniť admin panel XSS-om. Test to
kontroluje doslova: `assertStringNotContainsString('<script>', $html)`.

## Pravidlo bez testu sa nedodržiava

Ako všade v tomto repe: zámok, ktorý netestuješ, neexistuje.
`AuditLogTest` sa pokúša log rozbiť ... a čaká, že sa to nepodarí:

```php
public function testUpdateIsBlockedByImmutabilityTrigger(): void
{
    AuditServices::log('roles.create', 'role:r1', 'Created role editor');

    $pdo = DatabaseConnectionManager::instance()->connection('audit');

    $this->expectException(\PDOException::class);
    $this->expectExceptionMessage('immutable');

    $pdo->exec("UPDATE audit_log SET detail = 'tampered'");
}
```

Test úmyselne spustí `UPDATE` priamo proti databáze ... nie cez
repozitár, ktorý to ani neponúka. Ak by niekto trigger odstránil
alebo obišiel, test začne padať. A `testDeleteIsBlockedByImmutabilityTrigger`
robí to isté pre `DELETE`. Okrem toho testy overujú, že akcie
v `/mark` (users, locales, comments) naozaj zapisujú, že poradie
je správne a že `system` fallback funguje bez session.

Najkrajšie na tomto vzore je, že **fyzikálny zámok stojí menej ako
procesný**. Žiadny „musíme si dávať pozor, nech nikto nemaže log“.
Namiesto dohody je tam trigger ... a trigger nemá zlé dni, nespraví
preklep a nepodľahne tlaku. Ak niekto chce záznam zmeniť, musí
zmeniť databázu (vypnúť trigger), a to už zanecháva stopy.

## Čo si z toho odniesť

1. **Audit log odpovedá na päť otázok** ... kto, čo, na čom, detail,
   kedy a odkiaľ. Nič viac netreba.
2. **Imutabilita má dve vrstvy** ... repozitár bez update/delete
   (disciplína) + databázové triggery (fyzikálny zákon).
3. **Trigger beží pred operáciou** ... `BEFORE UPDATE`/`BEFORE DELETE`
   s `RAISE(ABORT)` zastaví aj priamy SQL z terminálu.
4. **Denormalizuj actor pri zápise** ... záznam je snímka okamihu,
   nie odkaz na premenlivý stav.
5. **Zámok testuj útokom** ... test sa úmyselne pokúsi log rozbiť
   a čaká, že to nevyjde.

A to je celý bonusový článok ... nezničiteľný, ako záznam, o ktorom
hovorí. Ak máš otázky, čakám ťa v komentároch.


→ [ACID pre deti](/blog/acid-pre-deti): prečo transakcie nie sú nudné
→ [Cache wrappers](/blog/cache-wrappers-dobry-slucha-zly-pan): dobrý sluha, zlý pán
→ [CSP a onclick](/blog/csp-onclick-spravodliva-chyba): tichá bezpečnostná diera

## Zdroje

- [SQLite Documentation](https://www.sqlite.org/docs.html): CREATE TRIGGER a RAISE(ABORT), mechanika druhej vrstvy
- [OWASP, Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html): čo a prečo logovať v admin vrstve
- [PHP Manual, PDO](https://www.php.net/manual/en/book.pdo.php): `PDOException`, ktorú test očakáva
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
