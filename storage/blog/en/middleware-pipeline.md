---
title: "The Onion That Guards Your Routes"
date: "2026-09-01"
version: "1.0.0"
tags: ["php", "architecture", "middleware"]
excerpt: "Seven layers wrap every request in Nativa Minimal. How the middleware pipeline works, why it matters, and what happens when one layer fails."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "middleware-pipeline"
published: true
---

A login form. No CSRF token. The request goes through. A user edits another user's profile by changing the ID in the URL. No role check. The database accepts it.

That was the old system. One check in one place. If it passed, everything was open.

Nativa Minimal wraps every HTTP request in seven middleware layers. Each layer has one job. If any layer fails, the request never reaches the route handler.

## The problem with flat authentication

I built a small admin panel once. One `if` statement at the top of every action class checked whether the user was logged in. It worked until someone added a new route and forgot the check.

The pattern was fragile because security lived in the handler, not the pipeline. Every new route was a new opportunity to forget.

```php
// The old way — security in the handler
public function handle(Request $request): Response
{
    if (!$this->auth->isLoggedIn()) {
        return Response::redirect('/login');
    }
    // ... actual logic
}
```

This is a single point of failure. One forgotten check, one new developer, one late Friday.

## Seven layers, one direction

The Kernel builds the pipeline as nested closures. Each middleware wraps the next. The outermost layer runs first. The innermost layer dispatches to the route handler.

Here is the pipeline order in `public/index.php`:

```php
$kernel = new Kernel($router);

// 1. SecurityHeaders — generates CSP nonce, sets all security headers
//    (auto-discovered via #[AsMiddleware(priority: 0)])

// 2. Locale — strips /sk|/en prefix from the URL
$kernel->addMiddleware(new LocaleMiddleware(LocalesServices::locale()));

// 3. CSRF — rejects POST/PUT/DELETE without valid token
//    (auto-discovered via #[AsMiddleware(priority: 20)])

// 4. RateLimit — 60 requests/minute per IP
$kernel->addMiddleware(new RateLimitMiddleware(...));

// 5. PageCache — passive cache population for rate-limit fallback
$kernel->addMiddleware(new PageCacheMiddleware($pageCache));

// 6. Auth — requires admin role for /mark/* routes
$kernel->addMiddleware(new AuthMiddleware(...));

// 7. Debugbar — injects floating toolbar (debug only)
//    (auto-discovered via #[AsMiddleware(priority: 999)])
```

The Kernel builds this as a chain of closures:

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

Each middleware receives the request and a `$next` callback. It can process the request before calling `$next`, or skip `$next` entirely and return a response immediately.

## How a request flows through

A POST request to `/mark/users/123/edit` passes through all seven layers:

**Layer 1 — SecurityHeaders.** Generates a random nonce (`bin2hex(random_bytes(16))`). Sets CSP, HSTS, nosniff, DENY frame. Passes the request down.

**Layer 2 — Locale.** Sees `/sk/mark/users/123/edit`. Strips the `/sk` prefix. The rest of the pipeline sees `/mark/users/123/edit`.

**Layer 3 — CSRF.** Checks for a `_token` field or `x-csrf-token` header. Validates with `hash_equals()` against the session token. If missing, returns 403 immediately. The request never reaches auth.

**Layer 4 — RateLimit.** Checks the IP against `rate_limits` table. Atomic `INSERT ... ON CONFLICT DO UPDATE` — no race conditions. If blocked, returns 429 with a cached page if available.

**Layer 5 — PageCache.** Passive — only writes to cache after the response comes back. Does not read from cache. That job belongs to RateLimitMiddleware.

**Layer 6 — Auth.** Checks session. If not authenticated, tries remember-me cookie. If the session is still not valid, it redirects to `/login`. If authenticated but no `admin` role, returns 403.

**Layer 7 — Debugbar.** Only active when `APP_DEBUG=true`. Injects a floating toolbar into HTML responses. In production, this is a no-op.

**Dispatch.** The router matches the route. The action class handles the request. The response flows back through all seven layers in reverse order.

## Two discovery mechanisms

Middleware in Nativa Minimal is discovered two ways:

**Auto-discovery.** Classes annotated with `#[AsMiddleware(priority: N)]` are found by `MiddlewareRegistry`. It scans `src/Modules/*/Interfaces/HTTP/Middleware/` for PHP files containing the attribute. Priority controls order — lower numbers run first.

```php
#[AsMiddleware(priority: 0)]
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    // ...
}
```

Auto-discovery only works for middleware with no required constructor parameters. The registry instantiates each class with `new $className()`.

**Manual registration.** Middleware with dependencies — `LocaleMiddleware`, `RateLimitMiddleware`, `AuthMiddleware` — is added via `$kernel->addMiddleware()`. These wrap closer to the dispatch because they are added after auto-discovered middleware.

This is a deliberate split. Stateless middleware (headers, CSRF, debug toolbar) is auto-discovered. Stateful middleware (locale, rate limiting, auth) needs explicit wiring.

## The nonce flow

CSP nonces are the most interesting part of the pipeline. Every inline script and style must carry the nonce. But the nonce is different for every request.

The flow:

1. `SecurityHeadersMiddleware` generates the nonce and stores it as a property.
2. It sets a placeholder in the response: `__CSP_NONCE_PLACEHOLDER__`.
3. Templates embed this placeholder in inline `<script>` and `<style>` tags.
4. After the response is built, the middleware replaces the placeholder with the real nonce in the response body.
5. The CSP header carries the same nonce value.

The replacement only runs on `text/html` responses. Binary files (images, uploads) pass through byte-identical. This avoids corrupting cached immutable assets.

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

This design means cached HTML pages always get a fresh nonce. The placeholder is stable; only the header and the body replacement change per request.

## Defense in depth

Each layer protects a different attack surface:

| Layer | Protects against |
|-------|-----------------|
| SecurityHeaders | XSS, clickjacking, MIME sniffing |
| Locale | Routing confusion from URL prefixes |
| CSRF | Cross-site request forgery |
| RateLimit | Brute force, denial of service |
| PageCache | Performance degradation under load |
| Auth | Unauthorized access, privilege escalation |
| Debugbar | Information disclosure (production only) |

No single layer is sufficient. SecurityHeaders alone does not prevent CSRF. Auth alone does not prevent brute force. The layers are complementary, not redundant.

When a comment is submitted (`POST /blog/{slug}/comments`), it hits CSRF first (token check), then RateLimit (10/minute per IP+slug), then Auth (optional — guests can comment), then the action validates and stores the comment as pending. Five checks before the database write.

---

Middleware is not a framework feature. It is a pattern. The Kernel does not know what SecurityHeadersMiddleware does. It only knows that every middleware implements `handle(Request, callable): Response`. This is the contract. Everything else is composition.

The pipeline remains the simplest pattern in PHP — just not a substitute for reading the code. No magic. No auto-wiring. Just closures nested inside closures, each one doing one thing.

---

→ [CSP & htmx interaction](/blog/csp-htmx-interaction) — when nonces meet dynamic content
→ [Rate-limit cache fallback](/blog/rate-limit-cache-fallback) — what happens when the pipeline blocks you
→ [Module isolation](/blog/module-isolation) — why middleware lives where it lives

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
