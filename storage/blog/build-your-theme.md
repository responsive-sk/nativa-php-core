---
title: "Build Your Own Theme ... návod krok za krokom"
date: "2026-08-21"
version: "1.0.2"
tags: ["php", "themes", "tutorial", "vite"]
excerpt: "Ako vytvoriť vlastnú tému pre Nativa Minimal ... 3 súbory, auto-discovery, žiadna manuálna registrácia."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Tutorial"
views: "0"
translation: "build-your-theme"
published: true
---

Ukážeme, ako vytvoriť vlastnú tému ... krok za krokom.

## Čo budeme vytvárať

Vytvoríme tému „corporate“ ... čistý, profesionálny layout pre firmu.
Bude mať:
- Vlastný shell (header/footer)
- Vlastný renderer (homepage, blog, article)
- Vlastné CSS tokeny

## Krok 1: Vytvoriť priečinok

```bash
mkdir src/Themes/corporate
```

Hotovo. Auto-discovery ju nájde automaticky.

## Krok 2: Manifest.php

Vytvoríme `src/Themes/corporate/Manifest.php`:

```php
<?php

declare(strict_types = 1);

namespace Themes\corporate;

use Themes\ThemeManifest;

final class Manifest implements ThemeManifest
{
    public function id(): string
    {
        return 'corporate';
    }

    public function name(): string
    {
        return 'Corporate';
    }

    public function shellClass(): string
    {
        return Shell::class;
    }

    public function rendererClass(): string
    {
        return Renderer::class;
    }
}
```

Štyri metódy. Žiadna logika. Len deklarácia.

## Krok 3: Shell.php

Shell je kompletná HTML stránka ... header, footer, critical CSS,
deferred CSS, theme toggle, auth-aware user link. Vytvoríme
`src/Themes/corporate/Shell.php`:

```php
<?php

declare(strict_types = 1);

namespace Themes\corporate;

use Modules\Core\Application\Services\AssetManager;
use Modules\Core\Interfaces\HTTP\FrontendLayout;

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
        $assets = new AssetManager('starter');
        $cssUrls = $assets->cssUrls('corporate');
        $jsUrl = $assets->jsUrl('corporate');
        $year = date('Y');

        // Critical CSS ... inline, nie deferred
        $styleTag = '<style nonce="__CSP_NONCE_PLACEHOLDER__">'
            . self::criticalCss() . '</style>';
        
        // Deferred CSS ... media="print" → all cez switcher
        $deferredCss = self::deferredCss($cssUrls);
        
        return <<<HTML
        <!DOCTYPE html>
        <html lang="{$htmlLang}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <meta name="description" content="{$description}">
            {$canonicalTag}
            {$hreflangTags}
            {$ogTags}
            {$noindexTag}
            {$structuredTag}
            {$styleTag}
            {$deferredCss}
            <script nonce="__CSP_NONCE_PLACEHOLDER__">
                // Theme init ... dark/light z localStorage
                (function() {
                    try {
                        var theme = localStorage.getItem('nativa-theme');
                        if (!theme) {
                            theme = window.matchMedia('(prefers-color-scheme: dark)')
                                .matches ? 'dark' : 'light';
                        }
                        document.documentElement.setAttribute('data-theme', theme);
                    } catch (e) {}
                })();
            </script>
        </head>
        <body>
            <header>...</header>
            <main>{$content}</main>
            <footer>...</footer>
        </body>
        </html>
        HTML;
    }
}
```

**Povinné prvky Shell triedy:**
- `__CSP_NONCE_PLACEHOLDER__` pre všetky inline scripty/style
- Theme init script (dark/light toggle)
- Critical CSS parity (zrkadlí built verziu)
- Auth-aware user link (session-based, bez I/O)

**Voliteľné (pre opt-in témy):**
- Deferred CSS pattern (media=„print“ → all) ... ak téma očakáva buildnutý bundle

**Poznámka:** Starter téma (FrontendLayout) **inlinuje CSS** namiesto
deferred patternu ... funguje bez Vite buildu.

## Krok 4: Renderer.php

Renderer je obsah stránky. Vytvoríme
`src/Themes/corporate/Renderer.php`:

```php
<?php

declare(strict_types = 1);

namespace Themes\corporate;

use Themes\ThemeRenderer;

final class Renderer implements ThemeRenderer
{
    public function homepage(
        array $sections,
        array $articleLinks,
        string $locale,
        callable $t,
        array $articles,
    ): string {
        $html = '<div class="corporate-home">';
        $html .= '<h1>' . htmlspecialchars($t('home.title', 'Welcome')) . '</h1>';
        
        foreach ($articles as $article) {
            $html .= '<article class="corporate-card">';
            $html .= '<h2><a href="/blog/' . htmlspecialchars($article['slug']) . '">';
            $html .= htmlspecialchars($article['title']);
            $html .= '</a></h2>';
            $html .= '<p>' . htmlspecialchars($article['excerpt']) . '</p>';
            $html .= '</article>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    public function blogIndex(array $articles, callable $t): string
    {
        $html = '<div class="corporate-blog">';
        $html .= '<h1>' . htmlspecialchars($t('blog.title', 'Blog')) . '</h1>';
        
        foreach ($articles as $article) {
            $html .= '<article class="corporate-card">';
            $html .= '<h2><a href="/blog/' . htmlspecialchars($article['slug']) . '">';
            $html .= htmlspecialchars($article['title']);
            $html .= '</a></h2>';
            $html .= '<p>' . htmlspecialchars($article['excerpt']) . '</p>';
            $html .= '</article>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    public function blogArticle(
        array $article,
        array $headings,
        ?array $related,
        ?array $prev,
        ?array $next,
        callable $t,
    ): string {
        $html = '<article class="corporate-article">';
        $html .= '<h1>' . htmlspecialchars($article['title']) . '</h1>';
        $html .= '<div class="corporate-article-body">' . $article['body'] . '</div>';
        $html .= '</article>';
        
        return $html;
    }

    public function blogTags(array $articles, array $allTags, ?string $activeTag, callable $t): string
    {
        $html = '<div class="corporate-tags">';
        $html .= '<h1>' . htmlspecialchars($t('tags.title', 'Tags')) . '</h1>';
        
        foreach ($allTags as $tag => $count) {
            $html .= '<a href="/blog/tags/' . htmlspecialchars($tag) . '" class="corporate-tag">';
            $html .= htmlspecialchars($tag) . ' (' . $count . ')';
            $html .= '</a>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    public function blogCategories(array $articles, array $allCategories, ?string $activeCategory, callable $t): string
    {
        $html = '<div class="corporate-categories">';
        $html .= '<h1>' . htmlspecialchars($t('categories.title', 'Categories')) . '</h1>';
        
        foreach ($allCategories as $category => $count) {
            $html .= '<a href="/blog/categories/' . htmlspecialchars($category) . '" class="corporate-category">';
            $html .= htmlspecialchars($category) . ' (' . $count . ')';
            $html .= '</a>';
        }
        
        $html .= '</div>';
        
        return $html;
    }

    public function authorProfile(array $author, array $articles, callable $t): string
    {
        $html = '<div class="corporate-author">';
        $html .= '<h1>' . htmlspecialchars($author['name']) . '</h1>';
        $html .= '<p>' . htmlspecialchars($author['bio']) . '</p>';
        
        foreach ($articles as $article) {
            $html .= '<article class="corporate-card">';
            $html .= '<h2><a href="/blog/' . htmlspecialchars($article['slug']) . '">';
            $html .= htmlspecialchars($article['title']);
            $html .= '</a></h2>';
            $html .= '</article>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
}
```

## Krok 5: CSS/JS (voliteľné)

Ak chceš vlastné CSS, vytvor entry súbor a pridaj ho do Vite configu:

```typescript
// Frontend/packages/starter/src/corporate.ts
import './corporate.css';

// Corporate theme JS
document.addEventListener('DOMContentLoaded', () => {
    console.log('Corporate theme loaded');
});
```

A CSS súbor s `@supports` fallbackmi pre Chrome 70:

```css
/* Frontend/packages/starter/src/corporate.css */

/* Accent tokens */
:root {
    --corp-accent: #2563eb;        /* decorative (ikony, bordery) */
    --corp-accent-strong: #1d4ed8; /* button bg, white text >= 4.5:1 */
    --corp-accent-soft: #3b82f6;   /* link/tag text on surface >= 4.5:1 */
}

.corporate-home {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem;
}

.corporate-card {
    border: 1px solid var(--st-border);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 1rem;
}

/* Grid s @supports fallback */
.corporate-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
}

@supports not ((gap: 1px) and (inset: 0)) {
    .corporate-grid { gap: 0; margin: -1.5rem; }
    .corporate-grid > * { margin: 1.5rem; }
}
```

**WCAG AA povinnosť:** všetky textové tokeny musia mať >= 4.5:1
kontrast. Dekoratívne tokeny (ikony, bordery) nemajú AA požiadavku.

## Krok 6: Testovanie

Otvor admin panel (`/mark/settings`) ... uvidíš „Corporate“ v theme
dropdown. Vyber ju a ulož.

Alebo použi query parameter:

```
/?style=corporate
```

Hotovo. Téma funguje.

| Krok | Súbor | Účel |
|---|---|---|
| 1 | `mkdir src/Themes/corporate` | Vytvoriť priečinok |
| 2 | `Manifest.php` | Deklarovať tému |
| 3 | `Shell.php` | Header/footer/chrome |
| 4 | `Renderer.php` | Stránkový obsah |
| 5 | `corporate.css` + `corporate.ts` | Voliteľné CSS/JS |

**3 povinné súbory.** Auto-discovery sa postará o zvyšok.

## Odobranie témy

```bash
rm -rf src/Themes/corporate
```

Žiadne ďalšie úpravy. Žiadne zostávajúce referencie.

---

**Ďalší článok:** [Theme Comparison](/blog/theme-comparison) ...
porovnanie starter, magazine a minimal tém.


→ [Theme Architecture](/blog/theme-architecture): auto-discovery, registry a interface
→ [Theme Comparison](/blog/theme-comparison): Starter vs Magazine vs Minimal
→ [Clean code pre deti](/blog/co-je-cisty-kod-pre-deti): ako písať kód, ktorý sa číta sám

## Zdroje

- [W3C, ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance): ACID definícia
- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [SQLite Documentation](https://www.sqlite.org/docs.html): SQLite referenčná príručka
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
