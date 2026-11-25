---
title: "JSON-LD Structured Data for Blog — Article, BreadcrumbList, Person"
date: "2026-09-01"
version: "1.0.0"
tags: ["seo", "json-ld", "structured-data", "schema.org"]
excerpt: "How we implement JSON-LD structured data for blog articles, breadcrumbs, and author profiles. XSS-safe encoding, tests, Google Rich Results."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "jsonld-structured-data-pre-blog"
published: true
---

# JSON-LD Structured Data for Blog — Article, BreadcrumbList, Person

A blog article publishes. Google indexes it. No stars, no breadcrumb, no rich result. The page has a problem — it looks naked in search results without structured data.

## What We Generate

Structured data helps Google understand page content. Thanks to them, rich results appear in SERPs — stars, images, breadcrumb navigation.

### Homepage — Organization + WebSite + SearchAction

```json
[
  {
    "@type": "Organization",
    "name": "Nativa Starter",
    "url": "https://example.com/"
  },
  {
    "@type": "WebSite",
    "name": "Nativa Starter",
    "url": "https://example.com/",
    "potentialAction": {
      "@type": "SearchAction",
      "target": {
        "@type": "EntryPoint",
        "urlTemplate": "https://example.com/blog?q={search_term_string}"
      }
    }
  }
]
```

Google can display a search box directly in SERPs.

### Article — Article + BreadcrumbList

```json
[
  {
    "@type": "Article",
    "headline": "How We Built sitemap.xml",
    "datePublished": "2026-09-01",
    "author": {"@type": "Person", "name": "Nativa Dev"},
    "publisher": {"@type": "Organization", "name": "Nativa Starter"},
    "image": "https://example.com/storage/blog-images/cover.jpg"
  },
  {
    "@type": "BreadcrumbList",
    "itemListElement": [
      {"@type": "ListItem", "position": 1, "name": "Blog", "item": "https://example.com/blog"},
      {"@type": "ListItem", "position": 2, "name": "How We Built sitemap.xml"}
    ]
  }
]
```

Google displays breadcrumbs below the article title in SERPs.

### Author Profile — Person

```json
{
  "@type": "Person",
  "name": "Eva Autor",
  "url": "https://example.com/authors/u1"
}
```

## Implementation

```php
final class StructuredDataService
{
    public static function article(
        string $title,
        string $description,
        string $url,
        string $published,
        ?string $image,
        string $authorName,
        string $siteName,
    ): string {
        $article = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Article',
            'headline'         => $title,
            'datePublished'    => $published,
            'author'           => ['@type' => 'Person', 'name' => $authorName],
            'publisher'        => ['@type' => 'Organization', 'name' => $siteName],
        ];

        // BreadcrumbList
        $blogUrl = substr($url, 0, strrpos($url, '/'));
        $breadcrumb = [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Blog', 'item' => $blogUrl],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $title],
            ],
        ];

        return self::encode([$article, $breadcrumb]);
    }
}
```

## Security — JSON_HEX_TAG

```php
json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
```

`JSON_HEX_TAG` ensures that `</script>` in user values never breaks the JSON block — XSS-safe by design.

## Integration with FrontendLayout

```php
FrontendLayout::render(
    'Blog',
    $content,
    structuredData: StructuredDataService::article(...),
);
```

JSON-LD is inserted into `<script type="application/ld+json">` in `<head>`.

## Tests

```php
public function testArticlePageIncludesJsonLd(): void
{
    $html = (new BlogArticleAction())->handle(...)->getContent();

    self::assertStringContainsString('application/ld+json', $html);
    self::assertStringContainsString('"@type":"Article"', $html);
    self::assertStringContainsString('"@type":"BreadcrumbList"', $html);
}
```

The solution was three JSON-LD blocks built by `StructuredDataService`. We replaced manual schema markup with a service that reads article metadata. One test per block.

JSON-LD remains the simplest rich result contract — just not one you should skip. When you know what Google needs to understand, the schema writes itself. The next person who adds an article should not have to guess which structured data is required.

→ [How we built the sitemap](/blog/how-we-built-sitemap) — dynamic XML sitemap with hreflang
→ [Robots.txt what we hide](/blog/robots-txt-what-we-hide) — the allow-list behind Disallow
→ [CSP & htmx interaction](/blog/csp-htmx-interaction) — when security meets the frontend

## Sources

- [Google — Structured data](https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data)
- [schema.org — Article](https://schema.org/Article)
- [schema.org — BreadcrumbList](https://schema.org/BreadcrumbList)
- [schema.org — SearchAction](https://schema.org/SearchAction)
- [Google — Rich results test](https://search.google.com/test/rich-results)
