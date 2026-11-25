---
title: "JSON-LD structured data pre blog: Article, BreadcrumbList, Person"
date: "2026-09-01"
version: "1.0.0"
tags: ["seo", "json-ld", "structured-data", "schema.org"]
excerpt: "Ako implementujeme JSON-LD structured data pre blog články, breadcrumbs a author profily. XSS-safe encoding, testy, Google Rich Results."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "jsonld-structured-data-for-blog"
published: true
---

# JSON-LD structured data pre blog: Article, BreadcrumbList, Person

Structured data pomáhajú Google pochopiť obsah stránky. Vďaka nim sa v SERP zobrazujú rich results, hviezdičky, obrázky, breadcrumb navigácia.

## Čo generujeme

### Homepage: Organization + WebSite + SearchAction

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

Google môže zobraziť search box priamo v SERP.

### Článok: Article + BreadcrumbList

```json
[
  {
    "@type": "Article",
    "headline": "Ako sme urobili sitemap.xml",
    "datePublished": "2026-09-01",
    "author": {"@type": "Person", "name": "Nativa Dev"},
    "publisher": {"@type": "Organization", "name": "Nativa Starter"},
    "image": "https://example.com/storage/blog-images/cover.jpg"
  },
  {
    "@type": "BreadcrumbList",
    "itemListElement": [
      {"@type": "ListItem", "position": 1, "name": "Blog", "item": "https://example.com/blog"},
      {"@type": "ListItem", "position": 2, "name": "Ako sme urobili sitemap.xml"}
    ]
  }
]
```

Google zobrazí breadcrumbs pod názvom článku v SERP.

### Author profil: Person

```json
{
  "@type": "Person",
  "name": "Eva Autor",
  "url": "https://example.com/authors/u1"
}
```

## Implementácia

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

## Bezpečnosť: JSON_HEX_TAG

```php
json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
```

`JSON_HEX_TAG` zabezpečuje, že `</script>` užívateľská hodnota nikdy nepreruší JSON blok, XSS-safe by design.

## Integrácia s FrontendLayout

```php
FrontendLayout::render(
    'Blog',
    $content,
    structuredData: StructuredDataService::article(...),
);
```

JSON-LD sa vloží do `<script type="application/ld+json">` v `<head>`.

## Testy

```php
public function testArticlePageIncludesJsonLd(): void
{
    $html = (new BlogArticleAction())->handle(...)->getContent();

    self::assertStringContainsString('application/ld+json', $html);
    self::assertStringContainsString('"@type":"Article"', $html);
    self::assertStringContainsString('"@type":"BreadcrumbList"', $html);
}
```

---

## Zdroje

- [Google, Structured data](https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data)
- [schema.org, Article](https://schema.org/Article)
- [schema.org, BreadcrumbList](https://schema.org/BreadcrumbList)
- [schema.org, SearchAction](https://schema.org/SearchAction)
- [Google, Rich results test](https://search.google.com/test/rich-results)
