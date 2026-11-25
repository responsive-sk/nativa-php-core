---
title: "Cache wrappers ... dobrý sluha, zlý pán"
date: "2026-08-25"
version: "1.0.2"
tags: ["security-series", "php", "cache", "rate-limiting", "performance", "architecture"]
excerpt: "Cache je skvelý nástroj ... ale len keď viete kedy ho použiť a kedy nie. Príbeh o tom, ako sme pridali cache ako fallback pre rate limiting a prečo sme odmietli cache pre performance optimalizáciu."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "cache-wrappers-good-servant-bad-master"
published: true
---

Vývojári majú dva tábory: cache všetko, alebo žiadny cache. Cache nie je ani automatická odpoveď, ani nepriateľ. Je to nástroj.

## Cache má zlú povesť

Vývojári majú dva tábory:

1. **Cache všetko** ... Redis, OPcache, CDN, Varnish, stratené hodiny života
2. **Žiadny cache** ... „LCP 100/100 dosiahneme bez neho“

Oba sú zlé ... a každý nástroj má svoje miesto.

## Dva typy cache

### 1. Cache pre čítanie (read cache)

Ukladáte odpovede na otázky, ktoré sa opakujú:

- Statické HTML stránky
- Výsledky drahých dotazov
- API odpovede

```php
// PageCacheService ... ukladá HTML odpovede do súborov
$cache->set($key, $response, ttl: 300); // 5 minút

// Pri ďalšom requeste ... čítanie z cache
$cached = $cache->get($key);
if (null !== $cached) {
    return Response::html($cached['content'], 200);
}
```

### 2. Cache pre zápis (write cache / Outbox)

Ukladáte **udalosti** pre neskoršie spracovanie:

- Email notifikácie
- Webhook volania
- P2P broadcasty

```php
// Outbox ... ukladá domain events pre async spracovanie
OutboxMessage::create('OrderCreated', $order->toArray());
```

**Cache = „Čo si mi povedal?“** → READ
**Outbox = „Čo ti mám povedať?“** → WRITE

## Kedy cache pomáha

| Prípad | Typ cache | Prečo |
|--------|-----------|-------|
| Rate-limit fallback | Read | Serving cached on 429 |
| Statické assety | Read | CDN, browser cache |
| Renderované HTML | Read | Drahý rendering |
| Session data | Read | User state |
| Database queries | Read | DB-specific |

### Príklad: Rate-limit cache fallback

Keď klient prekročí rate limit (60 requestov/min), dostane **cached verziu stránky** namiesto holého „Too Many Requests“:

```php
// RateLimitMiddleware ... keď je klient bloknutý
private function blockedResponse(...): Response
{
    if (null !== $this->cache) {
        $cached = $this->cache->get($cacheKey);
        if (null !== $cached) {
            $response = Response::html($cached['content'], 429);
            $response->setHeader('Retry-After', (string) $retryAfter);
            $response->setHeader('X-Cache', 'HIT');
            return $response; // Cached stránka s 429
        }
    }
    return Response::html('Too Many Requests', 429); // Holý text
}
```

Normálne requesty cache **neovplyvňujú** ... cache sa napĺňa pasívne cez `PageCacheMiddleware`. Rate-limit cache fallback je **záložný plán**, nie optimalizácia.

## Kedy cache škodí

| Prípad | Prečo NIE | Alternatíva |
|--------|-----------|-------------|
| User-specific data | Vidíte cudzie dáta | Priamy query |
| Real-time data | Stale data | WebSocket |
| Write-heavy paths | Invalidation complexity | Skip cache |
| Simple reads | Overhead > benefit | Priamy query |

### Príklad: Prečo necacheujeme user data

```php
//  ZLE: cache pre user-specific data
$cached = $cache->get('user-' . $userId);
if ($cached) return $cached;
$user = $db->query('SELECT * FROM users WHERE id = ?', $userId);
$cache->set('user-' . $userId, $user);

// Problém: User A vidí User B's cached data
// keď sa User B zmení meno, cache stále má staré
```

```php
//  DOBRE: priamy query pre user-specific data
$user = $db->query('SELECT * FROM users WHERE id = ?', $userId);
// Žiadna invalidácia, žiadne stale data, žiadna komplexita
```

## Cache v našom projekte

### PageCacheService ... file-based HTML cache

```php
final readonly class PageCacheService
{
    private const int DEFAULT_TTL = 300; // 5 minút
    private const int MAX_CACHE_SIZE = 1_048_576; // 1 MB

    // Kľúč = SHA256(method + path + query + locale)
    public function cacheKey(Request $request, string $locale = ''): string
    {
        $data = $request->getMethod()
            . '|' . $request->getPathInfo()
            . '|' . http_build_query($request->getQueryParams())
            . '|' . $locale;
        return hash('sha256', $data);
    }

    // Lazy pruning ... vyčistí expirované súbory raz za 5 minút
    private function lazyPrune(): void { ... }
}
```

**Kľúčové rozhodnutia:**
- **File-based** ... žiadny Redis, žiadna externá závislosť
- **Lazy pruning** ... nečistíme na každom requeste
- **Bypass pre admin** ... `/mark`, `/debug`, `/storage` sa necachujú
- **Bypass pre auth** ... `/login`, `/register` sa necachujú

### PageCacheMiddleware ... pasívne napĺňanie

```php
// Každý cacheable GET response sa uloží do cache
// Ale len keď:
// - Je to GET request
// - Status 200
// - Content-Type text/html
// - Nie je to admin/auth path
// - Obsah nie je prázdny
```

Cache sa **nikdy nenapĺňa aktívne** ... len pasívne, keď už request prešiel normálnym pipeline.

## Evolučná fáza

```
1. Žiadny cache
   → Raw performance, priame queries
   → LCP 100/100 dosiahnuteľný bez cache

2. Cache wrappers
   → Rate-limit fallback (cached na 429)
   → OPcache pre PHP bytecode
   → Browser cache pre statické assety

3. Outbox pattern
   → Cache pre events (nielen data)
   → Async side effects s retry
   → Write-path optimization

4. P2P cache
   → Distributed availability
   → Každý uzol je consumer aj provider
   → Knowledge chunks shared across network
```

**Cache nie je chyba ... ale nie je ani automatická odpoveď.**

Keď cache pomáha (rate-limit fallback, externé systémy, drahý rendering) ... použite ho. Keď škodí (user data, real-time, write-heavy) ... preskočte ho.

A pamätajte: **cache wrappers nie sú chyby ... sú evolučné štádiá.** Každé vás naučilo niečo o prístupe k dátam, čo vás pripravilo na ďalšie. Progresia cache → outbox → P2P je v skutočnosti o pochopení, že „odpoveď nemusí byť vždy nová, len blízka.“

Cache zostáva dobrým sluhom. Chyba je nechať ho stať sa pánom. Rate-limit fallback, drahý rendering, externé systémy, cache si tam miesto zaslúži. User data, real-time, write-heavy cesty, cache tam nemá čo robiť.

---

Odpoveď nemusí byť vždy nová. Len blízka.

---

→ [ACID pre deti](/blog/acid-pre-deti): prečo transakcie nie sú nudné
→ [Outbox pattern](/blog/outbox-pattern): cache pre write path
→ [Ako argon2id zrazil shared hosting](/blog/ako-sha-hash-algorytm-zrazil-shared-hosting): infraštruktúra obmedzuje voľby

## Zdroje

- [MDN, Cache-Control](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control): HTTP caching headers špecifikácia
- [PHP Manual, APCu](https://www.php.net/manual/en/book.apcu.php): in-memory cache pre PHP
- [Cloudflare, What is Caching?](https://www.cloudflare.com/learning/performance/what-is-caching/): caching základy
- [OWASP, Caching Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Caching_Cheat_Sheet.html): bezpečnostné aspekty cache
- [HTTP/1.1 Caching (RFC 9111)](https://httpwg.org/specs/rfc9111.html): oficiálna RFC špecifikácia
