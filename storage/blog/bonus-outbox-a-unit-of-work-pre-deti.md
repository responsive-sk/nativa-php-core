---
title: "Bonus pre členov ... UnitOfWork a outbox: keď „buď všetko, alebo nič“ neplatí"
date: "2026-10-03"
version: "1.0.1"
tags: ["transactions", "outbox", "architecture", "sqlite", "security", "php"]
excerpt: "Ako sa v tomto repozitári zapisuje prihlasovací účet a zaznamenáva sa, kto ho zmenil ... a prečo na to nestačí jedna transakcia. Konkrétny kód: UnitOfWork so savepointom, outbox s idempotentným handlerom a tabuľka troch okien, v ktorých sa to môže pokaziť."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-outbox-and-unit-of-work"
published: true
---

> Tento **bonus pre členov** ide po kóde, ktorý v tomto repozitári vznikol
> poprvé ... nie ako plán, ale ako oprava živého incidentu. Predchádzajúce
> diely: [ACID pre deti](/blog/acid-pre-deti),
> [immutable audit log](/blog/bonus-audit-log-pre-deti),
> [members-only](/blog/bonus-members-only-pre-deti) a
> [vydávanie](/blog/bonus-vydavanie-pre-deti).
> Dnes dve veci, ktoré spolu vyzerajú ako jedna ... a robia úplne
> odlišné veci.

Si PHP vývojár. Píšeš aplikáciu s používateľmi a rolami. Pridáš audit log,
aby si vedel, kto čo zmenil. Všetko funguje, kým nepríde chvíľa, keď sa
opýtaš: „a čo ak spadne proces medzi zápisom a záznamom?“

Vtedy zistíš, že odpoveď je: **všetko stratíme, okrem zmeny, ktorú
nechceme stratiť.**

## Dve databázy, jedna transakcia

Začnime faktom, ktorý rozhoduje všetko ostatné.

Tento kit má databázu na modul. Používateľa a jeho roly žijú v `users.db`.
Audit log žije v `audit.db`. Oboje sú súbory SQLite.

A SQLite **nemá transakciu cez dva súbory**. To nie je nastavenie, ktoré by
sa dalo zapnúť. Je to hranica motora.

Takže keď `UsersAction` zmení rolu a hneď nato zapíše audit záznam, tieto
dve veci nie sú v jednej transakcii:

```php
// src/Modules/Cardboard/Interfaces/HTTP/Actions/UsersAction.php
CardboardServices::users()->update($id, $data);   // users.db
CacheInvalidator::flush();
AuditServices::log('users.update', 'user:' . $id, $detail, $ip);  // audit.db
SecurityEventServices::userChanged($id, SecurityEventServices::OP_UPDATE, $ip);
```

Tretí riadok je tu len pre istotu: `AuditServices::log()` je obyčajný zápis,
a keď medzi prvým a tretím riadkom zomrie proces, zmena v `users.db` zostane
a záznam o nej nie.

Úprimne: **zlyhať v tomto smere sa nemá.** Vec, ktorú by si chcel vyšetriť,
je presne tá, ktorá zmizla.

Pozri na [`acid-pre-deti`](/blog/acid-pre-deti) ... tam je transakcia
využitá na to, na čo je určená. Tento článok je o tom, čo sa stane, keď
dve veci musia byť naraz a ležia v rôznych súboroch.

## Jeden endpoint, dve otázky

Skôr než sa pustíme do mechaniky, poďme sa pozrieť na chybu, ktorá celú
prácu spôsobila. Bola živá, nie teoretická.

Sedíš v `/mark` ako autor článkov ... rola, ktorá má `mark.access` a
`content.manage`, a nič viac. Každá samostatná cesta na článok ti odmietla
článok kolegu s kódom `403`.

A potom sa stalo toto:

```
POST /mark/bulk-delete   entity=articles   ids[]=clanka-kolegu
POST /mark/bulk-publish  entity=articles   ids[]=clanka-kolegu
```

Súbor zmizol. `published: true` sa zmenilo na `false`.

Každá jednotlivá cesta fungovala. Chyba nebola v authorize per action. Bola
v authorize per operation. A endpoint mal jednu tabuľku povolení pre celú
obsahovú oblasť, takže nebolo kde zapísať, že „toto je obsah“ a „toto nie
je“.

| Čo sa overovalo | Výsledok |
|---|---|
| cesta `/mark/articles/{slug}/delete` | 403, správne |
| cesta `/mark/bulk-delete` s cudzím slugom | zmazalo súbor |
| podvrhnuté `entity=users` na tej istej ceste | prešlo, `users.manage` nikto nekontroloval |

Ten tretí riadok je dôležitý. Cesta `/mark/bulk-delete` je jediná, ale to,
čo maže, príde v **tele požiadavky**. A telo požiadavky je údaj, ktorý si
vyberie útočník.

```php
// src/Modules/Users/Domain/AdminRoutePolicy.php
public static function permissionForEntity(string $entity): ?string
{
    return match ($entity) {
        'articles', 'pages'     => Permissions::CONTENT,
        'users'                 => Permissions::USERS,
        'roles'                 => Permissions::ROLES,
        default                 => null,
    };
}
```

`default => null` je dôležité. Entity, ktorú nikto neklasifikoval, sa
neprejde ticho ... odmietne sa. Inak by sa nový bulk formulár dal spustiť
bez toho, aby ho niekto schválil.

## Vlastníctvo nie je povolenie

Druhá vrstva je iná. `content.manage` otvára celú oblasť článkov, čo je
správne pre jedného autora a zlé pre troch. Autor mohol prepísať príspevok
kolegu a audit log by verno zapísal, že to urobil.

Takže pravidlo sa presťahuje do jedného miesta, ktoré volajú všetci
vrátane bulk handlerov:

```php
// src/Modules/Blog/Application/Services/ArticleWriteGuard.php
public function partition(array $ids, string $locale, ?User $user): array
{
    $allowed = [];
    $refused = [];

    foreach ($ids as $id) {
        $slug = (string) $id;

        if ($this->owns($slug, $locale, $user)) {
            $allowed[] = $slug;

            continue;
        }

        $refused[] = $slug;
    }

    return ['allowed' => $allowed, 'refused' => $refused];
}
```

Zoznam s dvoma riadkami, z ktorých jeden nie je tvoj, neodmietneme celý.
Preskočí sa, skountuje a **výsledok povie, koľko preskočil**:

```
Deleted 1 articles, 1 skipped (not yours).
```

Ticho ignorovaný checkbox vyzerá ako chyba. Vypočítaný počet nie.

## UnitOfWork, ktorý si myslel, že je jediný

Teraz ten jeden riadok, ktorý všetko držal pokope.

Každý si vytvoril vlastnú inštanciu `UnitOfWork`. Vnorenie teda nebolo
krajný prípad, bolo to bežné: akcia otvorí transakciu a repozitár, ktorý
zavolá, otvorí druhú nad tou istou spojenou.

```php
// src/Modules/Cardboard/Interfaces/HTTP/Actions/BulkDeleteAction.php
private function deleteDbEntities(string $entity, array $ids): int
{
    $pdo = DatabaseConnectionManager::instance()->connection('users');
    $uow = new UnitOfWork($pdo);

    $uow->transaction(function (PDO $_pdo) use ($entity, $ids, &$deleted): void {
        foreach ($ids as $id) {
            if ($this->deleteItem($entity, (string) $id, '')) {
                $deleted++;
            }
        }
    });

    return $deleted;
}
```

Vnorenie bolo normálne. Trieda o nom nevedela. Stará implementácia mala
per-instance príznak „už som začal“ ... a ten druhý objekt o sebe nevedel,
že niečo už beží.

Výsledok nebol číslo 403. Bolo to `PDOException: There is already an
active transaction` v polovici mazania.

```php
// src/Modules/Database/Infrastructure/Persistence/UnitOfWork.php
private static ?WeakMap $depth = null;

public function beginTransaction(): void
{
    $depth = $this->depth();

    if (0 === $depth) {
        self::stack()[$this->pdo] = 1;
        $this->pdo->beginTransaction();

        return;
    }

    self::stack()[$this->pdo] = $depth + 1;
    $this->pdo->exec('SAVEPOINT ' . self::savepointName($depth + 1));
}
```

Hĺbka je v `WeakMap` podľa **spojenia**, nie podľa objektu. Jediný objekt,
ktorý vie, že dve inštancie sú ten istý zásobník, je samotné PDO.

A `depth()` má zástupný výpočet pre prípad, že je v spojení transakcia,
ktorú trieda UnitOfWork neotvorila:

```php
public function depth(): int
{
    return self::stack()[$this->pdo] ?? ($this->pdo->inTransaction() ? 1 : 0);
}
```

To je konzervatívne čítanie. Ďalší `beginTransaction()` otvorí savepoint
namiesto toho, aby si žiadal od PDO začať dvakrát.

### Čo sa stane, keď sa vnorený scope pokazí

Savepoint má presne tú vlastnosť, ktorú potrebujeme: rollback **iba** svojho
scope, zatiaľ čo vonkajšia transakcia žije.

```php
public function rollback(): void
{
    $depth = $this->depth();

    if (1 === $depth) {
        unset(self::stack()[$this->pdo]);
        $this->pdo->rollBack();

        return;
    }

    self::stack()[$this->pdo] = $depth - 1;
    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::savepointName($depth));
    // ROLLBACK TO necháva savepoint na zásobníku. RELEASE ho odstráni ...
    // inak by ďalší scope v tej istej hĺbke zdedil stav po rollbacku.
    $this->pdo->exec('RELEASE SAVEPOINT ' . self::savepointName($depth));
}
```

Riadok `RELEASE` tam nie je pre krásu. Bez neho ďalší scope v tej istej
hĺbke zdedí niečo, čo už bolo odvolané.

Vonkajší scope je stále všetko alebo nič. Vnorený je svoj scope.

## Čo UnitOfWork NEgarantuje

Tu je tá časť, ktorá sa zvykne zabúdať, a práve to je problém.

`UnitOfWork` garantuje atomicitu **vnútri jedného súboru SQLite**. Bodka.

Nie medzi `users.db` a `audit.db`. Nie medzi `users.db` a `outbox.db`.
Nikde, kde je druhá databáza.

| Čo spravíš | Čo to dáva |
|---|---|
| `UnitOfWork` okolo zápisu do `users.db` | všetko alebo nič v `users.db` |
| `UnitOfWork` okolo zápisu do `users.db` aj audit zápisu | presne to isté, audit je iný súbor |
| obalíš obe veci a povieš si, že sú atomické | klamstvo, ktoré sa zistí pri prvom páde |

Niektorý človek povie „dám to do transakcie“ a tým myslí presne toto
klamstvo. Je to tá najdrahšia veta v tejto oblasti, pretože vyzerá
zodpovedne.

## Outbox: záznam, ktorý prežije pád

Keď sa dve veci nedajú spojiť transakciou, dá sa spojiť poradím.
Outbox nepovie „mám atomicitu“. Povie, čo reálne vie: **udalosť sa
zapíše po tom, čo sa zápis podarilo, doručenie je aspoň raz, a handler je
taký, že ho možno spustiť dvakrát.**

Päť typov udalostí, všetky v jednom vlastníkovi:

| Typ udalosti | Kedy |
|---|---|
| `security.authz.denied` | prihlásený používateľ dostal 403 |
| `security.user.changed` | vytvorenie, zmena alebo zmazanie používateľa |
| `security.role.changed` | zmena role alebo jej povolení |
| `security.auth.attempt` | prihlásenie, odhlásenie, pripomenutie, zlyhanie hesla |
| `security.ratelimit.tripped` | obmedzovač rýchlosti odmietol volajúceho |

`AuthMiddleware` zapisuje do fronty priamo, `RolesAction` volá helper:

```php
// src/Modules/Cardboard/Interfaces/HTTP/Actions/RolesAction.php
SecurityEventServices::roleChanged(
    $id,
    SecurityEventServices::OP_PERMISSIONS,
    $request->getClientIp(),
);
```

Pridanie povolenia `roles.manage` role je tá najdôležitejšia zmena v
paneli. Je to miesto, kde sa niekomu otvorí prístup ku všetkému, čo role
obsahuje.

### Idempotencia podľa identity, nie podľa obsahu

Tu sa dáva ľahko zle. Ako poznáš, že udalosť už bola zapísaná?

Nie podľa obsahu. Podľa identity. Primárny kľúč audit záznamu **je**
identifikátor správy vo fronte:

```php
// src/Modules/Audit/Application/Services/SecurityEventHandler.php
try {
    $this->audit->logWithId(
        $message->id(),      // <-- toto je kľúč
        $message->eventType(),
        self::detail($message->eventType(), $payload),
        self::actor($payload, $message),
        self::target($message->eventType(), $payload),
        self::ip($payload),
    );
} catch (PDOException $e) {
    if (self::isDuplicate($e)) {
        return;              // replay ... zahodený
    }

    throw $e;
}
```

Opakovanie narazí na duplicitný kľúč a zahodí sa.

Najzábavnejšia alternatíva, ktorú každý napíše, je deduplikácia podľa
obsahu: „ten istý používateľ, tá istá cesta, v tej istej sekunde“. A je
**zlá**. Dve odlišné zamietnutia sa zlepijú do jedného ... a druhý pokus je
presne to, čo vyšetrovanie chce vidieť.

Ťažko uveriteľná identita nie je identita.

## Tri okná, v ktorých sa to môže pokaziť

Toto je tabuľka, ktorú by som dal na stenu, keby sa dalo:

| Pád medzi | Výsledok |
|---|---|
| zápisom do `users.db` a insertom do fronty | zmena stojí, záznam nie |
| insertom do fronty a zápisom auditu | zmena stojí, záznam čaká a dopadne pri ďalšom vyprázdnení |
| zápisom auditu a označením správy ako spracovanej | zmena stojí **a** záznam sa zapíše znova |

Tretie okno je dôvod, prečo musí byť handler idempotentný. Nie preto, že by
sme verili v doručenie presne raz, ale preto, že doručovanie aspoň raz je
zadanie.

Pri prehrávaní sa to dá aj automaticky, cez `bin/cms outbox:process`.

### Úprimná časť o tom, čo tu chýba

V `src/` nikto nevolá `OutboxMessage::withRetry()`. Správa, na ktorej
handler zlyhal, ostáva v stave `failed`, kým ju niekto ručne nevráti do
fronty.

Nie je to retry queue. Je to „záznam zostal, niekto sa naň pozrie“ ... čo je
lepšie než ticho zahodiť bezpečnostný záznam, ale nie je to automatické
opakovanie. Hovoríme to, lebo dokumentácia, ktorá tvrdí „doručenie sa
opakuje“, je klamstvo, ktoré niekto o rok objaví.

## Kedy toto nie je správna odpoveď

Ak sú dve veci, ktoré musia byť naraz alebo ani raz, a ležia v dvoch
databázových súboroch, **tento kit to nevie spraviť**.

Bodka.

Máte dve možnosti a obe sú čisté:

- dať ich do tej istej databázy, kde transakcia funguje;
- prijať doručenie aspoň raz s idempotentným handlerom a povedať to
  nahlas v návrhu.

Tretia možnosť, obaliť obe veci do `UnitOfWork` a nazvať to atomicitou, nie
je možnosť. Je to len chyba, ktorá čaká na pád.

A ešte jedna vec, ktorú treba povedať: outbox tu nie je zdroj pravdy. Zápis
do `users.db` je pravda. Fronta je spôsob, ako sa k nej dostane záznam.
Keď sa insert do fronty nepodarí, zápis stojí a volajúci dostane `false` ...
zámerna je to chyba, ktorá nesmie zabiť to, čo sa už podarilo.

## Čo si z toho odniesť

1. **Atomicita je vlastnosť súboru, nie vzorca.** `UnitOfWork` platí vnútri
   jedného súboru SQLite a nikde inde.
2. **Vnorené scope dostávajú savepoint.** Zlyhanie má dosah svojho vlastného
   scope; vonkajšia transakcia prežije a môže potvrdiť to, čo stihla.
3. **Hĺbka patrí spojeniu, nie objektu.** Dve inštancie nad jedným PDO sú
   jeden zásobník, a viac to nevie nikto iný.
4. **Idempotencia podľa identity.** Kľúč auditu je ID správy, nie hash
   obsahu ... inak zlúpi dva rôzne pokusy o prielom.
5. **Test, ktorý volá to, čo testuje, nedokazuje, že sa to deje.**
   `SecurityEvents` mal päť typov, handler ich pokrýval a test ich
   vyzdvihol zo siete ... a `RolesAction` nevyžiarl ani jeden. Chýbala
   vrstva, ktorá prejde cez skutočného volajúceho.

Bonus, ktorý sa oplatí povedať zvlášť: ten piaty bod nebol odhalený
testom. Bol odhalený prehľadom `grep` po `src/`. A opravený bol dvoma
strážmi, ktoré odpovedajú na rôzne otázky ... jeden hľadá emiter, ktorý
nikto nevolá, druhý beží akciu a číta frontu.

→ [ACID pre deti](/blog/acid-pre-deti) — prečo transakcie nie sú nudné
→ [Audit log pre deti](/blog/bonus-audit-log-pre-deti) — nezničiteľný záznam
→ [Members-only pre deti](/blog/bonus-members-only-pre-deti) — kto vlastní článok

## Zdroje

- [SQLite — Transactions](https://www.sqlite.org/lang_transaction.html) — `BEGIN`, `SAVEPOINT`, `RELEASE`, a prečo je to všetko v jednom súbore
- [SQLite — Atomic Commit](https://www.sqlite.org/atomiccommit.html) — ako SQLite rieši trvanlivosť zápisu
- [PHP Manual — PDO::beginTransaction](https://www.php.net/manual/en/pdo.begintransaction.php) — výnimka, ktorú starý kód nechával v polovici práce
- [microservices.io — Transactional Outbox](https://microservices.io/patterns/data/transactional-outbox.html) — kanonický zápis vzoru a jeho hraníc
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
