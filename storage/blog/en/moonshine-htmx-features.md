---
title: "MoonShine Features + HTMX: Admin Panel Without a JavaScript Framework"
date: "2026-08-29"
version: "1.0.0"
tags: ["php", "moonshine", "htmx", "admin-panel", "ux", "design-system"]
excerpt: "From MoonShine UI we took toast notifications, progress bar, skeleton loading, tooltips, breadcrumbs, and empty states. From HTMX we took live search, pagination, and form submit. All in pure PHP 8.5."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "moonshine-htmx-features"
published: true
---

In the previous article we wrote about how MoonShine UI inspired Cardboard — immutable cards, SVG charts, design tokens. But that was just the beginning.

MoonShine has dozens of components that make admin panels pleasant. Toast notifications, progress bar, tooltips, breadcrumbs. And HTMX adds live search, pagination, and form submit without reloads.

The question was: how much of this can we build in pure PHP without a JavaScript framework?

The answer: almost all of it.

## What We Took From MoonShine

MoonShine UI is a Laravel package. It has Blade components, Alpine.js interactions, Livewire state. We have none of those.

But the language — CSS classes, naming conventions, layout patterns — that works without Laravel.

### 1. Toast Notifications

When a user creates a page, they want confirmation. MoonShine uses Alpine.js toasts. We used inline JavaScript:

```php
// AdminLayout — toast helper
public static function toast(string $message, string $type = 'ok'): string
{
    return '<script nonce="__CSP_NONCE_PLACEHOLDER__">'
        . 'mToast("' . self::e($message) . '", "' . self::e($type) . '");'
        . '</script>';
}
```

```css
.m-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    padding: 12px 20px; border-radius: var(--m-r-md);
    animation: m-toast-in 300ms forwards;
}
.m-toast--ok { background: var(--m-success); }
.m-toast--err { background: var(--m-error); }
```

How it works:

```
1. User clicks "Create page"
2. Server processes → saves → redirects to /mark/pages?toast=Page+created
3. PagesIndexAction detects ?toast= parameter
4. Renders AdminLayout::toast("Page created")
5. Toast displays for 3 seconds in the bottom-right corner
```

CSP-safe. No external scripts. No frameworks.

### 2. Progress Bar

File uploads on `/mark/media` needed feedback. MoonShine has a progress bar component. We used XHR:

```css
.m-progress { height: 6px; background: var(--m-border); border-radius: 3px; }
.m-progress-bar {
    height: 100%; background: var(--m-primary);
    transition: width 300ms ease;
}
```

```javascript
var xhr = new XMLHttpRequest();
xhr.upload.addEventListener("progress", function(e) {
    var pct = Math.round((e.loaded / e.total) * 100);
    bar.style.width = pct + "%";
    text.textContent = pct + "% — " + formatSize(e.loaded);
});
xhr.open("POST", form.action);
xhr.send(new FormData(form));
```

Users see exactly how much has been uploaded. No surprises.

### 3. Skeleton Loading

When data is loading, MoonShine shows a shimmer skeleton. We did the same:

```css
.m-skeleton {
    background: linear-gradient(90deg,
        var(--m-surface) 25%, var(--m-surface-2) 50%, var(--m-surface) 75%);
    background-size: 200% 100%;
    animation: m-skeleton-shimmer 1.5s infinite;
}
```

Usage:

```php
AdminLayout::skeleton(['title', 'text', 'text']); // 3 shimmer rows
```

### 4. Tooltip System

MoonShine uses Alpine.js for tooltips. We made CSS-only:

```css
[data-tooltip] { position: relative; }
[data-tooltip]::after {
    content: attr(data-tooltip);
    position: absolute; bottom: calc(100% + 6px); left: 50%;
    transform: translateX(-50%);
    opacity: 0; transition: opacity 150ms ease;
}
[data-tooltip]:hover::after { opacity: 1; }
```

Usage — no JavaScript API needed:

```html
<button data-tooltip="Edit page">Edit</button>
<button data-tooltip="Delete page">Delete</button>
```

### 5. Breadcrumbs

MoonShine has a breadcrumb component. We created a helper:

```php
AdminLayout::breadcrumb([
    ['label' => 'Dashboard', 'href' => '/mark'],
    ['label' => 'Pages'],  // current — no href
]);
```

Renders:

```html
<nav class="m-breadcrumb" aria-label="Breadcrumb">
    <a href="/mark">Dashboard</a>
    <span class="m-breadcrumb__sep">/</span>
    <span class="m-breadcrumb__current">Pages</span>
</nav>
```

Aria-label. Semantic HTML. No framework.

### 6. Empty State Illustrations

When a table is empty, MoonShine shows an illustration. We created a helper with an SVG icon:

```php
AdminLayout::emptyState(
    'No pages yet',
    'Create your first page to get started.',
    '/mark/pages/create?locale=sk',
    'New page'
);
```

Centered icon, title, description, CTA button. All in one PHP line.

### 7. Rich Status Badges

MoonShine has a badge system. We added badges to the Articles index:

```
[Public] [php] [types] [clean-code]
[Members] [admin] [moonshine]
```

Each badge is a CSS class — `.m-badge-success`, `.m-badge-purple`, `.m-badge-info`, `.m-badge-gray`.

## What We Added From HTMX

MoonShine uses Alpine.js for interactions. We chose HTMX — server-driven, no client-side state.

### Why HTMX and Not Alpine.js

| | HTMX | Alpine.js |
|---|---|---|
| **Approach** | Server renders HTML | Client-side reactivity |
| **PHP renders** | Yes (HTML fragments) | No (JSON only) |
| **Fallback** | Full page reload | None |
| **Size** | ~14KB | ~15KB |
| **Zero-dep** | yes — self-hosted | yes — self-hosted |

HTMX is server-driven. PHP renders HTML fragments. HTMX swaps them into the DOM. No client-side state, no reactive variables.

### 1. Live Search

Before HTMX: user types in search input, presses Enter, full page reload.

With HTMX: instant results:

```html
<input type="search" name="q"
    hx-get="/mark/pages"
    hx-trigger="keyup changed delay:300ms, search"
    hx-target="#pages-content"
    hx-swap="outerHTML"
    hx-include="[name=\"locale\"]"
    placeholder="Search pages...">
```

Server filters and returns only `<div id="pages-content">` with the table. HTMX swaps outerHTML. No reload.

### 2. Pagination

Before HTMX: click a page → reload.

With HTMX: partial update:

```html
<a class="m-btn m-btn-sm" href="?locale=sk&page=2"
    hx-get="/mark/pages?locale=sk&page=2"
    hx-target="#pages-content"
    hx-swap="outerHTML"
    hx-push-url="true">
    2
</a>
```

`hx-push-url="true"` updates the URL in the address bar. Browser history works.

### 3. Form Submit

Before HTMX: submit → redirect → reload.

With HTMX:

```html
<form method="POST" action="/mark/pages/create?locale=sk"
    hx-post="/mark/pages/create?locale=sk"
    hx-indicator="#create-spinner">
    ...
    <button type="submit">
        <span class="htmx-indicator" id="create-spinner">⏳</span>
        Create page
    </button>
</form>
```

Server returns `HX-Redirect` header. HTMX redirects. No full reload.

### 4. Delete

Before HTMX: confirm → redirect → reload.

With HTMX:

```html
<form method="POST" action="/mark/pages/about/delete?locale=sk"
    hx-delete="/mark/pages/about/delete?locale=sk"
    hx-confirm="Delete this page?"
    hx-target="closest tr"
    hx-swap="outerHTML swap:500ms">
    ...
</form>
```

Server returns empty response. HTMX removes the row with a 500ms animation.

### CSRF with HTMX

HTMX needs a CSRF token in every request. Solution — inline config script:

```javascript
document.body.addEventListener("htmx:configRequest", function(e) {
    e.detail.headers["X-CSRF-Token"] = "token-value";
});
```

Token is injected via the `htmx:configRequest` event. No global variables.

## Opt-in Detection

HTMX is **optional**. If the file `public/dist/htmx.min.js` doesn't exist, nothing loads:

```php
private static function htmxScriptTag(): string
{
    $path = AppPaths::instance()->public('dist/htmx.min.js');
    if (!is_file($path)) {
        return '';
    }
    return '<script src="/dist/htmx.min.js" defer></script>';
}
```

If HTMX isn't there, everything works via full page reloads. No breaking changes.

## What We Didn't Do

MoonShine has components that require Alpine.js:

- **Dropdown** — needs `x-data`, `x-show`
- **Modal** — needs `x-on:click`
- **Tabs** — needs `x-data`
- **Off-canvas** — needs `x-show`

We replaced them with CSS-only solutions:

| MoonShine | Nativa solution |
|-----------|-----------------|
| Dropdown (Alpine) | CSS `position: absolute` + `:hover` |
| Modal (Alpine) | `<dialog>` element + CSS |
| Tabs (Alpine) | CSS `:target` + radio buttons |
| Toast (Alpine) | Inline JS `mToast()` |
| Tooltip (Alpine) | CSS `::after` pseudo-element |

Everything works without a single line of client-side framework.

## The Result

An admin panel at `/mark` with:

- **Toast notifications** — CSS + inline JS, 3 types (ok/err/info)
- **Progress bar** — XHR upload with tracking
- **Skeleton loading** — CSS shimmer animation
- **Tooltips** — CSS `data-tooltip` attribute
- **Breadcrumbs** — `AdminLayout::breadcrumb()` helper
- **Empty states** — SVG illustrations with CTA
- **Rich badges** — status, category, tags
- **HTMX live search** — instant results without reload
- **HTMX pagination** — partial updates with browser history
- **HTMX form submit** — HX-Redirect without reload
- **HTMX delete** — row fade-out animation

All in pure PHP 8.5. No Composer packages in production. No JavaScript frameworks.

639 tests. PHPStan level 9. 0 CS-Fixer violations.

→ [MoonShine Cardboard](/blog/moonshine-cardboard) — how MoonShine inspired Cardboard
→ [Clean Code for Kids](/blog/co-je-cisty-kod-pre-deti) — how to write code that reads itself
→ [Philosophy of Fewer Layers](/blog/filozofia-menej-vrstiev) — why fewer abstractions = fewer bugs

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
