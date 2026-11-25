---
title: "CSP + HTMX: When Security Breaks Your Frontend (and How to Fix It)"
date: "2026-09-01"
version: "1.0.0"
tags: ["htmx", "csp", "security", "frontend"]
excerpt: "Content Security Policy blocks HTMX inline handlers. We show why, how we test for it, and CSP-safe patterns for filters, forms, and dynamic content."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "csp-htmx-interrazie"
published: true
---

# CSP + HTMX: When Security Breaks Your Frontend (and How to Fix It)

HTMX is a great tool for dynamic content without JavaScript frameworks. But when you enable strict Content Security Policy (CSP), things break fast. In this article we show a real problem from our admin panel and how we solved it.

## The Problem: `hx-on:*` = eval()

HTMX has the `hx-on:change="this.form.submit()"` attribute — an intuitive way to react to changes. Under the hood, HTMX uses the `Function()` constructor, which is an internal alias for `eval()`.

With strict CSP:

```
Content-Security-Policy: script-src 'self' 'nonce-abc123'
```

The browser blocks:

```
Uncaught EvalError: call to Function() blocked by CSP
```

HTMX `hx-on:*` attributes are **incompatible** with strict CSP.

## Our Architecture Test

In Nativa Minimal we have an architecture test that blocks **all** inline event handlers in PHP source:

```php
// tests/Architecture/CorePatternTest.php
if (preg_match_all(
    '/\bon(?:click|submit|mouseover|change|input|keydown)\s*=/',
    $content,
    $matches
)) {
    $violations[] = $file . ':' . $line . ' → ' . $match;
}
```

This catches not just `hx-on:*`, but also `onchange=`, `onclick=`, etc. The reason: CSP blocks all inline handlers, not just HTMX-specific ones.

## CSP-Safe Patterns

### 1. Selects with HTMX

**Bad** (blocks CSP):
```html
<select hx-on:change="this.form.submit()">
    <option>Newest</option>
    <option>Most viewed</option>
</select>
```

**Good** (CSP-safe):
```html
<select name="sort"
        hx-get="/mark/media"
        hx-trigger="change"
        hx-target="#media-content"
        hx-swap="outerHTML"
        hx-include="closest form">
    <option value="newest">Newest</option>
    <option value="most_viewed">Most viewed</option>
</select>
```

Key attributes:
- `hx-trigger="change"` — HTMX native trigger, no eval
- `hx-include="closest form"` — includes all form values
- `hx-get` + `hx-target` — loads new content without reload

### 2. Form with Dropdowns + Hidden Fields

When a select changes value, we need to preserve other filter state:

```html
<form method="GET" action="/mark/media">
    <input type="hidden" name="type" value="image">
    <input type="hidden" name="tag" value="nature">

    <!-- HTMX pills — fast switching -->
    <a href="/mark/media?type=image"
       hx-get="/mark/media?type=image"
       hx-target="#media-content"
       hx-swap="outerHTML">Images</a>

    <!-- HTMX selects — change trigger -->
    <select name="sort"
            hx-get="/mark/media"
            hx-trigger="change"
            hx-include="closest form"
            hx-target="#media-content"
            hx-swap="outerHTML">
        <option value="newest">Newest</option>
        <option value="most_viewed">Most viewed</option>
    </select>

    <select name="category"
            hx-get="/mark/media"
            hx-trigger="change"
            hx-include="closest form"
            hx-target="#media-content"
            hx-swap="outerHTML">
        <option value="">All categories</option>
        <option value="photos">Photos</option>
    </select>
</form>
```

When the user changes the sort dropdown:
1. HTMX captures the `change` event
2. `hx-include="closest form"` takes all values (type, tag, sort, category)
3. Sends GET `/mark/media?type=image&tag=nature&sort=most_viewed&category=`
4. Server returns new `#media-content` div
5. HTMX swaps it — no full page reload

### 3. Buttons with Confirmation

**Bad**:
```html
<button onclick="return confirm('Delete?')">Delete</button>
```

**Good** (HTMX):
```html
<button hx-delete="/mark/media/123/delete"
        hx-confirm="Delete this file?"
        hx-target="closest tr"
        hx-swap="outerHTML swap:500ms">
    Delete
</button>
```

`hx-confirm` is a native HTMX attribute — uses `window.confirm()`, not `eval()`.

### 4. Inline Scripts with Nonce

When you need JavaScript, use a nonce:

```html
<script nonce="__CSP_NONCE_PLACEHOLDER__">
(function(){
    var sa = document.getElementById('select-all');
    if (sa) sa.addEventListener('change', function(){
        document.querySelectorAll('.checkbox').forEach(function(c){
            c.checked = sa.checked;
        });
    });
})();
</script>
```

The nonce is generated per-request and CSP automatically allows it.

## What HTMX CSP-Safe Can't Do

HTMX has several features that require `eval()` and are blocked by strict CSP:

- **`hx-on:*` attributes** — use the `Function()` constructor. Replace with `hx-trigger` + `hx-get/post`
- **`hx-vals='js:{...}'`** — evaluates a JS object. Replace with hidden fields or `hx-vals` without the `js:` prefix
- **Trigger filters** — `hx-trigger="click[isValid()]"` evaluates a filter expression. Use standard triggers instead
- **`hx-confirm='js:...'`** — JS-evaluated confirm. Use `hx-confirm="Text"` without the `js:` prefix

**CSP-safe HTMX features** (work without `eval()`):

- `hx-get`, `hx-post`, `hx-delete` — just URL strings
- `hx-trigger="change"`, `hx-trigger="click"` — native triggers
- `hx-target`, `hx-swap`, `hx-include` — CSS selectors
- `hx-vals` without `js:` prefix — key=value pairs
- `hx-ws` (WebSockets) — uses the native `WebSocket` API, not `eval()`
- `hx-boost` — intercepts links/forms
- `hx-confirm="Text"` — standard confirm dialog

## Conclusion

HTMX + strict CSP works, but requires discipline:

1. **Never use `hx-on:*`** — use `hx-trigger` + `hx-get/post` instead
2. **Test inline handlers** — architecture tests catch problems early
3. **Use `hx-include="closest form"`** — for dropdowns with multiple filters
4. **Hidden fields for state** — preserve values during partial updates

CSP is not an enemy of HTMX — it's a partner. You just need to know the rules.

CSP is not a restriction you must fight. It is a contract you must understand. When you know what `eval()` means in the browser, the fixes become obvious. The next person who reads your code should not need to guess why `hx-on:change` was replaced with `hx-trigger`. The architecture test explains it. CSP and operability must go together.

→ [CSP onclick silent security bug](/blog/csp-onclick-silent-security-bug) — when `onclick` slips through CSP
→ [Rate-limit cache fallback](/blog/rate-limit-cache-fallback) — what happens when the pipeline blocks you
→ [Module isolation](/blog/module-isolation) — why security lives where it lives

## Sources

- [htmx.org — Web Security Basics (with htmx)](https://htmx.org/essays/web-security-basics-with-htmx/) — official HTMX security guide
- [htmx.org — hx-csp extension](https://four.htmx.org/extensions/hx-csp) — CSP extension for HTMX 4
- [W3C — Content Security Policy Level 3](https://www.w3.org/TR/CSP3/) — CSP specification
- [MDN — Content-Security-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP) — MDN documentation
- [HTMX — Trigger Filters](https://htmx.org/attributes/hx-trigger/) — trigger filters and eval()
