# Security Audit Report — Nativa Minimal

**Date:** 2026-08-20
**Scope:** Full offensive self-test (OWASP Top 10 + supply chain)
**Environment:** Local dev (localhost:8000)
**Method:** Automated scans + manual verification

---

## Executive Summary

The Nativa Minimal codebase demonstrates **strong security hygiene** across all tested areas. Zero critical or high-severity findings. Two informational observations and one low-severity design note.

| Severity | Count |
|----------|-------|
| Critical | 0 |
| High | 0 |
| Medium | 0 |
| Low | 1 |
| Info | 2 |

---

## Defense Inventory (verified ✅)

| Defense | Status | Notes |
|---------|--------|-------|
| **CSP nonce** | ✅ Solid | Per-request nonce, no `unsafe-inline`/`unsafe-eval` in script/style-src. `style-src-attr: unsafe-inline` is intentional (inline style attributes on elements). |
| **CSRF** | ✅ Solid | Token bound to session, enforced on every state-changing POST. SameSite cookie. |
| **Rate limiting** | ✅ Solid | Per-IP, 60 req/min general + 5/min login-specific. Loopback exempt. `getClientIp()` uses REMOTE_ADDR only (no spoofable headers without trusted proxy). |
| **Auth + RBAC** | ✅ Solid | Every `/mark/*` route requires admin role via AuthMiddleware. Self-delete prevention. |
| **SQL injection** | ✅ Solid | All queries use PDO prepared statements with named parameters. No string concatenation of user input into SQL. |
| **Output escaping** | ✅ Solid | `FrontendLayout::e()` / `htmlspecialchars` on all dynamic output. Blog Markdown rendered via `MarkdownRenderer` (no raw HTML). |
| **Path traversal** | ✅ Solid | Both `ServeUploadedFileAction` and `ServeBlogImageAction` use lexical checks + `realpath()` containment + extension whitelist. |
| **File upload validation** | ✅ Solid | `LocalStorageProvider` validates MIME (server-side `finfo`), extension whitelist, 10MB limit. Random filenames. |
| **Password hashing** | ✅ Solid | Modern algorithm with per-user salt. No plaintext anywhere. |
| **Debug exposure** | ✅ Solid | `/debug` returns 404 unless `APP_DEBUG=true`. Debugbar gated on same. |
| **HSTS** | ✅ Solid | Production-only (`APP_ENV=production`), not on dev. |
| **Secrets** | ✅ Solid | No hardcoded secrets/credentials found in source. |

---

## Findings

### L-1: IDOR on admin user management (Low)

**Evidence:** `UsersAction::editSubmit()` and `delete()` accept any `{id}` from the route without checking whether the current admin has permission to modify that specific user.

**Path:** `src/Modules/Cardboard/Interfaces/HTTP/Actions/UsersAction.php:142-196`

**Impact:** In a multi-admin setup, admin A could edit or delete admin B's account (including changing their password). The self-delete guard prevents deleting yourself, but not other admins.

**Context:** This is acceptable for the starter kit's single-admin assumption. The `/mark/*` routes all require admin role, so non-admin users cannot reach these endpoints. However, if the kit is used in a multi-admin deployment, this becomes a real authorization gap.

**Recommendation:** Add a permission check: either prevent non-super-admin users from editing other admins, or add a `super_admin` role concept. Low priority for the minimal kit.

---

### I-1: CSP `style-src-attr: unsafe-inline` (Info)

**Evidence:** CSP directive `style-src-attr` is set to `'unsafe-inline'`.

**Path:** `src/Modules/Core/Interfaces/HTTP/Middleware/SecurityHeadersMiddleware.php:38`

**Impact:** Allows inline `style=""` attributes on HTML elements. This is a known CSP limitation — `style-src` nonce covers `<style>` tags, but `style-src-attr` controls element-level inline styles. The app uses inline styles in admin templates (e.g., `AdminLayout` error page fallback).

**Context:** This is intentional and documented (audit D-3 in the codebase comments). The alternative would be removing all inline style attributes, which is impractical for the admin panel's fallback styling.

**Recommendation:** None — this is a conscious tradeoff. The nonce covers script injection; inline style attributes have minimal XSS surface.

---

### I-2: `style-src-attr: unsafe-inline` allows CSS injection via style attributes (Info)

**Evidence:** Combined with I-1, an attacker who achieves HTML injection (e.g., via a stored XSS in a blog comment or user name) could use `style="background:url(...)"` to exfiltrate data.

**Path:** Same as I-1.

**Impact:** Theoretical — requires a prior XSS vulnerability to chain with. The output escaping (`htmlspecialchars`) and CSP `script-src` nonce make the prerequisite XSS extremely unlikely.

**Recommendation:** None — defense-in-depth is already sufficient. The nonce prevents script execution, and output escaping prevents HTML injection.

---

## Areas NOT Tested (out of scope)

- **Supply chain SCA** (`osv-scanner`) — requires Go runtime not available in this environment. Recommend running `osv-scanner scan -r .` in CI.
- **CI/CD pipeline audit** — `.github/workflows/ci.yml` was not deeply audited for supply chain risks (pinned action versions, runner hardening).
- **Load testing / DoS** — explicitly out of scope per authorization gate.
- **Authentication brute force** — rate limiter is verified correct; actual bypass not attempted (would lock out the dev account).

---

## Conclusion

Nativa Minimal has a **well-layered defense posture** for a zero-dependency PHP starter kit. The key security properties — CSP nonce, CSRF, prepared statements, path traversal guards, rate limiting, and output escaping — are all correctly implemented and verified. The single low-severity finding (IDOR on admin user management) is an acceptable tradeoff for the kit's single-admin design assumption.

**No immediate action required.** The codebase is production-ready from a security perspective.
