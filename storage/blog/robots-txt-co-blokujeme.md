---
title: "robots.txt — čo blokujeme a prečo"
date: "2026-09-01"
version: "1.0.2"
tags: ["seo", "robots-txt", "security", "crawl"]
excerpt: "Allow-whitelist stratégia pre robots.txt — disallow all, re-allow len verejné stránky. Prečo nikdy nespomíname admin, debug ani uploads."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "robots-txt-what-we-hide"
published: true
---

# robots.txt — čo blokujeme a prečo

Väčšina webov má robots.txt plný `Disallow` pravidiel — ale každé `Disallow` hovorí crawlerovi „tu niečo je“. Lepší prístup: **disallow all, allow len verejné**.

## Allow-whitelist stratégia

```txt
User-agent: *
Disallow: /
Allow: /$
Allow: /sk
Allow: /en
Allow: /blog
Allow: /authors
Allow: /storage/blog-images
Allow: /sitemap.xml
Allow: /llms.txt

Sitemap: https://example.com/sitemap.xml
```

Prvé pravidlo `Disallow: /` zakazuje **všetko**. Potom `Allow` pravidlá povoľujú len to, čo chceme indexovať.

## Prečo nie blacklist?

Blacklist approach:
```txt
Disallow: /mark
Disallow: /debug
Disallow: /storage/uploads
Disallow: /login
```

Problém: každé `Disallow` hovorí crawlerovi „existuje cesta `/mark`“. Hackeri čítajú robots.txt — je to prvý reconnaissance krok.

Whitelist approach:
```txt
Disallow: /
Allow: /blog
```

Crawler vidí len to, čo chceme. `/mark`, `/debug`, `/uploads` — nikdy sa nespomenú.

## Čo povolujeme

| Cesta | Prečo |
|-------|-------|
| `/` + `/sk` + `/en` | Domovské stránky |
| `/blog` | Články + index |
| `/authors` | Author profily |
| `/storage/blog-images` | Cover images |
| `/sitemap.xml` | Sitemap |
| `/llms.txt` | LLM-friendly overview |

## Čo nikdy nespomíname

- `/mark` — admin panel (chránený auth + RBAC)
- `/debug` — debug panel (chránený APP_DEBUG)
- `/storage/uploads` — user uploads
- `/login`, `/register` — auth stránky
- `/mark/*` — všetky admin routes

Tieto cesty chránme **inak** — auth middleware, noindex meta tags, APP_DEBUG gating. robots.txt nie je bezpečnostný nástroj, len crawl hint.

## Implementácia

```php
#[Route(path: '/robots.txt', method: 'GET', name: 'robots')]
final class RobotsAction
{
    public function handle(Request $request): Response
    {
        $content = 'User-agent: *' . "\n"
            . 'Disallow: /' . "\n"
            . 'Allow: /$' . "\n"
            . 'Allow: /sk' . "\n"
            . 'Allow: /en' . "\n"
            . 'Allow: /blog' . "\n"
            . 'Allow: /authors' . "\n"
            . 'Allow: /storage/blog-images' . "\n"
            . 'Allow: /sitemap.xml' . "\n"
            . 'Allow: /llms.txt' . "\n"
            . "\n"
            . 'Sitemap: ' . $request->absoluteUrl('/sitemap.xml') . "\n";

        $response = new Response($content);
        $response->setHeader('Content-Type', 'text/plain; charset=utf-8');

        return $response;
    }
}
```

`$request->absoluteUrl('/sitemap.xml')` — absolútna URL pre Sitemap riadok.

## Najčastejšie chyby

1. **Blacklist namiesto whitelist** — prezrádza existenciu admin stránok
2. **Chýbajúci Sitemap riadok** — Google musí hľadať sitemap inde
3. **Allow: /storage/uploads** — user uploads by nemali byť indexované
4. **Disallow: /blog** — blokuje indexovanie článkov

---

## Zdroje

- [Google — Build and submit a sitemap](https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview)
- [Google — robots.txt specification](https://developers.google.com/search/docs/crawling-indexing/robots/intro)
- [Google — Guide to robots.txt](https://developers.google.com/search/docs/crawling-indexing/robots/robots_txt)
- [RFC 9309 — Robots Exclusion Protocol](https://www.rfc-editor.org/rfc/rfc9309)
- [Moz — robots.txt guide](https://moz.com/learn/seo/robotstxt)
