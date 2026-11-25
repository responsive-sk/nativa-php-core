---
title: "Ako sme urobili sitemap.xml bez frameworku"
date: "2026-09-01"
version: "1.0.2"
tags: ["seo", "sitemap", "xml", "php"]
excerpt: "Dynamický XML sitemap generovaný čistým PHP, hreflang alternates, author profily, feed endpoints. Žiadny framework, žiadne generovanie."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "how-we-built-sitemap"
published: true
---

# Ako sme urobili sitemap.xml bez frameworku

Sitemap.xml je prvá vec, ktorú Googlebot hľadá. V Nativa Minimal generujeme dynamický XML sitemap priamo v PHP, žiadny framework, žiadne CLI generovanie, žiadne cache.

## Prečo dynamický?

Statický sitemap vyžaduje build krok. Keď pridáte článok, musíte spustiť `generate-sitemap`. Pri dynamickom sitemap sa obsah aktualizuje automaticky, každý request generuje aktuálny XML.

## Štruktúra sitemapu

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">
  <url>
    <loc>https://example.com/</loc>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc>https://example.com/blog/hello-world</loc>
    <lastmod>2026-08-04</lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
    <xhtml:link rel="alternate" hreflang="sk" href="..."/>
    <xhtml:link rel="alternate" hreflang="en" href="..."/>
  </url>
</urlset>
```

## Implementácia

Sitemap sa generuje v jednej akcii,`SitemapAction`:

```php
#[Route(path: '/sitemap.xml', method: 'GET', name: 'sitemap')]
final class SitemapAction
{
    public function handle(Request $request): Response
    {
        $base = $request->absoluteUrl('');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"
            . $this->staticEntries($base)
            . $this->articleEntries($base)
            . $this->pageEntries($base)
            . $this->authorEntries($base)
            . '</urlset>' . "\n";

        return Response::xml($xml, 'application/xml; charset=utf-8');
    }
}
```

Kľúčové: `$request->absoluteUrl('')`: generuje absolútnu URL z aktuálneho requestu. Funguje na localhost, na produkcii, cez proxy.

## Čo zahŕňame

### Statické stránky
- Domovská stránka (obe locale)
- Blog index (obe locale)
- Feed endpoints (Atom + RSS)

### Články
Každý článok v oboch locale s hreflang alternates:

```php
$alternates = '<xhtml:link rel="alternate" hreflang="sk" href="...'
    . '"/>' . "\n";
$alternates .= '<xhtml:link rel="alternate" hreflang="en" href="...'
    . '"/>' . "\n";
```

Google tak vie, že `example.com/blog/hello-world` a `example.com/en/blog/hello-world` sú rovnaký článok v rôznych jazykoch.

### Stránky
Statické stránky (iba published) s hreflang.

### Autori
Author profily dosiahnuteľné z článkov, deduplikované.

## Priority a changefreq

| Typ | Priority | Changefreq |
|-----|----------|------------|
| Domov | 1.0 | weekly |
| Blog index | 0.9 | daily |
| Články | 0.8 | weekly |
| Stránky | 0.6 | monthly |
| Autori | 0.6 | weekly |
| Feedy | 0.3 | daily |

Priorita nie je záväzná, Google ju ignoruje. Ale `changefreq` a `lastmod` pomáhajú s crawl budgetom.

## Bezpečnosť

Všetky hodnoty sú XML-escaped:

```php
FrontendLayout::e($loc)  // escape &, <, >, ", '
```

Žiadny user input nedosiahne XML bez sanitizácie.

## Testy

Sitemap má dedicated testy:

```php
public function testSitemapListsArticles(): void
{
    $xml = (new SitemapAction())->handle(
        Request::create('GET', '/sitemap.xml')
    )->getContent();

    self::assertStringContainsString('<loc>', $xml);
    self::assertStringContainsString('hreflang="sk"', $xml);
    self::assertStringContainsString('hreflang="en"', $xml);
}
```

---

## Zdroje

- [Google, Sitemaps XML format](https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview)
- [Sitemaps.org, Protocol 0.9](https://www.sitemaps.org/protocol.html)
- [Google, hreflang](https://developers.google.com/search/docs/specialized/international/localized-versions)
- [MDN, XML escape characters](https://developer.mozilla.org/en-US/docs/Web/XML/XML_reference#special_characters_in_XML)
- [Google, Ping sitemap](https://developers.google.com/search/docs/crawling-indexing/sitemaps/ask-google-to-re-crawl)
