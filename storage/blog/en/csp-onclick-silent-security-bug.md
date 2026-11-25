---
title: "CSP and onclick: The Silent Bug That Teaches You the Rules"
slug: "csp-onclick-silent-security-bug"
date: "2026-09-01"
version: "1.0.0"
author: "admin"
translation: "csp-onclick-spravodliva-chyba"
excerpt: "Why your onclick button works in DEV but crashes in PROD? Because the browser compiles onclick into eval()."
tags: ""
category: "architecture"
published: true
lang: "en"
---

# CSP and onclick: The Silent Bug That Teaches You the Rules

A button with `onclick` works for years. Then you deploy CSP and it breaks. Not a bug, a fair rule.

## Why onclick = eval()

When the browser sees:

```html
<button onclick="return confirm('Delete?')">Delete</button>
```

it compiles it to:

```javascript
button.addEventListener('click', function(event) {
  return Function('event', 'return confirm(\'Delete?\')').call(this, event);
})
```

`Function()` constructor = `eval()`. CSP with `script-src 'self' 'nonce-...'` blocks `eval()`: your button crashes.

## How we catch it automatically

The project has an architecture test that scans PHP files:

```php
// CorePatternTest.php
public function testNoOndefaultHandlersInPhp(): void
{
    // Match onclick/onsubmit/onmouseover/etc. in PHP strings
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

The test runs in CI, if someone adds `onclick` to a PHP template, the build fails. Automatically, no code review needed.

## What to use instead of onclick

### HTMX hx-confirm

Most common case, delete with confirmation:

```html
<!-- Bad: onclick → eval() → CSP violation -->
<button onclick="return confirm('Delete?')">Delete</button>

<!-- Good: hx-confirm → window.confirm() → CSP-safe -->
<button hx-delete="/mark/media/123/delete"
        hx-confirm="Delete this file?">
    Delete
</button>
```

`hx-confirm` calls `window.confirm()`: native API, no eval.

### data-* + addEventListener

When you need custom logic:

```html
<!-- Bad -->
<button onclick="openPicker('cover')">Choose cover</button>

<!-- Good -->
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

Nonce script, CSP allows it because it has the correct nonce.

### HTMX hx-on:smth

HTMX has `hx-on:click` attribute, but it internally uses the `Function()` constructor. So also `eval()`. Also crashes.

Instead: `hx-get` + `hx-trigger="click"` + `hx-target`.

## Why this is fair

CSP doesn't block onclick because it's bad. It blocks it because:

1. **XSS protection**: if an attacker injects `onclick` into your content, they get `Function()` access
2. **Non-reflected inline**: inline handlers aren't nonce-ified (no `nonce` attribute)
3. **Principle of least privilege**: less inline JS = less attack surface

## Summary

| Pattern | CSP | Alternative |
|---------|-----|-------------|
| `onclick="..."` | **NO**: eval() | `data-*` + `addEventListener` |
| `hx-on:click` | **NO**: Function() | `hx-trigger` + `hx-get/post` |
| `hx-confirm="..."` | **YES**: window.confirm() |, |
| `<script nonce="...">` | **YES**: nonce |, |
| `addEventListener` | **YES**: addEventListener |, |

The architecture test catches `onclick` automatically in CI. You don't have to think about it, the test thinks for you.

One line of regex fixed the problem across the entire codebase. The solution was not a framework migration or a new dependency, just a pattern match that caught the mistake before it reached production.

The code you read tomorrow should not make you guess why `onclick` was replaced with `addEventListener`. The test explains it. The comment explains it. CSP and operability must go together.

→ [CSP & htmx interaction](/blog/csp-htmx-interaction): when nonces meet dynamic content
→ [Rate-limit cache fallback](/blog/rate-limit-cache-fallback): what happens when the pipeline blocks you
→ [Module isolation](/blog/module-isolation): why security lives where it lives

## Sources

- [MDN, Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP): CSP specification
- [W3C, CSP Level 3](https://www.w3.org/TR/CSP3/): official specification
- [OWASP, DOM Based XSS Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/DOM_based_XSS_Prevention_Cheat_Sheet.html): inline handler risks
- [CSP Spec, Inline Event Handlers](https://www.w3.org/TR/CSP3/#directive-script-src): why inline handlers need unsafe-inline
- [htmx.org, Web Security Basics](https://htmx.org/essays/web-security-basics-with-htmx/): HTMX and CSP
