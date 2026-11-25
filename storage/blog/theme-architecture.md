---
title: "Theme Architecture ... auto-discovery, registry a interface pre témy"
date: "2026-08-20"
version: "1.0.2"
tags: ["php", "architecture", "themes", "vite"]
excerpt: "Ako funguje systém tém v Nativa Minimal ... ThemeRegistry s auto-discovery, ThemeManifest a ThemeRenderer interface, a prečo pridanie novej témy zaberie 3 súbory."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "theme-architecture"
published: true
---

V minulom článku sme písali o filozofii menej vrstiev. Teraz si ukážeme,
ako táto filozofia vyzerá v praxi ... na konkrétnom prípade: systéme
tém v Nativa Minimal.

## Problém: témy = 12+ súborov

Pred refactoringom pridanie novej témy znamenalo:

1. Vytvoriť Shell a Renderer triedy
2. Pridať CSS/JS entry do Vite configu
3. Upraviť `ThemeResolver` ... whitelist
4. Upraviť `SettingsAction` ... dropdown
5. Upraviť 6 action tried ... `switch`/`if` podmienky
6. Upraviť `FrontendLayout` ... `alternateShell()`

**12+ súborov, ~275 riadkov podmienenej logiky.**

## Riešenie: auto-discovery + self-contained modules

Po refactoringu má každá téma **jeden priečinok** pod `src/Themes/`:

```
src/Themes/
├── ThemeManifest.php      # interface
├── ThemeRenderer.php      # interface  
├── ThemeRegistry.php      # auto-discovery
├── magazine/
│   ├── Manifest.php       # implementuje ThemeManifest
│   ├── Shell.php          # header/footer/chrome
│   └── Renderer.php       # stránkový obsah
├── minimal/
│   ├── Manifest.php
│   ├── Shell.php
│   └── Renderer.php
└── starter/
    ├── Manifest.php
    └── StarterRenderer.php
```

**Pridanie témy = 3 súbory.** Odobranie = `rm -rf src/Themes/{name}`.

## Ako funguje auto-discovery

`ThemeRegistry` pri prvom prístupe naskenuje `src/Themes/*/Manifest.php`:

```php
$manifestFiles = glob($base . '/*/Manifest.php');

foreach ($manifestFiles as $file) {
    require_once $file;
    // extrahuje namespace + class z súboru
    $manifest = new $fqcn();
    $themes[$manifest->id()] = $manifest;
}
```

Žiadna manuálna registrácia. Žiadny config súbor. Stačí vytvoriť
priečinok s `Manifest.php` a auto-discovery sa postará o zvyšok.

## Shell ... stránková chrome

Shell nie je len „header/footer“ ... je to kompletná HTML stránka s:
- **SEO head kontraktmi** (canonical, hreflang, OG/Twitter, JSON-LD, noindex)
- **Critical CSS** (nad-fold styling pre prvý paint)
- **Deferred CSS** (`media="print"` → `all` cez CSP-nonce switcher)
- **Theme init script** (dark/light toggle z localStorage)
- **Auth-aware user link** (session-based, bez users.db I/O)

```php
final class Shell
{
    public static function render(
        string $title,
        string $content,
        string $siteName,
        string $description,
        string $currentPath,
        string $htmlLang,
        string $localeSwitcher,
        string $canonicalTag,
        string $hreflangTags,
        string $ogTags,
        string $noindexTag,
        string $structuredTag,
    ): string {
        // ... kompletná HTML stránka
    }
}
```

Shell sa napojí na `FrontendLayout` cez `alternateShell()` ... keď je
aktívna téma, FrontendLayout zavolá `$shellClass::render()` namiesto
vlastného renderovania.

## CSS loading stratégie

Témy používajú **dve rôzne stratégie** načítavania CSS:

### Starter (FrontendLayout) ... inline CSS

Starter téma priamo inlinuje celý CSS súbor do `<style>` tagu:

```php
$inlineCss = self::inlineCss($assets);
if ('' === $inlineCss) {
    $inlineCss = self::criticalCss(); // dev fallback
}
$styleTag = '<style nonce="__CSP_NONCE_PLACEHOLDER__">' . $inlineCss . '</style>';
```

**Výhoda:** Žiadny dodatočný request, všetko v jednom HTML.
**Nevýhoda:** Väčší HTML, CSS nie je cache-ovateľný samostatne.

### Magazine + Minimal ... deferred CSS

Magazine a Minimal používajú `media="print"` → `all` switcher:

```html
<link rel="stylesheet" href="magazine.css" media="print">
<script nonce="__CSP_NONCE_PLACEHOLDER__">
  (function(){
    var l = document.querySelectorAll('link[media="print"][rel="stylesheet"]');
    for(var i=0;i<l.length;i++){
      l[i].onload = function(){ this.media="all"; };
      if(l[i].sheet){ l[i].media="all"; }
    }
  })();
</script>
<noscript>
  <link rel="stylesheet" href="magazine.css">
</noscript>
```

**Výhoda:** CSS sa načíta s `media="print"` (neblokuje render),
potom sa switcher prepne na `all` keď je pripravený. Cache-ovateľné.
**Nevýhoda:** Dodatočný request, flash-of-unstyled-content (rieši critical CSS).

### Prečo rozdiel?

Starter je **default fallback** ... musí fungovať bez Vite buildu
(inline CSS = funguje okamžite). Magazine/Minimal sú **opt-in témy**
... očakávajú buildnutý bundle a používajú deferred pattern pre lepší
rendering výkon.

## Critical CSS parity

Shell triedy obsahujú **inlinovaný critical CSS**, ktorý zrkadlí built
verziu ... aby dev server (bez Vite buildu) vyzeral rovnako ako produkcia.

```php
public static function criticalCss(): string
{
    return FrontendLayout::criticalCss()  // základné tokeny
         . self::criticalCssMagTheme();   // mag/min špecifické
}
```

## ThemeManifest ... čo musí téma deklarovať

Každá téma implementuje `ThemeManifest`:

```php
interface ThemeManifest
{
    public function id(): string;        // 'magazine'
    public function name(): string;      // 'Magazine'
    public function shellClass(): string; // 'Themes\magazine\Shell'
    public function rendererClass(): string; // 'Themes\magazine\Renderer'
}
```

Štyri metódy. Žiadna logika. Len deklarácia.

## ThemeRenderer ... rendering interface

`ThemeRenderer` definuje 6 metód pre stránkový obsah:

| Metóda | Stránka |
|---|---|
| `homepage()` | Domovská stránka |
| `blogIndex()` | Index blogu |
| `blogArticle()` | Článok |
| `blogTags()` | Tag cloud / filter |
| `blogCategories()` | Kategórie / filter |
| `authorProfile()` | Profil autora |

Action triedy delegujú na renderer:

```php
// Pred refactoringom (v HomepageAction):
return match ($theme) {
    'magazine' => (new MagazineRenderer())->homepage(...),
    'minimal' => (new MinimalRenderer())->homepage(...),
    default => $this->starterHomepage(...),
};

// Po refactoringu:
return ThemeRegistry::renderer($theme)->homepage(...);
```

**Žiadne switch/if v akciách.** Action triedy sa nikdy nemenia, keď pridáš novú tému.

## ThemeResolver ... prepínanie

`ThemeResolver` zostáva zodpovedný za voľbu aktívnej témy.
Používa `ThemeRegistry` namiesto hardcoded whitelistu:

```php
public static function resolve(Request $request): string
{
    // 1. ?style= query override
    $style = $request->query('style', '');
    if ('' !== $style && ThemeRegistry::isValid($style)) {
        return $style;
    }

    // 2. THEME env var
    $env = getenv('THEME');
    if (is_string($env) && '' !== $env && ThemeRegistry::isValid($env)) {
        return $env;
    }

    // 3. site.theme setting
    $setting = (string) $settings->get('site.theme', '');
    if ('' !== $setting && ThemeRegistry::isValid($setting)) {
        return $setting;
    }

    // 4. Default
    return 'starter';
}
```

## Vite bundling + @supports fallbacks

CSS/JS témy zostávajú v `Frontend/packages/starter/` ... zdieľajú
rovnaký Vite bundle. Každá téma má vlastný entry point:

- `src/main.ts` ... starter
- `src/magazine.ts` ... magazine (+ `magazine.css`)
- `src/minimal.ts` ... minimal (+ `minimal.css`)

Vite automaticky generuje samostatné CSS súbory pre každý entry.
Manifest súbor (`public/dist/starter/.vite/manifest.json`) mapuje
entry na hashované súbory.

### Chrome 70 baseline

Všetky CSS používajú `@supports not ((gap: 1px) and (inset: 0))`
fallbacky pre staršie prehliadače:

```css
/* Moderné prehliadače */
.mag-grid { display: grid; gap: 24px; }

/* Chrome 70 fallback */
@supports not ((gap: 1px) and (inset: 0)) {
    .mag-grid { gap: 0; margin: -24px 0 0 -24px; }
    .mag-grid > * { margin: 24px 0 0 24px; }
}
```

## WCAG AA kontrakty

Všetky témy musia spĺňať WCAG AA (4.5:1 kontrast). `BuildCssAuditTest`
testuje tokeny v oboch témach (dark + light):

- `--st-accent-strong` ... button bg, white text
- `--st-accent-soft` ... tag/link text
- `--st-error-ink` ... error banner text
- `--mag-accent-strong` / `--min-accent-strong` ... téma-špecifické tokeny

| | Pred | Potom |
|---|---|---|
| Pridať tému | 12+ súborov | **3 súbory** |
| Odobrať tému | 12+ súborov | **1 príkaz** |
| Action triedy | switch/if reťazce | **0 zmien** |
| Registrácia | Manuálna | **Auto-discovery** |

Témy sú self-contained moduly. Žiadne magic, žiadne skryté závislosti.
C-style transparency ... presne ako zvyšok stacku.

---

**Ďalší článok:** [Build Your Own Theme](/blog/build-your-theme) ... návod
krok za krokom ako vytvoriť vlastnú tému.

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
