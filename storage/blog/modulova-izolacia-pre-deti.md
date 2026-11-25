---
title: "Clean code pre deti ... diel 6: modulová izolácia ... kto s kým môže rozprávať (C-pattern hranice)"
date: "2026-08-11"
version: "1.0.3"
tags: ["clean-code", "php", "architecture", "modules", "boundaries"]
excerpt: "Záverečný diel série. Čistý kód nekončí pri metódach ... končí pri hraniciach modulov. Diel 6 vysvetlí, prečo Domain nevie o databáze, ako ModuleIsolationTest hliadkuje importy a prečo Core + Database sú „libc“ starter kitu."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "module-isolation"
published: true
---

> Toto je diel 6 ... **záverečný diel** série **Clean code pre deti**.
> Predošlé diely: [1 ... čo je čistý kód](/blog/co-je-cisty-kod-pre-deti),
> [2 ... názvy sú dokumentácia](/blog/nazvy-su-dokumentacia-pre-deti),
> [3 ... jedna vec (SRP)](/blog/jedna-vec-srp-pre-deti),
> [4 ... DRY vs predčasná abstrakcia](/blog/dry-vs-abstrakcia-pre-deti)
> a [5 ... smoke testy](/blog/smoke-test-pre-deti). Všetky pravidlá, ktoré
> sme sa naučili, žijú vo vnútri jedného súboru alebo jednej triedy. Dnes
> urobíme krok späť a pozrieme sa na **väčšie meradlo**: hranice medzi
> modulmi. Pretože čistý kód nekončí pri metóde ... končí pri tom, **kto
> s kým môže rozprávať.**

## Panelák: každý byt má svoje potrubie

Predstav si panelák. Každý byt má vlastné potrubie, vlastnú elektriku,
vlastné steny. Keď ti tečie kúpeľňa, nevlámeš sa k susedovi do kúpeľne
... voláš svojho inštalatéra a ten opraví **tvoj** byt. Sused ti nepomôže
tým, že by si mu položil potrubie cez obývačku.

Moduly v kóde sú byty v paneláku. Každý modul (napr. `Users`, `Settings`,
`Locales`) má vlastné vrstvy ... a tie vrstvy majú presne určené, čo smú
robiť. Hranice nie sú byrokracia. Sú to **body, kde sa mení svet**: keď
vymeníš potrubie v jednom byte, ostatné byty to nemajú pocítiť.

## Tri vrstvy modulu: čo robí ktorá

Každý feature modul v tomto repo má tri vrstvy. Pozri sa na `Users`:

```
src/Modules/Users/
├── Domain/ # čistá logika ... pravidlá, entity, „čo to znamená byť používateľom“
├── Application/ # prípady použitia ... orchestrácia, služby
└── Infrastructure/ # databáza, úložiská, konkrétne technológie └── Interfaces/ # HTTP ... actions, middleware („Interfaces“ = rozhranie so svetom)
```

A teraz to najdôležitejšie ... **ktorá vrstva čo smie importovať**:

| Vrstva | Čo smie použiť | Čo NESMIE |
|---|---|---|
| `Domain` | len samo seba (čisté PHP, žiadne importy) | **Infrastructure a Interfaces ... ani vlastné, ani cudzie** |
| `Application` | svoje Domain + zdieľané jadro (Core + Database) | cudzích modulov Infrastructure/Interfaces |
| `Infrastructure`/`Interfaces` | čokoľvek (je to „vonkajší svet" modulu) | ... |

Všimni si ten prvý riadok. **`Domain` neimportuje vôbec nič.** To je
najprísnejšie pravidlo ... a najdôležitejšie.

## Reálny príklad: `User` entita nevie o databáze

Pozri sa na entitu `User` v `src/Modules/Users/Domain/Entities/` ... celý
súbor, vrátane hlavičky:

```php
<?php declare(strict_types = 1); namespace Modules\Users\Domain\Entities; /** * User domain entity. */
final class User
{ /** @var list<string> role names */ private array $roles = []; // … public function __construct(private array $data) { $active = $data['is_active'] ?? 1; $this->isActive = 1 === (is_numeric($active) ? (int) $active : 0); }
}
```

Všimni si, čo **chýba**: žiadny `use` statement. Žiadny `DatabaseConnectionManager`,
žiadny `PDO`, žiadne SQL. `User` dostane obyčajné pole a vie z neho spraviť
svoju logiku. Ako vie, že je aktívny? Z poľa, nie z databázy. Prečo je to dobré? **Pretože logika používateľa sa nemení, keď sa zmení
úložisko.** Či sú dáta v SQLite, v JSON súbore alebo v cloude ... pravidlo
„používateľ je aktívny, keď `is_active` je 1“ ostáva rovnaké. Keď by
`User` poznalo databázu, každá zmena úložiska by ťa donútila siahnuť do
logiky. Hranica = bod, kde sa mení svet ... a my sme ho dali presne tam,
kde sa má. Kde teda žije databáza? Vo vrstve `Infrastructure` ... v `UserRepository`:

```php
// src/Modules/Users/Infrastructure/Persistence/Repositories/UserRepository.php
final class UserRepository
{ public function __construct(private readonly PDO $pdo) { } // SELECT * FROM users WHERE … → new User($row)
}
```

`UserRepository` vie o `PDO` a SQL. `User` nevie o ničom. Dve triedy,
dva svety ... a medzi nimi presne jedna hranica.

## Ako test hliadkuje hranice

Toto repo to vynucuje testom ... to sme sa naučili
v [dieli 5](/blog/smoke-test-pre-deti). Test sa volá
`ModuleIsolationTest` a robí presne to, čo by robil inšpektor
v paneláku: prejde všetky moduly a pozrie sa na každý `use` statement.

```php
// tests/Architecture/ModuleIsolationTest.php ... zjednodušené
foreach ($files as $file) { // Kontrola sa týka len Domain a Application vrstiev $isDomainOrApp = str_contains($relativePath, '/Domain/') || str_contains($relativePath, '/Application/'); if (!$isDomainOrApp) { continue; } foreach ($lines as $line) { if (preg_match('/use\s+(Modules\\\\(.+)\\\\(Infrastructure|Interfaces))/', $line, $matches)) { $importedModule = explode('\\', $matches[1])[1] ?? ''; // Application smie zdieľané jadro (Core + Database)… if (('Core' === $importedModule || 'Database' === $importedModule) && !$isDomainLayer) { continue; } // …ale nikdy nie cudzí modul a Domain nikdy nič z Infrastructure if ($importedModule !== $moduleName) { $violations[] = …; } } }
}
```

Rozoberme si to:

1. **Skenuje len Domain a Application** ... Infrastructure a Interfaces sú „vonkajší svet" modulu, ten smie importovať, čo potrebuje. Kontrola je presne tam, kde hranica bolí.
2. **Regex hľadá `use Modules\…\Infrastructure` a `…\Interfaces`** ... nie len tak hocijaký import. Pointa nie je „modul nesmie poznať iný modul" (to by bolo nereálne), ale „modul nesmie siahať do **vnútra** cudzieho modulu".
3. **Core a Database sú výnimkou pre Application** ... zdieľané jadro, „libc" starter kitu. Každý modul smie použiť `AppPaths`, `DatabaseConnectionManager`, `FrontendLayout`…
4. **Domain výnimku nemá** ... `$isDomainLayer` vypne aj Core/Database. Domain je prísne čisté. Bodka.

A keď test nájde porušenie, povie presne, kto a ako:

```
Module "Locales" violates DDD boundaries by importing
Modules\Cardboard\Interfaces in Application/Services/SomeService.php
```

Hláška, ktorá sa nedá prepočuť: „modul X siahol do vnútra modulu Y v tejto
triede“. Oprava je vždy rovnaká ... presuň závislosť tam, kam patrí, alebo
ju vyrieš cez zdieľané jadro.

## Prečo je `Core` „libc“

Vráťme sa k tomu „Core + Database sú zdieľané jadro“. Čo to znamená
v praxi? Pozri sa na `BlogArticlesService` z predošlých dielov ... je
v `src/Modules/Core/Application/Services/` a importuje:

```php
use Modules\Core\Infrastructure\Paths\AppPaths;
use Modules\Core\Infrastructure\Storage\LocalStorageProvider;
use Modules\Core\Interfaces\HTTP\FrontendLayout;
```

Všetky tri importy sú **z vlastného modulu Core**. To je povolené ...
modul môže používať sám seba. Ale `BlogArticlesService` používajú aj
iné časti projektu: `BlogIndexAction`, `AuthorProfileAction`… A oni
k nemu nepotrebujú žiadne zvláštne povolenie, lebo **Core je spoločný
jazyk celej aplikácie** ... ako štandardná knižnica v C (odtiaľ „libc").

`AppPaths` (cesty k súborom), `DatabaseConnectionManager` (spojenia
s databázami), `FrontendLayout` (HTML layout) ... to sú veci, ktoré
potrebuje **každý** modul. Keby si ich každý modul robil po svojom,
mali by sme sedem rôznych spôsobov, ako zistiť cestu k súboru ... a to
je presne to, proti čomu sme bojovali v [dieli 4](/blog/dry-vs-abstrakcia-pre-deti).
Zdieľané jadro je jeden zdroj pravdy pre prierezové veci.

## Prečo test, a nie dôvera

A teraz otázka, ktorú sme si kládli v každom dieli: prečo to vynucovať
testom? Lebo hranice sú **tiché**. Keď niekto z Application `Locales`
importuje `Cardboard\Interfaces`, aby si ušetril tri riadky ... kód beží
ďalej, testy prechádzajú, nikto si nič nevšimne. Až o pol roka, keď
`Cardboard` zmení svoje vnútro, spadne `Locales` ... cudzí modul, ktorý
s tým nemá nič spoločné.

Modul je byt: jeho potrubie má opravovať jeho inštalatér.

Keď sa do
cudzieho potrubia napojí sused, netečie to dnes ... ale praskne to
zajtra, v byte, ktorý o tom nič nevie. Test je poistka, že sa to
nestane: hranice nie sú odporúčanie, sú červený test (diel 5).

## Mapa celej série

A tým sme na konci. Poďme si celú sériu zložiť do jednej tabuľky ...
šesť princípov, šesť meradiel, šesť testov, ktoré ich vynucujú:

| Diel | Princíp | Meradlo | Test, ktorý ho vynucuje |
|---|---|---|---|
| [1](/blog/co-je-cisty-kod-pre-deti) | Kód sa číta viac, než píše; príbeh nad detailom | celý `src/` | `CorePatternTest` |
| [2](/blog/nazvy-su-dokumentacia-pre-deti) | Názvy sú dokumentácia zadarmo | mená metód, premenných, tried | čitateľnosť (chunky test) |
| [3](/blog/jedna-vec-srp-pre-deti) | Jedna metóda, jedna trieda ... jedna vec | dôvod zmeny | chunky rule + `handle()` |
| [4](/blog/dry-vs-abstrakcia-pre-deti) | DRY = jedno miesto pravdy, žiadne rozhrania do zásoby | počet kópií, implementácií | no-speculative-interface, baseline 0 |
| [5](/blog/smoke-test-pre-deti) | Pravidlá vynútené testom = smoke testy | tokeny, nie text | detektory v `CorePatternTest` |
| [6](/blog/modulova-izolacia-pre-deti) | Kto s kým môže rozprávať | importy medzi modulmi | `ModuleIsolationTest` |

Všimni si, ako sa meradlo zväčšuje: diel 1–2 hovorili o riadkoch
a menách, diel 3–4 o metódach a triedach, diel 5 o testoch, ktoré to
celé strážia, a diel 6 o hraniciach medzi modulmi. Čistý kód je
**vrstvený**: začína pri jednom riadku a končí pri tom, ako spolu
rozprávajú celé moduly. A ak si čítal aj [typovú sériu](/blog/chunky-rule-pre-deti), spoznáš
to isté naprieč: tam sme typovali dáta, tu tvarujeme kód. Obe série
sa stretávajú v jednom bode ... **všetko sa dá vynútiť testom**, a keď
je pravidlo automatické, nemusíš o ňom diskutovať.

## Záver série

Šesť dielov, šesť princípov, jedna filozofia:

1. **Kód sa číta desaťkrát častejšie, než píše** ... píšeš ho pre čitateľa, nie pre kompilátor ([diel 1](/blog/co-je-cisty-kod-pre-deti)).
2. **Názvy sú dokumentácia zadarmo** ... dobré meno ťa zbaví povinnosti čítať telo ([diel 2](/blog/nazvy-su-dokumentacia-pre-deti)).
3. **Jedna vec na jednom mieste** ... metóda, trieda, modul: každé meradlo má jeden dôvod zmeny ([diel 3](/blog/jedna-vec-srp-pre-deti)).
4. **DRY áno, predčasná abstrakcia nie** ... jedno miesto pravdy, rozhrania až keď príde druhá implementácia ([diel 4](/blog/dry-vs-abstrakcia-pre-deti)).
5. **Pravidlo bez testu sa nedodržiava** ... detektory strážia pravidlá za teba, token po tokene ([diel 5](/blog/smoke-test-pre-deti)).
6. **Hranice sú body, kde sa mení svet** ... Domain nevie o databáze, moduly si nelezú do potrubia ([diel 6](/blog/modulova-izolacia-pre-deti)). A to najdôležitejšie na záver: **čistý kód nie je estetika. Je to
úcta k čitateľovi.** Každé meno, každá hranica, každý test v tomto
repo existuje pre jedného človeka ... pre toho, kto príde po tebe
o pol roka a bude sa snažiť pochopiť, čo si myslel. Čisté hlavy,
čisté dáta, čisté hranice.

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse nástroj
