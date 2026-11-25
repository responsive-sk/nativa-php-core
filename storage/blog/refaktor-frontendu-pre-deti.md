---
title: "Refaktor frontendu pre deti ... diel 1: moonshine padol do sekcií"
date: "2026-09-13"
version: "0.1.1"
tags: ["php", "refactoring", "moonshine", "architektura", "frontend"]
excerpt: "751-riadkový Shell a 659-riadkový Renderer v téme moonshine padli do sekcií, kritické CSS v ôsmich triedach s dokázanou byte-paritou, chrome vo vlastných triedach a renderer ako facade bez jedinej mágie. A cestou refactor ukázal, prečo sa zlé názvy tried lubujú s PHP 8.5."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "frontend-refactor-part-1"
published: false
---

> Toto je diel 1 série **Refaktor frontendu**. Ide o sériu o tom, ako sme
> rozbíjali príliš veľké súbory tém na sekcie, bez jediného vizuálneho
> zhovorania. Všetky čísla sú živý výstup z repozitára, nič nie je
> vymyslené. [Čítajte aj anglickú verziu](/en/blog/frontend-refactor-part-1).

## 1. Prečo: a poctivé rozlíšenie pravidiel

Všetko to začalo otázkou: „príliš veľké files, 600+ riadkov > RULES!“.
Pravda je nuancovaná a stojí za to ju povedať narovnako:

- **Vynucované pravidlo v projekte je metódové**: chunky rule, telo
  metódy max 60 riadkov, stráži ho architektonický test. Tému moonshine
  to pravidlo nespálilo. Žiadna CI brána ne_svietila_.
- **600+ riadkov na súbor bol náš vlastný threshold**: pravidlo
  údržby, nie stroja. `Shell.php` mal 751 riadkov, `Renderer.php` 659,
  `moonshine.css` 1315. Editovať jednu vec znamenalo scrollovať
  1300 riadkov.

Práve preto refactor neskončil len presúvaním kódu, ten threshold
z neho robí architektonický test, ktorý spadne, ak sa niektorý súbor
témou kedy vrastie späť cez 600 riadkov. O tom v dieli 3.

## 2. Mapa pred a po

```
Pred                              Po (moonshine)
─────────────────────────────     ─────────────────────────────
Shell.php            751 riadkov  Shell.php            229 (facade)
Renderer.php         659 riadkov  Renderer.php         293 (facade)
moonshine.css       1315 riadkov  CriticalCss/Ms*    8 tried
                                  Chrome/{Nav,Controls,Scripts}
                                  Sections/{Support,Cards,
                                            Widgets,Article,
                                            Hero,BlogIndex}
```

Súboj: žiadna metóda sa nezmenila, žiadna nezmyzla, nič sa nespájalo.
Menili sa adresy, nie správanie.

## 3. Tri druhy sekcií

**CriticalCss/Ms\***, osem tried (`MsTokens`, `MsSidebar`, `MsTopbar`,
`MsMobile`, `MsContent`, `MsWidgets`, `MsArticle`, `MsToc`), každá má
jednu metódu `render(): string` s heredoc-om presunutým slovo od slova
z pôvodných helperov `criticalCssMs*()`. Facade `Shell::criticalCss()`
ich reťazí v presnom pôvodnom poradí.

**Chrome/**: nábytok shellu: `Nav` (sidebar navigácia, hamburger,
collapse), `Controls` (theme toggle, locale prepínač, `chromeLabels()`,
deferred CSS) a `Scripts` (inicializácia témy, drawer, back-to-top).

**Sections/**: telo renderera: `Support` (zdieľaná slovná zásoba:
`t()`, dátumy, href-y, wrappery), `Cards`, `Widgets`, `Article`, `Hero`,
`BlogIndex`. `Renderer.php` zostáva facade, každá z ~20 verejných
statických metód je explicitný jednoriadkový delegate. Žiadne `__call`,
to je C-pattern zákaz: transparentnosť pred skratkou.

## 4. Byte-parita: pasca, ktorá číha v heredoc-och

Jediný jednoduchý invariant tejto série: **kritické CSS musí vyjsť
byte-identicky**. Pred presunom snapshot:

```bash
php -r 'require "src/init.php";
file_put_contents("/tmp/ms-critical-before.txt",
  Themes\moonshine\Shell::criticalCss());'
# 41 140 bajtov
```

Po presune porovnanie, a prvý beh hlásil rozdiel. Príčina je jemná
a typická pre PHP heredoc-y: **uzatvárací marker heredoc-u zje
koncový nový riadok**. Pôvodný reťazec ôsmich heredoc-ov tak mal
medzi časťami miesta s *nulovým* newline (a jedno miesto tokens→sidebar
jeden, lebo boli v tom istom heredoc-e). Nové triedy každý písali
s vlastným odriadkovaním, a rozdiel bol hotový.

Druhá pasca bola v mojom vlastnom skripte: `rindex("/**")` pochytil
o jeden docblock skôr, lebo jedna z pôvodných metód **docblock nemala**.
Python to ticho vystrihol, PHP to ticho prehltilo, a snapshot to
odhalil za pár sekúnd.

Presne preto parity dril nie je formalita:

```bash
php -r 'require "src/init.php";
echo Themes\moonshine\Shell::criticalCss()
  === file_get_contents("/tmp/ms-critical-before.txt")
  ? "BYTE-PARITY OK" : "MISMATCH";'
# BYTE-PARITY OK (41 140 bytes)
```

## 5. PHP 8.5: keď sa trieda lubuje s importom

Najzaujímavejšia chyba prišla až z PHPStanu, ktorý cez bootstrap
načítava **každý** súbor. `Sections/Article.php` deklarovala triedu
`Article` a zároveň robila `use Modules\Blog\Domain\Entities\Article;`,
pod rovnakým krátkym menom. PHP 8.5 na to fataľne:

```
Fatal error: Cannot redeclare class Themes\moonshine\Sections\Article
(previously declared as local import)
```

Zaujímavé je, že PHPUnit to prehltol (načítal len potrebné triedy),
až PHPStanovo plné načítanie to odkrylo. Lekcia: pomenovávajte sekcie
podľa *úlohy*, nie podľa entity, v téme magazine preto article-sekcia
sa volá `ArticleView`.

## 6. Mirror: magazine a CSS s vlastnými hranicami

Vzor sa presunul do témy magazine (Shell 752 → 196, Renderer 576 → 249).
Tu pribudlo niečo nové, **delenie CSS**. `magazine.css` (423 riadkov)
už má vlastné sekčné komentáre (`── Header ──`, `── Cards ──`…), takže
súbory `src/magazine/{index,tokens,shell,content,article,utilities}.css`
vznikli rezaním po pôvodných hraniciach, `index.css` je zoradený
`@import` reťazec, lebo poradie importov JE kaskáda.

Invariant aj tu hardvérový:

```bash
cmp public/dist/starter/assets/magazine-CeqghFH8.css /tmp/mag-built-before.css
# identické — rovnaký content hash aj po rebuild-e
```

Rovnaký hash `CeqghFH8` znamená, že Vite videl presne rovnaké pravidlá
v presne rovnakom poradí. A `AssetManager`: kľúče manifestu, hashed
názvy, sa vôbec nezmenil.

## 7. Čo z toho máte vy

- **Sekcie podľa reálnych švov, nie podľa plánu na papieri.** Plán
  predpokladal `Sections/Home.php`: realita: home/taxonomy markup
  žije v action triedach, téma renderuje len stavebné bloky. Falošné
  sekcie by boli nový chaos.
- **Facade bez mágie** je lacná a čitateľná. 20 delegátov po jednom
  riadku.
- **Parita sa dokazuje snapshotmi, nie presvedčením.** Heredoc
  nové riadky, chýbajúce docblocky a importy pod rovnakým menom,
  to všetko je ticho, kým to nespravíte hlasným.

V ďalšom diele: téma minimal, regresný test `ThemeFileSizeTest`
(600-riadkový strop vynútený architektonickým testom) a dokumentácia.

→ [Ako MoonShine inšpiroval Cardboard](/blog/moonshine-cardboard): odkiaľ
témam jazyk tokenov a komponentov
→ [Architektúra tém](/blog/theme-architecture): prečo témy vôbec
majú Shell/Renderer/Manifest

## Zdroje

- `openspec/changes/2026-09-13-theme-section-split/`: plán celej série
- `src/Themes/moonshine/`: výsledná štruktúra (CriticalCss/, Chrome/, Sections/)
- `tests/Css/BuildCssAuditTest.php`: WCAG kontrakty, ktoré ostali zelené
  proti byte-identickému bundle
