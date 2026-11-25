---
title: "Core Web Vitals in Practice: LCP, srcset, Lazy Loading"
date: "2026-09-01"
version: "1.0.0"
tags: ["seo", "performance", "core-web-vitals", "images"]
excerpt: "How we optimized LCP via srcset and eager loading, reduced CLS via width/height, and minimized JavaScript for FID."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "core-web-vitals-v-praxi"
published: true
---

# Core Web Vitals in Practice: LCP, srcset, Lazy Loading

Core Web Vitals are three metrics Google uses to evaluate user experience. In Nativa Minimal we optimized each one.

## LCP (Largest Contentful Paint)

LCP measures how fast the main content loads, typically the cover image on a blog article.

### Problem
Imagine a cover image at 720x360px. On mobile, 360px width is enough. We're loading 2x more pixels than needed.

### Solution: srcset

```php
private function coverSrcset(string $image): string
{
    if (!str_contains($image, 'res.cloudinary.com')) {
        return '';
    }

    $small = str_replace('w_720,h_360', 'w_360,h_180', $image);
    $medium = str_replace('w_720,h_360', 'w_634,h_317', $image);

    return ' srcset="' . $small . ' 360w, '
        . $medium . ' 634w, '
        . $image . ' 720w"'
        . ' sizes="(min-width: 1080px) 330px, '
        . '(min-width: 640px) 520px, 100vw"';
}
```

The browser picks the optimal size:
- Desktop (1080px+): 360w (330px card)
- Tablet (640-1080px): 634w (520px card)
- Mobile: 360w (100vw)

### Eager vs Lazy

First 3 images (first row) are `eager` + `fetchpriority="high"`: LCP candidates:

```php
$loading = $inFirstRow ? 'eager' : 'lazy';
$priority = $inFirstRow ? ' fetchpriority="high"' : '';
```

The rest are `lazy`: loaded after scroll.

## CLS (Cumulative Layout Shift)

CLS measures visual stability, how much content shifts during loading.

### Problem
An image without `width`/`height` causes layout shift, text moves when the image loads.

### Solution: explicit dimensions

```php
$cover = '<img class="st-blog-index__card-image" src="..."'
    . ' width="720" height="360"'
    . ' loading="' . $loading . '"' . $priority
    . ' decoding="async">';
```

The browser knows in advance how much space the image takes, no shift.

## FID/INP (First Input Delay / Interaction to Next Paint)

FID measures how fast the page responds to the first interaction. INP is its successor.

### Solution: minimal JavaScript

Nativa Minimal uses:
- **HTMX**: dynamic content without a framework
- **Inline scripts**: only for CSS switcher and select-all
- **No external CDNs**: everything self-hosted

```html
<script nonce="__CSP_NONCE_PLACEHOLDER__">
(function(){
    var sa = document.getElementById('select-all');
    if (sa) sa.addEventListener('change', function(){
        document.querySelectorAll('.checkbox').forEach(function(c){
            c.checked = sa.checked;
        });
    });
})();
</script>
```

Entire admin panel: ~15KB JavaScript. No React, no Vue, no Angular.

## Tests

Core Web Vitals are covered by tests:

```php
public function testFirstCardImageIsEagerLcp(): void
{
    $html = (new BlogIndexAction())->handle(...)->getContent();

    self::assertStringContainsString(
        'loading="eager" fetchpriority="high"',
        $html
    );
    self::assertSame(3, substr_count($html, 'loading="eager"'));
}
```

The solution was three attributes: `loading="eager"`, `fetchpriority="high"`, and `srcset`. One test per pattern. The code you read tomorrow should not make you guess why the image loads fast, the test explains it.

Core Web Vitals remains the simplest performance contract, just not one you should skip. When you know what LCP, CLS, and INP measure, the fixes become obvious. The next person who deploys your app should not have to guess which image loads first.

→ [Article cover LCP](/blog/article-cover-lcp): the first image that matters
→ [LCP srcset basics](/blog/lcp-srcset-basics): responsive images done right
→ [Reading Lighthouse report](/blog/reading-lighthouse-report): detective work on audits

## Sources

- [web.dev, Core Web Vitals](https://web.dev/vitals/)
- [web.dev, LCP](https://web.dev/lcp/)
- [web.dev, CLS](https://web.dev/cls/)
- [web.dev, INP](https://web.dev/inp/)
- [Google, Image optimization](https://developers.google.com/web/fundamentals/performance/optimizing-content-efficiency/image-optimization)
