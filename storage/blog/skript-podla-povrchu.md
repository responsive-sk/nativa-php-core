---
title: "Skript podľa povrchu: JavaScript, ktorý stránka naozaj potrebuje"
date: "2026-11-25"
tags: ["nativa", "themes", "javascript", "vite", "assets", "brány", "architektúra"]
excerpt: "Minulé kolo skončilo vetou, že JavaScript sa nedelil. Toto je kolo, ktoré ju zobralo vážne: Griffe poslal dva landingové elementy do vlastného vstupu a erik s jednotkou presunuli Markdown editor do vstupu článku. Článok na Griffe spadol z 28 889 na 9 439 bajtov skriptu, bežné stránky eriku a jednotky stratili 54 percent, a test, ktorý by stratu skriptu nezachytil, sa zmenil na test, ktorý ju zachytí."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architektúra"
published: true
---

Minulý článok o povrchoch stránok skončil jednou otvorenou vetou:
JavaScript sa nedelil, pretože povrch mení pravidlá, nie správanie,
a rozdeliť skripty je ďalšie kolo s vlastným meraním.
Toto je to kolo.

Prišlo v dvoch commitoch z 25. novembra.
Prvý presunul landingové elementy Griffeho na domovskú stránku,
druhý presunul Markdown editor eriku a jednotky na povrch článku.
Oba robia to isté: skript prestal byť vlastnosťou témy
a stal sa vlastnosťou povrchu, ktorý naozaj vykresľuje jeho markup.

---

## Časť I > Kde boli bajty

`griffe.ts` importoval štyri veci: tri samostatné správania
(hlavičku, odhaľovanie a zvýrazňovač kódu)
a dva custom elementy `hero-layout` a `horizontal-scroll-hero`.
Len `GriffeTheme::homepage()` vykresľuje tie dva elementy.
Článok, listing ani bežná stránka ich v dokumente nemajú,
a napriek tomu sťahovali kód, ktorý ich definuje.

Skript sa načítal, parsoval a nič nevylepšil.
To je presne ten typ nákladu, ktorý sa v diffe nevidí,
pretože súbor je stále jeden a jeho veľkosť je stále platná.

| vstup | bajty | gzip -9 |
|---|---|---|
| `griffe.js` pred | 28 889 | nemerané |
| `griffe.js` po | 9 439 | 3 699 |
| `griffe-home.js` (len domov) | 19 450 | 5 637 |

Pohľad z renderera hovorí to isté v reči povrchov:

| povrch | pred | po |
|---|---|---|
| page | 28 889 | 9 439 |
| listing | 28 889 | 9 439 |
| article | 28 889 | 9 439 |
| home | 28 889 | 28 889 |

Domovská stránka platí presne toľko čo predtým,
len to platí v dvoch súboroch namiesto jedného.
Každý iný povrch stratil 19 450 bajtov,
čo je 67 percent skriptu, ktorý predtým označoval.

---

## Časť II > Prečo split nepotreboval PHP

`Themes\PageSurface` už vedel o skriptoch povrchu.
`AssetManager::jsUrlsForSurface()` označí `<jsKey>-<surface>`,
keď ho manifest menuje, a inak padne späť na základný vstup.
Landingový vstup je preto len vstup buildu s menom `griffe-home`
a v PHP sa nezmenil ani riadok.

To nie je náhoda, ale dôsledok rozhodnutia z minulého kola:
povrch je meno, ktoré vlastní trieda, nie odhad z URL.
Keď sa k menu pridá záznam v manifeste, renderer ho nájde.
Keď sa nepridá, správanie zostane presne také ako predtým.

Split je čistý aj z druhej strany.
Dva elementy importujú len `utils/css`
a tri správania neimportujú nič.
Build teda môže vygenerovať dva samostatné klasické skripty
namiesto jedného súboru so zdieľaným chunkom.
Zdieľaný chunk by bol problém, nie optimalizácia:
odložený `<script>` bez `type="module"`
ho na baseline Chrome 70 nevykoná.

Oba elementy pri importe len definujú tag.
Ani jeden z nich nečíta DOM,
takže poradie registrácie je poradie, v ktorom sa objavujú
v dokumente, a nič iné na ňom nezávisí.

---

## Časť III > Tá istá chyba v editore

Griffe nebol jediný.
Erik aj jednotka importovali `initInlineEditor()` do základného vstupu.
Editor je autorov panel s náhľadom nad `<textarea name="body">`
a tá textarea existuje len na článku, ktorý čitateľ môže upraviť.
Domovská stránka, listing aj každá bežná stránka teda sťahovali
Markdown renderer, toolbar a panel náhľadu, ktoré sa nemali k čomu pripojiť.

Editor sa presunul do vstupu povrchu článku.
Ten dnes importuje aj vlastný stylesheet,
takže jeden riadok manifestu nesie CSS aj skript
a dve veci sa v builde nemôžu rozísť.

| téma | povrch | pred | po |
|---|---|---|---|
| erik | page, home, listing | 22 322 | 10 176 |
| erik | article | 22 322 | 22 412 |
| jednotka | page, home, listing | 22 687 | 10 537 |
| jednotka | article | 22 687 | 22 773 |

Nepovrchy článku stratili 12 146 bajtov na eriku
a 12 150 bajtov na jednotke, čo je 54 percent skriptu.
Článok zaplatí o 90 a 86 bajtov viac, než platil:
druhý vstup zopakuje obal `DOMContentLoaded`
a to je celá cena splitu.

`inline-edit.css` zostáva v základnom sete.
To je rozhodnutie z kola 73 a tento článok ho neotvára.
Menil sa skript, nie to, kde je štýl.

Ostatných päť tém zostalo bez zmeny a je to zapísané:
boson 11 255, mono 17 875 a nativa 1 176 bajtov.
`config/page-budget.json` preto mení len riadky eriku, jednotky a Griffeho
a jeho dátum merania sa posúva na 2026-11-25.

---

## Časť IV > Test, ktorý to nemohol zachytiť

`PageAssetBudgetTest` meria CSS stránky proti manifestu na bajt,
ale kontrola JavaScriptu bola `js < strop`.
Domovská stránka, ktorá by prestala označovať `griffe-home.js`,
by namerala 9 439 bajtov, zostala pod stropom a prešla.
Test by teda odobril presne tú chybu, ktorú má split odstrániť.

Domovský prípad dnes porovnáva **označené skripty**
so základným vstupom nasledovaným domovským vstupom.
Stratený landingový skript je zlyhanie, nie úspora.
Rovnaký tvar už mal prípad článku, takže kontrola má dva zdroje,
ktoré sa musia zhodnúť.

`GriffeBuildFreezeTest` mal druhý slepý bod.
`theFrozenScriptIsASingleFileWithNoModuleSyntax` čítal len základný vstup,
čo stačilo, kým existoval jeden skript.
Nový `everyFrozenScriptEntryIsAClassicScript` prejde celý manifest
a na každom `.js` zázname overí, že súbor existuje
a neobsahuje `import` ani `export`.
Zdieľaný chunk, ktorý by presiakol do druhého vstupu, teda padne.

Test má aj poistku proti zelenému prázdnu:
`assertGreaterThanOrEqual(2, $entries)` zlyhá,
keď manifest menuje menej než dva skripty.
Bez nej by test, ktorý nič neprejde, vyzeral ako test, ktorý všetko prešiel.

Toto je malý detail s veľkým dôsledkom.
Keď sa bajty presunú, test, ktorý meria len strop,
zostane zelený a stratí zmysel.
Test musí porovnať mená, nie iba veľkosť.

---

## Časť V > Čo zostáva otvorené

Kolo meria to, čo presunulo, a necháva tri veci vedome bokom:

- **Mono a nativa nie sú rozdelené.**
  Nativiných 1 176 bajtov je jeden TOC scrollspy,
  ktorý vykresľuje len článok, takže bežná stránka nesie skript,
  v ktorom pre ňu nič nie je.
  Monových deväť správaní by si vyžiadalo povrch pre každé správanie
  a jeho bundle je zmrazený.
- **Starter téma `Frontend/packages/basic` importuje ten istý editor
  do základného vstupu.**
  Je to bundel jadra, nie jednej zo šiestich tém,
  a `config/page-budget.json` ho nepokrýva.
  Kolo ho preto zmeralo a nechalo tak.
- **Meno editora sa v každej téme opakuje.**
  `inline-editor.ts` je už bajt na bajt rovnaký v štyroch balíkoch
  a vstup povrchu je štvrtou kópiou tej istej znalosti.
  To je kópia, ktorú by mal vlastniť jeden súbor, nie štyri konfigurácie.

Posledná vec je nález, nie sľub.
`Frontend/packages/build/vite-theme-factory.ts` cituje
`bin/build/verify-build.php` ako miesto, ktoré overuje,
že vstupy nenesú zdieľaný JavaScript.
Taký súbor v strome neexistuje,
takže pravidlo držal len docblock a odteraz aj griffe test.

---

## Reprodukcia

```
pnpm --filter @nativa/theme-griffe build
pnpm --filter @nativa/theme-erik build
pnpm --filter @nativa/theme-jednotka build
composer test tests/Theme/PageAssetBudgetTest.php
composer test modules/Themes/Griffe/tests/GriffeBuildFreezeTest.php
composer test modules/Themes/Erik/tests modules/Themes/Jednotka/tests
jq '.ceilings.griffe, .ceilings.erik, .ceilings.jednotka' config/page-budget.json
```

Griffeho bundle nepostavil `make assets`, ale balík
`pnpm --filter @nativa/theme-griffe build`,
a zmrazený `public/dist/griffe/` je to, čo zapísal.
Ostatné dve témy sa stavali rovnako cez svoje balíky.

Kolo zmeralo tieto výsledky:
`PageAssetBudgetTest` 6 testov a 75 asertov bez skipu
(fallbackový prípad dnes meria kernel tému),
`GriffeBuildFreezeTest` 9 testov a 53 asertov,
testy eriku a jednotky spolu 37 testov a 272 asertov.
`pnpm --filter @nativa/theme-griffe typecheck` bol čistý,
`composer phpstan` a `composer cs-fix-dry` tiež.
Živý server vrátil `/dist/griffe/assets/griffe-U-QwI81-.js`
s 200 a 9 439 bajtmi
a `/dist/griffe/assets/griffe-home-Dy3-WO6T.js`
s 200 a 19 450 bajtmi.
Ani jeden manifest eriku a jednotky nemá kľúč `imports`,
takže žiadny vstup nezávisí od zdieľaného chunku.

---

## Záver

Minulé kolo povedalo, že JavaScript sa nedelil.
Bola to pravda a zároveň pozvánka:
skript sa nedelil preto, že sa nemeral dôvod na delenie.
Toto kolo ten dôvod našlo v markupe.

Dva landingové elementy existujú len na domovskej stránke.
Textarea editora existuje len na článku, ktorý sa dá upraviť.
Skript, ktorý ich obsluhuje, patrí presne tam.
Nie preto, že je veľký, ale preto, že inde nemá čo robiť.

Rozdiel medzi týmito dvoma vetami je celý článok:
**skript nie je vlastnosťou témy, ale povrchu, ktorý vykresľuje jeho markup.**
