---
title: "Theme Architecture ... auto-discovery, registry and interfaces for themes"
date: "2026-08-20"
version: "1.0.0"
tags: ["php", "architecture", "themes", "vite"]
excerpt: "How the theme system works in Nativa Minimal ... ThemeRegistry with auto-discovery, ThemeManifest and ThemeRenderer interfaces, and why adding a new theme takes 3 files."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "theme-architecture"
published: true
---

In the previous article we wrote about the philosophy of fewer layers.
Now let's see how that philosophy looks in practice ... on a concrete
case: the theme system in Nativa Minimal.

## The problem: themes = 12+ files

Before the refactoring, adding a new theme meant:

1. Creating Shell and Renderer classes
2. Adding a CSS/JS entry to the Vite config
3. Editing `ThemeResolver` ... whitelist
4. Editing `SettingsAction` ... dropdown
5. Editing 6 action classes ... `switch`/`if` conditions
6. Editing `FrontendLayout` ... `alternateShell()`

**12+ files, ~275 lines of conditional logic.**

## The solution: auto-discovery + self-contained modules

After the refactoring, each theme has **one directory** under `src/Themes/`:

```
src/Themes/
├── ThemeManifest.php      # interface
├── ThemeRenderer.php      # interface  
├── ThemeRegistry.php      # auto-discovery
├── magazine/
│   ├── Manifest.php       # implements ThemeManifest
│   ├── Shell.php          # header/footer/chrome
│   └── Renderer.php       # page content
├── minimal/
│   ├── Manifest.php
│   ├── Shell.php
│   └── Renderer.php
└── starter/
    ├── Manifest.php
    └── StarterRenderer.php
```

**Adding a theme = 3 files.** Removing = `rm -rf src/Themes/{name}`.

## How auto-discovery works

`ThemeRegistry` scans `src/Themes/*/Manifest.php` on first access:

```php
$manifestFiles = glob($base . '/*/Manifest.php');

foreach ($manifestFiles as $file) {
    require_once $file;
    // extracts namespace + class from file
    $manifest = new $fqcn();
    $themes[$manifest->id()] = $manifest;
}
```

No manual registration. No config file. Just create a directory with
`Manifest.php` and auto-discovery handles the rest.

## Shell ... page chrome

The Shell isn't just "header/footer" ... it's a complete HTML page with:
- **SEO head contracts** (canonical, hreflang, OG/Twitter, JSON-LD, noindex)
- **Critical CSS** (above-fold styling for first paint)
- **Deferred CSS** (`media="print"` → `all` via CSP-nonce switcher)
- **Theme init script** (dark/light toggle from localStorage)
- **Auth-aware user link** (session-based, zero users.db I/O)

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
        // ... full HTML page
    }
}
```

The Shell connects to `FrontendLayout` via `alternateShell()` ... when a
theme is active, FrontendLayout calls `$shellClass::render()` instead
of its own rendering.

## CSS loading strategies

Themes use **two different strategies** for loading CSS:

### Starter (FrontendLayout) ... inline CSS

The starter theme inlines the entire CSS file directly into a `<style>` tag:

```php
$inlineCss = self::inlineCss($assets);
if ('' === $inlineCss) {
    $inlineCss = self::criticalCss(); // dev fallback
}
$styleTag = '<style nonce="__CSP_NONCE_PLACEHOLDER__">' . $inlineCss . '</style>';
```

**Pro:** No additional request, everything in one HTML.
**Con:** Larger HTML, CSS isn't cacheable separately.

### Magazine + Minimal ... deferred CSS

Magazine and Minimal use the `media="print"` → `all` switcher:

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

**Pro:** CSS loads with `media="print"` (doesn't block render),
then the switcher flips to `all` when ready. Cacheable.
**Con:** Additional request, flash-of-unstyled-content (solved by critical CSS).

### Why the difference?

Starter is the **default fallback** ... it must work without a Vite build
(inline CSS = works immediately). Magazine/Minimal are **opt-in themes**
... they expect a built bundle and use the deferred pattern for better
rendering performance.

## Critical CSS parity

Shell classes contain **inline critical CSS** that mirrors the built
version ... so the dev server (without Vite build) looks identical
to production.

```php
public static function criticalCss(): string
{
    return FrontendLayout::criticalCss()  // base tokens
         . self::criticalCssMagTheme();   // mag/min specific
}
```

## ThemeManifest ... what a theme must declare

Each theme implements `ThemeManifest`:

```php
interface ThemeManifest
{
    public function id(): string;        // 'magazine'
    public function name(): string;      // 'Magazine'
    public function shellClass(): string; // 'Themes\magazine\Shell'
    public function rendererClass(): string; // 'Themes\magazine\Renderer'
}
```

Four methods. No logic. Just declaration.

## ThemeRenderer ... the rendering interface

`ThemeRenderer` defines 6 methods for page content:

| Method | Page |
|---|---|
| `homepage()` | Homepage |
| `blogIndex()` | Blog index |
| `blogArticle()` | Article |
| `blogTags()` | Tag cloud / filter |
| `blogCategories()` | Categories / filter |
| `authorProfile()` | Author profile |

Action classes delegate to the renderer:

```php
// Before refactoring (in HomepageAction):
return match ($theme) {
    'magazine' => (new MagazineRenderer())->homepage(...),
    'minimal' => (new MinimalRenderer())->homepage(...),
    default => $this->starterHomepage(...),
};

// After refactoring:
return ThemeRegistry::renderer($theme)->homepage(...);
```

**No switch/if in actions.** Action classes never change when you add
a new theme.

## ThemeResolver ... switching

`ThemeResolver` remains responsible for choosing the active theme.
It uses `ThemeRegistry` instead of a hardcoded whitelist:

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

Theme CSS/JS stays in `Frontend/packages/starter/` ... they share the
same Vite bundle. Each theme has its own entry point:

- `src/main.ts` ... starter
- `src/magazine.ts` ... magazine (+ `magazine.css`)
- `src/minimal.ts` ... minimal (+ `minimal.css`)

Vite automatically generates separate CSS files for each entry. The
manifest file (`public/dist/starter/.vite/manifest.json`) maps entries
to hashed files.

### Chrome 70 baseline

All CSS uses `@supports not ((gap: 1px) and (inset: 0))` fallbacks
for older browsers:

```css
/* Modern browsers */
.mag-grid { display: grid; gap: 24px; }

/* Chrome 70 fallback */
@supports not ((gap: 1px) and (inset: 0)) {
    .mag-grid { gap: 0; margin: -24px 0 0 -24px; }
    .mag-grid > * { margin: 24px 0 0 24px; }
}
```

## WCAG AA contracts

All themes must meet WCAG AA (4.5:1 contrast). `BuildCssAuditTest`
tests tokens in both themes (dark + light):

- `--st-accent-strong` ... button bg, white text
- `--st-accent-soft` ... tag/link text
- `--st-error-ink` ... error banner text
- `--mag-accent-strong` / `--min-accent-strong` ... theme-specific tokens

## Summary

| | Before | After |
|---|---|---|
| Add a theme | 12+ files | **3 files** |
| Remove a theme | 12+ files | **1 command** |
| Action classes | switch/if chains | **0 changes** |
| Registration | Manual | **Auto-discovery** |

Themes are self-contained modules. No magic, no hidden dependencies.
C-style transparency ... just like the rest of the stack.

---

→ [Build Your Own Theme](/blog/build-your-theme): step-by-step guide to creating your own theme
→ [Theme Comparison](/blog/theme-comparison): starter vs magazine vs minimal

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
