---
title: "Rust a Lekalo pre deti ... diel 10: agentovi stačí štyri vydychy"
series: "Rust & Lekalo"
series-part: "10"
date: "2026-09-16"
version: "1.0.2"
tags: ["rust", "lekalo", "agenti", "hash", "architektura"]
excerpt: "Myšlienka z Rust fingerprintu sa stala nástrojom: revízny report z gitu, záznam neúspešných gateov a pokračovanie z uloženého tailu. Menej iterácií nie preto, že agent háda lepšie, preto, že stav číta namiesto hádania."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "agent-state-protocol"
published: true
---

V [dieli 9](/blog/lekalo-bindings-pre-deti) dostali témy routy v modeli.
Dnes nič nepridávame do modelu. Dnes sa stáva niečo redšie: **myšlienka
z disku sa stáva nástrojom**. Pôvodný text sľuboval, že revízny hash
zníži iterácie agenta z jedenástich na štyri. Čísla boli vymyslené.
Nápad nebol.

## Čo sa stalo

Agent sa k úlohe vracia a prvú iteráciu míňa na otázku: *čo sa zmenilo?*
Gate spadne a ďalšia iterácia míňa na druhú otázku: *kde?* Obe odpovede
existujú deterministicky. Agent si ich doteraz musel znovu vyrábať.

Vznikli tri nástroje (žiadny nový hashovací kód, žiadny graf hrán):

| Nástroj | Čo robí |
|---|---|
| `bin/revision-report.php` | zmeny z `git status` zoskupí do modulov + digest |
| `bin/gate-run.php` | pustí gate, záznam zlyhania (exit + digest + tail) |
| `make revision-report` | oboje na jeden príkaz, `--json` pre stroje |

Report má v hlavičke jednoduchý kontrakt: *informatívny, quality gates
bežia vždy celé*. Záznam zlyhania je pomôcka na čítanie, nie výmena
verifikácie.

## Čo sa neurobilo

Pôvodný plán mal `openspec-hash.php` a `edge-diff.php`. Odmietnuté
vedome:

- **git už pozná stav stromu**: content-based, imúnny voči mtimu;
  nový hash by sa len mohol rozhádzať s gitovým pohľadom,
- **`lekalo.lock` je už hash kontraktu**: kopírovať ho do druhého
  súboru znamená dve pravdy namiesto jednej,
- **hrany potrebujú graf**: v PHP monolite žiadny nie je; vymyslieť ho
  kvôli reportu je mini-Bazel.

Presne piata dohoda: architektúra sa rieši na sto percent, alebo sa
nerieši. Sto percent tu znamenalo menej kódu, nie viac.

---

Revízny report ostáva skvelý na zisťovanie rozsahu zmeny, len nie na
rozhodovanie, čo je správne. Rozhodovať zostáva na testoch a na ľuďoch.

---

## Živý beh: štyri vydychy

Deň po nasadení prišiel prvý reálny test: do `src/` sa vedome dostal
súbor, ktorý PHPStan level 9 odmieta.

```
$ make revision-report
Core: 1
  ?? src/Modules/Core/Application/Services/DemoResumeLoopViolation.php
digest: sha256:922c78a4…

$ make gate-run GATES="phpstan"
FAIL (exit 1) — recorded; resume: php bin/gate-run.php --output phpstan

$ php bin/gate-run.php --output phpstan
== recorded failure: phpstan (exit 1) ==
Line   src/Modules/Core/Application/Services/DemoResumeLoopViolation.php
...    Method broken() has parameter $noType with no value type specified.

$ rm src/Modules/Core/Application/Services/DemoResumeLoopViolation.php
$ make gate-run GATES="phpstan"
OK (1.0s)

$ php bin/revision-report.php
(no failed-gates section — záznam sa vyčistil sám)
```

Štyri kroky: report, pád, čítanie tailu, oprava. Žiadne hádanie,
žiadne znovupúšťanie celých gateov. A všimni si posledný krok, záznam
sa zmaže sám, keď gate prejde. Nástroj, ktorý si sám uprace po sebe.

## Prečo to funguje

Lebo stav je deterministický. Rovnaký strom → rovnaký report → rovnaký
digest. Docker alebo CI resetuje mtimes? Report sa nezmení. HashMap
zmení poradie? Sort pred hashom. Gate spadne na výpise s časom? Digest
identifikuje presný zaznamenaný výstup, posledný záznam vyhráva,
priechod čistí.

Krok číslo jeden agenta prestal byť hádaním a stal sa čítaním. Celé
tajomstvo stojí na determinizme, a presne tak funguje [Cargo
fingerprint](https://doc.rust-lang.org/cargo/reference/build-cache.html):
negeneruj znovu to, čo je fresh.

## Záznam nahrádza gate?

Nikdy. `make pre-commit` beží vždy celý, lint, analýza, testy,
lekalo. Záznam zlyhania je poznámka na okraji, nie rozhodnutie. Keď
report hovorí „phpstan spadol“, agent číta tail a opravuje; keď hovorí
„čisto“, agent púšťa pre-commit ako vždy. Rozdiel medzi AI agentom a AI
agentom s kontraktom nie je v inteligencii, je v tom, čo si smie
zapamätať a čomu smie veriť.

---

→ [Diel 9, témy dostali routy](/blog/lekalo-bindings-pre-deti),
bindingy, scenáre a gramatický limit
→ [Cargo build caching](https://doc.rust-lang.org/cargo/reference/build-cache.html),
fingerprint mechanika, z ktorej myšlienka vyšla

## Zdroje

- `bin/revision-report.php` + `bin/gate-run.php`: agent-state tooling
  (zero-dep, mimo phpstan paths, podľa precedensu `audit-rhythm.php`)
- [Diel 9, témy dostali routy](/blog/lekalo-bindings-pre-deti),
  bindingy, scenáre a gramatický limit
- [Cargo build caching](https://doc.rust-lang.org/cargo/reference/build-cache.html),
  fingerprint mechanika, z ktorej myšlienka vyšla
