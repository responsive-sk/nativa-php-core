---
title: "Tri návrhy, ako prepojiť nástroje : a jeden zapísaný plán"
date: "2026-10-27"
tags: ["nativa", "tooling", "openspec", "brány", "proces", "lekalo", "mago", "plán"]
excerpt: "Mapa toolchainu ukázala tri miesta, kde to isté pravidlo vlastní viac nástrojov, a dve diery: model ani scénare nie sú priviazané na kód. Tri návrhy, čo s tým : registr nástrojov, väzba scénar↔test a rozhodnutie o vlastníctve : plus openspec change, ktorý som pre ten najsilnejší napísal."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Nástroje"
published: true
---

Mapa
v [predchádzajúcom článku](/blog/sest-nastrojov-a-jedna-otazka) ukázala
šesť nástrojov a dve veci, ktoré sa v takej mape nedajú prehliadnuť
tri prekryvy, kde to isté pravidlo vlastní viac než jeden nástroj
a dve diery, kde dátová vrstva nemá s kódom žiadnu strojovú väzbu.
Toto je o tom, čo by som s tým urobil
a nie je to „pridať siedmy nástroj".

Princíp je jednoduchý a v tomto strome už raz zaplatený
**každá otázka má jedného vlastníka a každé tvrdenie, na ktorom záleží
má test, ktorý ho prečíta.**
Presne tak vznikli `DocsCatalogContractTest`, `SlovakProseContractTest`
`MagoScopeContractTest` aj `StatementHelperContractTest`
dokument alebo konvencia, ktorá prestala byť len veta v súbore.
Toto je ten istý krok o úroveň vyššie, nie nový nástroj.

---

## Časť I > Tri návrhy, v poradí podľa toho, čo je v sázke

| # | návrh | čo to rieši | cena |
|---|---|---|---|
| **B** | **väzba scénar ↔ test** | openspec nemá cestu ku kódu: 57 scénarov, žiadny neukazuje na test | anotovať 57 scenárov, pár testov dopísať |
| **A** | **jeden registr nástrojov, brán a pinov** | `1.58.2` na 4 miestach, zoznam brán na 4 miestach | jeden súbor + jeden kontraktný test |
| **C** | **rozhodnutie o vlastníctve prekryvov** | tri duplicity bez zapísaného vlastníka a bez podmienky odchodu | jedna strana, tri riadky |

Poradie je zámerné.
A a C sú údržba
lacná, mechanická, robí sa kedykoľvek.
B zatvára dieru v **správnosti**
dnes sa dá zmeniť scénar a nič sa nestane.
Preto je plán napísaný pre B a A s C zostávajú ako samostatné
menšie zmeny.

---

## Časť II > Návrh B: väzba scénar ↔ test

### Čo je dnes zlé

Scénar v openspec strome je dnes text pre človeka:

```
#### Scenario: Empty password reports once

- GIVEN data `['password' => '', 'password_confirmation' => '']`
- AND rules `['password' => 'required|min:8|confirmed']`
- WHEN validated
- THEN the error count is 1
```

Test, ktorý to naozaj drží
je `emptyPasswordReportsOnce()`
v `modules/Validation/tests/ValidatorTest.php`.
Nič v tom scénari neukazuje
na ten test a nič v tom teste neukazuje na ten scénar.
Keď sa test premenuje alebo zmaže
scénar zostane a `openspec validate --all` je zelený
pretože validátor kontroluje model, nie kód.

To je presne tá istá diera, akú má lekalo s `adapters 0`.
Rozdiel je, že pri lekale ju už raz niekto zatvoril
tri kontrakty čítajú triedu ako **text** a porovnajú ju s modelom.
Toto je ten istý ťah.

### Ako to má vyzerať

Skúšal som najprv, či to openspec vôbec prijme
lebo formát specu nie je môj.
Dočasná zmena `_pin-spike` s delta specom
ktorého scénar mal namiesto ďalšieho `THEN` riadok

```
- PINS: modules/Validation/tests/ValidatorTest.php::emptyPasswordReportsOnce
```

prešla `openspec validate --strict` s exit 0.
Adresár som po meraní zmazal, takže to nie je plán „možno to pôjde"
je to zmeraný formát.
Nič sa neforkuje, nič sa nedopĺňa do openspec schémy
je to bullet v zozname, ktorý validátor ignoruje a náš test číta.

Kontraktný test (`tests/Architecture/ScenarioTestBindingContractTest`)
potom číta `openspec/specs/**/spec.md`
a `openspec/changes/*/specs/**/spec.md` mimo `archive/`
a pre každý `#### Scenario:` vyžaduje:

1. **`PINS:` riadok** : aspoň jeden, alebo `PINS: none : <dôvod>`.
2. **Súbor existuje** a je v `tests/` alebo `modules/*/tests/`.
3. **Metóda existuje** : v tom súbore je `function <názov>(`.
4. **`none` má dôvod** a je započítané v ratchete, ktorý smie len klesať.

Prečo text a nie reflexia:
tie isté dôvody
aké má `ModelPayloadContractTest`
súbor je v `modules/`, ktorý `phpstan.neon` neanalyzuje, a test
ktorý by katalóg načítal, by spravil výsledok brány závislým od toho
ktoré moduly má checkout nainštalované.

### Jedno obmedzenie, ktoré sa nedá obísť

`openspec/` je **gitignored** (rozhodnutie 2026-10-14
plány a specy zostávajú lokálne a na GitHub nejdú).
Test, ktorý číta `openspec/specs/**`, preto v CI nenájde nič
a to nie je chyba návrhu, to je vlastnosť stromu.
Väzba musí tú neprítomnosť zvládnuť rovnako
ako ju zvládajú modulové testy:
keď modul nie je nainštalovaný
test skippuje, nepadá.
Vzor je `TaxonomyRegistryTest` pri Blogu
a `KernelOnlyBootTest` pri Cardboarde.

Čo to znamená čestne:
**väzba je lokálna brána, nie CI brána.**
Chráni toho, kto spec edituje
teda presne to miesto, kde scénar vzniká
a v CI je nečinná.
Kto chce, aby scénare strážil aj GitHub
musí najprv zmeniť rozhodnutie o `openspec/` v `.gitignore`
bez toho by test v CI len tvrdil, že nič nenašiel.
Radšej to napíšem, než aby to vyzeralo ako nová brána, ktorá neexistuje.

### Rozsah, zmeraný

Dnes je v strome **57 scénarov v 6 súboroch**:

| súbor | requirements | scenarios |
|---|---:|---:|
| `openspec/specs/validation/spec.md` (kanonický) | 7 | 9 |
| `changes/marko-adoption/specs/health/` | 3 | 7 |
| `changes/marko-adoption/specs/notification-inbox/` | 4 | 10 |
| `changes/marko-adoption/specs/page-cache-invalidation/` | 3 | 11 |
| `changes/marko-adoption/specs/pagination/` | 4 | 11 |
| `changes/marko-adoption/specs/translation-file/` | 4 | 9 |

Preto to nie je jedna zmena, ale zmena s fázami
**ratchet začína na 48** (kanonický spec priviazaný hneď
delty zapísané ako `PINS:
none
not yet annotated` s počtom), a každá ďalšia fáza priviaže jednu capability a zníži ratchet.
Brána je zelená od prvého dňa a nedá sa do nej schovať nový scénar
ten prichádza bez výnimky a bez pin-u neprejde.

### Čo to odhalí

To je celý zmysel.
Pri deviatich scénaroch kanonického specu existuje v `ValidatorTest.php`
12 testovacích metód, takže väčšina sa priviaže hneď
ale „Errors flatten in field-then-rule order" zrejme vlastný test nemá
pretože ho dnes kryje `customMessagesUseFieldRuleKeys()` len implicitne.
A presne tu sa rozhoduje medzi dvoma čestnými odpoveďami:
dopísať test
alebo zapísať `PINS: none` s dôvodom.
Obe sú rozhodnutie; ticho nie je.

Cena je tiež merateľná:
štyri riadky na scénar
teda **do 230 riadkov v specoch** a jeden nový kontraktný test.

---

## Časť III > Návrh A: jeden registr nástrojov, brán a pinov

Dnes sa verzia `mago` píše na štyri miesta (konštanta
v `bin/build/mago-spike.sh` a tri cache key-e v `ci.yml`), `lekalo` na dve
a zoznam brán žije v hlavičke CI, v `CONTRIBUTING.md`
v `##@` blokoch `Makefile` a v `docs/`.
Každé z tých miest má iného čitateľa
takže sa to nedá len tak spojiť do jedného odseku.

Návrh:
jeden strojovo čitateľný súbor (`toolchain.json`)
v ňom pre každý nástroj pin (verzia, target, sha256, plus „ihly"
literály s `{{version}}`, ktoré musia sedieť v súbore
ktorý ten pin naozaj používa) a pre každú bránu riadok (target, tier
môže padnúť `yes/no`, na akú otázku odpovedá, a ktorý CI job ju púšťa).

Toto je implementované, nie navrhnuté
a prvý návrh mal jednu vec zle.
Pôvodne som písal `toolchain.toml`
PHP v tomto strome nemá TOML parser (runtime je zero-dependency)
a vlastný mini-parser by bol ďalší kód, ktorý nikto netestuje.
`toolchain.json` číta `json_decode()`, ktorý tam už je.

Druhá vec, ktorú návrh preháňal:
CI si cache key z registra **neberie**
stále ho píše samo (trikrát v `ci.yml`).
Registr ten zápis vlastní a kontraktný test ho stráži
bump v jednej strane bez druhej je červený
ale zostáva to duplicita s vlastníkom, nie jediný zdroj.
Derivovať cache key v CI je ďalší krok a samostatné rozhodnutie
nie tichá súčasť tohto.

`make gates` je čítacia strana toho istého súboru
vypíše piny, brány a na čo odpovedajú, a zámerne nepočíta nič vlastné
aby sa zoznam, ktorý vidí človek, a zoznam, ktorý vynucuje test
nemohli rozísť.
Ten test padne, keď:

- je v `Makefile` target, ktorý v registri nie je (a naopak),
- je v `ci.yml` job, ktorý v registri nie je (a naopak),
- brána je v registri zapísaná ako „beží v CI" a v tom jobe sa naozaj
  nevolá
    vrátane zakomentovanej (`# make mago-guard` nie je volanie),
- pin v registri nesedí s konštantou v skripte alebo s cache key-om.

Meradlo hotového z návrhu zostalo nesplnené v jednej časti
bump verzie na jednom mieste **neprejde** celé CI, prejde len vtedy
keď sa upraví aj druhý súbor
ale už to neprejde ticho.
To je o krok menej, než som napísal, a je to takto pomenované zámerne.

---

## Časť IV > Návrh C: rozhodnutie o vlastníctve, nie o nástroji

Tri prekryvy z mapy nie sú problém
kým sa dá odpovedať na otázku „kto je vlastník a kedy ten druhý odíde".
Dnes sa na to odpovedá z hlavy, takže:

- **Perimeter jadra:** vlastník je `mago guard`. `KernelCouplingTest` drží
  ledger a klasifikáciu M/C/G. Guard sa nezruší, kým test nezačne rozpoznávať
  inline FQCN
    a to bez parsera nezvládne, takže pravdepodobne nikdy.
  Zápis: *guard je brána, test je záznam.*
- **Typy:** vlastník je `phpstan` level 9 nad jadrom. `mago analyze` je
  čítanie a jeho rozpočet je **dva reálne nálezy na dve kolá**
    ak ďalšie
  rozšírenie scope neprinesie nič okrem konfigurácie a inferencie, `analyze`
  z `module-quality` reportu vypadne a `lint` + `guard` zostanú.
  Zápis: *čítanie s rozpočtom, nie brána s nádejou.*
- **Pravidlá, ktoré vidia oba nástroje:** vlastník je `rector`, pretože vie
  opraviť. Keď to isté pravidlo hlási aj `mago lint`, vypne sa v `mago.toml`
  s počtom a dôvodom
    tak, ako to už dnes robí 18 pravidiel v
  `[linter.rules]`. Zápis: *keď dvaja vidia to isté, opravovať smie ten,
  kto to vie opraviť.*

Toto nie je článok, to je polstrana v registri z návrhu A.
Preto samostatne.

---

## Časť V > Čo som zapísal

Pre B som napísal openspec change
`openspec/changes/scenario-test-binding/`
`proposal.md` s dôvodom a dopadom
`tasks.md` s fázami (kanonický spec → päť capability → ratchet 0)
a delta spec `specs/spec-binding/spec.md` s požiadavkami na väzbu.
`openspec validate --all` je zelený, takže to nie je poznámka v šuplíku
je to change, ktorý sa dá archivovať až vtedy, keď je hotový.

Aj to má jednu úprimnú vadu:
ten plán je **v gitignorovanom strome**
takže do GitHubu sa nedostane.
Trvalý záznam o ňom je tento článok
a ak si plán má niekedy prečítať niekto iný než ja na tomto počítači
je to rozhodnutie o `openspec/` v `.gitignore`, nie o pláne samotnom.

A takto to dopadlo:
**A je hotové**
`toolchain.json`, `make gates` a `ToolchainRegistryContractTest`
ktorý padne pri rozjazde s `Makefile`, `ci.yml` alebo s pinom v skripte.
**B má fázu 0 hotovú**
`ScenarioTestBindingContractTest` s ratchetom
na 57 zatiaľ nepriviazaných scénarov a so skip-om
keď `openspec/` v checkoute nie je; pinovanie kanonického specu je fáza 1
a až tá ukáže, ktoré scénare vlastný test nemajú.
**C zostáva nenapísané.**

C som odložil zámerne a nie je to lenivosť
je to polstrana do registra a má sa urobiť v momente
keď sa na niektorom z troch prekryvov naozaj niečo pokazí.
Zapísať ju skôr by znamenalo držať „rozhodnutie" o niečom
čo nikto nespochybňuje.

---

## Reprodukcia merania

```
openspec list --specs                       # validation, 7 requirements
openspec list                               # marko-adoption, 7/8 tasks
grep -h '^#### Scenario:' openspec/specs/*/spec.md \
     openspec/changes/*/specs/*/spec.md | wc -l          # 57
openspec validate --all                     # 2 items, 0 failed
```

---

## Záver

Návrhy nie sú o nástrojoch.
Sú o dvoch vetách, ktoré sa v tomto strome už raz ukázali ako pravdivé
**dokument, ktorý nikto nečíta strojom
zhnije** (lekalo to vie od `adapters 0`
docs od `DocsCatalogContractTest`) a **brána, ktorá nemá jedného vlastníka
sa raz začne opravovať na dvoch miestach** (`1.58.2` na štyroch).

Prvý návrh je preto test, druhý je súbor, tretia je veta.
Ani jeden z nich nepridáva nástroj
a to je pri toolchaine, ktorý má 15 CI jobov, ten najlepší možný výsledok.
