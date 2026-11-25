---
title: "Cache wrappers ... good servant, bad master"
date: "2026-08-25"
version: "1.0.0"
tags: ["security-series", "php", "cache", "rate-limiting", "performance", "architecture"]
excerpt: "Cache is a great tool ... but only when you know when to use it and when not to. The story of how we added cache as a rate-limit fallback and why we rejected cache for performance optimization."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "cache-wrappers-dobry-slucha-zly-pan"
published: true
---

Developers fall into two camps: cache everything or cache nothing. Both are wrong. Cache is a tool — and every tool has its place.

## Cache has a bad reputation

Developers fall into two camps:

1. **Cache everything** ... Redis, OPcache, CDN, Varnish, lost hours of life
2. **No cache** ... "We'll hit LCP 100/100 without it"

Both are wrong. Cache isn't an automatic answer or an enemy. It's a **tool** ... and every tool has its place.

## Two types of cache

### 1. Read cache

You store answers to questions that repeat:

- Static HTML pages
- Expensive query results
- API responses

```php
// PageCacheService ... stores HTML responses as files
$cache->set($key, $response, ttl: 300); // 5 minutes

// On next request ... reading from cache
$cached = $cache->get($key);
if (null !== $cached) {
    return Response::html($cached['content'], 200);
}
```

### 2. Write cache (Outbox)

You store **events** for later processing:

- Email notifications
- Webhook calls
- P2P broadcasts

```php
// Outbox ... stores domain events for async processing
OutboxMessage::create('OrderCreated', $order->toArray());
```

**Cache = "What did you tell me?"** → READ
**Outbox = "What should I tell you?"** → WRITE

## When cache helps

| Use case | Cache type | Why |
|----------|-----------|-----|
| Rate-limit fallback | Read | Serve cached on 429 |
| Static assets | Read | CDN, browser cache |
| Rendered HTML | Read | Expensive rendering |
| Session data | Read | User state |
| Database queries | Read | DB-specific |

### Example: Rate-limit cache fallback

When a client exceeds the rate limit (60 requests/min), they get a **cached version of the page** instead of bare "Too Many Requests":

```php
// RateLimitMiddleware ... when client is blocked
private function blockedResponse(...): Response
{
    if (null !== $this->cache) {
        $cached = $this->cache->get($cacheKey);
        if (null !== $cached) {
            $response = Response::html($cached['content'], 429);
            $response->setHeader('Retry-After', (string) $retryAfter);
            $response->setHeader('X-Cache', 'HIT');
            return $response; // Cached page with 429
        }
    }
    return Response::html('Too Many Requests', 429); // Bare text
}
```

Normal requests are **not affected** by cache ... cache is populated passively via `PageCacheMiddleware`. Rate-limit cache fallback is a **backup plan**, not an optimization.

## When cache hurts

| Use case | Why NOT | Alternative |
|----------|---------|-------------|
| User-specific data | See other users' data | Direct query |
| Real-time data | Stale data | WebSocket |
| Write-heavy paths | Invalidation complexity | Skip cache |
| Simple reads | Overhead > benefit | Direct query |

### Example: Why we don't cache user data

```php
//  BAD: cache for user-specific data
$cached = $cache->get('user-' . $userId);
if ($cached) return $cached;
$user = $db->query('SELECT * FROM users WHERE id = ?', $userId);
$cache->set('user-' . $userId, $user);

// Problem: User A sees User B's cached data
// When User B changes name, cache still has old value
```

```php
//  GOOD: direct query for user-specific data
$user = $db->query('SELECT * FROM users WHERE id = ?', $userId);
// No invalidation, no stale data, no complexity
```

## Cache in our project

### PageCacheService ... file-based HTML cache

```php
final readonly class PageCacheService
{
    private const int DEFAULT_TTL = 300; // 5 minutes
    private const int MAX_CACHE_SIZE = 1_048_576; // 1 MB

    // Key = SHA256(method + path + query + locale)
    public function cacheKey(Request $request, string $locale = ''): string
    {
        $data = $request->getMethod()
            . '|' . $request->getPathInfo()
            . '|' . http_build_query($request->getQueryParams())
            . '|' . $locale;
        return hash('sha256', $data);
    }

    // Lazy pruning ... cleans expired files once per 5 minutes
    private function lazyPrune(): void { ... }
}
```

**Key decisions:**
- **File-based** ... no Redis, no external dependency
- **Lazy pruning** ... doesn't clean on every request
- **Admin bypass** ... `/mark`, `/debug`, `/storage` aren't cached
- **Auth bypass** ... `/login`, `/register` aren't cached

### PageCacheMiddleware ... passive population

```php
// Every cacheable GET response is saved to cache
// But only when:
// - It's a GET request
// - Status 200
// - Content-Type text/html
// - Not an admin/auth path
// - Content is not empty
```

Cache is **never actively populated** ... only passively, when a request already passed through the normal pipeline.

## Evolution stage

```
1. No cache
   → Raw performance, direct queries
   → LCP 100/100 achievable without cache

2. Cache wrappers
   → Rate-limit fallback (cached on 429)
   → OPcache for PHP bytecode
   → Browser cache for static assets

3. Outbox pattern
   → Cache for events (not just data)
   → Async side effects with retry
   → Write-path optimization

4. P2P cache
   → Distributed availability
   → Each node is consumer and provider
   → Knowledge chunks shared across network
```

## The lesson

**Cache isn't a mistake ... but it's not an automatic answer either.**

When cache helps (rate-limit fallback, external systems, expensive rendering) ... use it. When it hurts (user data, real-time, write-heavy) ... skip it.

And remember: **cache wrappers aren't mistakes ... they're evolution stages.** Each one taught you something about data access that prepared you for the next. The progression cache → outbox → P2P is really about understanding that "the answer doesn't always need to be new, just close."

Cache remains a good servant. The mistake is letting it become the master. Rate-limit fallback, expensive rendering, external systems — cache earns its place there. User data, real-time, write-heavy paths — cache has no business there.

---

The answer doesn't always need to be new. Just close.

---

→ [ACID for beginners](/blog/acid-for-beginners) — why transactions aren't boring
→ [Outbox pattern](/blog/outbox-pattern-when-cache-isnt-enough) — cache for the write path
→ [How argon2id crashed shared hosting](/blog/how-sha-hash-algorithm-crashed-shared-hosting) — infrastructure limits choices

## Sources

- [MDN — Cache-Control](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control) — HTTP caching headers specification
- [PHP Manual — APCu](https://www.php.net/manual/en/book.apcu.php) — in-memory cache for PHP
- [Cloudflare — What is Caching?](https://www.cloudflare.com/learning/performance/what-is-caching/) — caching basics
- [OWASP — Caching Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Caching_Cheat_Sheet.html) — security aspects of caching
- [HTTP/1.1 Caching (RFC 9111)](https://httpwg.org/specs/rfc9111.html) — official RFC specification
