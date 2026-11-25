---
title: "CSP a onclick: Spravodlivá chyba, ktorá vás naučí pravidlá"
slug: "csp-onclick-spravodliva-chyba"
date: "2026-09-01"
version: "1.0.1"
author: "admin"
translation: "csp-onclick-silent-security-bug"
excerpt: "Prečo vaše tlačidlo s onclick funguje v DEV ale padá v PROD? Pretože prehliadač kompiluje onclick na eval()."
tags: ""
category: "architecture"
published: true
lang: "sk"
---

# CSP a onclick: Spravodlivá chyba, ktorá vás naučí pravidlá

Tlačidlo s `onclick` funguje roky. Potom nasadíte CSP a zrazu — padne. Nie je to bug. Je to spravodlivé pravidlo.

## Prečo onclick = eval()

Keď prehliadač vidí:

```html
<button onclick="return confirm('Delete?')">Delete</button>
```

kompiluje ho na:

```javascript
button.addEventListener('click', function(event) {
  return Function('event', 'return confirm(\'Delete?\')').call(this, event);
})
```

`Function()` constructor = `eval()`. CSP s `script-src 'self' 'nonce-...'` blokuje `eval()` — vaše tlačidlo padne.

## Ako to chytíme automaticky

V projekte máme architecture test ktorý scanuje PHP súbory:

```php
// CorePatternTest.php
public function testNoOndefaultHandlersInPhp(): void
{
    // Match onclick/onsubmit/onmouseover/etc. v PHP strings
    if (preg_match_all(
        '/\bon(?:click|submit|mouseover|mouseout|mouseenter|'
        . 'mouseleave|focus|blur|change|input|keydown|keyup|'
        . 'load|error)\s*=/',
        $content, $matches
    )) {
        $violations[] = $file . ':' . $line . ' → ' . $match;
    }
}
```

Test beží v CI — ak niekto pridá `onclick` do PHP šablóny, build padne. Automaticky, bez code review.

## Čo namiesto onclick

### HTMX hx-confirm

Najčastejší prípad — delete s potvrdením:

```html
<!-- Zlé: onclick → eval() → CSP violation -->
<button onclick="return confirm('Delete?')">Delete</button>

<!-- Dobré: hx-confirm → window.confirm() → CSP-safe -->
<button hx-delete="/mark/media/123/delete"
        hx-confirm="Delete this file?">
    Delete
</button>
```

`hx-confirm` volá `window.confirm()` — natívne API, žiadne eval.

### data-* + addEventListener

Keď potrebujete custom logiku:

```html
<!-- Zlé -->
<button onclick="openPicker('cover')">Choose cover</button>

<!-- Dobré -->
<button data-action="open-picker" data-target="cover">Choose cover</button>

<script nonce="__CSP_NONCE_PLACEHOLDER__">
document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-action="open-picker"]');
    if (!btn) return;
    var target = btn.dataset.target;
    if (typeof openPicker === 'function') openPicker(target);
});
</script>
```

Nonce script — CSP ho pustí pretože má správny nonce.

### HTMX hx-on:smth

HTMX má `hx-on:click` atribút — ale interné používa `Function()` constructor. Teda tiež `eval()`. Tiež padne.

Namiesto toho: `hx-get` + `hx-trigger="click"` + `hx-target`.

## Prečo je to spravodlivé

CSP neblokuje onclick pretože je zlý. Blokuje ho pretože:

1. **XSS ochrana** — ak útočník vloží `onclick` do vášho obsahu, má prístup k `Function()`
2. **Non-reflected inline** — inline handlery nie sú nonce-ované (nemajú `nonce` atribút)
3. **Princíp najmenších oprávnení** — menej inline JS = menej attack surface

## Zhrnutie

| Pattern | CSP | Alternatíva |
|---------|-----|-------------|
| `onclick="..."` | **NO** — eval() | `data-*` + `addEventListener` |
| `hx-on:click` | **NO** — Function() | `hx-trigger` + `hx-get/post` |
| `hx-confirm="..."` | **YES** — window.confirm() | — |
| `<script nonce="...">` | **YES** — nonce | — |
| `addEventListener` | **YES** — addEventListener | — |

Architecture test chytí `onclick` automaticky v CI. Nemusíte na to myslieť — test na to myslí za vás.

---

## Zdroje

- [MDN — Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP) — CSP špecifikácia
- [W3C — CSP Level 3](https://www.w3.org/TR/CSP3/) — oficiálna špecifikácia
- [OWASP — DOM Based XSS Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/DOM_based_XSS_Prevention_Cheat_Sheet.html) — inline handler riziká
- [CSP Spec — Inline Event Handlers](https://www.w3.org/TR/CSP3/#directive-script-src) — prečo inline handlery potrebujú unsafe-inline
- [htmx.org — Web Security Basics](https://htmx.org/essays/web-security-basics-with-htmx/) — HTMX a CSP
