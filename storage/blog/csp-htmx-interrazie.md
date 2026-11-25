---
title: "CSP + HTMX: Keď bezpečnosť zlomí frontend (a ako to opraviť)"
date: "2026-09-01"
version: "1.0.1"
tags: ["htmx", "csp", "security", "frontend"]
excerpt: "Content Security Policy blokuje HTMX inline handlery. Ukážeme si prečo, ako to testujeme, a CSP-safe patterny pre filtre, formuláre a dynamický obsah."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "csp-htmx-interaction"
published: true
---

# CSP + HTMX: Keď bezpečnosť zlomí frontend (a ako to opraviť)

HTMX je skvelý nástroj pre dynamický obsah bez JavaScript frameworkov. Ale keď zapnete strict Content Security Policy (CSP), veci sa rýchlo pokazia. V tomto článku ukážeme reálny problém z nášho admin panelu a ako ho vyriešiť.

## Problém: `hx-on:*` = eval()

HTMX má atribút `hx-on:change="this.form.submit()"`: intuitívny spôsob ako reagovať na zmeny. Pod kapotou ale HTMX používa `Function()` constructor, čo je interný alias pre `eval()`.

Keď máte strict CSP:

```
Content-Security-Policy: script-src 'self' 'nonce-abc123'
```

Prehliadač zablokuje:

```
Uncaught EvalError: call to Function() blocked by CSP
```

HTMX `hx-on:*` atribúty sú teda **nekompatibilné** s strict CSP.

## Náš architecture test

V Nativa Minimal máme architecture test, ktorý blokuje **všetky** inline event handlery v PHP zdrojáku:

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

Test zachytí nielen `hx-on:*`, ale aj `onchange=`, `onclick=` a podobne. Dôvod: CSP blokuje všetky inline handlery, nie len HTMX-specific.

## CSP-safe patterny

### 1. Selects s HTMX

**Zlé** (blokuje CSP):
```html
<select hx-on:change="this.form.submit()">
    <option>Newest</option>
    <option>Most viewed</option>
</select>
```

**Dobré** (CSP-safe):
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

Kľúčové atribúty:
- `hx-trigger="change"`: HTMX natívny trigger, žiadne eval
- `hx-include="closest form"`: zahrnie všetky hodnoty z formulára
- `hx-get` + `hx-target`: načíta nový obsah bez reloadu

### 2. Formulár s dropdowns + hidden fields

Keď select zmení hodnotu, potrebujeme zachovať stav ostatných filtrov:

```html
<form method="GET" action="/mark/media">
    <input type="hidden" name="type" value="image">
    <input type="hidden" name="tag" value="nature">

    <!-- HTMX pills — rýchle prepínanie -->
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

Keď user zmení sort dropdown:
1. HTMX zachytí `change` event
2. `hx-include="closest form"` zoberie všetky hodnoty (type, tag, sort, category)
3. Odošle GET `/mark/media?type=image&tag=nature&sort=most_viewed&category=`
4. Server vráti nový `#media-content` div
5. HTMX ho vymení, žiadny full page reload

### 3. Tlačidlá s potvrdením

**Zlé**:
```html
<button onclick="return confirm('Delete?')">Delete</button>
```

**Dobré** (HTMX):
```html
<button hx-delete="/mark/media/123/delete"
        hx-confirm="Delete this file?"
        hx-target="closest tr"
        hx-swap="outerHTML swap:500ms">
    Delete
</button>
```

`hx-confirm` je natívny HTMX atribút, používa `window.confirm()`, nie `eval()`.

### 4. Inline scripty s nonce

Keď potrebujete JavaScript, použite nonce:

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

Nonce sa generuje per-request a CSP ho automaticky pustí.

## Čo HTMX CSP-safe NESTIHNE

HTMX má niekoľko funkcií, ktoré potrebujú `eval()` a sú blokované strict CSP:

- **`hx-on:*` atribúty**, používajú `Function()` constructor. Nahraďte `hx-trigger` + `hx-get/post`
- **`hx-vals='js:{...}'`**: evaluuje JS objekt. Nahraďte hidden fields alebo `hx-vals` bez `js:` prefixu
- **Trigger filtre**: `hx-trigger="click[isValid()]"` evaluuje filter. Použite štandardné triggers
- **`hx-confirm='js:...'`**: JS-evaluated confirm. Použite `hx-confirm="Text"` bez `js:` prefixu

**CSP-safe HTMX funkcie** (fungujú bez `eval()`):

- `hx-get`, `hx-post`, `hx-delete`: len URL reťazce
- `hx-trigger="change"`, `hx-trigger="click"`: natívne triggers
- `hx-target`, `hx-swap`, `hx-include`: CSS selektory
- `hx-vals` bez `js:` prefixu, key=value páry
- `hx-ws` (WebSockets), používa natívny `WebSocket` API, nie `eval()`
- `hx-boost`: intercepts links/forms
- `hx-confirm="Text"`: štandardný confirm dialog

## Záver

HTMX + strict CSP funguje, ale vyžaduje disciplínu:

1. **`hx-on:*` patria do minulosti**, nahraďte `hx-trigger` + `hx-get/post`
2. **Testujte inline handlery**: architecture test zachytí problémy skoro
3. **Používajte `hx-include="closest form"`**: pre dropdowns s viacerými filtrami
4. **Hidden fields pre state**: zachovajte hodnoty pri partial updates

CSP nie je nepriateľ HTMX, je to partner. Len treba poznať pravidlá.

---

## Zdroje

- [htmx.org, Web Security Basics (with htmx)](https://htmx.org/essays/web-security-basics-with-htmx/): oficiálny sprievodca bezpečnosťou HTMX
- [htmx.org, hx-csp extension](https://four.htmx.org/extensions/hx-csp): CSP extension pre HTMX 4
- [W3C, Content Security Policy Level 3](https://www.w3.org/TR/CSP3/): CSP specifikácia
- [MDN, Content-Security-Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP): MDN dokumentácia
- [HTMX, Trigger Filters](https://htmx.org/attributes/hx-trigger/): trigger filtre a eval()
