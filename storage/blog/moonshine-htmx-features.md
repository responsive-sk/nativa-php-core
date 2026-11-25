---
title: "MoonShine Features + HTMX: admin panel bez JavaScript frameworku"
date: "2026-08-29"
version: "1.0.0"
tags: ["php", "moonshine", "htmx", "admin-panel", "ux", "design-system"]
excerpt: "Z MoonShine UI sme vzali toast notifikácie, progress bar, skeleton loading, tooltips, breadcrumbs a empty states. Z HTMX live search, pagination a form submit. Všetko v čistom PHP 8.5."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "moonshine-htmx-features"
published: true
---

V predchádzajúcom článku sme písali, ako MoonShine UI inšpiroval Cardboard, immutable karty, SVG grafy, design tokeny. Ale to bol len začiatok.

MoonShine má desiatky komponentov, ktoré robia admin panel príjemným. Toast notifikácie, progress bar, tooltips, breadcrumbs. A HTMX pridáva live search, pagination a form submit bez reloadov.

Otázka znela: koľko z toho vieme urobiť v čistom PHP bez JavaScript frameworku?

Odpoveď: takmer všetko.

## Čo sme vzali z MoonShine

MoonShine UI je Laravel balík. Má Blade komponenty, Alpine.js interakcie, Livewire state. My nemáme ani jedno.

Ale jazyk, CSS triedy, naming konvencie, layout patterny, ten funguje aj bez Laravel.

### 1. Toast notifikácie

Keď používateľ vytvorí stránku, chce vidieť potvrdenie. MoonShine používa Alpine.js toast. My sme použili inline JavaScript:

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

Ako to funguje:

```
1. User klikne "Create page"
2. Server spracuje → uloží → redirect na /mark/pages?toast=Page+created
3. PagesIndexAction detekuje ?toast= param
4. Renderuje AdminLayout::toast("Page created")
5. Toast sa zobrazí 3 sekundy v pravom dolnom rohu
```

CSP-safe. Žiadne external skripty. Žiadne frameworky.

### 2. Progress bar

Upload súborov na `/mark/media` potreboval feedback. MoonShine má progress bar komponent. My sme použili XHR:

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

Užívateľ vidí presne, koľko sa uploadlo. Žiadne prekvapenia.

### 3. Skeleton loading

Keď sa dáta načítavajú, MoonShine ukazuje shimmer skeleton. My sme urobili to isté:

```css
.m-skeleton {
    background: linear-gradient(90deg,
        var(--m-surface) 25%, var(--m-surface-2) 50%, var(--m-surface) 75%);
    background-size: 200% 100%;
    animation: m-skeleton-shimmer 1.5s infinite;
}
```

Použitie:

```php
AdminLayout::skeleton(['title', 'text', 'text']); // 3 shimmer riadky
```

### 4. Tooltip system

MoonShine používa Alpine.js pre tooltips. My sme urobili CSS-only:

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

Použitie, žiadne JavaScript API:

```html
<button data-tooltip="Edit page">Upraviť</button>
<button data-tooltip="Delete page">Zmazať</button>
```

### 5. Breadcrumbs

MoonShine má breadcrumb komponent. My sme vytvorili helper:

```php
AdminLayout::breadcrumb([
    ['label' => 'Dashboard', 'href' => '/mark'],
    ['label' => 'Pages'],  // current — bez href
]);
```

Renderuje:

```html
<nav class="m-breadcrumb" aria-label="Breadcrumb">
    <a href="/mark">Dashboard</a>
    <span class="m-breadcrumb__sep">/</span>
    <span class="m-breadcrumb__current">Pages</span>
</nav>
```

Aria-label. Semantický HTML. Žiadny framework.

### 6. Empty state illustrations

Keď je tabuľka prázdna, MoonShine ukazuje ilustráciu. My sme vytvorili helper s SVG ikonou:

```php
AdminLayout::emptyState(
    'No pages yet',
    'Create your first page to get started.',
    '/mark/pages/create?locale=sk',
    'New page'
);
```

Centrovaná ikona, titulok, popis, CTA tlačidlo. Všetko v jednom riadku PHP.

### 7. Rich status badges

MoonShine má badge systém. My sme pridali badges na Articles index:

```
[Public] [php] [types] [clean-code]
[Members] [admin] [moonshine]
```

Každý badge je CSS trieda,`.m-badge-success`, `.m-badge-purple`, `.m-badge-info`, `.m-badge-gray`.

## Čo sme pridali z HTMX

MoonShine používa Alpine.js pre interakcie. My sme si vybrali HTMX, server-driven, žiadny client-side state.

### Prečo HTMX a nie Alpine.js

| | HTMX | Alpine.js |
|---|---|---|
| **Prístup** | Server renderuje HTML | Client-side reaktivita |
| **PHP roluje** | Áno (partial HTML) | Nie (len JSON) |
| **Fallback** | Full page reload | Žiadny |
| **Veľkosť** | ~14KB | ~15KB |
| **Zero-dep** | áno, self-hosted | áno, self-hosted |

HTMX je server-driven. PHP renderuje HTML fragmenty. HTMX ich len swapne do DOM. Žiadny client-side state, žiadne reaktivné premenné.

### 1. Live search

Pred HTMX: user píše do search inputu, stlačí Enter, full page reload.

S HTMX: instant results:

```html
<input type="search" name="q"
    hx-get="/mark/pages"
    hx-trigger="keyup changed delay:300ms, search"
    hx-target="#pages-content"
    hx-swap="outerHTML"
    hx-include="[name=\"locale\"]"
    placeholder="Search pages...">
```

Server filtruje a vracia len `<div id="pages-content">` s tabuľkou. HTMX swapne outerHTML. Žiadny reload.

### 2. Pagination

Pred HTMX: klik na stránku → reload.

S HTMX: partial update:

```html
<a class="m-btn m-btn-sm" href="?locale=sk&page=2"
    hx-get="/mark/pages?locale=sk&page=2"
    hx-target="#pages-content"
    hx-swap="outerHTML"
    hx-push-url="true">
    2
</a>
```

`hx-push-url="true"` aktualizuje URL v address bare. Browser history funguje.

### 3. Form submit

Pred HTMX: submit → redirect → reload.

S HTMX:

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

Server vracia `HX-Redirect` header. HTMX presmeruje. Žiadny full reload.

### 4. Delete

Pred HTMX: confirm → redirect → reload.

S HTMX:

```html
<form method="POST" action="/mark/pages/about/delete?locale=sk"
    hx-delete="/mark/pages/about/delete?locale=sk"
    hx-confirm="Delete this page?"
    hx-target="closest tr"
    hx-swap="outerHTML swap:500ms">
    ...
</form>
```

Server vrací prázdny response. HTMX odstráni riadok z tabuľky s 500ms animáciou.

### CSRF s HTMX

HTMX potrebuje CSRF token v každom requeste. Riešenie, inline config script:

```javascript
document.body.addEventListener("htmx:configRequest", function(e) {
    e.detail.headers["X-CSRF-Token"] = "token-hodnota";
});
```

Token sa injectne cez `htmx:configRequest` event. Žiadne global premenné.

## Opt-in detekcia

HTMX je **voliteľný**. Ak súbor `public/dist/htmx.min.js` neexistuje, nič sa nenačíta:

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

Ak HTMX nie je, všetko funguje cez full page reload. Žiadny breaking change.

## Čo sme neurobili

MoonShine má komponenty, ktoré vyžadujú Alpine.js:

- **Dropdown**: vyžaduje `x-data`, `x-show`
- **Modal**: vyžaduje `x-on:click`
- **Tabs**: vyžaduje `x-data`
- **Off-canvas**: vyžaduje `x-show`

My sme ich nahradili CSS-only riešeniami:

| MoonShine | Nativa riešenie |
|-----------|-----------------|
| Dropdown (Alpine) | CSS `position: absolute` + `:hover` |
| Modal (Alpine) | `<dialog>` element + CSS |
| Tabs (Alpine) | CSS `:target` + radio buttons |
| Toast (Alpine) | Inline JS `mToast()` |
| Tooltip (Alpine) | CSS `::after` pseudo-element |

Všetko funguje bez jediného riadku client-side frameworku.

## Výsledok

Admin panel na `/mark` s:

- **Toast notifikácie**: CSS + inline JS, 3 typy (ok/err/info)
- **Progress bar**: XHR upload s trackingom
- **Skeleton loading**: CSS shimmer animation
- **Tooltips**: CSS `data-tooltip` atribút
- **Breadcrumbs**: `AdminLayout::breadcrumb()` helper
- **Empty states**: SVG ilustrácie s CTA
- **Rich badges**: status, kategória, tagy
- **HTMX live search**: instant results bez reloadu
- **HTMX pagination**: partial updates s browser history
- **HTMX form submit**: HX-Redirect bez reloadu
- **HTMX delete**: row fade-out animácia

Všetko v čistom PHP 8.5. Žiadne Composer balíky v produkcii. Žiadne JavaScript frameworky.

639 testov. PHPStan level 9. 0 CS-Fixer violations.

→ [MoonShine Cardboard](/blog/moonshine-cardboard): ako MoonShine inšpiroval Cardboard
→ [Clean code pre deti](/blog/co-je-cisty-kod-pre-deti): ako písať kód, ktorý sa číta sám
→ [Filozofia menej vrstiev](/blog/filozofia-menej-vrstiev): prečo menej abstrakcií = menej chýb

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
