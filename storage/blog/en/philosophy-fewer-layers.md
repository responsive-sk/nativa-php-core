---
title: "Fewer layers ... the final part of the series about why PHP is fast"
series: "Why PHP is fast"
series-part: "3"
date: "2026-08-19"
version: "1.0.0"
tags: ["php", "philosophy", "performance"]
excerpt: "The final part of the series: why PHP's speed comes from C and from removing layers, how that looks in our stack and what rule we take away from thirty years of the language's history."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "filozofia-menej-vrstiev"
published: true
---

This is the last part of the series. We went through the Zend engine,
PHP's history, OPcache, JIT and C extensions. Now let's sit down and say
what's left of it all ... because the whole series had a single point, and
that point is also the philosophy of our stack.

Speed comes from C and from removing layers. That's the whole philosophy.

## What we learned

| Part | Conclusion |
|---|---|
| 1. Zend engine | PHP is a compiler + virtual machine in C; opcodes are precompiled code |
| 2. History | Every speed jump of PHP was new C code ... not a new layer |
| 3. OPcache + JIT | Precompilation at the language level; JIT compiles all the way to machine code |
| 4. C extensions | Built-in functions are C libraries; SQLite means direct C calls to your data |

All four parts prove from a different angle one single sentence:

> **PHP's speed comes from C and from removing layers.**

Not from a "better framework", not from another cache, not from a bigger
server. From C code doing the work, and from nothing standing between it
and your code to slow it down.

## What that looks like in our stack

This series wasn't theory ... our stack is a practical demonstration of
exactly those rules:

- **Zero-dependency runtime** ... there isn't a single interpreted layer
  between our code and the C functions. No framework, no vendor, no
  "PHP in PHP".
- **Native PHP templates** ... no Twig, no Blade, no template
  compilation. The code already is PHP; there is nothing to translate.
- **Per-module SQLite** ... every query is a direct C call into a C
  library. No DB server, no network latency.
- **The cache experiment** ... when the cache was slowing down LCP, we
  removed it and got 100/100. The extra layer wasn't adding value; it
  was removing it (more in the article [about our stack's
  philosophy](/blog/filozofia-super-rychleho-php)).
- **OPcache + preload** ... precompilation at the language level:
  opcodes in memory, key classes loaded at startup. A warm start with
  no layers at all.

## The rule going forward

From thirty years of PHP's history we take away one decision rule that we
apply to everything new:

1. **Need speed?** Move the work into C ... or find out that C already
   does it. Don't add a layer.
2. **Want a cache?** Distinguish: OPcache/JIT compile **underneath** the
   code (yes), a response cache is a layer **between** the code and the
   visitor (careful).
3. **Want edge/CDN?** That's fine ... it's an outer layer, not between
   code and data. Static HTML from nearby + a PHP core without
   dependencies.

## The manifesto

Fewer layers isn't minimalism for its own sake. It's an engineering rule
that PHP has been proving for thirty years: every time PHP needed to be
faster, it moved work into C and simplified what stood between C and the
developer. We do the same ... from the Zend engine in 1997 to our stack
today.

## Summary

- The whole series in one sentence: **speed comes from C and from
  removing layers**
- The engine, history, OPcache/JIT and C extensions all prove it from
  different angles
- Our stack: zero-dependency runtime, native templates, per-module
  SQLite, no extra cache ... all by the same rule
- The decision rule: move work into C, don't add layers; OPcache/JIT
  are underneath the code, a response cache is between code and visitor
- Fewer layers = more C = faster. That's the whole philosophy.

The starter kit remains a proof of concept for fewer layers. No framework, no ORM, no cache abstraction. Just PHP and SQLite, the way the language was designed to work.

---

Fewer layers is not minimalism. It's an engineering rule that PHP has been proving for thirty years.

---

→ [Super-fast PHP philosophy](/blog/super-fast-php-philosophy) — why PHP is fast
→ [C extensions API](/blog/php-c-extensions-api) — how extensions work at the C level

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse tool
