---
title: "Šesť nástrojov a jedna otázka pre každý: mapa toolchainu, ktorá nikde nebola spolu"
date: "2026-10-27"
tags: ["nativa", "tooling", "brány", "ci", "openspec", "lekalo", "mago", "phpstan", "rector", "ai-factory"]
excerpt: "openspec, ai-factory, lekalo, phpstan s rectorom a php-cs-fixerom, mago a PHPUnit : šesť nástrojov, z ktorých každý odpovedá na inú otázku. Mapa, ktorá doteraz existovala len po kusoch: 15 CI jobov, 29 make targetov a tri miesta, kde tie isté pravidlá vlastní viac než jeden nástroj."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Nástroje"
published: true
---

Tento strom nemá jeden nástroj.
Má šesť
a každý z nich odpovedá na inú otázku, čo je presne dôvod
prečo sa nedajú porovnávať
a prečo sa nikdy nedostali do jednej vety spolu.
`phpstan` hovorí o typoch, `lekalo` o modeli, `openspec` o tom
čo sa bude stavať, `ai-factory` o tom, ako to vykoná agent, `mago` o tom
čo kód robí proti napísanému pravidlu, a PHPUnit so shell bránami o tom
čo strom naozaj spraví.

Mapa tých nástrojov existovala len po kusoch
rozptýlená v hlavičke `.github/workflows/ci.yml`, v `CONTRIBUTING.md`
v `##@` blokoch `Makefile` a v troch dokumentoch pod `docs/`.
Toto je pokus o jednu tabuľku
a zároveň zoznam toho, čo v nej nesedí.

Merané 2026-10-27 na tomto checkout-e:
**15 CI jobov, 29 `make` targetov
33 skillov pod `.agents/skills`
16 kontraktných testov pod `tests/Architecture` (53 testovacích metód)
a 66 testovacích súborov v katalógu.**

---

## Časť I > Čo vlastní ktorý nástroj

| nástroj | otázka, na ktorú odpovedá | kde žije | brána? |
|---|---|---|---|
| `openspec` | **čo** sa bude stavať: requirements + scenarios | `openspec/specs/`, `openspec/changes/` : **lokálne, gitignored** | nie vlastnou bránou, ale `openspec validate` je nástroj pravdy |
| `ai-factory` | **ako** to vykoná agent: plány, pravidlá, skilly | `.ai-factory/`, `.agents/skills/`, `.ai-factory.json` : **lokálne, gitignored** | nie |
| `lekalo` | **čím systém je**: entity, commands, events, policies ako dáta | `lekalo/`, `lekalo.lock` | áno : `make lekalo-check` |
| `phpstan` (level 9) | typy nad jadrom | `phpstan.neon`, 9 ciest | áno : `composer phpstan` |
| `rector` + `php-cs-fixer` | jazykové prepisy a štýl nad jadrom | `rector.php`, `.php-cs-fixer.dist.php` | áno : dry-run |
| `mago` | čo kód robí proti napísanému pravidlu, nad jadrom **aj katalógom** | `mago.toml` | áno, dvakrát: `lint` (limit 0) a `guard` |
| PHPUnit + shell brány | čo strom naozaj spraví: správanie, hranice, distribúcia | `tests/`, `modules/*/tests/`, `bin/build/` | áno, 15 jobov |

Tri veci na tej tabuľke sú dôležitejšie než zvyšok.

**`openspec` a `ai-factory` nie sú dva workflow.**
`.ai-factory/config.yaml` posiela `paths.plans` na `openspec/changes`
`aifhub.tools.openspec: true`
a `.ai-factory/rules/generated/` je z openspec specov **generovaný**
päť súborov
`index.json` s sha256 odtlačkami vstupov a trace s 21 pravidlom
naposledy pregenerovaný 27.
10.
Skilly (`aif-plan`, `aif-implement`, `aif-verify`
`aif-done`) teda neplánujú do vlastného adresára
plánujú na openspec strom, a pravidlá si z neho čítajú.
Jeden plánovací povrch, dva nástroje.

**`lekalo` je jediný nástroj, ktorý o kóde nehovorí nič.**
`lekalo.lock` obsahuje `"adapters":[]`
neexistuje PHP adaptér, takže niet kroku
ktorý by model vytiahol zo zdrojáku a porovnal.
Model musí byť dobre utvorený
a vnútorne konzistentný (každý `ref` sa vyrieši, gramatika sedí
id sú unikátne) a to je všetko, čo jeho vlastná brána vie.
Že je aj **pravdivý**, držia len tri PHPUnit kontrakty.

**`mago` je jediný nástroj, ktorý vidí aj katalóg.**
`phpstan`, `rector` a `php-cs-fixer` majú `modules/` vylúčené
každý s vlastným dôvodom napísaným v konfigurácii.
`mago` je preto jediná statická brána nad kódom
ktorý inštalácia naozaj spustí.

**Prvé dva nástroje nie sú v git repozitári.**
`.gitignore` má na to vlastný zápis s dôvodom:
`.agents/`
`.ai-factory.json`, `plans/`
`evolutions/` a celý `/openspec/` zostávajú lokálne
a na GitHub nejdú (rozhodnutie 2026-10-14).
Nie je to nedopatrenie
interné toolingy nemajú čo robiť v diffe pull requestu
ale má to dôsledok, ktorý stojí za pomenovanie
**plánovací strom nevidí žiadna CI brána, pretože v CI neexistuje.**
Preto je `openspec validate --all` nástroj pravdy pre toho
kto spec práve edituje, a nie brána
ktorá by niečo zachránila pred mergom.

---

## Časť II > Ktorá brána môže padnúť a ktorá len meria

15 CI jobov, presne ako sa volajú:

| job | čo tvrdí |
|---|---|
| `analysis` | `make lint` + `composer phpstan` + celý lekalo model a každý modul zvlášť |
| `kernel-dependency` | `mago guard`: jadro nesmie menovať modul : bez baseline, bez výnimiek |
| `curated-lint` | `mago lint` s limitom 0 nad jadrom aj katalógom |
| `module-quality` | **meranie**: lint + analyze nad katalógom, `continue-on-error` |
| `code-style` | `cs-fix-dry` + `rector-dry` |
| `security-audit` | žiadny tracked `.env`, žiadny literál tvaru secret |
| `dependency-audit` | žiadne high/critical advisory v dvoch lockfile-och |
| `tests` | PHPUnit vrátane katalógových testov pod `modules/*/tests/` |
| `modules` | tá istá sada s **nainštalovaným** katalógom |
| `admin-smoke` | panel nabootovaný na temp DB, admin flows po HTTP |
| `assets` | pnpm workspace a manifesty tém |
| `boot` | šesť povrchov jadra po HTTP, bez `composer install` |
| `dist` | artefakt z čistého klonu |
| `themes-check` | päť modulov tém proti `nativa-themes` na bajty |
| `extract-check` | jadro proti upstream slice (dnes 206 identických, 82 zaznamenaných divergencií) |

Ani jeden z tých 15 jobov nečíta `openspec/` ani `.ai-factory/`
v CI tie dva adresáre jednoducho nie sú.
Kto teda mení plán alebo spec, mení niečo
čo uvidí jeho vlastná sada testov (ak ju spustí) a nikto iný.
Je to vedomá cena za to, že plánovacie artefakty nechodia do repozitára
a nie je to cena, ktorú by niektorý z tých 15 jobov mohol vyvážiť.

`make` má k tomu 29 targetov a delia sa na dve jasné polovice.
Brány padajú (`lint`, `mago-lint`, `mago-guard`, `extract-check`
`security-audit`, `dependency-audit`, `dist-check`, `dist-profile-test`
`themes-check`, `workspace-audit`, `lekalo-check`)
merania nikdy (`mago-spike`, `mago-modules`, `kernel-size`
`lekalo-verify`, `lekalo-reports`, `lekalo-doctor`, `impact-report`).
To rozdelenie je v tomto strome pravidlo, nie náhoda:
brána
ktorá padá na číslo, ktoré nikto netriedil
padá prvý deň a ignoruje sa na tretí.

---

## Časť III > Tri miesta, kde to isté vlastní viac než jeden nástroj

**1.
`mago guard` a `KernelCouplingTest` tvrdia to isté pravidlo.**
Jedno pravidlo, dvaja vlastníci.
Guard je pritom prísnejší
na nezmenenom strome našiel 8 nálezov v `AuthMiddleware.php`
a `RateLimitMiddleware.php`
kde jadro dosiahlo na `Modules\Outbox\…`
cez inline FQCN za `class_exists()`
a test ich nemal, práve preto, že to nie sú `use` riadky.
Test zato drží ledger a klasifikuje hrany (M/C/G).
Guard vidí viac, test si pamätá rozhodnutia.

**2.
`mago analyze`
a `phpstan` sa pýtajú na to isté a odpovedajú rôzne silno.**
Analyzér je dnes čítanie:
nad celým stromom 1191 issue / 463 errorov
nad katalógom samotným 884 / 348
a väčšina z toho je konfigurácia a inferencia
ktorú `phpstan` s `bootstrapFiles` rieši.
Jeho obhajobou je pritom niečo, čo `phpstan` nevie
auto-vivifikácia poľa v teste, ktorú level 9 prehliadne.
Cenou je druhý scope, ktorý sa musí držať v zhode
`MagoScopeContractTest` existuje presne preto
aby cesty `mago.toml` a `phpstan.neon` nemohli ticho odísť od seba.

**3.
Veci, ktoré sú doslova skopírované.**
Verzia `1.58.2` je v konštante `bin/build/mago-spike.sh`
a trikrát ako cache key v `ci.yml`.
`v0.6.5` pre lekalo je v CI a v komentári skriptu.
A zoznam brán žije na štyroch miestach:
hlavička `ci.yml`
`CONTRIBUTING.md`, `##@` bloky `Makefile` a dokumenty pod `docs/`.
To nie je názor, to je čakajúci drift bug
bump verzie na jednom mieste vyrobí dva rôzne pinované nástroje
v jednom behu.

---

## Časť IV > Dve diery, ktoré mapa ukázala

Prvá:
**model a kód porovnáva len test, nie nástroj.**
`adapters 0` znamená, že model, ktorý by celý zostatol, prejde.
Most medzi vrstvami sú tri PHPUnit kontrakty (`ModelPayloadContractTest`
`ModelReferenceContractTest`
`SecurityEventPayloadContractTest`) plus `KernelCouplingTest`.
To je málo na 57 scénarov, ktoré dnes v openspec strome
sú ale zase je to všetko, čo existuje.

Druhá:
**openspec má cestu k agentovi, ale nie ku kódu.**
Z specov sa generujú pravidlá pre agenta (21 pravidiel, sha256 odtlačky
regenerácia na vyžiadanie)
takže „čo sa bude stavať" sa dostane do promptu.
Nikde však nie je napísané, ktorý test ten scenár naozaj drží.
Scénar je dnes vykonateľný text pre človeka, nie väzba
`#### Scenario: Empty password reports once` nemá v sebe nič
čo by povedalo `emptyPasswordReportsOnce`.

Obe diery sú jedna téma:
**dátové vrstvy
ktoré nikto neporovnáva s kódom.**
Čo s tým, je v druhom článku
[Tri návrhy
ako prepojiť nástroje](/blog/tri-navrhy-na-prepojenie-nastrojov).

---

## Časť V > Čo z toho vyplýva pre prispievateľa

Lokálne prejde päť príkazov a v CI ich beží pätnásť.
`boot`, `dist`
`themes-check` a `extract-check` sa lokálne púšťajú len vtedy
keď máš dôvod:
bootovaný panel na temp databázach
klon z `HEAD`
a porovnanie s upstream slice sa nedajú skrátiť na `make test`.

Praktická mapa:

```
make lint && composer phpstan     # typy a syntax nad jadrom
composer test                     # správanie, aj katalógové testy
composer cs-fix-dry && composer rector-dry
openspec validate --all           # scénare a zmeny
make lekalo-check                 # model, celý a po moduloch
make mago-lint && make mago-guard # katalóg + hranica jadra
```

Keď padne niečo z toho, je to jedna z otázok z Časti I.
Keď padne niečo v CI, čo lokálne nebeží, je to jedna z tých štyroch
ktoré treba pustiť vedome.

---

## Reprodukcia

```
openspec list && openspec validate --all
openspec list --specs
make lekalo-check
make mago-lint && make mago-guard && make mago-modules
sed -n '/^jobs:/,$p' .github/workflows/ci.yml | grep -c '^  [a-z][a-z0-9_-]*:$'  # 15
ls .agents/skills | wc -l                                   # 33
ls tests/Architecture/*.php | wc -l                         # 16
grep -h '^#### Scenario:' openspec/specs/*/spec.md >
openspec/changes/*/specs/*/spec.md | wc -l  # 57
```

---

## Záver

Šesť nástrojov, každý s jednou otázkou, vyzerá ako šesť nástrojov navyše.
Lenže keď sa napíšu pod seba, ukáže sa, že tri z nich (openspec
ai-factory, lekalo) hovoria o **dátach** a tri (`phpstan`, `rector`
`php-cs-fixer`, `mago`) o **kóde**
a jediné, čo spája prvú trojicu s druhou, sú PHPUnit kontrakty.
To nie je prebytok nástrojov.
To je jedna nedokončená väzba a šesť miest, odkiaľ sa na ňu dá pozerať.

Čo s tou väzbou, je v druhom článku.
