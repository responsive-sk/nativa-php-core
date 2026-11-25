---
title: "Core Web Vitals v praxi: LCP, srcset, lazy loading"
date: "2026-09-01"
version: "1.0.1"
tags: ["seo", "performance", "core-web-vitals", "images"]
excerpt: "Ako sme optimalizovali LCP cez srcset a eager loading, znížili CLS cez width/height, a minimalizovali JavaScript pre FID."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "core-web-vitals-in-practice"
published: true
---

# Core Web Vitals v praxi: LCP, srcset, lazy loading

Core Web Vitals sú tri metriky, ktoré Google používa na hodnotenie používateľskej skúsenosti. V Nativa Minimal sme optimalizovali každú z nich.

## LCP (Largest Contentful Paint)

LCP meria ako rýchlo sa načíta hlavný obsah, typicky cover image na blog článku.

### Problém
Predstavte si cover image s 720x360px. Na mobile stačí 360px šírka. Načítavame 2x viac pixelov než potrebujeme.

### Riešenie: srcset

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

Prehliadač si sám vyberie optimálnu veľkosť:
- Desktop (1080px+): 360w (330px karta)
- Tablet (640-1080px): 634w (520px karta)
- Mobile: 360w (100vw)

### Eager vs Lazy

Prvé 3 obrázky (first row) sú `eager` + `fetchpriority="high"`: LCP kandidáti:

```php
$loading = $inFirstRow ? 'eager' : 'lazy';
$priority = $inFirstRow ? ' fetchpriority="high"' : '';
```

Ostatné sú `lazy`: načítajú sa až po scroll.

## CLS (Cumulative Layout Shift)

CLS meria vizuálnu stabilitu, ako veľa sa obsah posúva pri načítavaní.

### Problém
Obrázok bez `width`/`height` spôsobí layout shift, text sa posunie keď sa obrázok načíta.

### Riešenie: explicitné rozmery

```php
$cover = '<img class="st-blog-index__card-image" src="..."'
    . ' width="720" height="360"'
    . ' loading="' . $loading . '"' . $priority
    . ' decoding="async">';
```

Prehliadač vie vopred, koľko miesta obrázok zaberie, žiadny shift.

## FID/INP (First Input Delay / Interaction to Next Paint)

FID meria ako rýchlo reaguje stránka na prvú interakciu. INP je jeho nástupca.

### Riešenie: minimum JavaScript

Nativa Minimal používa:
- **HTMX**: dynamický obsah bez frameworku
- **Inline scripty**: len pre CSS switcher a select-all
- **Žiadne externé CDN**: všetko self-hosted

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

Celý admin panel: ~15KB JavaScript. Žiadny React, žiadny Vue, žiadny Angular.

## Testy

Core Web Vitals máme pokryté testami:

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

---

## Zdroje

- [web.dev, Core Web Vitals](https://web.dev/vitals/)
- [web.dev, LCP](https://web.dev/lcp/)
- [web.dev, CLS](https://web.dev/cls/)
- [web.dev, INP](https://web.dev/inp/)
- [Google, Image optimization](https://developers.google.com/web/fundamentals/performance/optimizing-content-efficiency/image-optimization)
