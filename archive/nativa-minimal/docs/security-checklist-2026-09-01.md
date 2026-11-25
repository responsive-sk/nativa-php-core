# Security Checklist — Defensive OWASP Audit

**Date:** 2026-09-01
**Project:** Nativa Minimal (PHP 8.5 + TypeScript/Vite)
**Method:** aif-security-checklist — OWASP Top 10 (2021) + best practices
**Companion:** Offensive self-test in `docs/security-audit-2026-09-01.md`

---

## Pre-Deployment Checklist

| Item | Status | Evidence |
|------|--------|----------|
| No secrets in code or git history | ✅ | `.env` gitignored, `.env.example` has placeholders only, `composer audit` clean, gitleaks in CI |
| All user input validated and sanitized | ✅ | Email validated via `filter_var(FILTER_VALIDATE_EMAIL)`, slugs validated via regex, body lengths capped, type checks (`is_string`) at boundaries |
| Authentication on all protected routes | ✅ | `AuthMiddleware` gates all `/mark/*` routes — requires `admin` role |
| HTTPS enforced | ✅ | HSTS header in production (`max-age=31536000; includeSubDomains; preload`), `SESSION_SECURE` auto-detected from HTTPS |
| SQL injection prevented | ✅ | All queries use PDO prepared statements with named parameters |
| XSS protection in place | ✅ | CSP nonce-based (`script-src 'self' 'nonce-...'`), all output escaped, `X-Content-Type-Options: nosniff` |
| CSRF tokens on state-changing requests | ✅ | Global `CsrfMiddleware` — POST/PUT/DELETE/PATCH require valid token |
| Rate limiting enabled | ✅ | General 60/min/IP, login 5/min/IP+email, comments 10/min/IP+slug |
| Error messages don't leak sensitive info | ✅ | Production: generic "Server Error" page; debug: gated behind `APP_DEBUG=true` |
| Client-side debug logging disabled in production | ✅ | No `console.log` in frontend code; debug panel returns 404 unless `APP_DEBUG=true` |
| Production UI never displays raw errors | ✅ | Exception handler shows generic message in production; full trace logged server-side only |
| Dependencies scanned for vulnerabilities | ✅ | `composer audit --locked` + `pnpm audit --audit-level=high` + gitleaks in CI |
| LLM prompt injection mitigated | N/A | No AI/LLM integration in the application |
| Race conditions prevented | ✅ | Rate limiter uses atomic `INSERT ... ON CONFLICT DO UPDATE`; no critical financial operations |

---

## Authentication & Sessions

### Password Security
| Check | Status | Evidence |
|-------|--------|----------|
| Minimum length enforced | ✅ | 8 characters minimum (`AuthService::register()`) |
| Hashed with modern algorithm | ✅ | `PasswordHasher` service (bcrypt/argon2 via `password_hash()`) |
| Never stored in plain text | ✅ | Password hashed before storage, raw password never persisted |
| Never logged | ✅ | No password values in any log output |
| Breach detection (HIBP) | ❌ | Not implemented — acceptable for starter kit |

### Session Management
| Check | Status | Evidence |
|-------|--------|----------|
| Session ID regenerated after login | ✅ | `session_regenerate_id(true)` in `establishSession()` |
| Session timeout (idle + absolute) | ✅ | Idle: 3600s (60 min) via `SESSION_IDLE_TIMEOUT`; absolute: `SESSION_LIFETIME` (14 days GC) |
| Secure cookie flags set | ✅ | `httponly=1`, `samesite=Lax`, `secure` auto-detected from HTTPS |
| Session invalidation on logout | ✅ | `$_SESSION = []`, cookie expired, `session_destroy()`, remember-me token deleted |
| Strict mode | ✅ | `use_strict_mode=1` — prevents uninitialized session IDs |
| Cookie-only transport | ✅ | `use_only_cookies=1` — no session ID in URLs |
| Session storage | ✅ | Custom path `storage/sessions/` (not system temp) |

---

## Injection Prevention

### SQL Injection
| Check | Status | Evidence |
|-------|--------|----------|
| Prepared statements used | ✅ | All queries via `$pdo->prepare()` + `execute()` with named params |
| No string concatenation of user input | ✅ | Grep scan: 0 user-input hits in SQL concatenation |
| Identifiers not user-controlled | ✅ | Table/column names hardcoded; `{$placeholders}` uses `array_fill('?', ...)` |
| ORM/driver properly configured | ✅ | PDO with `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` |

### Command Injection
| Check | Status | Evidence |
|-------|--------|----------|
| No `exec()`/`system()` in web code | ✅ | Only in CLI commands (`GenerateImagesCommand`, `ServeCommand`) |
| Shell args escaped | ✅ | All `exec()` calls use `escapeshellarg()` |
| No `eval()`/`create_function()` | ✅ | Grep scan: 0 matches in `src/` |

### NoSQL Injection
| Check | Status | Evidence |
|-------|--------|----------|
| N/A | — | SQLite only, no NoSQL |

---

## Cross-Site Scripting (XSS)

| Check | Status | Evidence |
|-------|--------|----------|
| All output HTML-encoded | ✅ | No unescaped `<?= $` in templates; `htmlspecialchars()` used at boundaries |
| CSP header configured | ✅ | Nonce-based: `script-src 'self' 'nonce-...'`, `style-src 'self' 'nonce-...'` |
| `X-Content-Type-Options: nosniff` | ✅ | Set by `SecurityHeadersMiddleware` |
| `X-Frame-Options: DENY` | ✅ | Set by `SecurityHeadersMiddleware` |
| `frame-ancestors 'none'` | ✅ | In CSP directives |
| `base-uri 'self'` | ✅ | In CSP directives |
| Rich text sanitized | N/A | Blog content is Markdown rendered server-side; no raw HTML input from users |
| URLs validated before rendering | ✅ | Cover images from Cloudinary whitelist; no user-supplied URLs rendered |

---

## CSRF Protection

| Check | Status | Evidence |
|-------|--------|----------|
| CSRF tokens on all state-changing requests | ✅ | Global `CsrfMiddleware` — POST/PUT/DELETE/PATCH |
| SameSite cookie | ✅ | `session.cookie_samesite = Lax` |
| Timing-safe comparison | ✅ | `hash_equals()` for token validation |
| Token regeneration | ✅ | `regenerateToken()` available post-login |
| GET not used for state changes | ✅ | All state-changing routes use POST/PUT/DELETE |
| Form + header support | ✅ | `_token` field + `x-csrf-token` header |

---

## Secrets Management

| Check | Status | Evidence |
|-------|--------|----------|
| Secrets in env vars | ✅ | All config via `Env::get()` / `.env` file |
| `.env` in `.gitignore` | ✅ | Confirmed: `.env` not tracked in git |
| `.env.example` exists | ✅ | With placeholder values and documentation |
| No secrets in client-side code | ✅ | Frontend has no hardcoded secrets |
| No secrets in error messages | ✅ | Debug panel masks keys containing PASS/SECRET/TOKEN/DSN/AUTH/KEY |
| No secrets in logs | ✅ | Password reset tokens logged for dev only (dev mailer pattern) |
| Secret rotation | ⚠️ | Manual — acceptable for starter kit |
| Different secrets per environment | ✅ | `.env` per environment; `.env.example` as template |

---

## Infrastructure Security

### Security Headers
| Header | Status | Value |
|--------|--------|-------|
| `Content-Security-Policy` | ✅ | Nonce-based, no `unsafe-inline`/`unsafe-eval` in script-src |
| `X-Content-Type-Options` | ✅ | `nosniff` |
| `X-Frame-Options` | ✅ | `DENY` |
| `Referrer-Policy` | ✅ | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | ✅ | `camera=(), microphone=(), geolocation=()` |
| `Strict-Transport-Security` | ✅ | Production only: `max-age=31536000; includeSubDomains; preload` |
| `X-Powered-By` removed | ✅ | `header_remove('X-Powered-By')` in `init.php` |
| `X-XSS-Protection` not set | ✅ | Intentionally omitted (deprecated, can mask real XSS) |

### Error Handling
| Check | Status | Evidence |
|-------|--------|----------|
| Display errors off in production | ✅ | `display_errors=0` when `APP_DEBUG=false` |
| Log errors to file | ✅ | `storage/logs/php-error.log` |
| Generic error page in production | ✅ | "Server Error" — no stack traces, no SQL errors |
| Debug panel gated | ✅ | `/debug` returns 404 unless `APP_DEBUG=true` |
| Debugbar gated | ✅ | `DebugbarMiddleware` no-ops when `APP_DEBUG=false` |

### File Serving
| Check | Status | Evidence |
|-------|--------|----------|
| Path traversal prevented | ✅ | Lexical checks + `realpath` containment + extension whitelist |
| MIME detected server-side | ✅ | `finfo(FILEINFO_MIME_TYPE)` — never trusts client |
| Dotfiles blocked | ✅ | `isSafePath()` rejects basenames starting with `.` |
| Content-Type explicit | ✅ | Set per action, not from filename |

### Deployment
| Check | Status | Evidence |
|-------|--------|----------|
| HTTPS only | ✅ | HSTS in production; redirect via Apache `.htaccess` |
| Debug mode disabled | ✅ | `APP_DEBUG=false` in production compose overlay |
| Default credentials changed | ✅ | `SEED_ADMIN_PASSWORD` in `.env` — documented to change |
| File permissions restricted | ✅ | Docker: read-only rootfs, `cap_drop: ALL` |
| Logging enabled | ✅ | PHP error log + audit log |

---

## API Security

| Check | Status | Evidence |
|-------|--------|----------|
| Rate limiting per IP | ✅ | General 60/min, login 5/min, comments 10/min |
| Input validation | ✅ | Type checks, length limits, regex validation at boundaries |
| Response security | ✅ | No internal errors exposed; generic messages in production |
| No sensitive data in responses | ✅ | User responses exclude passwords; debug panel masks secrets |

---

## Skill-Context Rules Compliance

| Rule | Status | Evidence |
|------|--------|----------|
| Debug routes: register route, gate handler with 404 | ✅ | `DebugAction` always registered, returns 404 unless `APP_DEBUG=true` |
| Mask secret-like env keys in debug output | ✅ | `SECRET_FRAGMENTS = ['PASS', 'SECRET', 'TOKEN', 'DSN', 'AUTH', 'KEY']` — values replaced with `••••••` |

---

## Findings Summary

| Severity | Count | Details |
|----------|-------|---------|
| 🔴 Critical | 0 | — |
| 🟠 High | 0 | — |
| 🟡 Medium | 0 | — |
| 🟢 Low | 0 | — |
| ℹ️ Info | 1 | No HIBP breach detection (acceptable for starter kit) |

---

## Comparison with Offensive Self-Test

Both audits (defensive checklist + offensive self-pentest) reached the same conclusion: **no exploitable vulnerabilities found**. The defensive audit confirms that all OWASP Top 10 categories have proper controls in place. The offensive audit verified that these controls actually hold under attack.

---

```aif-gate-result
{
  "schema_version": 1,
  "gate": "security",
  "status": "pass",
  "blocking": false,
  "blockers": [],
  "affected_files": [],
  "suggested_next": null
}
```
