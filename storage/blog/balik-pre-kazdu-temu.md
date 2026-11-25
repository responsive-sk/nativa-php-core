---
title: "Témy pre deti ... diel 2: balík pre každú tému"
series: "Témy pre deti"
series-part: "2"
date: "2026-10-02"
version: "1.0.0"
published: true
tags: ["frontend", "pnpm", "themes", "vite"]
excerpt: "Diel 1 urobil z témy modul v PHP. Frontend však ležal ďalej v jednom priečinku ... dnes má každá téma vlastný balík, vlastný dist a vlastný manifest. Čo stálo za rozhodnutie duplikovať a čo hovorí test."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "a-package-per-theme"
---

> [Diel 1](/blog/temy-ako-moduly-pre-deti) postavil hranicu medzi jadrom a témou
> v PHP. Tento diel presúva rovnakú hranicu na frontend ... a priznáva sa, že
> sľúbil niečo iné.

Si PHP vývojár. Diel 1 si dočítal a zovrieš notebook s myšlienkou „téma je
modul, hotovo".

Otvoríš `Frontend/packages/starter/` a tam je všetko.

`erik/`, `magazine/`, `minimal/`, `moonshine/`, `mark.css`, `mark-switch.css`,
`moonshine.css` a ešte `styles.css`, ktorý patrí starteru.

Tridsaťjeden súbor v priečinku pomenovanom po jednej téme z piatich.

## Zmluva bola len na jednej strane

Diel 1 mal dve polovice: kontrakt `ThemeModule` na strane PHP a hranica,
ktorú stráži `ThemeOwnershipTest`.

Táto hranica platila pre `src/Modules`.

Frontend žiadnu hranicu nemal. Bol na druhej strane tej istej steny a nikto
ho netlačil.

A preto sa to dalo pokojne rozbaliť bez toho, aby sa pokojne zrolovalo.

| | PHP strana pred refaktorom | Frontend strana pred refaktorom |
|---|---|---|
| Hranica | `ThemeModule` + `ThemeOwnershipTest` | žiadna |
| Jednotka práce | priečinok `src/Themes/<tema>/` | priečinok `Frontend/packages/starter/` |
| Susedné súbory | každý import je dohodnuty | `styles.css` a `erik/blog.css` v tej istej zložke |

Táto asymetria nebola zámerná. Bola to stopa po tom, ako štadión plaval
v jednom plavebnom kanáli.

## Čo sa zmenilo

`Frontend/packages/` je dnes korenový pnpm workspace.

Pod ním leží `build/` s fabrikou konfigurácie a päť balíkov:

```
Frontend/packages/
├── build/
│   ├── strip-at-layer.ts
│   └── vite-theme-factory.ts
├── package.json          # @nativa/frontend
├── pnpm-workspace.yaml
├── starter/              # @nativa/theme-starter
├── erik/                 # @nativa/theme-erik
├── moonshine/            # @nativa/theme-moonshine
├── magazine/             # @nativa/theme-magazine
└── minimal/              # @nativa/theme-minimal
```

Každý balík má svoj `package.json`, svoj `tsconfig.json`, jeden
`vite.config.ts` a svoj `src/`.

Každý stavia do svojho adresára:

```
public/dist/starter/
public/dist/erik/
public/dist/moonshine/
public/dist/magazine/
public/dist/minimal/
```

Prečo všetkých päť? Aj `magazine` a `minimal` sú zapojené do stavby.

Dva spôsoby, ako sa to dalo spraviť, a obidva majú zmysel:

| | Len tri zapojené témy | Všetkých päť |
|---|---|---|
| `public/dist/` | 3 adresáre | 5 adresárov |
| `BuildCssAuditTest` | 3 súbory | 5 súborov |
| WCAG kontrast `magazine`/`minimal` | netestované | testované |
| Cena | referenčné témy hnijú potichu | 52 kB + 56 kB navyše v dist |

Referenčná téma, ktorú nikto nestaví, je téma, ktorá zhnedne.

Aktuálny stav: starter 44 kB, erik 36 kB, magazine 56 kB, minimal 52 kB,
moonshine 100 kB.

Reálne o 152 kB navyše. Za to dostaneš kontrastný audit dvoch tém, ktoré by
inak nikto nikdy neprebúdal.

## Fabrika, ktorá drží baseline

`build/vite-theme-factory.ts` má 105 riadkov a jednu úlohu: aby päť balíkov
nemohlo rozejdeť sa v tom, čo patrí ich spoločným pravidlám.

```ts
// Frontend/packages/build/vite-theme-factory.ts — port pár per téma
const THEME_PORTS: Record<string, { dev: number; preview: number }> = {
  starter: { dev: 5177, preview: 4177 },
  erik: { dev: 5178, preview: 4178 },
  moonshine: { dev: 5179, preview: 4179 },
  magazine: { dev: 5180, preview: 4180 },
  minimal: { dev: 5181, preview: 4181 },
};

// Frontend/packages/starter/vite.config.ts — šesť riadkov
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'starter',
  inputs: { main: 'src/main.ts' },
});
```

Každý balík tak vyzerá rovnako. Baseline `target: 'es2015'`, striatie
`@layer` cez plugin, `manifest.json` pre PHP a adresár `public/dist/<tema>/`
pochádzajú z jedného miesta.

Porty sú súčasťou pravidla, nie rozptylom.

Dve `vite dev` po sebe už nekolidujú, lebo 5177 a 5178 nie je to isté číslo.

### Jeden príznak má podmienku

`codeSplitting: false` nie je voliteľný prepínač. Vypína delenie na
spoločné chunky.

Dôvod je jednoduchý a tvrdý: `public/index.php` na stránku vkladá
`<script defer>`, nie `<script type="module">`.

Klasický odložený skript na Chrome 70 nesplní `import` statement.

Lenže pri viacerých vstupoch príznak odmieta samotný rolldown. Toto nie je
bug, je to zámerné odmietnutie:

```ts
// Frontend/packages/build/vite-theme-factory.ts
const singleEntry = Object.keys(inputs).length === 1;

output: {
  ...(singleEntry ? { codeSplitting: false } : {}),
},
```

`starter`, `erik`, `magazine` a `minimal` majú po jednom vstupe ... príznak
sa aplikuje.

`moonshine` má tri (`moonshine`, `mark`, `mark-switch`) ... príznak sa
neaplikuje a `bin/verify-build.php` miesto toho overuje, že žiadny emitovaný
bundle neobsahuje `import` ani `export`.

Dva mechanizmy, jeden zmluvný bod: „žiadny cross-bundle import“.

## Manifest sa stal hranicou

`AssetManager` dnes pozná balíky ako zoznam, nie ako vetvu:

```php
// src/Modules/Core/Application/Services/AssetManager.php
private const array BUNDLES = ['starter', 'erik', 'moonshine', 'magazine', 'minimal'];
```

Neznáme meno padá na `starter`, nie na prázdnotu.

A keďže Vite 8 kľúčuje manifest **rozlišenou cestou zdroja**, nie kľúčom
z `rollupOptions.input`, našiel sa tu malý háčik.

Vstup `mark` dnes bodá na `src/mark/index.css`, lebo adminový dizajn je sám
rozdelený na sekcie. Kľúč sa teda zmenil z `mark.css` na `src/mark/index.css`.
Preto `manifestEntry()` skúša všetky štyri podoby:

```php
// src/Modules/Core/Application/Services/AssetManager.php — štyri podoby
$candidates = [$key, 'src/' . $key . '.ts', 'src/' . $key . '.css', 'src/' . $key . '/index.css'];
```

Šablóna volá `cssUrls('mark')` a nemusí vedieť, či sa balík nazýva
`mark.css` alebo má vlastný priečinok.

Toto nie je hádačka. Je to dôsledok refaktoru CSS.

## 1871 riadkov je deväť súborov

Druhý krok refaktoru sa týkal jedného čísla.

`styles.css` mal 1871 riadkov. Bol to jeden súbor s deviatimi rozloženými
zodpovednosťami: tokeny, chrome, domovská stránka, článok, obsah, blog,
karty, komentáre.

Po rozdelení:

| Súbor | Riadkov | Čo nesie |
|---|---|---|
| `base/index.css` | 16 | poradie importov |
| `base/tokens.css` | 81 | farby, priestor, tiene |
| `base/chrome.css` | 454 | nav, hlavička, päta, prepínač témy |
| `base/home.css` | 214 | domovská stránka |
| `base/article.css` | 303 | článok a jeho meta |
| `base/toc.css` | 161 | obsah na okraji a FAB |
| `base/blog.css` | 349 | zoznam článkov |
| `base/cards.css` | 179 | karta článku |
| `base/comments.css` | 123 | formulár komentárov |

Rovnaký krok dostal `mark.css` (1013 riadkov na sedem súborov) a
`moonshine.css` (1315 riadkov na päť).

Hranica 600 riadkov v `ThemeFileSizeTest` platí dnes aj pre
`Frontend/packages/*/src`. Zoznam výnimiek je prázdny.

## Duplikácia má cenu

Tu je bod, kde by sa človek najradšej zastavil.

Ten istý `base/` leží v štyroch balíkoch. Deväť súborov, štyri balíky,
36 kópií.

| | `@nativa/shared` | Kopie v každom balíku |
|---|---|---|
| Jeden zdroj pravdy | áno | nie |
| Cross-package import | áno | **zákaz** |
| Chyba pri úprave | všetky témy naraz | padne parity test |
| Dôkaz zhody | žiadny | byte porovnanie |

`FrontendPackageParityTest` neoveruje, že súbory „vyzerajú podobne“.

Overuje bajty.

```php
// tests/Architecture/FrontendPackageParityTest.php
self::assertSame(
    $expected,
    (string) file_get_contents($path),
    sprintf('%s has drifted from %s — the duplicated sources must stay byte-identical.', $copy, $reference),
);
```

Zdroj pravdy je jeden. Kopíruje sa. Zhodu drží stroj.

A `code-highlight.ts` je zdrojovaný dvakrát, v `starter` aj v `erik`,
kvôli tej istej vecnej príčine: spoločný chunk by znamenal `import`.

Parity test má dve tváre. Okrem zhody bajtov hľadá aj **cross-package
import** a nahlas o ňom hovorí, že by vrátil presne to spojenie, ktoré
rozloženie odstraňuje.

Keď jeden balík siahne do priečinka druhého, jeden riadok jeho CSS zmení
build inej témy.

Preto je to chybové hlásenie, nie poznámka.

## O čom sme nehovorili

Buďme úprimní, štyri veci stoja za poznámkou.

**Zmenili sme plán.** Diel 1 sľúbil štvrtú tému krok za krokom. Namiesto toho
sa ukázalo, že frontend je jedna rozliata zmes, a kým sa povedala „nová
téma“, nemala vlastný balík ani nikoho, kto by jej vlastný balík strážil.
Štvrtá téma bez zabalenia by len pridala štvrtý súbor k tej istej hromade.

**Témy `magazine` a `minimal` nie sú zapojené do rútingu.** Stavia sa, auditujú
aj preto, že referencia má zostať živá. Ale `/magazine` neexistuje a
`ThemeKernel` ich nepozná. Sú to dve vetvy mimo schémy.

**`container-query.ts` a `toc-scrollspy.ts` žijú len v `starter`.** Pri
zdieľaní TypeScriptu sme duplikovali `code-highlight.ts`, pretože jeho
spoločný chunk by bol `import`. Tieto dva moduly taký tlak nemajú ... zatiaľ.
Až budú potrebné inde, dostanú ten istý osud: kópiu a parity test.

**Zámok na bajty nepreveruje zmysel.** Parity test zistí, že
`magazine/src/base/chrome.css` a `starter/src/base/chrome.css` sú rovnaké.
Nezistí, že je to správne. Zhodu tu drží tá rovnaká vlastnosť, ktorá vytvára
problém: štyri balíky zdieľajú ten istý zdroj, takže aj štyri dostanú
rovnakú chybu.

Prečo to nevadí? Lebo táto šablóna je zámerná. Design systém má jedno
miesto, nie päť.

## Čo bude ďalej

V treťom diele otvoríme najväčší zostávajúci kus:
`src/Themes/erik/assets/critical/blog.css` má 454 riadkov a je to najväčší
kritický súbor v kitre.

Porovnáme ho so štiepením, ktoré práve prešlo na bundle stránky.

O tom, či kritické CSS patrí do `dist` balíka témy, alebo ostáva v PHP ako
inline blok.

## Zdroje

- [pnpm workspaces](https://pnpm.io/workspaces): oficiálna dokumentácia worksov
- [Vite, Building Libraries / multi-page](https://vite.dev/guide/build): viac vstupov a manifest
- [MDN, script defer](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/script): prečo klasický skript nesplní `import`
- [PHPUnit](https://phpunit.de/): dokumentácia testovacieho frameworku
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod kitu