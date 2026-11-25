---
title: "robots.txt: What We Hide and Why"
date: "2026-09-01"
version: "1.0.0"
tags: ["seo", "robots-txt", "security", "crawl"]
excerpt: "Allow-whitelist strategy for robots.txt, disallow all, re-allow only public pages. Why we never mention admin, debug, or uploads."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "robots-txt-co-blokujeme"
published: true
---

# robots.txt: What We Hide and Why

Most websites have a robots.txt full of `Disallow` rules, but every `Disallow` tells a crawler "something exists here." A better approach: **disallow all, allow only public**.

## Allow-Whitelist Strategy

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

The first rule `Disallow: /` blocks **everything**. Then `Allow` rules permit only what we want indexed.

## Why Not Blacklist?

Blacklist approach:
```txt
Disallow: /mark
Disallow: /debug
Disallow: /storage/uploads
Disallow: /login
```

Problem: every `Disallow` tells a crawler "the path `/mark` exists." Hackers read robots.txt, it's the first reconnaissance step.

Whitelist approach:
```txt
Disallow: /
Allow: /blog
```

The crawler sees only what we want. `/mark`, `/debug`, `/uploads`: never mentioned.

## What We Allow

| Path | Why |
|------|-----|
| `/` + `/sk` + `/en` | Homepages |
| `/blog` | Articles + index |
| `/authors` | Author profiles |
| `/storage/blog-images` | Cover images |
| `/sitemap.xml` | Sitemap |
| `/llms.txt` | LLM-friendly overview |

## What We Never Mention

- `/mark`: admin panel (protected by auth + RBAC)
- `/debug`: debug panel (protected by APP_DEBUG)
- `/storage/uploads`: user uploads
- `/login`, `/register`: auth pages
- `/mark/*`: all admin routes

These paths are protected **differently**: auth middleware, noindex meta tags, APP_DEBUG gating. robots.txt is not a security boundary, just a crawl hint.

## Implementation

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

`$request->absoluteUrl('/sitemap.xml')`: absolute URL for the Sitemap line.

## Common Mistakes

1. **Blacklist instead of whitelist**: reveals admin page existence
2. **Missing Sitemap line**: Google must find the sitemap elsewhere
3. **Allow: /storage/uploads**: user uploads should not be indexed
4. **Disallow: /blog**: blocks article indexing

The fix is one file with twenty lines. We replaced the blacklist with a whitelist, added the Sitemap line, and closed the admin gap. Google re-crawled within hours.

robots.txt remains a simple text file, just not one you should ignore. When you know what to hide, the Allow list writes itself. The next person who deploys your app should not have to guess which paths are public.

→ [How we built the sitemap](/blog/how-we-built-sitemap): dynamic XML sitemap with hreflang
→ [JSON-LD structured data](/blog/jsonld-structured-data-for-blog): rich results for blog articles
→ [CSP & htmx interaction](/blog/csp-htmx-interaction): when security meets the frontend

## Sources

- [Google, Build and submit a sitemap](https://developers.google.com/search/docs/crawling-indexing/sitemaps/overview)
- [Google, robots.txt specification](https://developers.google.com/search/docs/crawling-indexing/robots/intro)
- [Google, Guide to robots.txt](https://developers.google.com/search/docs/crawling-indexing/robots/robots_txt)
- [RFC 9309, Robots Exclusion Protocol](https://www.rfc-editor.org/rfc/rfc9309)
- [Moz, robots.txt guide](https://moz.com/learn/seo/robotstxt)
