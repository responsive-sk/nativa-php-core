---
title: "Mago nad modulovým katalógom: jedna brána navyše a 313 nálezov, ktoré z neho vypadli"
date: "2026-10-27"
tags: ["nativa", "php", "mago", "moduly", "tooling", "lint", "phpstan", "kvalita"]
excerpt: "Katalóg modulov je 360 PHP súborov, ktoré spustí inštalácia, a doteraz ich nemeral žiadny nástroj — phpstan ho vynecháva, php-cs-fixer a rector tiež. Rozšírenie Mago scope na modules/ odkrylo 1691 nálezov, z toho 313 v jednej triede. Článok je záznam o tom, čo sa zmeralo, čo sa opravilo a čo zostalo na stole."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Nástroje"
published: true
---

Kým Mago meralo len jadro, bolo to meranie deviatich ciest z `phpstan.neon`.
Katalóg `modules/` — kód, ktorý `bin/cms module:add` skopíruje do inštalácie a
ktorý tam naozaj beží — neanalyzoval nikto. Nebola to otázka vkusu, bol to
scope: `phpstan`, `php-cs-fixer` aj `rector` mali `modules/` vylúčené a vo
svojich konfiguráciách mali napísané prečo. Tá poznámka je správna a nezmenila
sa. Zmenilo sa len to, že **Mago tú dieru teraz zaplnilo** — a článok je
záznam o tom, čo v nej bolo.

Zmena je v commite `f129ca0`: 127 súborov, +2119 / −471, z toho 13 nových
súborov v katalógu a dva nové nástroje (`make mago-modules`,
`bin/build/mago-modules-report.sh`).

---

## Časť I > Prečo katalóg nikto nemeral

Tri konfigurácie hovoria to isté tromi rôznymi slovami:

| nástroj | prečo `modules/` nie |
|---|---|
| `phpstan.neon` | je to text, ktorý jadro nespustí, kým ho `module:add` neskopíruje; jeho overenie je closure scan `module:show`, nie PHPStan |
| `.php-cs-fixer.dist.php` | katalóg je doslovný import zo showroomu a re-importuje sa pri každom update; preformátovanie by z budúceho re-importu urobilo ručné zlučovanie |
| `rector.php` | ten istý dôvod, tá istá veta |

Každá z nich je obhájiteľná. Súčet je však toto: **360 PHP súborov, ktoré
inštalácia spustí, a žiadny nástroj na nich nič nemeria.** (Dnes je v katalógu
373 `.php` súborov — trinásť pribudlo v tomto commite, zvyšok je ten istý
kód.) Pri module, ktorý niečo pokazí, sa to prejaví v odpovedi servera, nie
v bráne.

Mago je na doplnenie tejto medzery vhodné z jedného dôvodu: `mago.toml` je
konfiguračný súbor tohto stromu, nie súbor showroomu. Jeho `[source] paths`
môže menovať katalóg bez toho, aby sa niečo prepisovalo — a čo je dôležitejšie,
`mago.toml` vie povedať **pre každý nástroj zvlášť**, či sa ho katalóg týka.

---

## Časť II > Čo presne sa zmenilo

Do `[source] paths` pribudol riadok `"modules"` a z `[source] excludes` zmizol.
Zvyšok je o tom, čo **nie**:

| sekcia | rozhodnutie | prečo |
|---|---|---|
| `[source] paths` | `"modules"` navyše | nález v katalógu je nález v kóde, ktorý inštalácia beží |
| `[formatter] excludes` | `"modules"` | preformátovanie by z najbližšieho re-importu modulu urobilo merge |
| `[guard] excludes` | `"modules"` | perimeter hovorí o **jadre**; moduly sú druhá strana pravidla a model je whitelist, takže namespace bez pravidla by hlásil každú hranu |
| `[linter] excludes` | `"modules/Themes"` | témy sú SHA overovaný mirror `nativa-themes`; nález tam opraví len upstream |

Scope drží `tests/Architecture/MagoScopeContractTest`: cesty Mago musia
obsahovať všetky cesty `phpstan.neon` a **jediné, čo smie pribudnúť navyše, je
`modules`**. Kontrakt z nesúladu neurobí ticho — spadne.

---

## Časť III > Prvé meranie

Mago 1.58.2, PHP 8.5, ten istý binárny súbor, len o 360 súborov širší scope:

| nástroj | jadro (22. 10.) | + `modules/` (27. 10.) |
|---|---|---|
| `mago lint` | 0 nálezov | 0 nálezov (po opravách; 5 nálezov v `Themes` je vylúčených) |
| `mago analyze` | 367 nálezov / 184 errorov | **1691 / 917** |
| `mago format` | 124 z 124 súborov | nezmenené |
| `mago guard` | 0 nálezov | nezmenené |

`1691 / 917` je takmer päťkrát viac errorov, než malo jadro. To je presne ten druh
čísla, ktorý vyzerá ako dôvod scope nerozširovať — a je presne ten dôvod ho
rozšíriť: v jadre tie isté triedy nálezov existujú tiež (`prepare()` vracajúci
`PDOStatement|false`, nedefinovaný `APP_BASE_PATH` v testoch), takže nešlo o
nový druh problému, len o nový výskyt známeho.

Dôležité je, že **`analyze` zostal meraním, nie bránou.** Brána nad katalógom
je len jedna trieda nálezov — `lint`, s limitom 0 — a to je zásadné: brána,
ktorá padá na číslo, ktoré nikto netriedil, padá prvý deň a ignoruje sa na
tretí.

---

## Časť IV > `invalid-method-access`: 313 → 0

Najväčšia jediná trieda v katalógu bola 313 nálezov `invalid-method-access` a
všetky boli ten istý defekt povedaný 313-krát:

```php
$stmt = $this->pdo->prepare($sql);
$stmt->execute($params);          // <- typovo: volanie metódy na `false`
```

`PDO::prepare()` a `PDO::query()` sú deklarované ako `PDOStatement|false`,
pretože PDO vie byť nakonfigurované tak, že zlyhá potichu. Tieto pripojenia
také nie sú — `DatabaseConnectionManager` nastavuje `ERRMODE_EXCEPTION`, takže
vetva `false` je za behu nedosiahnuteľná. Lenže **nedosiahnuteľné nie je
odtypované** a kód, ktorý bol o runtime správne, bol o typ nesprávne.

Riešenie je nová trieda v každom module, ktorý pripravuje SQL:
`Modules\<Name>\Infrastructure\Persistence\Statement` s dvomi statickými
metódami, ktoré vyhodia `RuntimeException` namiesto toho, aby vrátili `false`.

Prečo trinásť kópií a nie jedna zdieľaná trieda? Pretože platia dve pravidlá
súčasne: moduly sú samostatné slicy, ktoré **nesmú** importovať jeden druhý, a
jedna trieda v `Modules\Database` by do jadra pridala súbor, ktorý
`extract-kernel --check` porovnáva s upstreamom. Trinásť malých súborov je cena
za tie dve pravidlá — a aby to nebola cena za trinásť rôznych správaní, drží ich
`tests/Architecture/StatementHelperContractTest`: rovnaké bajty po normalizácii
namespace, namespace odvodený z adresára, obe poistky a zoznam modulov ako
ratchet.

Popri tom zmizlo 20 už mŕtvych guardov typu
`false === $stmt ? [] : $stmt->fetchAll()`. Neboli neškodné: keby zostali,
vyrobili by 20 nových nálezov `redundant-comparison` a `impossible-condition`
a časť výhry by sa sama zjedla.

Výsledok: **313 → 0**, a vedľajším efektom `possibly-false-argument` 15 → 1.

---

## Časť V > `mixed-assignment`: 291 → 245 (úprimne, čiastočne)

Druhá najväčšia trieda vyzerá ako jedna, ale jedna nie je. 291 nálezov
„priradenie `mixed` do premennej“ má rôzne zdroje: 98 čítaní
`Request::body()`/`query()`, 79 prístup do indexu netypovaného poľa, 20
`json_decode()`, zvyšok `foreach` cez `array<string, mixed>` alebo
`get_object_vars()`.

Jedna časť mala jednu príčinu. `body()` a `query()` vracajú `mixed`, a tak
každá akcia v katalógu písala ten istý idiom:

```php
$name = is_string($request->body('name')) ? $request->body('name') : '';
```

To čítanie je **dvojité** a to druhé je stále `mixed`: zúženie jedného volania
nevypovedá o druhom. `Request` má preto vedľa `header(): ?string`, ktoré taký
typovaný prístup malo odjakživa, nové `queryString()` a `bodyString()` — jedno
čítanie, priznaný default. Používa ich 71 miest v 21 triedach.

| trieda | pred | po |
|---|---|---|
| `mixed-assignment` | 291 | **245** |
| `mixed-argument` (vedľajší efekt) | 197 | **73** |

Zvyšných 245 je tá ťažšia polovica a nechávam ju na stole vedome. Index do
`array<string, mixed>` je `mixed` preto, že to pole **nebolo nikdy typované** —
správna oprava je tvar na producentovi (`@return array{meta?: array{image?: string}}`),
nie cast na konzumentovi. Nesprávny tvar je horší než tá výstraha a
`json_decode()` potrebuje to isté rozhodnutie raz za miesto. To je návrhová
práca cez hranice modulov, nie mechanická; urobiť polovicu by znamenalo
vyrobiť 245 miest, kde sa typ len tvári.

---

## Časť VI > Ktoré brány vidia moduly a ktoré jadro

Rozšírenie scope dalo katalógu **jednu** bránu a nič viac. Zvyšok brán stromu
bol napísaný pre jadro a nikde to nebolo napísané spolu, takže teraz je to v
`docs/mago-evaluation.md` tabuľkou:

| brána | jadro | `modules/` | čo kontroluje |
|---|---|---|---|
| `composer phpstan` (level 9) | áno | **nie** | typy nad jadrom |
| `composer cs-fix-dry` | áno | **nie** | štýl |
| `composer rector-dry` | áno | **nie** | jazykové prepisy |
| `make mago-lint` (limit 0) | áno | **áno**, okrem `modules/Themes/` | kurátorované pravidlá |
| `make mago-guard` | áno | **nie** | hrany jadro → modul |
| `make lint` (`php -l`) | áno | áno | syntaktické chyby |
| `make mago-modules` | — | áno, ako meranie | 0 nálezov lint, 884 nálezov analyze; nikdy nepadne |
| `composer test` | `tests/` | `modules/*/tests/` | správanie |

`make lint` pritom predtým katalóg tiež nemal — `find src public tests bin` na
`modules/` nedosiahol, takže syntaktická chyba v module sa odhalila až tým, že
súbor niečo načíta. Dnes je v `find` aj `modules`.

Aby číslo neostalo len v dokumente, je tu tretí diel: `make mago-modules` púšťa
`lint` + `analyze` len nad katalógom a `bin/build/mago-modules-report.sh`
z neho robí markdown. V CI to je job `module-quality`, ktorý tabuľku zapíše do
zhrnutia behu — a **nemôže** zlyhať: `--max-issues` s týmto scope odmietne
(a limit je brána), `--strict` len upozorní, že sa ignoruje, a celý job má
`continue-on-error`. Číslo, ktoré sa dá prečítať, ale nedá sa ním zablokovať
merge.

---

## Časť VII > Dopad

| meranie | pred | po |
|---|---|---|
| katalóg, `mago analyze` | 1384 nálezov / 802 errorov | **884 / 348** |
| celý strom, `mago analyze` | 1691 / 917 | **1191 / 463** |
| `invalid-method-access` (katalóg) | 313 | **0** |
| `mixed-assignment` (katalóg) | 291 | **245** |
| `mixed-argument` (katalóg) | 197 | **73** |
| `mago lint` (katalóg) | — | **0** |

Overené: `make mago-lint` 0, `make mago-guard` 0, `make lint` čistý,
`make extract-check` OK (206 identických, 82 zaznamenaných divergencií),
phpstan a php-cs-fixer čisté, architektonické testy 94/94, celá sada **804
testov / 6242 asertov** a modulová sada 539 testov proti naozaj
nainštalovanému katalógu. (Tento článok k tomu číslu pridáva jednu asertu:
jazyková brána `SlovakProseContractTest` číta každý obsahový súbor zvlášť, takže
bez neho je sada o jednu kratšia.)

Dva dôsledky stoja za pomenovanie:

- **Katalóg nie je držaný na úrovni jadra.** `phpstan` level 9 ho nečíta a
  `mago analyze` nad ním nemá limit — 348 errorov je údaj, nie verdikt.
  Jediná statická brána nad modulovým kódom je `mago-lint`.
- **Katalóg sa re-importuje.** Každý lokálny zásah do `modules/` je pri
  najbližšom update modulu kandidát na konflikt, čo je presne dôvod, prečo je
  formatter vylúčený. Oprava sa preto robí v katalógu a vedome; ak by mala
  žiť, patrí do showroomu, z ktorého sa moduly berú.

Nový je aj jeden záznam v `bin/build/kernel-divergence.txt`: `Request.php` má
teraz typované čítania (`queryString()`, `bodyString()`), takže sa líši od
upstreamu — a brána `extract-check` to vie prečítať namiesto toho, aby na to
ticho padla.

---

## Čo ešte nie je hotové

- **245 `mixed-assignment`** — viď Časť V; potrebujú tvary polí na strane
  producentov, nie casty.
- **99 `non-existent-constant`** — `APP_BASE_PATH` v testoch modulov, ktoré si
  nebootstrapujú `src/init.php`. Rovnaká trieda ako v jadre.
- **5 `readable-literal` v `modules/Themes`** — mirror `nativa-themes`;
  opraví sa upstream, tu je vylúčený s dôvodom.
- **`extract-check` má 15 novo zaznamenaných divergencií** — nie oprava, ale
  zviditeľnenie; každá nesie commit, ktorý ju spôsobil.

Reprodukcia:

```
make mago-spike        # všetky štyri nástroje, vždy exit 0
make mago-lint         # brána: 0 nálezov nad jadrom aj katalógom
make mago-modules      # meranie katalógu: lint + analyze, vždy exit 0
make extract-check     # jadro proti upstream slice
```

---

## Záver

Brána, ktorá vidí viac, nie je víťazstvo. Je to zoznam, ktorý treba prejsť — a
v tomto prípade sa z neho dve triedy prejsť dali: jedna bola jeden defekt v 313
kópiách a druhá mala v sebe jednu časť s jednou príčinou. Zvyšok zostal
pomenovaný, zmeraný a publikovaný, nie vymazaný z reportu.

To je celý rozdiel medzi meraním a bránou: **`mago analyze` nad katalógom smie
ukazovať 348 errorov, `mago lint` nesmie ukázať ani jeden nález.**
