---
title: "Mago: jediný nástroj, ktorý spĺňa podmienku „Rust“ : a čo z toho naozaj vyhrá"
date: "2026-10-23"
tags: ["nativa", "php", "mago", "rust", "tooling", "guard", "lint", "phpstan"]
excerpt: "Mago je napísaný v Ruste, beží ako samostatný binárny súbor a Composer je pri ňom voliteľný. Zmerané na 123 PHP súboroch: 0,19 s proti ~3 s PHPStanu. Rýchlosť však nie je verdikt : článok ukazuje, čo Mago naozaj nahradilo, čo pridalo a čo zostalo na PHP nástrojoch."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Nástroje"
published: true
---

Podmienka znela skoro ako vkus >
nástroj na kontrolu PHP kvality má byť napísaný v **Ruste** >
dodávaný ako **samostatný binárny súbor** >
nemá vyžadovať PHP runtime závislosti a má byť rádovo rýchlejší než >
nástroje, ktoré bežia na PHP.

[Mago](https://github.com/carthage-software/mago) túto podmienku spĺňa.
Je to Rust, je to jeden binárny súbor a Composer je pri ňom jedna z ciest >
nie podmienka.
Lenže „spĺňa podmienku“ a „má nahradiť PHPStan“ sú dve rôzne otázky.
Tento článok je záznam z merania, ktoré ich oddelilo >
a odpovedá na obe.

---

## Časť I > Prečo bola podmienka „Rust“

Nie preto, že by Rust robil kód správnejším.
PHP kód je rovnaký, nech ho parsuje čokoľvek.
Rozdiel je v tom, **kde beží kontrola**:

| požiadavka | čo z nej vyplýva | ako to Mago spĺňa |
|---|---|---|
| Rust | parser a analýza nepotrebujú PHP interpreter | `mago` je natívny binárny súbor |
| samostatný binary | žiadny `vendor/`, žiadny autoload, žiadny bootstrap | jeden súbor, ktorý sa spustí priamo |
| bez runtime PHP závislostí | nástroj sa dá spustiť aj tam, kde PHP aplikácia ešte nebeží | Mago nepotrebuje PHP rozšírenia ani Composer |
| Composer voliteľne | inštalácia nesmie byť podmienkou používania | existuje Composer wrapper aj archivovaný release |
| rýchlosť | kontrola sa dá pustiť pri každej zmene, nie raz za večer | na tomto strome ~16× rýchlejšie než PHPStan |

Posledný riadok je dôvod, prečo to nie je akademická otázka.
Keď analýza trvá tri sekundy, človek si ju nechá na koniec práce.
Keď trvá pätinu sekundy, dá sa pustiť medzi dvoma úpravami.

Mago ale nie je „PHPStan v Ruste“.
Sú to štyri nástroje nad jedným parserom >
a každý z nich má inú mieru pravdy.

---

## Časť II > Štyri nástroje, jeden parser

Meranie prebehlo 22. októbra 2026 s `mago 1.58.2` na PHP 8.5.11.
Scope bol presne ten istý ako pri `phpstan.neon` >
123 `.php` súborov plus `bin/cms`, deväť ciest >
ktoré strom naozaj analyzuje.
Dokumentácia >
je na [mago.carthage.software](https://mago.carthage.software) >
zdrojový kód na [GitHube](https://github.com/carthage-software/mago).

| nástroj | čo tvrdí | verdikt v tomto strome |
|---|---|---|
| `mago analyze` | typová a sémantická analýza | **nie je náhrada** : 189 errorov, z toho 2 skutočné |
| `mago lint` | pravidlá štýlu, bezpečnosti a čitateľnosti | **brána** : 570 nálezov v default prese, 0 v kurátorovanom |
| `mago format` | formátovanie | **nie je náhrada** : 124 z 124 súborov by sa zmenilo |
| `mago guard` | architektonické hranice | **brána** : pridaná hodnota, ktorú strom dovtedy nemal |

To je celý článok v jednej tabuľke.
Zvyšok vysvetľuje >
prečo prvé dva verdikty nie sú „áno“ a prečo posledný nie je marketing.

---

## Časť III > Inštalácia: Composer áno, ale voliteľne

Mago sa dá nainštalovať cez Composer:

```bash
composer require --dev carthage-software/mago
```

Je to **tenký wrapper**, nie PHP knižnica.
Pri prvom volaní stiahne zodpovedajúci binárny súbor >
na zdieľanom CI runneri potrebuje `GITHUB_TOKEN` >
inak narazí na anonymný rate limit.
Do `composer.lock` pribudne záznam >
ktorý runtime tohto kernelu nepotrebuje >
a to je presne dôvod, prečo tu Composer nie je povinný.

Druhá cesta je archivovaný release s pevne daným `sha256`:

```bash
MAGO_VERSION="1.58.2"
MAGO_ARCHIVE_SHA256="80b6e6ed3cd2fe2da348f9cd83d23e638f2ccd44988d48bebd766c20ccd30dc2"
```

`bin/build/mago-spike.sh` stiahne >
`mago-1.58.2-x86_64-unknown-linux-gnu.tar.gz` >
overí checksum a binary uloží do `storage/cache/mago-spike/`.
Druhý beh je offline, CI nepotrebuje token a žiadny PHP balík sa nemení.
Rovnaký vzor ako pri Lekalo.

**Composer je teda optional.**
Ak ho chceš, dostaneš pohodlie a zápis v lock file.
Ak nie, dostaneš deterministický binary bez závislosti.
Mago v oboch prípadoch nepotrebuje PHP runtime >
potrebuje len PHP súbory, ktoré má parsovať.

---

## Časť IV > Rýchlosť: 0,19 s proti trom sekundám

Namerané wall time na tom istom checkoute:

| príkaz | wall time | poznámka |
|---|---|---|
| `mago analyze` | **~0,19 s** | 189 errorov / 96 warningov / 87 help |
| `mago lint` | **~0,03 s** | default preset, 570 nálezov |
| `mago format --dry-run` | **~0,03 s** | 124 súborov na prepis |
| `mago guard` | **~53 ms** | prázdny report, dnes |
| `composer phpstan` (level 9) | **~3 s** | 0 nálezov, zelené |

Pomer je približne **16×**.
To je „o rád“, nie zázrak a nie dôvod na prepis všetkého.
Tento strom má 123 súborov; pri tisícoch súborov by sa rozdiel zväčšil >
ale ani dnes nie je PHPStan bottlenecks.
Rýchlosť je dôvod vyskúšať Mago, nie dôvod mu veriť.

A ešte jedna poznámka k porovnaniu s Pintom >
Pint je formátovač z Laravel sveta.
Tento strom používa `php-cs-fixer` >
takže porovnávať Mago s Pintom by znamenalo porovnávať ho s nástrojom >
ktorý tu vôbec nebeží.
Rozhodujúce je porovnanie s tým, čo je v CI.

---

## Časť V > Analyzátor: 189 errorov, dva skutočné

Prvý pohľad vyzerá ako katastrofa >
PHPStan level 9 je zelený na nule a Mago hlási 189 errorov.
Druhý pohľad ukáže, že väčšina z nich nie je nález v kóde >
ale medzera v konfigurácii alebo v inferencii.

| príčina | počet | príklad |
|---|---|---|
| `APP_BASE_PATH` z `define()` | 53 | každý testovací súbor |
| voliteľný modul mimo scope | 54 | `Modules\Outbox\…` v `bin/cms` |
| limity inferencie / stubov | 78 | `ReflectionClass::newInstanceArgs()` označené ako neplatné |
| známe, zrkadlí ignore v `phpstan.neon` | 1 | `NavBadgeRegistry::scanFile()` |
| zámerne | 1 | `new $class()` v `MiddlewareRegistry` |
| **skutočný nález** | **2** | neinicializované `$emitted` v teste |

Dva skutočné nálezy sú jeden bug.
Test v `ThemeClassOwnershipTest` robí toto:

```php
foreach ($this->emittedClasses() as $class => $where) {
    $emitted[$class] = $where;
}
```

`$emitted` nikde predtým nevznikne.
PHP ho pri prvom zápise automaticky vytvorí ako pole >
takže test prejde a PHPStan level 9 mlčí.
Mago to pomenuje.
To je presne ten signál, ktorý sa hľadal >
ale sú to **dva errory z 189**.

Preto analyzer nie je brána.
Aby bol, potreboval by baseline alebo široký ignore list; baseline >
ktorý rastie, schová ďalší nález.
Menšia verzia pravdy je lepšia než väčšia verzia šumu.

---

## Časť VI > Linter: 570 návrhov, nula po kurátorovaní

Default preset Mago je názorový.
Na tomto strome hlási **570** nálezov:

- 241 × `literal-named-argument` : prepis volaní na named arguments,
- 51 × `no-error-control-operator` : proti `@`, ktoré strom používa vedome
  pri best-effort súborových operáciách,
- 36 × `cyclomatic-complexity` pri threshold 15.

Taký preset sa nedá použiť ako brána.
`mago.toml` preto vypína 18 pravidiel, každé s nameraným počtom a dôvodom >
a necháva zapnuté correctness, security a readability.
Výsledok po opravách: **0 nálezov**.

Dvadsaťdva nálezov sa vyriešilo 22. októbra, žiadny vypnutím pravidla.
Šestnásť boli skutočné opravy:

- `explicit-octal` (5): `0755` → `0o755`, `0775` → `0o775`, `0002` → `0o002`;
- `assert-description` (1): `assert()` v `DatabaseMigrator` dostal dôvod,
  prečo nemôže nastať;
- `no-empty-catch-clause` (3): prázdne catch bloky buď tvrdia na výsledok,
  alebo zalogujú zlyhanie;
- `no-ini-set` (5): `@mago-expect lint:no-ini-set` s dôvodom na miestach,
  kde sa PHP konfiguruje pred frameworkom;
- `sensitive-parameter` (1): `CsrfMiddleware::verify()` dostal
  `#[\SensitiveParameter]`, aby sa token neobjavil v stack trace;
- `no-unsafe-finally` (1): cleanup v teste už neprepíše pôvodnú výnimku.

Zvyšných šesť boli false positives dvoch security pravidiel.
Obe pravidlá zostali zapnuté:

- `no-insecure-comparison` (4): heuristika čítala `$token` ako credential,
  ale v `KernelCoupling.php` to bol prvok z `token_get_all()` >
    pole alebo
  jednoznakový string. Premenovanie na `$element` problém vyriešilo bez
  pragmy;
- `no-literal-password` (2): test naozaj porovnáva literálny CSRF token,
  pretože ten literál **je** assert. Obe miesta majú
  `@mago-expect lint:no-literal-password` s dôvodom.

`make mago-lint` beží s `--max-issues=0`.
Prázdna množina je tvrdenie; jeden nález job zhodí.
A pragma, ktorá prežije svoj kód >
sa počíta ako nález cez `unfulfilled-expect` >
takže ani výnimky nemôžu zhrdzavieť.

---

## Časť VII > Formátovač: 124 z 124 súborov

`mago format --dry-run` by prepísal **všetkých 124** súborov v scope.
To nie je „rýchlejší `php-cs-fixer`“, to je iný štýl.

| pravidlo v `.php-cs-fixer.dist.php` | ekvivalent v Mago |
|---|---|
| `declare_strict_types` | **nie** : nie je formátovacia vec |
| `strict_comparison` (`==` → `===`) | **nie** |
| `fully_qualified_strict_types.import_symbols => false` | **nie** : a toto je nosné: `true` by vrátilo `Modules\Outbox` importy do middleware a `KernelCouplingTest` by sčervenal |
| `phpdoc_align` | **nie** : docblocky neformátuje |
| `blank_line_before_statement` | **čiastočne** : len `empty-line-before-return` |
| `declare_equal_normalize` | **áno**, cez `space-around-assignment-in-declare` |
| `concat_space`, `single_quote`, `trailing_comma_in_multiline` | **áno** |

Z 124 súborov sa 28 líši len v `declare(strict_types = 1)` >
proti `declare(strict_types=1)`; zvyšných 96 má väčší diff >
najmä zbalené prázdne konštruktory a prelomené argumenty.
Prejsť >
na Mago formát znamená preformátovať celý strom **a** vzdať sa pravidiel >
ktoré tento strom drží zámerne.
To je samostatný plán s vlastným diffom, nie prepínač.

---

## Časť VIII > Guard: jediná vec, ktorá naozaj pridala hodnotu

`mago guard` tvrdí vetu, ktorú už drží `KernelCouplingTest` >
**súbor v kernelu nesmie menovať modul.**
Rozdiel je v tom, ako to vidí.

Test číta `use` riadky.
Guard číta aj inline FQCN >
a na neupravenom strome našiel **osem** takých hrán >
v `AuthMiddleware.php` a `RateLimitMiddleware.php` >
kde kernel siahal na `Modules\Outbox\…` cez `class_exists()` >
a plné meno triedy.
Test mal prázdny ledger práve preto, že to neboli `use` statementy.
Toto je celý argument za guard.

Osem nálezov sa nevyriešilo baseline.
Vyriešilo sa to portom `Modules\Core\Domain\Contracts\SecurityEventSink` >
ktorý má jednu metódu na fakt, ktorý kernel vyvoláva.
Outbox ho implementuje v `OutboxSecurityEventSink`; `src/http.php` >
composition root, ktorý smie moduly menovať >
ho zostaví za `class_exists()` a predá obom middleware.
Bez modulu je sink `null` a záznam sa zahodí, nikdy nie odmietnutie.

Merané po refaktore:

- guard: **8 errorov → 0**, bez baseline a bez ignore listu;
- `KernelCouplingTest` má stále prázdny ledger : kernel nepridal `use
  Modules\…`, pridal kontrakt, na ktorom modul závisí;
- `make mago-guard` dnes: **0 issues, 53 ms**;
- v CI beží job `kernel-dependency` bez PHP setupu a bez `composer install`
 >
    checkout, ktorý dokazuje bránu, je zároveň checkout bez `vendor/`.

Guard nie je náhrada testu.
Test klasifikuje hrany (M/C/G) a ratchetuje nerozhodnuté >
guard nemá ledger.
Guard hovorí pravidlo, test zapisuje rozhodnutia.
To je dôvod, prečo sú v strome obe veci a prečo to nie je duplicita.

---

## Časť IX > Verdikt: čo znamená „Rust“ v praxi

| vrstva | Mago | prečo |
|---|---|---|
| architektonické hranice | **áno, brána** | guard videl inline FQCN, ktoré PHPUnit test nevidel |
| kurátorovaný lint | **áno, brána** | 570 → 0 bez vypnutia security pravidiel |
| typová analýza | **nie, doplnok** | 189 errorov, 2 skutočné; PHPStan level 9 zostáva |
| formátovanie | **nie** | 124/124 prepis a strata nosných pravidiel |

Ak je podmienka „chcem PHP kvalitatívnu kontrolu pod Rust toolchainom“, Mago je správna voľba >
ale len na tie vrstvy, ktoré vie pomenovať.
Ak je podmienka „chcem drop-in náhradu PHPStanu a `php-cs-fixer`“, Mago ňou >
nie je a tvrdiť opak by bolo meranie proti marketingu.

Praktické pravidlo: **Mago pridáva tam, kde dokáže sformulovať vetu >
ktorá sa dá vyvrátiť.**
„Kernel nesmie menovať modul“ je taká veta.
„Tento kód je typovo správny“ je taká veta len vtedy, keď nástroj vidí bootstrap >
a voliteľné moduly >
a to Mago v tomto strome ešte nevidí.

---

## Časť X > Ako si to zopakovať

```bash
make mago-spike                     # štyri nástroje, vždy exit 0
make mago-spike MAGO_ARGS=--strict  # exit non-zero, keď niečo nájde
make mago-guard                     # brána: guard, --strict
make mago-lint                      # brána: lint, --max-issues=0
bin/build/mago-spike.sh --only=analyze
```

Reporty padajú do `storage/cache/mago-spike/` (gitignored) >
`<tool>.txt` a `summary.tsv`.
Rationale >
a všetky čísla sú v [`docs/mago-evaluation.md`](/docs/mago-evaluation) >
tento článok je jeho naratívna verzia, nie druhý zdroj pravdy.

---

*Merané 22. októbra 2026 s `mago 1.58.2` na PHP 8.5.11 >
scope 123 `.php` súborov + `bin/cms`.
Pri písaní článku 23. októbra: `composer test` 714 testov / 5889 asertí >
`make mago-guard` 0 issues (53 ms), `make mago-lint` 0 issues (30 ms).
Ak niektoré číslo už neplatí, je to dôvod na nové meranie >
nie na vymazanie odseku.*
