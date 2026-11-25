---
title: "Stav mimo kontextu ... keď pamäť žije v súboroch"
date: "2026-09-17"
version: "1.0.2"
tags: ["agents", "git", "testing", "architektura", "workflow"]
excerpt: "Kontext AI asistenta je mechanika, nie pamäť ... zomrie medzi sessions a žerie tokeny ako kachle. Stav preto externalizujeme: revízna správa z git content modelu, digesty namiesto mtimes, gate záznamy, z ktorých sa iterácia obnovuje. Na reálnych nástrojoch z tohto repa."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "state-out-of-context"
published: true
---

Si vývojár, ktorý pracuje s AI asistentom. Ráno mu napíšeš „pokračuj“.

A on nič nevie. Včerajšok je preč, kontextové okno sa zavrelo a s ním
každá myšlienka, ktorá v ňom žila.

Alebo horšie: nezavrelo sa úplne. Asistent si nesie polovicu včerajška,
kde-tade zmiešanú s polovicou minulého týždňa ... a každý nový krok
platí tokenmi za pamäť, ktorá už nerieši nič.

Toto repozitár má na to pravidlo: **stav nežije v kontexte. Žije
v súboroch.**

## Kontext je mechanika, nie pamäť

Okno asistenta vyzerá ako pamäť a správa sa ako kachle.

Každá iterácia ho žerie. Čím dlhší rozhovor, tým drahšia každá ďalšia
myšlienka ... a naraz je plný. Úprimná porovnávacia tabuľka:

| | kontextové okno | súbor na disku |
|---|---|---|
| prežije session | nie | áno |
| cena rastie s dĺžkou | áno | nie |
| vieš ho zdieľať | nie | `git push` |
| vieš ho hashovať | nie | `sha256` |

Preto v tomto repe žijú dva nástroje, ktoré stav píšu von,
`bin/revision-report.php` a `bin/gate-run.php`. Oba vznikli z jednej
vety v `AGENTS.md`: *never re-scan the tree to re-derive what changed*.

## Pravda nesie adresu, nie čas

Prvá otázka po prerušenej práci: čo sa zmenilo?

Lákavá odpoveď je čas súboru. `mtime`: keď sa to posledné dotklo.

A to je pasca. `mtime` hovorí, kedy sa niekto súboru dotkol, checkout,
`touch`, synchronizácia stromu, všetko ho prepíše. Nerieši, **čo**
v súbore je. Falošná random stopa, presne ako boolean bez kontraktu:
`true` ... ale odkiaľ, kedy, či to ešte platí?

`revision-report` číta iný zdroj, **git content model**. Zoznam zmien
(`git status --porcelain`) zoradí, zoskupí do modulových vedier a nad
výsledkom spočíta hash:

```php
// bin/revision-report.php — identity token stavu stromu
$digestInput .= $change['status'] . "\t" . $change['path'] . "\n";
$digest = hash('sha256', $digestInput);
```

Kontrakt stojí v docblocke: *identical working tree + identical agent
state → byte-identical output. Never mtimes.*

Digest je token pravdy s typom, viaže sa na bajty, nie na čas. Dve
rovnaké zmeny vydajú rovnaký hash ... rovnako ako zamrazená pravda
z [dielu 1 série](/blog/preco-rust-pre-deti).

## Brána, ktorá si pamätá bolesť

Druhý nástroj rieši pokračovanie po zlyhaní.

Brány bežia cez `bin/gate-run.php`: a keď jedna padne, záznam sa
nepíše do kontextu rozhovoru. Píše sa na disk:

```text
// storage/cache/agent-state/<gate>.json
{ "gate": "test", "exit": 1, "digest": "b48a5dc0…", "tail": "…" }
```

Tri polia a každé nesie jeden typ pravdy. `exit` hovorí *že*, `tail`
hovorí *čo*, `digest` viaže obidve na konkrétne bajty výstupu.

Ďalšia iterácia teda nebeží brány naslepo. Číta záznam:

```bash
php bin/gate-run.php --output test
```

A dostane presne ten kus sveta, ktorý ju zabil, chvost výstupu,
bez opakovania celej sady.

Nech sa to aj napíše. Včera padol test gate s 190 chybami, a v chvoste
záznamu bola jedna veta: provider dáva slug s príponou, porovnáva sa
bez nej. 190 falošných chýb, jeden skutočný koreň.

Bez záznamu by ďalšia iterácia buď celú sadu opakovala (tokeny), alebo
by ju čítala z umierajúceho kontextu (haluz). So záznamom prišla priamo
k riadku s bugom.

Zelený re-run záznam zmaže. Stav pamäti brány je teda vždy dnes.

## Prečo sorted ... a prečo json

Obe volby v nástrojoch nie sú kozmetika.

Sort pred hashom garantuje, že poradie riadkov v `git status` nikdy
nezmení identitu stavu. Ten istý strom vydá ten istý digest, či git
vypísal súbory v ktoromko poradí.

JSON formát záznamu je zase zmluva medzi pisateľom a čitateľom,
`RevisionState` je jediný modul, ktorý formát pozná, takže sa nemôže
rozbiehať medzi nástrojmi. Jeden spisovateľ, dvaja čitatelia
(`revision-report`, agenti, ľudia), nula driftu.

## Kedy to neplatí (a povinná úprimnosť)

Správa a záznamy sú **informácia, nie substitúcia**.

Nahrádzajú re-scanning stromu a slepé re-behanie. Nenahrádzajú brány.
`make pre-commit` v tomto repe beží vždy celý, lint, analyze, test,
lekalo, nezávisle od toho, čo hovorí cache. Záznam bez behu nie je
zelený, je len neuvedený.

Druhá úprimnosť: digest identifikuje **stav, nie pravdu**. Ak sa strom
zmení po tom, čo sa záznam napísal, chvost v ňom popisuje svet, ktorý
už neexistuje. Token nesie svoj čas, preto zelený beh záznam maže,
namiesto aby ho nechal starnúť.

A tretia: spätná archeológia, kopanie histórie až do koreňov kontraktu
cez `git log -S`: je tu jednorazová akcia s cache, nie živý proces.
Stojí tokeny a spusti sa, keď ju treba. Tým, že ju treba stále, nie je.

## Prakticky

1. **Začni iteráciu správou** ... `make revision-report` (alebo
   `--json`), čítaj len hlásené súbory, strom nepreskakuj znova.
2. **Brány cez `gate-run`** ... padnutá brána píše záznam, ďalší beh
   obnov z `--output <gate>`, nie naslepo.
3. **Skrz na `make pre-commit`** ... správa a záznamy sú mapa, nie
   terén. Kvalita sa overuje celá, vždy.

Stav potom odpovedá na otázku „kde sme“ ... aj keď kontext dávno
zomrel.

## Zdroje

- [Git, pickaxe, -S](https://git-scm.com/docs/git-log) ... hľadanie zmien počtu výskytov v histórii
- [SHA-256, NIST FIPS 180-4](https://nvlpubs.nist.gov/nistpubs/FIPS/NIST.FIPS.180-4.pdf) ... hash, ktorý nesie identitu bajtov
- [Unix, mtime](https://en.wikipedia.org/wiki/Stat_(system_call)) ... časová stopa súboru a jej pasce
- [Nativa Minimal Documentation](/docs/getting-started) ... getting started guide tohto kitu
- [docs/blog-voice.md](/docs/blog-voice) ... príručka hlasu, ktorej mechanickú polovicu stráži test
