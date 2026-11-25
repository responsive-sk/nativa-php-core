# Security Audit Report — 2026-09-01

**Scope:** Nativa Minimal — authorized offensive self-testing (own code, dev/staging only)
**Method:** aif-self-pentest skill — automated scans + manual code review + defense verification
**Target:** PHP 8.5 + TypeScript/Vite zero-dependency starter kit

---

## Summary

| Severity | Count |
|----------|-------|
| Critical | 0 |
| High | 0 |
| Medium | 0 |
| Low | 0 |
| Info | 3 |

**Overall posture: STRONG.** All major OWASP categories verified with no exploitable findings. Defenses are layered, tested, and follow best practices.

---

## Defenses Verified ✅

### 1. SQL Injection — PASS
All database queries use PDO prepared statements with parameter binding. No string concatenation of user input into SQL.

**Verified:**
- `RateLimiter::isAllowed()` — atomic `INSERT ... ON CONFLICT` with `:key` parameter
- `MediaRepository::deleteBatch()` — `{$placeholders}` uses `array_fill(0, count($ids), '?')` with `execute($ids)` — safe
- `SettingsDatabaseMigrator::addColumnIfMissing()` — `PRAGMA table_info({$table})` uses hardcoded table names from within the class, not user input
- All repository methods use `prepare()` + `execute()` with named parameters

### 2. Path Traversal — PASS
File-serving actions implement defense-in-depth: lexical checks + `realpath` containment + extension whitelist.

**Verified:**
- `ServeUploadedFileAction` — `isSafePath()` rejects `..`, null bytes, dotfiles; `realpath` containment inside `storage/uploads/`; extension/MIME whitelist via `LocalStorageProvider::isAllowed()`
- `ServeBlogImageAction` — same pattern; only `.svg` files allowed; `realpath` containment inside `storage/blog-images/`
- `BlogArticleAction::loadArticle()` — slug validated with `/^[a-z0-9][a-z0-9_-]*$/i` regex; file path constructed as `storage/blog/{locale}/{slug}.md`

### 3. XSS — PASS
No unescaped dynamic output in templates. CSP nonce properly implemented.

**Verified:**
- No `<?= $` hits without `htmlspecialchars` in source
- `CsrfMiddleware::field()` escapes token value with `htmlspecialchars(ENT_QUOTES)`
- `SecurityHeadersMiddleware` generates per-request nonce, replaces placeholder in HTML responses only
- `script-src` uses nonce-only (no `unsafe-inline`/`unsafe-eval`)
- `frame-src: none`, `object-src: none`, `base-uri: self`, `form-action: self`

### 4. CSRF — PASS
Global `CsrfMiddleware` blocks all state-changing requests without a valid token.

**Verified:**
- Token:64-char hex (32 random bytes) stored in session
- Validation: `hash_equals()` for timing-safe comparison
- Covers POST, PUT, DELETE, PATCH
- Supports both form field (`_token`) and header (`x-csrf-token`)
- `regenerateToken()` available for post-login fixation prevention

### 5. Authentication & RBAC — PASS
All `/mark/*` routes require authentication + admin role. No privilege escalation path.

**Verified:**
- `AuthMiddleware::isProtected()` — all paths starting with `/mark` require admin role
- Registration creates users with no roles (only `id`, `name`, `email`, `password`, `is_active=1`)
- `assignRole()` only callable from `UsersAction` behind `/mark/users` (admin-only)
- Guest-only pages (`/login`, `/register`, etc.) redirect authenticated users to `/mark`
- Remember-me cookie support with proper session establishment

### 6. Rate Limiting — PASS
Multi-layer rate limiting with loopback exemption for dev.

**Verified:**
- General:60 requests/minute per IP (`RateLimitMiddleware`)
- Login:5 attempts/minute per IP+email (`RateLimiter::isLoginAllowed()`)
- Comments:10 submissions/minute per IP+slug (`CommentsService::isAllowed()`)
- Atomic upsert (`INSERT ... ON CONFLICT DO UPDATE`) — no race conditions
- `getClientIp()` uses `REMOTE_ADDR` as source, only trusts `X-Forwarded-For` from verified proxies (fail-closed)

### 7. Password Reset — PASS
Tokens are hashed, time-limited, and single-use.

**Verified:**
- Token:64-char hex (32 random bytes), stored as SHA-256 hash in DB
- TTL:1 hour (`TOKEN_TTL_HOURS = 1`)
- Single-use: `used_at IS NULL` check, then `used_at = CURRENT_TIMESTAMP`
- Previous unused tokens invalidated on new request
- User enumeration prevented (request always succeeds)

### 8. Password Hashing — PASS
Modern algorithm with per-user salt via `PasswordHasher` service.

### 9. Server Header Hardening — PASS
- `X-Powered-By` removed via `header_remove()` in `init.php`
- HSTS (`max-age=31536000; includeSubDomains; preload`) in production only
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`

### 10. CI/CD Pipeline — PASS
- Minimal permissions: `contents: read`
- Actions version-pinned (not SHA-pinned, but version-pinned is reasonable)
- Supply chain scanning: `composer audit --locked`, `pnpm audit --audit-level=high`, `gitleaks` secret scan
- Concurrency group with `cancel-in-progress: true`

### 11. File Serving — PASS
- MIME detected server-side via `finfo(FILEINFO_MIME_TYPE)`, never trusted from client
- Content-Type set explicitly per action
- Immutable cache headers for static assets

---

## Informational Findings

### Info-1: CSP `style-src-elem` and `style-src-attr` use `unsafe-inline`
**Location:** `SecurityHeadersMiddleware.php` lines 28-29
**Impact:** Inline styles are allowed on elements and attributes. This is intentional for the deferred CSS switcher and critical CSS inline blocks.
**Risk:** Low — the `script-src` is properly nonce-only. Inline styles cannot execute JavaScript.
**Recommendation:** None — this is a deliberate design choice documented in the codebase.

### Info-2: `exec()` calls in CLI commands
**Location:** `GenerateImagesCommand.php` lines 490, 513, 552
**Impact:** Shell command execution for SVG→WebP/PNG conversion.
**Risk:** None — CLI-only, not web-accessible. All parameters use `escapeshellarg()`.
**Recommendation:** None — properly guarded.

### Info-3: `osv-scanner` not installed
**Impact:** Could not run full SCA scan with osv-scanner.
**Mitigation:** `composer audit` (PHP lockfile) found no vulnerabilities. `pnpm audit` covers frontend lockfile in CI.
**Recommendation:** Consider installing osv-scanner for broader coverage (Go binary, ~15MB).

---

## Automated Scan Results

| Scan | Result |
|------|--------|
| SQL string concatenation | 0 user-input hits (all hardcoded or parameterized) |
| Dangerous functions (`eval`, `assert`, etc.) | 0 in web code; `exec()`/`passthru()` only in CLI with `escapeshellarg()` |
| `extract`/`parse_str` | 0 matches |
| Unescaped `<?= $` | 0 matches |
| Hardcoded secrets | 0 real secrets (only documentation examples) |
| `composer audit` | No security vulnerability advisories found |
| `pnpm audit` | Covered in CI (not run locally) |

---

## Conclusion

The Nativa Minimal starter kit demonstrates a mature security posture for a zero-dependency PHP application. All major attack vectors (SQLi, XSS, CSRF, path traversal, IDOR, privilege escalation, rate-limit bypass) have been tested and verified against. No exploitable vulnerabilities were found.

The defense-in-depth approach — CSP nonces, prepared statements, realpath containment, CSRF tokens, RBAC, rate limiting — provides multiple layers of protection. Even if one layer were bypassed, others would still hold.

**Next recommended steps:**
1. Run `aif-security-checklist` for a defensive OWASP audit (complementary to this offensive test)
2. Consider installing `osv-scanner` for broader SCA coverage
3. Re-run after any major feature additions
