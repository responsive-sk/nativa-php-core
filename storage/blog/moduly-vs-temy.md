---
title: "Moduly a témy ... kto vlastní markup"
date: "2026-09-01"
version: "1.1.2"
tags: ["architecture", "modules", "themes", "templates"]
excerpt: "Modul povie ČO sa zobrazí, téma povie AKO to vyzerá ... a admin šablóny .phtml sú tretia vec. Mapa toho, kde žije markup v Nativa Minimal, a prečo nikdy nie naopak."
author: "nativa.dev@example.test"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "modules-vs-themes"
published: true
---

Staviaš v Nativa Minimal novú obrazovku. Hľadáš, kam napísať HTML ... v `src/Modules/` ležia `.phtml` súbory, v `src/Themes/` PHP triedy a v `Frontend/` len CSS a TypeScript.

Tri miesta, tri odpovede. Žiadna nie je náhoda, každé má svojho majiteľa.

Otázka „prečo máme templates aj modules“ sa v skutočnosti skladá z dvoch. Prvá: kto vlastní markup admin obrazovky? Druhá: kto vlastní markup verejnej stránky? Odpovede sú rôzne ... a o tom je tento článok.

## Tri vrstvy markupu

`docs/template-map.md` mapuje celý kit ... tu je jadro:

| Vrstva | Mechanizmus | Domov markupu |
|---|---|---|
| Verejný frontend | `FrontendLayout` shell + `ThemeRenderer` | `src/Themes/*/` (PHP triedy) |
| Admin (`/mark`) | `AdminLayout` + `ViewRenderer` | `src/Modules/*/Interfaces/HTTP/templates/*.phtml` |
| Auth | `AuthTemplate::render()` | sebestačný layout v module Users |

Zdieľané pomocníky pre všetky tri: `InlineCss::files()`, `InlineJs::file()`, `ViewRenderer`: všetko v `src/Modules/Core/Application/Services/View/`.

Klientské assety (CSS a TypeScript) žijú len v `Frontend/packages/starter/` a Vite ich builduje do `public/dist/starter/`. Spajza a hotové jedlo, tomu sme sa venovali v [článku o Frontende](/blog/templates).

## Modul ... ČO systém robí

Modul je vertikálny rez cez funkciu. `Blog` vlastní články od súboru v `storage/blog/` až po route `/blog/{slug}`. `Users` vlastní auth, sessions a RBAC. `Media` vlastní uploady a ich metadáta.

Štruktúra je stále tá istá:

```
src/Modules/Blog/
├── Domain/           # entity a hodnotové objekty
├── Application/      # služby — BlogArticlesService, BlogSearchService
└── Interfaces/HTTP/  # akcie, middleware, admin templates
```

`Domain` nikdy neimportuje cudzie `Infrastructure` ... stráži to `tests/Architecture/ModuleIsolationTest.php`. Moduly sú o logike, markup je ich posledná míľa, nie jadro.

## Admin šablóny žijú v module

Admin obrazovka je súčasť funkcie, ktorú modul spravuje. Preto `.phtml` nie je v spoločnom adresári `templates/` ... je pod modulom:

```
src/Modules/Media/Interfaces/HTTP/templates/
├── media-grid.phtml
├── media-upload-form.phtml
└── ...
```

Renderuje ich `ViewRenderer`:

```php
// src/Modules/Media/Interfaces/HTTP/Actions/MediaIndexAction.php — bunka mriežky
return ViewRenderer::render(
    'Modules/Media/Interfaces/HTTP/templates/media-grid.phtml',
    ['thumb' => $thumb, 'urlEscaped' => $urlEscaped, /* ... */],
);
```

`ViewRenderer` je output-buffered include ... šablóna dostane LEN premenné z `render()` (`EXTR_SKIP`), `$this` nie je dostupná a na začiatku musí stáť strážna klauzula `$viewRendererGuard = true;`. Stráži to `TemplateHygieneTest` ... šablóna includeovaná niečím iným padne hneď, netrápi sa s nedefinovanými premennými.

Obálku dodáva `AdminLayout` (alebo `CompactLayout`, keď tak nastavíš v `/mark/settings`). Modul vlastní obsah, admin shell vlastní chrome, a nikdy naopak.

## Témy ... AKO to vyzerá

Verejný frontend je iný prípad. Nechceš jeden markup, chceš vymeniteľné vzhľady. Preto existuje `src/Themes/`:

```
src/Themes/
├── ThemeRenderer.php    # interface — 6 metód
├── ThemeManifest.php    # interface — 4 deklarácie
├── ThemeRegistry.php    # auto-discovery
├── starter/             # default — shell JE FrontendLayout
├── magazine/            # mag-* triedy na --mag-* tokenoch
├── minimal/             # minimal-*, light-first
└── moonshine/           # ms-* chrome, dark-first
```

Interface povie všetko:

```php
// src/Themes/ThemeRenderer.php — šesť metód, každá vracia HTML string
public function blogIndex(array $articles, callable $t): string;
public function blogArticle(array $article, array $headings, ?array $related, ?array $prev, ?array $next, callable $t): string;
// + homepage(), blogTags(), blogCategories(), authorProfile()
```

Registrácia neexistuje,`ThemeRegistry` naskenuje `src/Themes/*/Manifest.php` cez `glob()` a hotovo. Každá téma je sebestačná: `Manifest.php`, fasády `Shell.php` a `Renderer.php`, markup v `Sections/`, chrome v `Chrome/`, critical CSS v `assets/critical/*.css`. Strop je 600 riadkov na súbor ... stráži `ThemeFileSizeTest`.

Prepnutie témy je vec jedného nastavenia,`?style=moonshine` v URL, `THEME` env var alebo `site.theme` v `/mark/settings`. Rozhodne `ThemeResolver` v tomto poradí, neplatné hodnoty padnú na `starter`.

## Modul povie ČO ... téma povie AKO

Najlepšie to vidíš na `BlogIndexAction`:

```php
// src/Modules/Blog/Interfaces/HTTP/Actions/BlogIndexAction.php — dáta
$articles = $service->all($locale);
$viewCounts = BlogArticleHelper::viewCounts($articles);

// ... a markup podľa témy
return match ($theme) {
    'magazine' => $this->magazineIndexPage($service, $locale),
    'minimal' => $this->minimalIndexPage($service, $locale),
    'moonshine' => $this->moonshineIndexPage($service, $locale),
    default => $this->indexPage($service, $locale, $page),
};
```

Akcia nazbiera články cez `BlogArticlesService`: to je modul. Volá renderer témy, to je vzhľad. Celé to obalí `FrontendLayout::render(..., theme: $theme)`: to je shell.

| | Modul | Téma |
|---|---|---|
| Odpovedá na | ČO sa zobrazí | AKO to vyzerá |
| Markup | `.phtml` pre admin | `Sections/` pre verejné strany |
| Mení sa keď | pridáš funkciu | vymeníš vzhľad |

Keď chceš novú tému, nesiahneš na moduly. Keď chceš novú verejnú stranu, pridáš metódu do `ThemeRenderer` a implementuješ ju vo všetkých štyroch témach. Keď chceš novú admin obrazovku, napíšeš `.phtml` pod modul. Tri zámery, tri cesty, každá má jedného majiteľa.

## Kedy to neplatí

Buďme úprimní: nie je to dokonalá polymorfia.

Po prvé, akcie nevolajú `ThemeRegistry::renderer()->blogIndex(...)` ako by si čakal od interface dispatchu. Volajú `match ($theme)` s priamymi volaniami `MagazineRenderer::blogIndex(...)`. Interface a registry existujú a akcie ich použiť môžu, ale dnešný stav je match reťazec ... pridanie piatej témy znamená siahnuť do match ramien v akciách. Nie je to 12 súborov ako pred refactoringom, ale nie je to ani nulový rozdiel.

Po druhé, admin nie je témovateľný. Štyri témy sú len pre verejný frontend. `/mark`, `/debug` a auth stránky majú svoje layouty vždy,`m-*` tokeny, `--auth-*` tokeny, vlastné CSS.

Po tretie, starter je výnimka. Nemá `Sections/` ani `Chrome/`: jeho shell JE `FrontendLayout` a `StarterRenderer` je jediná trieda. Preto `ThemeRegistry::shellClass('starter')` vracia `null` ... default shell sa použije priamo.

## Prakticky

1. **Nová admin obrazovka** ... `.phtml` pod `Interfaces/HTTP/templates/` vlastníckeho modulu + `ViewRenderer::render()` + obálka `AdminLayout`.
2. **Nová verejná strana** ... nová metóda v `ThemeRenderer` + implementácia vo všetkých témach v `Sections/` + render cez `FrontendLayout::render()`.
3. Keď váhaš, spýtaj sa na vlastníka funkcie ... modul je majiteľ logiky a admin markupu, téma je majiteľ verejného vzhľadu. Otázka „kam markup napísať“ tak dostane odpoveď ... „kto to vlastní“, nie „čo to je, actually“.

→ [Theme Architecture](/blog/theme-architecture): auto-discovery, registry a interface
→ [Frontend ... spajza so surovinami a hotový produkt](/blog/templates): dve vrstvy „templates“
→ [Modulová izolácia pre deti](/blog/modulova-izolacia-pre-deti): prečo `Domain` neimportuje cudzie `Infrastructure`


## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [MDN HTML elements reference](https://developer.mozilla.org/en-US/docs/Web/HTML/Element): markup ako štandard, nie vlastnosť frameworku
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod

---

**Production note:** Tento článok opisuje dev test so 4 témami. Finálny produkt má len 2 témy,`public` (`starter`) a `admin` (`moonshine`). Auto-discovery `ThemeRegistry` bol pre 2 témy over-engineering, bol nahradený `match ($theme)` s dvoma vetvami. `ThemeRenderer` interface ostal, je to kontrakt, ktorý drží markup oddelený od akcií. Viac v ADR-0001: Theme architecture for 2 themes.
