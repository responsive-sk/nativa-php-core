# Nativa Minimal Security Self-Pentest Report

> Date: 2026-10-11 · Tester: Buffy (AI) · Authorization: own app, dev only (`php -S 127.0.0.1:8125 -t public`), non-destructive, rate limiter respected
> Related skill: `/aif-self-pentest` · Defensive baseline: `/aif-security-checklist` (not run this round)

## 1. Executive Summary

White-box offensive self-test of Web attacks + Admin authorization + Supply-chain/CI against a local dev server. **No exploitable vulnerability found.** All 16 live probes held: traversal → 404, reflected XSS → escaped, CSRF → 403 without token, `/mark` → 302 to `/login`, open redirect → neutered, reset tokens → random + hashed + single-use + TTL, SQL → prepared statements only, uploads → extension+MIME whitelist + random names. `composer audit` and `pnpm audit` both clean. Three hardening notes (all Low/Info, no fix required this iteration): Host-header trust by default, `style-src` `unsafe-inline`, CI actions pinned to moving tags.

## 2. Scope

| Item | Value |
|---|---|
| Target | Nativa Minimal (PHP 8.5 + TS/Vite), local dev server `http://127.0.0.1:8125` |
| Test type | White-box (source available) |
| Areas | Web attacks · Admin authorization · Supply chain · CI/CD |
| Tools | curl, grep, composer audit, pnpm audit, code reading |
| Excluded | Production; third parties; destructive tests; JWT/OAuth (N/A — sessions used); SSRF (no outbound surface) |

## 3. Findings Summary

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| F-1 | Host header trusted when `TRUSTED_HOSTS` unset (absolute URLs) | Low | Confirmed (hardening) |
| F-2 | CSP allows `unsafe-inline` for `style-src-elem` / `style-src-attr` | Info | Confirmed (hardening) |
| F-3 | CI actions pinned to moving tags, not SHA | Low | Confirmed (hardening) |

No Critical / High / Medium findings. No `/aif-fix` needed.

## 4. Finding Details

### F-1 Host header trusted when `TRUSTED_HOSTS` unset

- **Severity:** Low
- **Location:** `src/Modules/Core/Infrastructure/Http/Request.php:125-140` (`absoluteUrl()`, `trustedHosts()`)
- **Description:** When `TRUSTED_HOSTS` is empty (the default; local `.env` does not set it), `absoluteUrl()` builds absolute links (sitemap, hreflang, canonical, feeds) from the raw `Host` header. The allowlist + fail-closed logic exists but is dormant until an operator sets the variable.
- **Impact:** Cache/SEO poisoning of absolute URLs behind a proxy that forwards attacker Host; password-reset itself is NOT affected (dev-log mailer, no Host-built link — verified in `PasswordResetService`).
- **Reproduction:**
  ```bash
  curl -s http://127.0.0.1:8125/login -H 'Host: attacker.example' | grep -oiE 'attacker\.example' | head -3
  # observed: no reflection on /login (safe today); risk is via absoluteUrl() consumers (sitemap/hreflang/feeds)
  ```
- **Evidence chain:**
  - E-10: poisoned-Host request shows no reflection on `/login`; code read confirms `absoluteUrl()` uses unvalidated Host when allowlist is null — `source_ref: src/Modules/Core/Infrastructure/Http/Request.php:125-140`.
  - F-1: finding — `evidence_ids: [E-10]`, `confidence: high`, `status: confirmed (hardening)`.
  - P-1: path — request with forged Host → `absoluteUrl()` → absolute link (sitemap/hreflang/feed) → poisoned cache/search index.
- **Suggested fix:** set `TRUSTED_HOSTS` in production env + document in `docs/getting-started.md` (fail-closed default already implemented).
- **Verification of fix:** with `TRUSTED_HOSTS=nativa.example`, `curl -H 'Host: attacker.example' /sitemap.xml` must contain only `nativa.example`.

### F-2 CSP allows `unsafe-inline` for styles

- **Severity:** Info
- **Location:** `src/Modules/Core/Interfaces/HTTP/Middleware/SecurityHeadersMiddleware.php:32-33`
- **Description:** `script-src` is nonce-only (good — no `unsafe-inline`/`unsafe-eval`), but `style-src-elem` and `style-src-attr` permit `unsafe-inline`. Observed live:
  `Content-Security-Policy: ... script-src 'self' 'nonce-…' style-src 'self' 'nonce-…' style-src-elem 'self' 'unsafe-inline'; style-src-attr 'unsafe-inline'; ...`
- **Impact:** style-attribute injection is not neutralized by CSP (script injection is). Defense-in-depth only — every output point observed is escaped, so no injection sink was found.
- **Reproduction:** `curl -sI http://127.0.0.1:8125/` (405 body but headers representative; same policy on `GET /` 200).
- **Suggested fix:** none required; if inline styles are ever removed, drop the two `unsafe-inline` directives.
- **Verification:** `curl -sI` shows `script-src` without `unsafe-inline` (confirmed).

### F-3 CI actions pinned to moving tags

- **Severity:** Low
- **Location:** `.github/workflows/ci.yml` (`actions/checkout@v6`, `actions/cache@v5`, `actions/setup-node@v5`, `pnpm/action-setup@v6.0.10`, `shivammathur/setup-php@v2`, `gitleaks/gitleaks-action@v3.0.0`)
- **Description:** supply-chain drift risk — a moved tag changes CI code without a diff. No secrets exfiltration surface today (`permissions: contents: read`, no `pull_request_target`, no `${{ github.event… }}` in `run:`, timeouts on every job).
- **Impact:** low (no secrets in workflow; worst case is build tampering via compromised tag).
- **Suggested fix:** pin to full SHAs (with tag comments) when convenient; no urgency.
- **Verification:** `grep -oE 'uses: [^ ]+@…' ci.yml` (all tags, no SHAs).

## 5. Things Tested and Found Solid

| Area | Probe (all against `http://127.0.0.1:8125`) | Result |
|---|---|---|
| Path traversal (blog) | `GET /blog/..%2f..%2fcomposer.json --path-as-is` | 404 layout page, no file bytes (E-1). Code: slug regex `^[a-z0-9][a-z0-9_-]*$` in `BlogArticleAction::loadArticle()` |
| Path traversal (uploads) | `GET /storage/uploads/..%2f..%2fcomposer.json --path-as-is` | 404 (E-2). Code: `isSafePath()` + `realpath()` containment + `isAllowed()` in `ServeUploadedFileAction` |
| Dotfiles / DB | `/.env`, `/.git/config`, `/backup.sql`, `/storage/data/users.db` | all 404 (E-3); docroot is `public/`, `.env` gitignored |
| Reflected XSS (slug) | `GET /blog/%3Csvg%20onload=alert(1)%3E` | slug escaped (`&lt;svg` in locale href); bare `<svg` hits are theme icons/CSS only (E-4a) |
| Reflected XSS (search) | `GET /blog?q="><script>alert(1)</script>` | escaped (`&quot;&gt;&lt;script&gt;` in heading + input value); `<script` hits are legit bundles (E-4b). `MarkdownRenderer::inline()` escapes first, links allowlisted to `http(s)/#//` |
| CSRF | `POST /logout` without token | 403 (E-5). `CsrfMiddleware` covers POST/PUT/DELETE/PATCH, session-bound, `hash_equals` |
| Method tricks | `GET /logout` → 405; `PUT /mark/users`, `DELETE /mark/users/1/edit` → 403 (CSRF, not executed) (E-6/E-15) |
| Admin guard | anon `GET /mark`, `/mark/users`, `/mark/users/create` → 302 `…/login` (E-7). `AuthMiddleware` + `AdminRoutePolicy::permits()` per-path permission |
| SQLi | `POST /login` with `password=x' OR '1'='1` → 403 (CSRF before SQL) (E-12); code: `UserRepository::findByEmail()` uses `prepare()` + bind; grep shows no user input interpolated into SQL (only internal `PRAGMA table_info({$table})` in migrator, placeholder-built `IN (…)` in `MediaRepository`) |
| Rate-limit bypass (XFF) | 7× `POST /login` with `X-Forwarded-For: 9.9.9.9` → uniform 403 (no differential) (E-9). Code: `Request::getClientIp()` honours forwarded headers only from `TRUSTED_PROXIES` |
| Open redirect | `GET /login?return_to=https://evil.example` → 200, no redirect; `AuthTemplate::returnTo()` regex `^/[a-z0-9/._?&=#%+~-]*$` + `//` reject (E-11) |
| Reset flow | `random_bytes(32)` token, `sha256` stored, 1 h TTL, `used_at` single-use, enumeration-safe message (`PasswordResetService`, `PasswordResetRequestAction:41-42`) |
| Sessions | `session_regenerate_id(true)` on login (`AuthService:153`); cookie `HttpOnly; SameSite=Lax`, `Secure` iff HTTPS (`config/session.php`); remember token `sha256`-hashed (E-14) |
| Uploads | `LocalStorageProvider::ALLOWED` = jpg/jpeg/png/gif/webp/pdf/txt/md with MIME↔extension cross-check + `random_bytes(16)` names (E-19) |
| Mass assignment | `UsersAction` reads explicit `name/email/password/role/is_active` + `roleExists()` check — no blind POST loop |
| Docs isolation | `GET /docs/..%2f.env --path-as-is` → 404; `DocsCatalog` whitelist (E-16) |
| Secrets | grep for hardcoded `password/secret/api-key = "…"` → none; `.env` gitignored (`git check-ignore` confirms) (E-20) |
| Dangerous funcs | no `eval/assert/create_function/system/shell_exec/passthru/popen` in `src/`; `exec` hits are `PDO::exec` DDL + `ServeCommand::passthru` (dev CLI) |
| Supply chain | `composer audit --locked` → clean; `pnpm audit --audit-level=high` (Frontend/packages) → clean |
| CI | `permissions: contents: read`, timeouts every job, no `pull_request_target`, no `github.event` interpolation in `run:`, gitleaks + composer/pnpm audit steps present |

## 6. Timeline / Work Log

| Time | Action | Result |
|---|---|---|
| 11:05 | scope confirm (dev-only, web+api+supply-chain) | authorized |
| 11:05 | route enumeration (`grep #\[Route\]`) | ~100 routes mapped; `/mark/*`, `/blog/*`, `/forms/*`, `/questions/*`, `/docs/*` |
| 11:06 | grep sweeps (SQL concat, dangerous funcs, echo, file handling, secrets) | candidates listed, none directly exploitable |
| 11:06 | defense inventory read (CSP/CSRF/rate-limit/auth/reset/markdown) | all defenses present |
| 11:07 | dev server boot `:8125` (persistent background) | 200 on `/` |
| 11:07 | probes E-1–E-8 (traversal, dotfiles, XSS, CSRF, guard, headers) | all hold; XSS hits triaged to false positives |
| 11:08 | probes E-9–E-14 (XFF, Host, redirect, SQLi, debug, cookies) | hold; `/debug` 200 explained (local `APP_DEBUG=true`, gitignored `.env`; 404 otherwise by code) |
| 11:09 | probes E-15–E-20 (methods, docs, sitemap, regen, upload, gitignore) | hold |
| 11:09 | `composer audit` + `pnpm audit` + CI grep | clean / least-privilege confirmed |

## 7. Appendix

- `/debug` 200 in this run is **expected**: local `.env` sets `APP_DEBUG=true` (gitignored, confirmed via `git check-ignore`), and `DebugAction` returns 404 unless `APP_DEBUG=true`, with secret env values masked (`SECRET_FRAGMENTS`). Ensure `APP_DEBUG=false` in production.
- Rate-limiter 429 was not directly observed (CSRF 403 fires first without a session token); XFF-bypass resistance verified by code (`TRUSTED_PROXIES` gate) + uniform responses with forged `X-Forwarded-For`.
- `osv-scanner` binary not installed in this environment; `composer audit` + `pnpm audit` cover the same lockfiles and both report clean.
- Redactions: tester email `a@b.c` used in probes; no real credentials, tokens, or PII in this report.
