---
title: "Cibuľa, ktorá stráži vaše routy"
date: "2026-09-01"
version: "1.0.1"
tags: ["php", "architektúra", "middleware"]
excerpt: "Sedem vrstiev obaľuje každý request v Nativa Minimal. Ako funguje middleware pipeline, prečo je dôležitá a čo sa stane, keď jedna vrstva zlyhá."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architektúra"
views: "0"
translation: "middleware-pipeline"
published: true
---

Prihlasovací formulár. Žiadny CSRF token. Request prejde. Používateľ upraví profil iného používateľa zmenou ID v URL. Žiadna kontrola oprávnení. Databáza to prijme.

Takto vyzeral starý systém. Jedna kontrola na jednom mieste. Ak prešla, všetko bolo otvorené.

Nativa Minimal obaľuje každý HTTP request v siedmich middleware vrstvách. Každá vrstva má jednu úlohu. Ak akákoľvek vrstva zlyhá, request sa nikdy nedostane k route handleru.

## Problém s plochou autentifikáciou

Raz som budoval malý admin panel. Jeden `if` na začiatku každej akcie kontroloval, či je používateľ prihlásený. Fungovalo to, kým niekto nepridal novú routu a nezabudol na kontrolu.

Vzorec bol krehký, lebo bezpečnosť žila v handlere, nie v pipeline. Každá nová routa bola nová príležitosť na zabudnutie.

```php
// Starý spôsob — bezpečnosť v handlere
public function handle(Request $request): Response
{
    if (!$this->auth->isLoggedIn()) {
        return Response::redirect('/login');
    }
    // ... skutočná logika
}
```

Toto je jediný bod zlyhania. Jedna zabudnutá kontrola, jeden nový vývojár, jeden neskorý piatok.

## Sedem vrstiev, jedným smerom

Kernel stavia pipeline ako zanorené closures. Každý middleware obalí ten ďalší. Vonkajšia vrstva beží ako prvá. Vnútorná vrstva dispatchuje na route handler.

Tu je poradie pipeline v `public/index.php`:

```php
$kernel = new Kernel($router);

// 1. SecurityHeaders — generuje CSP nonce, nastaví všetky bezpečnostné hlavičky
//    (auto-discovered via #[AsMiddleware(priority: 0)])

// 2. Locale — odstrihne /sk|/en prefix z URL
$kernel->addMiddleware(new LocaleMiddleware(LocalesServices::locale()));

// 3. CSRF — odmietne POST/PUT/DELETE bez platného tokenu
//    (auto-discovered via #[AsMiddleware(priority: 20)])

// 4. RateLimit — 60 requestov/minútu na IP
$kernel->addMiddleware(new RateLimitMiddleware(...));

// 5. PageCache — pasívne cacheovanie pre rate-limit fallback
$kernel->addMiddleware(new PageCacheMiddleware($pageCache));

// 6. Auth — vyžaduje admin rolu pre /mark/* routy
$kernel->addMiddleware(new AuthMiddleware(...));

// 7. Debugbar — vstrekuje plovúci panel (len v debug režime)
//    (auto-discovered via #[AsMiddleware(priority: 999)])
```

Kernel stavia túto reťaz ako closures:

```php
private function buildPipeline(): callable
{
    $next = fn (Request $request): Response => $this->dispatch($request);

    foreach (array_reverse($this->middleware) as $middleware) {
        $next = static fn (Request $request): Response
            => $middleware->handle($request, $next);
    }

    return $next;
}
```

Každý middleware dostane request a `$next` callback. Môže spracovať request pred zavolaním `$next`, alebo `$next` úplne preskočiť a okamžite vrátiť odpoveď.

## Ako request preteká cez pipeline

POST request na `/mark/users/123/edit` prejde cez všetkých sedem vrstiev:

**Vrstva 1 — SecurityHeaders.** Generuje náhodný nonce (`bin2hex(random_bytes(16))`). Nastaví CSP, HSTS, nosniff, DENY frame. Posunie request nižšie.

**Vrstva 2 — Locale.** Vidí `/sk/mark/users/123/edit`. Odstráni `/sk` prefix. Zvyšok pipeline vidí `/mark/users/123/edit`.

**Vrstva 3 — CSRF.** Hľadá `_token` pole alebo `x-csrf-token` hlavičku. Overí pomocou `hash_equals()` voči session tokenu. Ak chýba, vráti 403 ihneď. Request sa nikdy nedostane k auth.

**Vrstva 4 — RateLimit.** Kontroluje IP voči tabuľke `rate_limits`. Atomický `INSERT ... ON CONFLICT DO UPDATE` — žiadne race conditions. Ak je blokovaný, vráti 429 s cacheovanou stránkou (ak existuje).

**Vrstva 5 — PageCache.** Pasívna — len zapisuje do cache po príchode odpovede. Necacheuje. To je úloha RateLimitMiddleware.

**Vrstva 6 — Auth.** Kontroluje session. Ak nie je autentifikovaný, skúsi remember-me cookie. Ak stále nie, presmeruje na `/login`. Ak je autentifikovaný, ale nemá `admin` rolu, vráti 403.

**Vrstva 7 — Debugbar.** Aktívna len keď `APP_DEBUG=true`. Vstrekuje plovúci panel do HTML odpovedí. V produkcii je to no-op.

**Dispatch.** Router matchne routu. Action class spracuje request. Odpoveď sa vracia späť cez všetkých sedem vrstiev v opačnom poradí.

## Dva spôsoby objavenia

Middleware v Nativa Minimal sa objavuje dvoma spôsobmi:

**Auto-discovery.** Triedy anotované s `#[AsMiddleware(priority: N)]` nájde `MiddlewareRegistry`.  Skenuje `src/Modules/*/Interfaces/HTTP/Middleware/` pre PHP súbory obsahujúce atribút. Priority určuje poradie — nižšie čísla bežia skôr.

```php
#[AsMiddleware(priority: 0)]
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    // ...
}
```

Auto-discovery funguje len pre middleware bez povinných constructor parametrov. Registry instanciuje každú triedu pomocou `new $className()`.

**Manuálna registrácia.** Middleware závislostiami — `LocaleMiddleware`, `RateLimitMiddleware`, `AuthMiddleware` — sa pridáva cez `$kernel->addMiddleware()`. Tieto obaľujú bližšie k dispatch, pretože sa pridávajú po auto-discovered middleware.

Toto je zámerne rozdelenie. Bezstavové middleware (hlavičky, CSRF, debug toolbar) sú auto-discovered. Stavové middleware (locale, rate limiting, auth) potrebujú explicitné napojenie.

## Tok noncov

CSP nonces sú najzaujímavejšia časť pipeline. Každý inline `<script>` a `<style>` musí niesť nonce. Ale nonce je iný pre každý request.

Tok:

1. `SecurityHeadersMiddleware` generuje nonce a ukladá ho ako property.
2. Nastaví placeholder v odpovedi: `__CSP_NONCE_PLACEHOLDER__`.
3. Šablóny vkladajú tento placeholder do inline `<script>` a `<style>` tagov.
4. Postavení odpovede middleware nahradí placeholder skutočným nonce v tele odpovede.
5. CSP hlavička nesie rovnakú hodnotu nonca.

Nahradenie beží len na `text/html` odpovediach. Binárne súbory (obrázky, uploady) prechádzajú bajt-identicky. Tým sa zabráni poškodeniu cacheovaných immutable assets.

```php
$contentType = (string) ($response->getHeader('Content-Type') ?? '');
$content = $response->getContent();
if (str_starts_with($contentType, 'text/html')) {
    $updated = str_replace(self::NONCE_PLACEHOLDER, $this->nonce, $content);
    if ($updated !== $content) {
        $response->setContent($updated);
    }
}
```

Tento dizajn znamená, že cacheované HTML stránky dostávajú vždy čerstvý nonce. Placeholder je stabilný; menia sa len hlavička a nahradenie v tele.

## Hĺbková obrana

Každá vrstva chráni iný útočný povrch:

| Vrstva | Chráni proti |
|--------|-------------|
| SecurityHeaders | XSS, clickjacking, MIME sniffing |
| Locale | Routing confusion z URL prefixov |
| CSRF | Cross-site request forgery |
| RateLimit | Brute force, denial of service |
| PageCache | Degradácia výkonu pod záťažou |
| Auth | Neoprávnený prístup, eskalácia oprávnení |
| Debugbar | Únik informácií (len produkcia) |

Žiadna vrstva sama o sebe nestačí. SecurityHeaders sám nezabraňuje CSRF. Auth sám nezabraňuje brute force. Vrstvy sa dopĺňajú, nekopírujú.

Keď sa odosiela komentár (`POST /blog/{slug}/comments`), trafí ho CSRF (kontrola tokenu), potom RateLimit (10/min na IP+slug), potom Auth (voliteľné — hostia môžu komentovať) a nakoniec akcia validuje a uloží komentár ako pending. Päť kontrol pred zápisom do databázy.

---

Middleware nie je framework feature. Je to vzorec. Kernel nevie, čo robí SecurityHeadersMiddleware. Vie len, že každý middleware implementuje `handle(Request, callable): Response`. Toto je kontrakt. Všetko ostatné je kompozícia.

Pipeline beží v rovnakom poradí vždy. Žiadna magia. Žiadne auto-wiring. Len closures zanoérené vo closure, každá robí jednu vec.

---

→ [CSP a htmx interakcia](/blog/csp-htmx-interaie) — keď nonces stretnú dynamický obsah
→ [Rate-limit cache fallback](/blog/rate-limit-cache-fallback) — čo sa stane, keď vás pipeline zablokuje
→ [Modulová izolácia](/blog/modulova-izolacia-pre-deti) — prečo middleware žije tam, kde žije

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny getting started sprievodca
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
