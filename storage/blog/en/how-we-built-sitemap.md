---
title: "How We Built sitemap.xml Without a Framework"
date: "2026-09-01"
version: "1.0.0"
tags: ["seo", "sitemap", "xml", "php"]
excerpt: "Dynamic XML sitemap generated with pure PHP — hreflang alternates, author profiles, feed endpoints. No framework, no CLI generation, no cache."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "ako-sme-spravili-sitemap"
published: true
---

# How We Built sitemap.xml Without a Framework

A static sitemap breaks the moment you publish a new article. The old XML file is stale. Googlebot hits a problem on the last page you wrote — no entry in the sitemap, no index.

## Why Dynamic?

Sitemap.xml is the first thing Googlebot looks for. In Nativa Minimal we generate a dynamic XML sitemap directly in PHP — no framework, no CLI generation, no cache.

A static sitemap requires a build step. When you add an article, you must run `generate-sitemap`. With a dynamic sitemap, the content updates automatically — every request generates current XML.

## Sitemap Structure

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

## Implementation

The sitemap is generated in a single action — `SitemapAction`:

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

Key: `$request->absoluteUrl('')` — generates absolute URL from the current request. Works on localhost, production, behind proxy.

## What We Include

### Static Pages
- Homepage (both locales)
- Blog index (both locales)
- Feed endpoints (Atom + RSS)

### Articles
Every article in both locales with hreflang alternates:

```php
$alternates = '<xhtml:link rel="alternate" hreflang="sk" href="...'
    . '"/>' . "\n";
$alternates .= '<xhtml:link rel="alternate" hreflang="en" href="...'
    . '"/>' . "\n";
```

Google knows that `example.com/blog/hello-world` and `example.com/en/blog/hello-world` are the same article in different languages.

### Pages
Static pages (published only) with hreflang.

### Authors
Author profiles reachable from articles — deduplicated.

## Priority and Changefreq

| Type | Priority | Changefreq |
|------|----------|------------|
| Homepage | 1.0 | weekly |
| Blog index | 0.9 | daily |
| Articles | 0.8 | weekly |
| Pages | 0.6 | monthly |
| Authors | 0.6 | weekly |
| Feeds | 0.3 | daily |

Priority is not binding — Google ignores it. But `changefreq` and `lastmod` help with crawl budget.

## Security

All values are XML-escaped:

```php
FrontendLayout::e($loc)  // escape &, <, >, ", '
```

No user input reaches XML without sanitization.

## Tests

The sitemap has dedicated tests:

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

The solution was one action class with 80 lines. We replaced the static XML file with a dynamic generator that reads the router's `#[Route]` attributes. No manual registration per article.

Sitemap remains the simplest SEO contract — just not one you should ignore. When you know what Google needs to crawl, the XML writes itself. The next person who adds an article should not have to update the sitemap by hand.

→ [Robots.txt what we hide](/blog/robots-txt-what-we-hide) — the allow-list behind Disallow
→ [JSON-LD structured data](/blog/jsonld-structured-data-for-blog) — rich results for blog articles
→ [CSP & htmx interaction](/blog/csp-htmx-interaction) — when security meets the frontend

## Sources

- [Google — Sitemaps XML format](https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview)
- [Sitemaps.org — Protocol 0.9](https://www.sitemaps.org/protocol.html)
- [Google — hreflang](https://developers.google.com/search/docs/specialized/international/localized-versions)
- [MDN — XML escape characters](https://developer.mozilla.org/en-US/docs/Web/XML/XML_reference#special_characters_in_XML)
- [Google — Ping sitemap](https://developers.google.com/search/docs/crawling-indexing/sitemaps/ask-google-to-re-crawl)
