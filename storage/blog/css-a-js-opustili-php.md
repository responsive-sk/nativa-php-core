---
title: "CSS a JS opustili PHP: stránka, ktorá nesie len svoje bajty"
date: "2026-11-24"
tags: ["nativa", "php", "css", "themes", "assets", "brány", "architektúra"]
excerpt: "Dva commity z jedného pracovného kola: trinásť súborov stavalo CSS a JavaScript vnútri PHP a potom každá stránka začala nosiť len štýl svojho povrchu. Kód, ktorý nevedel prečítať žiadny editor ani linter, sa presunul do súborov, a Bosonova domovská stránka spadla zo 45 899 na 22 762 bajtov inlinovaného CSS. Článok je záznam o oboch kolách, o bráne, ktorá ich drží, a o chybe, ktorú split odhalil."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architektúra"
published: true
---

Tento strom mal trinásť súborov, ktoré stavali CSS alebo JavaScript vnútri PHP.
Každý z nich bol heredoc v metóde, blok `<style>` v šablone, alebo stylesheet
poskladaný z reťazcových fragmentov.
Ani jeden z nich nebol súbor.

To je celý problém a je menší, než sa zdá.
Súbor je vec, ktorú vidí editor, linter aj cache.
Reťazec v metóde nevidí nikto, takže v ňom môže zostať preklep, nepoužité
pravidlo aj celý komentár, ktorý už neplatí.
Sken, ktorý to spočítal, našiel 19 tiel tagov
a **37 621 bajtov** kódu, ktorý žiadny nástroj v tomto strome nemeral.

Druhá polovica príbehu prišla v tom istom kole o pár hodín neskôr.
Keď už boli štýly v súboroch, dalo sa s nimi pohnúť ďalej:
každá stránka začala nosiť **len štýl svojho povrchu**, nie celú tému.

---

## Časť I > Prečo kód v PHP nevidí žiadny nástroj

Formálne bolo všetko v poriadku.
`make lint` prešiel, PHPStan level 9 prešiel, testy boli zelené.
Lenže nástroje merali niečo iné, než sme si mysleli:

| nástroj | čo naozaj číta | čo v PHP reťazci nevidí |
|---|---|---|
| `make lint` (`php -l`) | syntax súboru | nič, reťazec je platný PHP |
| PHPStan | typy | obsah reťazca je preň `string` |
| `php-cs-fixer`, `rector` | štruktúru kódu | vnútro reťazca neformátujú |
| prehliadač a cache | súbor s URL | inline blok nemá URL ani `ETag` |

Kód v reťazci teda nie je neplatný.
Je **nemerateľný**, a to je iná trieda problému.
Človek, ktorý hľadá pravidlo `.je-hero__title`, ho v `SiteHomepageSurface.php`
nenájde, pretože tam nie je ako pravidlo, ale ako text.

Sken prešiel `src/**` a `modules/**`, preskočil `tests/`, nainštalované kópie
pod `src/Modules/` a adresáre s assetmi.
Odstránil komentáre, zo šablón zhodil `<?php ... ?>` výstup a každý súbor
počítal raz.
Našiel trinásť súborov, z toho sedem väčších než 600 bajtov:

| súbor | čo v ňom bolo | bajty |
|---|---|---|
| `modules/Site/.../SiteHomepageSurface.php` | CSS a loader diagramu | 13 689 |
| `modules/Media/.../media-picker.phtml` | JS pickeru | 8 432 |
| `modules/Cardboard/.../compact.phtml` | kritické CSS a `var recent` | 7 183 |
| `modules/Users/.../AuthTemplate.php` | `pageCss()` bez tagu | 4 300 |
| `modules/Cardboard/.../layout.phtml` | kritické CSS a `var recent` | 2 771 |
| `src/Themes/FrontendLayout.php` | CSS čítačky a theme-init JS | 2 078 |
| `modules/Themes/Mono/.../MonoTheme.php` | pre-paint skripty | 1 235 |

Zvyšných šesť je pod 600 bajtov a žiadny z nich nie je maličkosť:
`toast()`, picker cieľového poľa, konfigurácia htmx, pre-paint skripty dvoch
tém a limity upload formulára.

---

## Časť II > Prípad, ktorý skener s tagmi minie

Najzaujímavejší z trinástich bol `AuthTemplate::pageCss()`.
Neobsahoval `<style>` ani `<script>`, a napriek tomu to bol celý set pravidiel:

```php
return '.auth-card{...}' . '.auth-card__title{...}' . '.auth-card__hint{...}';
```

Skener, ktorý hľadá len telo tagu, by ho nenašiel.
Skener, ktorý hľadá len `heredoc`, tiež nie.
Kód sa teda skrýval v treťom tvare: **reťazec, ktorý je stylesheet.**

To je dôvod, prečo brána v Časti V nepozná jeden tvar, ale dva.
Prvý je telo tagu, ktoré prežije odstránenie PHP výstupu.
Druhý je literál, v ktorom sú v zložených zátvorkách aspoň dve vlastné
vlastnosti (`--var: value`).
Bez druhého pravidla by sa `pageCss()` vrátil pri prvom refaktore a brána by
bola zelená.

---

## Časť III > Čo nahradilo reťazce

Každé telo je dnes asset, ktorý sa číta cez `View\InlineCss::file()` alebo
`View\InlineJs::file()`.
Tagy `<style>` a `<script>` zostali v PHP a majú dôvod:
CSP nonce je PHP literál, ktorý bezpečnostný middleware nahrádza pre každú
odpoveď zvlášť.
Presunúť tag by znamenalo presunúť aj nonce a to je iná zmena.

Assetov je trinásť a majú spolu **36 142 bajtov**.
Súčet je menší než pôvodných 37 621 z jedného dôvodu,
ktorý stojí za vysvetlenie:
`InlineCss` pred vložením odstraňuje komentáre.
Najjasnejší prípad je `homepage.css`: 12 183 bajtov na disku proti 13 208
bajtom CSS v metóde.
Rozdiel je komentár, ktorý vysvetľoval orb.
Pravidlá sú tie isté, len komentár už nie je súčasťou odpovede.

Dynamické hodnoty sa nesplietli do tela funkcie, ale odišli do `data-*`
atribútov:

- ID textarey pickeru, URL indexu a URL uploadu,
- limity upload formulára,
- `recent` zoznam v Cardboarde,
- CSRF token pre htmx,
- chunk diagramu na domovskej stránke,
- ID vstupného poľa cieľa pickeru.

Rozdiel je v tom, kde je hranica.
Reťazec s vloženou hodnotou sa musí znovu poskladať pri každom volaní a každá
hodnota v ňom je ďalší literál na prečítanie.
Atribút je deklarácia, ktorú si skript prečíta aj otestuje.

`CardboardLayout::script()` zostal generický obal tagu, pretože mu Blog
posiela hotový JavaScript.
Ten volajúci sa nezmenil a meniť ho v tomto kole nemalo zmysel.

---

## Časť IV > Brána, ktorá počíta presne

`tests/Architecture/NoCssJsInPhpTest.php` skenuje PHP a PHTML zdroje na oba
tvary z Časti II.
Ignoruje JSON-LD, atribúty `style="..."` a komentáre, a jeho fixtúry
dokazujú oba smery: nález aj čistý súbor.

Baseline bol 13 a dnes je **0**, zapísaná ako presný počet.
To znamená dve veci naraz:
nové telo padne hneď, a migrácia, ktorá sa neprejaví v čísle, padne tiež.
Brána, ktorá by hovorila len „menej ako 13“, by sa dala obísť tým, že sa jedno
telo presunie a druhé pridá.

Dva testy sa presunuli **s kódom, nie okolo neho**.
`SiteHomepageTest` číta chunk diagramu z `data-diagram-chunk` a overuje, že ho
modul odtiaľ importuje.
Pôvodná asercia hľadala literál URL vo vnútri vygenerovaného skriptu.
To je niečo, čo vie vyrobiť len inline telo.
`MarkClassContractTest` číta dva kritické stylesheety Cardboardu
z `assets/styles`, keďže ich layout už nenesie.

Toto je malý detail s veľkým dôsledkom.
Keď sa kód presunie, test, ktorý zostal na starom mieste, zeleno potvrdzuje
staré správanie.
Presunúť ho znamená povedať, čo je teraz pravda.

---

## Časť V > Druhé kolo: povrch stránky

Keď boli štýly súbory, ukázalo sa, že každá stránka inlinuje **celú tému**.
Domovská stránka, článok, listing aj výsledok hľadania mali ten istý
stylesheet, pretože téma mala jeden a layout nepoznal inú odpoveď.

`Themes\PageSurface` dnes vlastní štyri mená: `PAGE`, `HOME`, `LISTING`,
`ARTICLE`.
Akcia svoj povrch **deklaruje** a layout sa ho opýta.
Nič ho nehádá z URL, a to nie je štýl:
URL je routa modulu a jadro ju nesmie poznať.
`make mago-guard` padne na import, ktorý by to jadru dovolil.

`PAGE` je fallback a znamená „kompletný set témy“.
Stránka, ktorá nič nedeklaruje, teda platí presne to, čo platila pred
povrchmi, a téma bez splitu je bajt na bajt tá istá.

`FrontendLayout::render(..., ?string $surface)` číta dva zdroje:
stylesheet pomenovaný `<base stem>-<surface>.css` vedľa základného setu,
a záznam v buildu s kľúčom `<jsKey>-<surface>`.
Oba sú voliteľné a oba padajú späť na základ, čo je presne to, čo drží
kompatibilitu.

---

## Časť VI > Meranie: koľko bajtov nesie ktorá stránka

Merané s `make assets` a šiestimi nainštalovanými témami, z dočasnej sondy,
ktorá vykreslila `FrontendLayout` pre každú tému a povrch a spočítala blok
`<style>` plus každý `<script src>`:

| téma | page | home | listing | article |
|---|---|---|---|---|
| kernel | 2 483 | 2 483 | 2 483 | 2 483 |
| boson | 22 762 | 35 538 | 25 182 | 30 790 |
| jednotka | 36 967 | 36 967 | 36 967 | 43 912 |
| mono | 37 158 | 37 158 | 37 158 | 42 007 |
| griffe | 24 613 | 24 613 | 24 613 | 25 431 |
| erik | 31 118 | 31 118 | 31 118 | 36 502 |
| nativa | 9 159 | 9 768 | 9 507 | 11 390 |

Baseline 23. novembra bol jedno číslo na tému: 45 899, 43 907, 42 002,
25 426, 36 497, 12 338 a 2 482.
Bosonova domovská stránka sa teda posunula z 45 899 na 22 762, Nativa
z 12 338 na 9 159.
Žiadny článok nenarástol o viac, než koľko stojí set, ktorý jeho povrch
naozaj pridáva.

JS je všade nezmenený.
Split hýbe pravidlami, nie správaním, a to je dobrá vlastnosť na oddelenie:
keby sa pohli obe veci naraz, nevedeli by sme, ktorá zmenila výsledok.

Galéria ukazuje to isté vo veľkosti dokumentu:

| stránka | pred | po |
|---|---|---|
| boson home | 77 523 | 67 159 |
| boson article | 66 806 | 51 694 |
| nativa home | 20 709 | 18 136 |
| nativa article | 21 562 | 20 611 |
| mono home | 69 612 | 64 765 |
| mono article | 69 385 | 69 387 |

Erikove stránky v galérii sú ručne písané a znova sa nevykresľujú.
Griffeho domovská stránka je zložená z web komponentov, takže ani jeden
z tých dvoch nemá riadok.
Absencia riadku nie je nula, je to „toto sa tu nemeria“.

---

## Časť VII > Chyba, ktorú split odhalil

Prvý priechod Bosonu presunul do listingu aj vyhľadávacie pole mobilného menu.
Pole je súčasťou shellu na každej stránke, takže článok stratil reset výšky,
ktorý listing mal.
Pravidlá sa vrátili do základného setu a dôvod je napísaný vedľa nich.

Z toho je pravidlo, ktoré sa dá použiť aj inde:

> Blok sa presúva len vtedy, keď ho používa každá stránka povrchu
> a žiadny iný povrch nie.

Nie je to pravidlo o veľkosti bloku ani o tom, kde ho autor napísal.
Je to pravidlo o množine stránok, ktoré ho potrebujú, a tú množinu vie
pomenovať len deklarácia, nie odhad.

---

## Časť VIII > Rozpočet, ktorý sa nedá obísť

`config/page-budget.json` vlastní jeden strop na tému a povrch, v bajtoch
inlinovaného CSS a tagovaného JS.
Strop, ktorý sa rovná riadku `page`, hovorí „tento povrch zostáva v základnom
sete“.
Nižší strop hovorí „tento povrch má vlastný záznam“.
Rozpočet je teda deklarácia, nie odhad, a manifest s ním musí súhlasiť:
téma nemôže tvrdiť split, ktorý nepostavila, a split nemôže prísť bez stropu.

`tests/Theme/PageAssetBudgetTest` číta očakávanie **z buildu**,
nie z renderera.
Zoberie `public/dist/<bundle>/manifest.json` a CSS súbory, ktoré menuje,
poskladá ich v poradí kaskády a porovná s bajtmi v `<style>` bloku dokumentu.
Test, ktorý by sa `FrontendLayout` opýtal, čo sa chystá inlinovať, by súhlasil
s každou chybou, ktorú by layout mal.

Aj tu je poistka proti zelenému prázdnu:
aspoň jedna postavená téma musí zúžiť každý povrch.
Bez nej by súbor s nulou nálezov znamenal, že sa nemeriate nič.

---

## Časť IX > Čo tento článok netvrdí

Dve veci treba povedať nahlas, pretože sa ponúkajú samé:

- **Nie je to meranie rýchlosti.**
  Zmerané sú počty súborov a bajty, nie časy paintu.
  Menší dokument je dobrý predpoklad, nie dôkaz, a LCP si vyžaduje vlastné
  meranie.
- **Atribúty `style="..."` zostali.**
  Nesú hodnoty jedného elementu, nie stylesheet.
  Presunúť ich je iná zmena s iným rizikom a v tomto kole sa nerobila.
- **JS sa nedelil.**
  Povrch mení pravidlá, nie správanie, takže skripty sú na všetkých stránkach
  tie isté.
  Rozdeliť ich je ďalšie kolo s vlastným meraním.

---

## Reprodukcia

```
make assets                       # manifesty a CSS súbory pod public/dist
composer test tests/Architecture/NoCssJsInPhpTest.php
composer test tests/Theme/PageAssetBudgetTest.php
cat config/page-budget.json       # jeden strop na tému a povrch
```

Overené na hotovom strome:
`composer test` pri **1 631 testoch a 9 926 asertoch** s dvadsiatimi skipmi
za neprítomné moduly, `make lint`, `composer phpstan`, `cs-fix-dry`
a `rector-dry` čisté.
Galéria sa vykreslila na 56 strán a `--check` hlási
`identical=56 diverged=0`.

---

## Záver

Dve kolá, jedna otázka: **kto vlastní bajty, ktoré stránka pošle?**
Prvé kolo odpovedalo „súbor, nie reťazec“.
Druhé odpovedalo „povrch, nie téma“.
Ani jedna odpoveď nebola o tom, že by predchádzajúca bola hlúpa.

Reťazec v PHP bol v poriadku, kým ho nikto nemusel prečítať.
Celá téma bola v poriadku, kým sa nemerala stránka, ktorá ju nepotrebuje.
Rozdiel medzi týmito dvoma vetami je celý článok:
**problém nezačína tým, že kód je zlý, ale tým, že ho nikto nemeria.**
