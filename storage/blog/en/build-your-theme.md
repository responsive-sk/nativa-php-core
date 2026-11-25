---
title: "Build Your Own Theme ... a step-by-step guide"
date: "2026-08-21"
version: "1.0.0"
tags: ["php", "themes", "tutorial", "vite"]
excerpt: "How to create your own theme for Nativa Minimal ... 3 files, auto-discovery, no manual registration."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Tutorial"
views: "0"
translation: "build-your-theme"
published: true
---

Three files. Auto-discovery. No manual registration. That's all you need for a custom theme.

The problem with most theme systems: too much configuration, too much boilerplate.

In the previous article we saw how the theme architecture works. Now
let's create our own theme ... step by step.

## What we'll be building

We'll create a "corporate" theme ... a clean, professional layout for a
company. It will have:
- Its own shell (header/footer)
- Its own renderer (homepage, blog, article)
- Its own CSS tokens

## Step 1: Create the directory

```bash
mkdir src/Themes/corporate
```

Done. Auto-discovery finds it automatically.

## Step 2: Manifest.php

Create `src/Themes/corporate/Manifest.php`:

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

Four methods. No logic. Just declaration.

## Step 3: Shell.php

The Shell is a complete HTML page ... header, footer, critical CSS,
deferred CSS, theme toggle, auth-aware user link. Create
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

        // Critical CSS ... inline, non-render-blocking
        $styleTag = '<style nonce="__CSP_NONCE_PLACEHOLDER__">'
            . self::criticalCss() . '</style>';
        
        // Deferred CSS ... media="print" → all via switcher
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
                // Theme init ... dark/light from localStorage
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

**Required Shell elements:**
- `__CSP_NONCE_PLACEHOLDER__` for all inline scripts/styles
- Theme init script (dark/light toggle)
- Critical CSS parity (mirrors the built version)
- Auth-aware user link (session-based, zero I/O)

**Optional (for opt-in themes):**
- Deferred CSS pattern (media="print" → all) ... if the theme expects a built bundle

**Note:** The starter theme (FrontendLayout) **inlines CSS** instead of
the deferred pattern ... it works without a Vite build.

## Step 4: Renderer.php

The renderer is the page content. Create
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

## Step 5: CSS/JS (optional)

If you want custom CSS, create an entry file and add it to the Vite
config:

```typescript
// Frontend/packages/starter/src/corporate.ts
import './corporate.css';

// Corporate theme JS
document.addEventListener('DOMContentLoaded', () => {
    console.log('Corporate theme loaded');
});
```

And a CSS file with `@supports` fallbacks for Chrome 70:

```css
/* Frontend/packages/starter/src/corporate.css */

/* Accent tokens */
:root {
    --corp-accent: #2563eb;        /* decorative (icons, borders) */
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

/* Grid with @supports fallback */
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

**WCAG AA requirement:** all text-bearing tokens must have >= 4.5:1
contrast. Decorative tokens (icons, borders) have no AA requirement.

## Step 6: Testing

Open the admin panel (`/mark/settings`) ... you'll see "Corporate" in
the theme dropdown. Select it and save.

Or use the query parameter:

```
/?style=corporate
```

Done. The theme works.

## Summary

| Step | File | Purpose |
|---|---|---|
| 1 | `mkdir src/Themes/corporate` | Create directory |
| 2 | `Manifest.php` | Declare the theme |
| 3 | `Shell.php` | Header/footer/chrome |
| 4 | `Renderer.php` | Page content |
| 5 | `corporate.css` + `corporate.ts` | Optional CSS/JS |

**3 required files.** Auto-discovery handles the rest.

## Removing a theme

```bash
rm -rf src/Themes/corporate
```

No other changes needed. No remaining references.

The theme system remains a foundation — not a constraint. Three files give you full control. Auto-discovery means zero configuration.

---

A theme is not a template. It's a contract between your content and your presentation.

---

A theme should be read, not just configured.

---

→ [Theme Architecture](/blog/theme-architecture) — how themes work
→ [Theme Comparison](/blog/theme-comparison) — starter vs magazine vs minimal

## Sources

- [W3C — ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance) — ACID definition
- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [SQLite Documentation](https://www.sqlite.org/docs.html) — SQLite reference manual
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
