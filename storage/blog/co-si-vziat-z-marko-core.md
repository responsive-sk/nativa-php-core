---
title: "Čo si vziať z marko-core : tri vzory, ktoré už máme, a jeden, ktorý chýba"
date: "2026-11-10"
tags: ["nativa", "php", "marko", "architektúra", "moduly", "cli", "discovery"]
excerpt: "Vedľa nativa-core leží marko, framework zo 107 Composer balíkov. Nie je to konkurent, je to cudzí strom s vlastnými rozhodnutiami : tri z nich už máme, jedno stojí za prevzatie a tri by nás stáli viac, než prinášajú."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architektúra"
published: true
---

## Časť I > Dva stromy, ktoré sa nedajú vymeniť

`marko` a `nativa-core` vyzerajú na prvý pohľad ako dve verzie
tej istej myšlienky.
Oba sú PHP 8.5, oba hovoria o moduloch a oba majú zelené brány.
Pri meraní sa však rozídu skôr, než sa stihne porovnať prvý súbor:

| položka | nativa-core | marko |
|---|---|---|
| čo to je | hotová CMS aplikácia | framework, na ktorom appku staviaš |
| balíky | žiadne za behu | 107 vlastných `marko/*` |
| PHP súbory | kernel 67, katalóg 491 | 1 474 v `packages/*/src` |
| riadky | kernel 9 899 | 130 576 |
| testy | PHPUnit, 161 súborov | Pest, 1 409 súborov |
| bez `vendor/` | bootuje | nebootuje |

Z toho plynie prvé pravidlo tohto článku:
z marka sa nedá vziať kus kódu.
Dá sa vziať len rozhodnutie, ktoré sa za tým kódom skrýva.
A pri troch rozhodnutiach sa ukáže, že ich už máme.

## Časť II > Tri vzory, ktoré už máme

### Discovery cache, ktorá to hovorí nahlas

`src/Discovery/DiscoveryCache.php` nie je náhodou podobný tomu markovmu.
Jeho docblock to priznáva:

```
Deliberate deviations from the Marko `DiscoveryCache` this ports the idea
from (reasons in the RFC)
```

Prevzatá je myšlienka, nie implementácia.
Marko ukladá objavené moduly a príkazy do cache,
my ukladáme len sken rout a middleware.
Fingerprint je u nás dvojúrovňový, pretože hashovať každý súbor
na teplej ceste by zrušilo úsporu, ktorú má cache priniesť.
A `VERSION = 3` je tam preto, že tvar payloadu sa už dvakrát zmenil;
marko v tom istom commite, ktorý menil definíciu príkazu,
zdvihol formát na verziu 5.
Zhoda nie je v kóde, je v pravidle:
**keď sa zmení tvar cache, zmení sa aj jej verzia**,
inak by starý súbor ticho klamal.

### Manifest, ktorý je meranie

Marko deklaruje modul v `composer.json` cez `extra.marko.module: true`
a v `module.php`, kde sú `bindings`, `singletons`, `boot` a `shutdown`.
Je to deklarácia a dáva zmysel, lebo marko má kontajner,
do ktorého sa tie väzby registrujú.

Náš `ModuleManifest` je opak:
je to **meranie**, nie deklarácia.
Routy číta z `#[Route]`, migrátory z `#[AsMigrator]`,
závislosti z `use Modules\<X>\...` v tele súborov.
Katalóg nikdy nenačíta triedu, ktorú opisuje,
a to je celý dôvod, prečo existuje.
Modul s chýbajúcou závislosťou sa musí dať opísať,
inak nástroj, ktorý má tú chybu pomenovať, spadne na tej istej chybe.
`module.json` preto pokrýva len to, čo sken nevidi:
npm balík, PHP balík, runtime závislosť.

Deklaratívny manifest by u nás bol druhý vlastník toho istého faktu.
Prvý vlastník je import, ktorý sa nedá zabudnúť.

### Poradie modulov a cyklus

Marko má `DependencyResolver`: Kahnov algoritmus, tvrdé hrany z `require`
a mäkké hinty `after` a `before`.
Cyklus skončí výnimkou `CircularDependencyException`.

My máme to isté bez deklarácie:
`make modules-install` inštaluje v poradí uzávierky
a cyklus odmietne, presne preto, že uzávierka je odvodená z importov.
Keď by import v `Pages` uzavrel cyklus `Pages -> Questions -> Pages`,
neopravilo sa to hintom, ale zmenou hrany.
Hint `before` by bol spôsob, ako obísť import, ktorý tam stále je.

## Časť III > Jeden vzor, ktorý naozaj chýba

V novembri marko opravilo chybu, ktorá sa čítala ako anekdota
a je skôr popis žánru:

> A command silently ignored any option it did not read, so a typo or an
> unsupported option ran the command as if the option were absent.
> In a downstream app, `--help` on a purge command ran the real purge.

Riešenie je v commite `f4d45b75` a má tri časti:
`#[Command]` dostal zoznam `options`,
`CommandRunner` odmietne každú voľbu, ktorú príkaz nedeklaruje,
a `tests/CommandOptionDeclarationTest.php` prejde zdrojáky príkazov,
nájde `hasOption('...')` a zlyhá, keď volba nie je deklarovaná.

U nás ten vzor existuje, ale nie všade.
`queue:prune` ho má celý:

```php
} elseif (str_starts_with($argument, '--')) {
    fail('unknown option "' . $argument . '" for queue:prune; known: --interval=, --dry-run');
}
```

Modulové príkazy ho majú aj s testami:
`GenerateImagesCommandTest` skúša `--formt`,
`ProcessOutboxCommandTest` skúša `--lmit`,
`AttachCommandTest` skúša `--dry-runn`.

`bin/cms queue:work` ho nemá.
Jeho parser je tento:

```php
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $limit = max(1, (int) substr($argument, strlen('--limit=')));
    } elseif (!str_starts_with($argument, '--')) {
        $queue = $argument;
    }
}
```

Voľba, ktorá sa nezačína na `--limit=`, a predsa začína na `--`,
nespadne ani do jednej vetvy.
`php bin/cms queue:work --lmit=100` teda prejde
a spracuje dávku s predvoleným limitom 10.
Nikto sa to nedozvie, pretože výstup vyzerá ako úspech.
`module:remove` má ten istý tvar: `in_array('--force', $argv, true)`,
takže `--forcee` je len neškodný reťazec vo `$argv`,
ktorý nikto neprečíta.

Cena prevzatia je malá a nič nové do stromu neprináša:
tabuľka prijatých volieb pri každom príkaze,
jeden parser, ktorý ju vynúti pred vykonaním,
a test, ktorý porovná `--` literály v `bin/cms` s tou tabuľkou.
Riziko je v tom, že dnes sa voľby čítajú na štyroch rôznych miestach
(`in_array`, `str_starts_with`, delegované `Command` triedy, `usage()`),
takže tabuľka sa musí stať jediným vlastníkom.

## Časť IV > Tri veci, ktoré nebrať

**Pluginy a interceptory.**
Marko generuje triedy, ktoré obalia každú verejnú metódu
cez `before` a `after`;
`InterceptorClassGenerator` má 451 riadkov.
Funguje to preto, že existuje kontajner a runtime, ktorý triedy nahradí.
Náš strom bootuje bez `vendor/` a bez kontajnera,
takže prevzatie by znamenalo runtime codegen v `src/init.php`.
To je nový mechanizmus, nie nový vzor.

**`module.php` s `bindings` a `boot`.**
Kompozičný koreň máme v `src/composition.php`, na jednom mieste a čitateľný.
Druhý deklaratívny vlastník väzieb by bol druhý zdroj pravdy
a prvý, ktorý sa rozejde.

**`composer.json` typu `marko-module`.**
Nemáme Composer za behu.
Katalóg je adresár a `bin/cms module:add` je celý inštalátor.
Typ balíka by bol fakt bez nositeľa.

## Časť V > Čo z toho plynie

Tri zo štyroch skúmaných vzorov už v strome sú,
a pri každom sa dá ukázať riadok, ktorý to hovorí:
docblock cache, `ModuleManifest`, `make modules-install`.
To je dobrá správa, pretože znamená, že rozdiel medzi nami a markom
nie je v nedostatku nápadov, ale v ich počte.

Štvrtý vzor je malý a konkrétny.
`bin/cms` mal dvoch vlastníkov toho, čo je prijatá voľba:
`usage()` a telo príkazu.
Dnes ich má jedného: `commandOptions()`, z ktorej čítajú help aj parser,
a `tests/Architecture/CliCommandOptionsTest.php` zlyhá,
keď sa dispatch, tabuľka a help rozídu.
Preklep prestal byť tichý úspech
a stal sa tým, čím je v marku: odmietnutím s menom volby.

Zvyšok marka zostal tam, kde je.
