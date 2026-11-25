---
title: "Rate limit cache fallback - keď 429 nie je koncom sveta"
date: "2026-08-21"
version: "1.0.2"
tags: ["php", "architecture", "rate-limiting", "cache", "middleware"]
excerpt: "Keď klient prekročí rate limit, dostane holé „Too Many Requests“. Ale čo keby sme namiesto toho servírovali cached verziu stránky? Bez nových závislostí, čistý PHP middleware."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "rate-limit-cache-fallback"
published: true
---

Keď klient prekročí náš rate limit (60 requestov za minútu na IP), dostane
holý text „Too Many Requests“ s HTTP status 429. Žiadny layout, žiadny
obsah, žiadna navigácia. Pre scrapery a botov je to v poriadku ... ale
pre legitímnych návštevníkov, ktorí náhodou prekročili limit, je to
mŕtvy koniec.

Dnes si ukážeme, ako sme pridali **cache fallback** stav, keď je klient
rate-limit-ovaný a cache existuje, dostane cached verziu stránky s HTTP
429 namiesto holého textu. A kľúčové: cache sa **napĺňa pasívne** ...
normálne requesty majú nulový caching overhead. 

## Prečo cache nie je na FCP

V našom stacku sme explicitne odmietli cache pre optimalizáciu
výkonu. V článku [o filozofii nášho stacku](/blog/filozofia-super-rychleho-php)
vysvetľujeme prečo cache pridáva vrstvu, ktorá nám nepridáva hodnotu.
LCP je 100/100 iba bez nej.

Ale rate limit cache fallback je iný prípad. Tu cache neslúži na
zrýchlenie ... slúži ako **záložný plán** keď je klient bloknutý.
Normálne requesty cache neovplyvňujú.

## Ako funguje middleware pipeline

Náš HTTP kernel používa onion model ... middleware sa zabalí okolo seba
ako vrstvy cibule:

```
Request
  → SecurityHeaders (priority 0)  ... CSP nonce, bezpečnostné hlavičky
    → Csrf (priority 1)           ... CSRF token validation
      → Session (priority 2)       ... session start
        → RateLimit (priority 3)   ... 60 req/min per IP
          → PageCache (priority 4) ... pasívne cache write
            → Auth                 ... autentifikácia
              → Dispatch           ... routing + action
```

Kľúčové poradie: **RateLimit beží PRED PageCache**. To znamená:

1. Keď RateLimit povolí request → PageCache ho zachytí a zapíše do cache
2. Keď RateLimit blokuje request → PageCache sa nikdy nedostane na rad
3. Ale RateLimit **má prístup k cache** a môže z nej servírovať

## Dva typy middleware

### PageCacheMiddleware ... pasívne cache write

```php
final readonly class PageCacheMiddleware implements MiddlewareInterface
{
    public function __construct(
        private PageCacheService $cache,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        // Iba cacheable GET responses
        if ($this->cache->isCacheable($request, $response)) {
            $key = $this->cache->cacheKey($request);
            $this->cache->set($key, $response);
        }

        return $response;
    }
}
```

Tento middleware **nikdy nečíta z cache**. Iba zapisuje. Keď request
prejde cez RateLimit a je úspešný, zachytí response a uloží ho.

### RateLimitMiddleware ... cache fallback pri blokovaní

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
            // NOVÉ: skús cache fallback
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

            // Cache miss ... existujúci behavior
            return Response::html('Too Many Requests', 429);
        }

        return $next($request);
    }
}
```

Keď RateLimit blokuje, **najprv overí cache**. Ak cache hit → cached
HTML s 429 + Retry-After. Ak cache miss → holý „Too Many Requests“.

## Kľúčový insight: CSP nonce

Bezpečnostné hlavičky middleware generuje per-request CSP nonce a nahradzuje
placeholder v HTML:

```php
// SecurityHeadersMiddleware
$request->setAttribute('csp_nonce', self::NONCE_PLACEHOLDER);
$response = $next($request);
// Replace placeholder with real nonce
$content = str_replace(self::NONCE_PLACEHOLDER, $this->nonce, $content);
```

Cache ukladá HTML s `47d69305c83a0a3fbdc9462ab43681f3` ... nie s reálnym nonce.
Keď RateLimit servíruje cached response, ten prejde cez SecurityHeaders
a dostane fresh nonce. Žiadne stale nonces v cache.

## Cache kľúč a format

Kľúč je SHA256(method + path + query + locale):

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

Rovnaký článok v SK a EN má **rôzne kľúče** ... cache je locale-aware.

Cache súbor je JSON:

```json
{
  "content": "<!DOCTYPE html>...",
  "statusCode": 200,
  "headers": {"Content-Type": "text/html; charset=UTF-8"},
  "cachedAt": 1724265600,
  "ttl": 300
}
```

## Kedy sa necachuje

Cache bypass pre:
- Non-GET requesty (POST, PUT, DELETE)
- Non-200 status kódy
- Non-HTML response (JSON, XML)
- Admin stránky (`/mark/*`)
- Debug panel (`/debug/*`)
- Auth stránky (`/login`, `/register`)
- Uploady (`/storage/*`)
- Prázdne alebo príliš veľké (> 1MB) responses

## Flow diagram

```
Normálny request:
  Request → RateLimit (PASS) → PageCache (write) → Response (200)

Rate-limitovaný request + cache HIT:
  Request → RateLimit (BLOCK) → check cache → Response (429 + cached HTML)

Rate-limitovaný request + cache MISS:
  Request → RateLimit (BLOCK) → no cache → Response (429 + "Too Many Requests")
```

## Prečo nie plný page cache

Plný page cache (cache na všetkých requestoch) by pridal overhead na
každý request ... file I/O, hash computation, TTL check. Pre našu
aplikáciu s SQLite a zero-dependency runtime je to zbytočná vrstva.

Rate limit cache fallback je **cielený** ... cache sa napĺňa iba keď
normálny request prejde, a servíruje sa iba keď je klient bloknutý.
Nulový overhead pre 99.9% requestov.

## Admin flush

Na `/mark/settings` je tlačidlo „Flush Page Cache“ ... vymaže všetko
v `storage/cache/pages/`. Užitočné keď publikuješ nový článok a chceš
istiť, že bloknutí klienti dostanú fresh content.

**Update (2026-09):** o toto sa teraz stará `CacheInvalidator::flush()`
automaticky, admin write operácie (pages, articles, settings, media,
users...) flushujú page cache, bulk akcie raz po spracovaní. Manuálne
tlačidlo ostáva len ako istota.


Cache fallback nie je optimalizácia výkonu ... je to **graceful degradation**.
Keď je klient bloknutý, namiesto mŕtveho konca dostane cached obsah.
A keď nie je bloknutý, cache ho nijak nespomaľuje.

To je presne v duchu nášho stacku: **žiadna vrstva navyše, iba keď
prináša hodnotu**.

## Zdroje

- [MDN, HTTP 429 Too Many Requests](https://developer.mozilla.org/en-US/docs/Web/HTTP/Status/429): HTTP rate limit status code
- [IETF, Rate Limit Header Fields (RFC 6585)](https://httpwg.org/specs/rfc6585.html): RateLimit-Limiter špecifikácia
- [PHP Manual, session_set_save_handler](https://www.php.net/manual/en/function.session-set-save-handler.php): session storage pre rate limiting
- [Cloudflare, Rate Limiting](https://developers.cloudflare.com/waf/rate-limiting-rules/): rate limiting best practices
- [OWASP, Brute Force Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Brute_Force_Prevention_Cheat_Sheet.html): brute force ochrana
