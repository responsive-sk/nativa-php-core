# Security Audit — 2026-08-16

Authorized self-pentest of **Nativa Minimal** (own repo, local dev server on
`127.0.0.1:8031`). Offensive methodology: `aif-self-pentest` skill (web-attack
playbooks + SCA + CI/CD audit). Scope: all public + admin routes, auth flows,
file-serving routes, supply chain, CI pipeline. No destructive actions, no
production targets.

## Summary

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| F-1 | Host header poisoning — `absoluteUrl()` trusts `Host` (canonical/hreflang/sitemap/OG/feeds) | Medium | Fixed (TRUSTED_HOSTS) |
| F-2 | nanoid <3.3.18 (GHSA-2v37-7h3g-55p8) via vite→postcss — dev-only | High advisory / Low reachability | Fixed (vite 8.2.1) |
| F-3 | CI actions on moving tags (`@v6`/`@v5`/`@v2`) | Low (hardening) | Open — suggested pin |

Everything else tested **clean** (details below). 0 exploitable web findings.

---

## F-1 — Host header poisoning (Medium) — FIXED

**Evidence (reproduced against dev server):**

```bash
$ curl -s http://127.0.0.1:8031/sitemap.xml -H "Host: evil.example" | grep -o "evil.example" | head -3
evil.example
evil.example
evil.example

$ curl -s http://127.0.0.1:8031/blog/opcache-a-jit-prekompilacia -H "Host: evil.example" \
    | grep -o '<link rel="canonical"[^>]*>'
<link rel="canonical" href="http://evil.example/blog/opcache-a-jit-prekompilacia">
```

**Finding → Path:** `Request::absoluteUrl()` (`src/Modules/Core/Infrastructure/Http/Request.php`)
reads the `Host` header directly with no allowlist. Every absolute-URL consumer
feeds on it: `SitemapAction`, `RobotsAction` (Sitemap line), `BlogArticleAction`
(canonical + hreflang), `HomepageAction`/`BlogIndexAction`/`AuthorProfileAction`
(OG images), `BlogFeedAction`. An attacker who can set the `Host` header poisons
all emitted absolute URLs → canonical/hreflang hijack, SEO poisoning, feed
link poisoning. No session/password-reset impact: reset links log a bare token
and are opened from the mailer context; cookies are host-scoped.

**Fix (implemented):** `TRUSTED_HOSTS` env (comma-separated hostnames, same
pattern as `TRUSTED_PROXIES`). When set, `absoluteUrl()` validates the `Host`
header against the allowlist and fails closed to the first allowed host on
mismatch; when unset, behaviour is unchanged (backward compatible). Documented
in `.env.example` and `docs/getting-started.md`.

## F-2 — nanoid advisory (High advisory, Low reachability) — FIXED

**Evidence:**

```
$ cd Frontend/packages/starter && pnpm audit
1 vulnerabilities found — high — nanoid <3.3.18 (custom generators can loop
indefinitely when size is zero) — Paths: .>vite>postcss>nanoid
GHSA-2v37-7h3g-55p8
```

**Finding → Path:** `nanoid@3.3.17` transitively via `vite@8.2.0 → postcss`.
Dev-tooling only (build pipeline, not shipped runtime). Vite does not call
nanoid with user-controlled size — not reachable from app input.

**Fix (implemented):** `vite 8.2.0 → 8.2.1` (`pnpm update vite --latest`), which
drops `nanoid@3.3.18` (patched). `pnpm audit` now clean; Vite build re-verified
(`✓ built in 56ms`).

## F-3 — CI actions on moving tags (Low / hardening) — OPEN

**Evidence:** `.github/workflows/ci.yml` uses `actions/checkout@v6`,
`shivammathur/setup-php@v2`, `actions/cache@v5`, `actions/setup-node@v5`,
`pnpm/action-setup@v6.0.10`, `gitleaks/gitleaks-action@v3.0.0`. Two are pinned
to exact minor versions (`@v6.0.10`, `@v3.0.0`); the rest are moving major tags.

**Assessment:** moving tags = supply-chain drift risk (an action's major-tag
target can be force-updated by the maintainer). Risk is low today: the workflow
has `permissions: contents: read` and uses no user secrets. Hardening: pin all
actions to full SHAs. Not fixed in this pass (CI-only, no runtime impact).

---

## Verified clean (no findings)

| Class | Evidence |
|---|---|
| SQLi | Grep: no string-concatenated queries, no dynamic identifiers; PDO prepared statements throughout (AGENTS.md policy). Zero `->query()` with user input. |
| XSS (stored/reflected/DOM) | All template output through `FrontendLayout::e()` / `htmlspecialchars`. `MarkdownRenderer` strips raw HTML (block renderer escapes everything, `html_entity_decode(strip_tags(...))`). Reflected probes: `/blog/%3Csvg onload=alert(1)%3E` → output escaped (`&lt;svg onload=alert(1)&gt;`), `?q="><script>` → not reflected. CSP: `script-src 'self' 'nonce-…'` — no `unsafe-inline`/`unsafe-eval`. |
| CSRF | `CsrfMiddleware` — session-bound token (`random_bytes(32)`), `hash_equals`, applied to all POST/PUT/DELETE/PATCH. Probes: POST `/logout` → 403, POST `/register` → 403, PUT `/blog` → 403, DELETE `/mark/users/1/delete` → 403. Cookie `SameSite=Lax`, `HttpOnly`, `use_strict_mode`. Token regenerated after login (fixation). |
| Path traversal | Blog slug: strict `^[a-z0-9][a-z0-9_-]*$` in `loadArticle()`. `ServeUploadedFileAction` + `ServeBlogImageAction`: lexical guards (no `..`, no null byte, no dotfile) + `realpath()` containment + extension/MIME whitelist. Probes: `..%2f..%2f.env`, `%2e%2e%2fcomposer.json`, `....//.git/config`, `/blog//etc/passwd` → all 404. |
| File serving | `LocalStorageProvider`: MIME+extension whitelist (jpeg/png/gif/webp/pdf/txt/md — no HTML/SVG/PHP), random hex names, 10 MB cap. `ServeUploadedFileAction` emits verbatim with server-side `finfo` MIME; `ServeBlogImageAction` SVG-only with `image/svg+xml` + nosniff. Files never executed. |
| Auth & sessions | `PASSWORD_ARGON2ID`. `AuthMiddleware` guards all `/mark` routes (role check per request, not just route prefix). Session: `HttpOnly`, `SameSite=Lax`, `Secure` auto-detect, `use_strict_mode=1`, `use_only_cookies=1`. `session_regenerate_id` on login. |
| Password reset | Token `bin2hex(random_bytes(32))`, stored `sha256`-hashed, single-use (previous unused tokens deleted), `expires_at` TTL, uniform response (no user enumeration: always generates token). No Host header in the logged link. |
| Rate limiting | `RateLimiter` DB-backed; login 5/min per IP+email; general 60/min per IP. `Request::getClientIp()` honours `X-Forwarded-For`/`X-Real-IP` **only** when `REMOTE_ADDR` is in `TRUSTED_PROXIES`, else returns `REMOTE_ADDR` (fail closed). Probe: 12× `X-Forwarded-For: 203.0.113.9` login → all rejected (403 CSRF first; spoofing never exempts). |
| Mass assignment | Explicit field whitelists: `SettingsAction::saveSite` (`in_array($theme, ['starter','magazine'])`, email filter_var, boolean coercion); `UsersAction` extracts named fields only. New magazine `theme` field is whitelist-validated. |
| IDOR / BFLA | Route audit (all 54 `#[Route]`): every `/mark` route behind `AuthMiddleware` admin check; no GET route mutates state; `/mark/{id}` actions re-check role in handler. `returnTo` redirect: regex `^/[a-z0-9/._?&=#%+~-]*$` + rejects `//` — no open redirect. |
| Info disclosure | `.env`/`.git/config`/`backup.sql` → 404 (outside docroot; `.htaccess` rewrites everything through `index.php`). Bad slug → styled 404, zero stack traces/warnings in body. `/debug` returns 404 unless `APP_DEBUG=true` (verified in code; local dev has it on). Headers: CSP nonce, `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`. HSTS prod-gated. |
| Command injection | No `eval`/`exec`/`shell_exec`/`system`/`popen`/`proc_open`/backticks/`extract`/`parse_str` in `src/` (grep). |
| Secrets | No hardcoded credentials in `src/`/`public/` (grep); `.env` gitignored; CI runs gitleaks. |
| SCA (composer) | `composer audit --locked` → no advisories. |

## CI pipeline audit (`.github/workflows/ci.yml`)

`permissions: contents: read` ✅ · no secrets in workflows ✅ · no
`pull_request_target` ✅ · no `${{ github.event… }}` script injection ✅ ·
concurrency + cancel-in-progress ✅ · timeouts on every job ✅ ·
composer audit + pnpm audit + gitleaks in supply-chain job ✅ · caches keyed
on lockfiles (content-addressed) ✅. Remaining: F-3 action pinning.

---

# Defensive audit — OWASP Top 10 (2021) checklist

Counterpart pass (`aif-security-checklist`) — missing-defenses view. Complements
the offensive pentest above (defenses proven to hold) with defense-presence checks.

## D-1 — No explicit session idle/absolute timeout (Low) — FIXED

**Finding:** sessions relied on `session.gc_maxlifetime = 1209600` (14 days, `config/session.php`)
— a GC window, not an idle timeout. `AuthService::establishSession()` regenerated the id and
CSRF token but no `last_activity` timestamp was checked; an authenticated session stayed valid
for the full lifetime regardless of idle time.

**Fix (implemented):** `AuthService::establishSession()` now stamps `last_activity` in the
session; `isAuthenticated()` invalidates the session when inactive longer than
`SESSION_IDLE_TIMEOUT` (default 3600 s = 60 min, env-overridable, `.env.example`). Sessions
started before the feature (no stamp) are not affected. Tests: 3 new cases in
`tests/Integration/UsersModuleTest.php` (fresh survives, idle invalidated, legacy unaffected).

## D-2 — Password policy: minimum 8 characters (Info)

**Finding:** `AuthService::register()` enforces `mb_strlen($password) >= 8`. The checklist
prefers 12; 8 is NIST SP 800-63B minimum. With ARGON2ID + per-IP/email rate limiting this is
acceptable for a starter kit — note for production hardening.

## D-3 — `X-XSS-Protection: 1; mode=block` (Info) — FIXED

**Finding:** `SecurityHeadersMiddleware` set the deprecated `X-XSS-Protection` header. Modern
guidance (OWASP) is to drop it (`0` or omit) — it is unreliable and can mask real XSS; CSP
nonce is the actual defense and is present.

**Fix (implemented):** header removed from `SecurityHeadersMiddleware` (commented in source).
No test referenced it; `composer test` green.

## D-4 — No HTTP→HTTPS redirect (Low, deployment-level) — FIXED

**Finding:** HSTS is set on `APP_ENV=production` (correctly gated), but `public/.htaccess`
performed no scheme redirect. On a TLS-terminating proxy (documented Docker setup) the proxy
should enforce the redirect; direct Apache deployment would serve plain HTTP alongside HTTPS.

**Fix (implemented):** `public/.htaccess` now 301-redirects plain HTTP to HTTPS, honouring
`X-Forwarded-Proto` so a TLS-terminating proxy does not cause a redirect loop (Apache sees
plain HTTP behind the proxy, where `%{HTTPS}` alone would be off). The dev server (`php -S`)
ignores `.htaccess`, so local development is unaffected.

## Verified present (defensive view)

| OWASP area | Status | Evidence |
|---|---|---|
| A01 Broken Access Control | ✅ | `AuthMiddleware` admin-role gate on every `/mark` route; no GET mutates state; `returnTo` redirect regex (no open redirect) |
| A02 Cryptographic Failures | ✅ | ARGON2ID; `random_bytes` for ids/tokens/CSRF; session `Secure` auto-detect + HSTS prod-gated; `password_reset_tokens.token` stored `sha256`-hashed |
| A03 Injection | ✅ | PDO prepared statements (0 concat); MarkdownRenderer strips raw HTML; no `eval`/`exec`/`extract`; UNIQUE constraints on email/role/permission/token |
| A04 Insecure Design | ✅ | Rate limiting per IP+email (login 5/min, general 60/min); email UNIQUE kills registration TOCTOU; **D-1** idle timeout implemented |
| A05 Security Misconfiguration | ✅ | Full header set (CSP nonce, `frame-ancestors 'none'`, nosniff, Referrer-Policy, Permissions-Policy, HSTS prod); `/debug` 404-gated; generic prod error page; **D-3** deprecated header removed, **D-4** HTTPS redirect added |
| A06 Vulnerable Components | ✅ | `composer audit` clean; `pnpm audit` clean (vite 8.2.1 fix); CI supply-chain job |
| A07 Identification & Auth Failures | ⚠️ | `session_regenerate_id(true)` + CSRF token regen on login; HttpOnly/SameSite/Secure; timing-safe login (dummy verify); **D-2** 8-char minimum; no MFA (Info, out of scope for kit) |
| A08 Software & Data Integrity | ✅ | Lockfiles committed; gitleaks + composer/pnpm audit in CI; caches keyed on lockfiles; no `pull_request_target`; no script injection |
| A09 Logging & Monitoring | ✅ | Uncaught exceptions logged full server-side, generic message client-side (non-debug); immutable admin audit log (append-only triggers); `login_attempts` audit trail |
| A10 SSRF | ✅ | No outbound HTTP surface (no webhooks/imports/proxies); locale JSON import is file upload, not URL fetch |
| Race conditions | ✅ | Email UNIQUE constraint; remember tokens hashed + single-use; rate limiting shrinks windows; uploads random-hex names |
| Prompt injection | ✅ | N/A — no LLM surface in the app |
| Client-side logging | ✅ | No `console.log`/`debug`/`info` in `Frontend/packages/starter/src/` |
| Secrets | ✅ | No hardcoded credentials in `src/`/`public/`; `.env` gitignored; seeded admin password documented with `BUILD_ADMIN_PASSWORD` override + change-on-first-login warning |

## Pre-deployment checklist

- [x] No secrets in code or git history (grep + gitleaks CI)
- [x] All user input validated/sanitized (typed extraction, filters, whitelists)
- [x] Auth on all protected routes (`/mark` admin gate)
- [x] HTTPS enforced — HSTS prod-gated + `.htaccess` HTTP→HTTPS redirect (**D-4**)
- [x] SQL/NoSQL injection prevented (prepared statements only)
- [x] XSS protection (escaping + CSP nonce, no raw HTML in markdown)
- [x] CSRF tokens on state-changing requests (session-bound, `hash_equals`)
- [x] Rate limiting enabled (login 5/min IP+email, general 60/min, comments)
- [x] Error messages don't leak sensitive info (generic 500, styled 404, no stack traces in prod)
- [x] Client-side debug logging disabled (none present)
- [x] Dependencies scanned (composer + pnpm audit in CI, clean)
- [x] LLM prompt injection — N/A
- [x] Race conditions on critical ops — covered (UNIQUE constraints, single-use tokens)

## Defensive audit verdict

No critical/high issues. All three Low/Info findings (**D-1** idle timeout, **D-3** deprecated
header, **D-4** HTTPS redirect) are now fixed; **D-2** (8-char password minimum) remains a
conscious Info-level choice (NIST-compliant). Overall: **pass** (no unignored findings).

---

## Re-test notes

- F-1 re-test: `curl -s http://localhost:8000/sitemap.xml -H "Host: evil.example"`
  must NOT contain `evil.example` when `TRUSTED_HOSTS` is set.
- F-2 re-test: `cd Frontend/packages/starter && pnpm audit` → no vulnerabilities.
- After any dependency bump: re-run `composer audit --locked` + `pnpm audit`
  (CI does this on every push).
