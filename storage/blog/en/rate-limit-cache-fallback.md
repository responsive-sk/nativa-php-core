---
title: "Rate limit cache fallback ... when 429 isn't the end of the world"
date: "2026-08-21"
version: "1.0.0"
tags: ["php", "architecture", "rate-limiting", "cache", "middleware"]
excerpt: "When a client exceeds the rate limit, they get a bare 'Too Many Requests'. But what if we served a cached version of the page instead? No new dependencies, pure PHP middleware."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "rate-limit-cache-fallback"
published: true
---

When a client exceeds our rate limit (60 requests per minute per IP),
they get a bare text "Too Many Requests" with HTTP status 429. No layout,
no content, no navigation. For scrapers and bots that's fine ... but for
legitimate visitors who accidentally hit the limit, it's a dead end.

Today we'll show how we added a **cache fallback** ... when a client is
rate-limited and a cached version exists, they get the cached page with
HTTP 429 instead of bare text. And the key insight: the cache is
**passively populated** ... normal requests have zero caching overhead.

## Why not cache for FCP

In our stack we've explicitly rejected caching as a performance
optimization. In the article [about our stack's
philosophy](/blog/filozofia-super-rychleho-php) we explain why ...
caching adds a layer that doesn't add value. LCP is 100/100 without it.

But rate limit cache fallback is a different case. Here caching isn't
about speed ... it's a **backup plan** when the client is blocked.
Normal requests are unaffected by the cache.

## How the middleware pipeline works

Our HTTP kernel uses the onion model ... middleware wraps around each
other like layers of an onion:

```
Request
  → SecurityHeaders (priority 0)  ... CSP nonce, security headers
    → Csrf (priority 1)           ... CSRF token validation
      → Session (priority 2)       ... session start
        → RateLimit (priority 3)   ... 60 req/min per IP
          → PageCache (priority 4) ... passive cache write
            → Auth                 ... authentication
              → Dispatch           ... routing + action
```

The critical ordering: **RateLimit runs BEFORE PageCache**. This means:

1. When RateLimit allows → PageCache captures and writes to cache
2. When RateLimit blocks → PageCache never gets a chance to run
3. But RateLimit **has access to the cache** and can serve from it

## Two types of middleware

### PageCacheMiddleware ... passive cache write

```php
final readonly class PageCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private PageCacheService $cache,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        // Only cacheable GET responses
        if ($this->cache->isCacheable($request, $response)) {
            $key = $this->cache->cacheKey($request);
            $this->cache->set($key, $response);
        }

        return $response;
    }
}
```

This middleware **never reads from cache**. It only writes. When a
request passes through RateLimit and succeeds, it captures the response
and stores it.

### RateLimitMiddleware ... cache fallback on block

```php
final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private \Closure $rateLimiterFactory,
        private ?PageCacheService $cache = null,  // backward compatible
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        // ... loopback check, rate limit check ...

        if (!$rateLimiter->isAllowed($key, self::GENERAL_LIMIT, self::GENERAL_WINDOW)) {
            // NEW: try cache fallback
            if (null !== $this->cache) {
                $cacheKey = $this->cache->cacheKey($request);
                $cached = $this->cache->get($cacheKey);
                if (null !== $cached) {
                    $response = Response::html($cached['content'], 429);
                    $retryAfter = $rateLimiter->getRetryAfter($key);
                    $response->setHeader('Retry-After', (string) $retryAfter);
                    $response->setHeader('X-Cache', 'HIT');
                    return $response;
                }
            }

            // Cache miss ... existing behavior
            return Response::html('Too Many Requests', 429);
        }

        return $next($request);
    }
}
```

When RateLimit blocks, it **checks the cache first**. Cache hit → cached
HTML with 429 + Retry-After. Cache miss → bare "Too Many Requests".

## The key insight: CSP nonce

The security headers middleware generates a per-request CSP nonce and
replaces the placeholder in HTML:

```php
// SecurityHeadersMiddleware
$request->setAttribute('csp_nonce', self::NONCE_PLACEHOLDER);
$response = $next($request);
// Replace placeholder with real nonce
$content = str_replace(self::NONCE_PLACEHOLDER, $this->nonce, $content);
```

The cache stores HTML with `__CSP_NONCE_PLACEHOLDER__` ... not a real
nonce. When RateLimit serves the cached response, it passes through
SecurityHeaders and gets a fresh nonce. No stale nonces in cache.

## Cache key and format

The key is SHA256 of method + path + query + locale:

```php
public function cacheKey(Request $request): string
{
    $data = $request->getMethod()
        . '|' . $request->getPathInfo()
        . '|' . http_build_query($request->getQuery())
        . '|' . LocalesServices::locale()->current()->code();

    return hash('sha256', $data);
}
```

The same article in SK and EN has **different keys** ... the cache is
locale-aware.

The cache file is JSON:

```json
{
  "content": "<!DOCTYPE html>...",
  "statusCode": 200,
  "headers": {"Content-Type": "text/html; charset=UTF-8"},
  "cachedAt": 1724265600,
  "ttl": 300
}
```

## When caching is bypassed

Cache bypass for:
- Non-GET requests (POST, PUT, DELETE)
- Non-200 status codes
- Non-HTML responses (JSON, XML)
- Admin pages (`/mark/*`)
- Debug panel (`/debug/*`)
- Auth pages (`/login`, `/register`)
- Uploads (`/storage/*`)
- Empty or too-large (> 1MB) responses

## Flow diagram

```
Normal request:
  Request → RateLimit (PASS) → PageCache (write) → Response (200)

Rate-limited request + cache HIT:
  Request → RateLimit (BLOCK) → check cache → Response (429 + cached HTML)

Rate-limited request + cache MISS:
  Request → RateLimit (BLOCK) → no cache → Response (429 + "Too Many Requests")
```

## Why not a full page cache

A full page cache (caching on every request) would add overhead on every
request ... file I/O, hash computation, TTL check. For our application
with SQLite and zero-dependency runtime, that's an unnecessary layer.

Rate limit cache fallback is **targeted** ... the cache is only populated
when a normal request succeeds, and only served when the client is
blocked. Zero overhead for 99.9% of requests.

## Admin flush

The `/mark/settings` page has a "Flush Page Cache" button ... deletes
everything in `storage/cache/pages/`. Useful when you publish a new
article and want to make sure blocked clients get fresh content.

**Update (2026-09):** `CacheInvalidator::flush()` now handles this
automatically, admin write operations (pages, articles, settings, media,
users...) flush the page cache, bulk actions once after the loop. The
manual button remains as a safety net.

## Conclusion

Cache fallback isn't a performance optimization ... it's **graceful
degradation**. When a client is blocked, instead of a dead end they get
cached content. And when they're not blocked, the cache doesn't slow
them down at all.

That's exactly in the spirit of our stack: **no extra layer, unless it
brings value**.

The solution was five lines of code in RateLimitMiddleware. We checked the page cache when blocking, served the cached HTML with a 429 status, and added a Retry-After header. No new dependencies, no new infrastructure.

Rate limiting remains a necessary guardrail, just not one that has to punish legitimate users. When you know the cache is already there, the fallback writes itself. The next person who gets blocked should see a page, not a dead end.

→ [CSP & htmx interaction](/blog/csp-htmx-interaction): when security meets the frontend
→ [Module isolation](/blog/module-isolation): why middleware lives where it lives
→ [Middleware pipeline](/blog/middleware-pipeline): seven layers that guard your routes

## Sources

- [MDN, HTTP 429 Too Many Requests](https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/429): HTTP rate limit status code
- [IETF, Rate Limit Header Fields (RFC 6585)](https://httpwg.org/specs/rfc6585.html): RateLimit-Limiter specification
- [PHP Manual, session_set_save_handler](https://www.php.net/manual/en/function.session-set-save-handler.php): session storage for rate limiting
- [Cloudflare, Rate Limiting](https://developers.cloudflare.com/waf/rate-limiting-rules/): rate limiting best practices
- [OWASP, Brute Force Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Brute_Force_Prevention_Cheat_Sheet.html): brute force protection
