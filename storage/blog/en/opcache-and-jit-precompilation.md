---
title: "OPcache and JIT ... precompilation at the language level"
date: "2026-08-17"
version: "1.2.0"
tags: ["php", "opcache", "jit", "performance"]
excerpt: "How OPcache keeps compiled opcodes in memory, what opcache.preload does and when JIT compiles PHP all the way down to native machine code ... part three of the series about why PHP is precompiled like Astro."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "opcache-a-jit-prekompilacia"
published: true
---

In the first part we saw the chain `source → tokens → AST → opcodes →
execution`, and in the second how the Zend engine evolved in C. Now let's
look at two things that turn PHP into a "precompiled" language: **OPcache**
and **JIT**. And one important thing up front: this is a cache that doesn't
bother us. It's not an extra layer ... it's a compiler.

OPcache keeps compiled opcodes in memory. JIT compiles hot loops to machine code. Both work underneath the code, not between the code and the visitor.

## OPcache ... opcodes in memory

Without OPcache, PHP runs the whole chain from lexing to compilation on
**every** request: it reads the source, parses it and produces opcodes.
That's like running an Astro build for every visitor.

OPcache changes that. On the first request it compiles the script into
opcodes and stores them in **shared memory**. On the second request it
just says "I have the opcodes for this file" and the Zend VM executes them
directly. Lexing, parsing and compilation are skipped.

That's exactly what Astro does when it pre-renders pages into static HTML
at build time: the work is done once, then it's just delivered. OPcache is
**precompilation at the language level** ... and today it's enabled by
default (it's been part of the core since PHP 5.5).

## opcache.preload ... warm start

OPcache handles opcodes after the first request. But what about that first
one? When a PHP process starts, nothing is in memory yet ... every class has
to be loaded and compiled on first use.

That's where **opcache.preload** comes in. You tell PHP which files to
load and compile **at process startup**, not on the first request:

```ini
opcache.preload=/path/to/preload.php
```

The `preload.php` file loads your key classes (for example our whole
`src/`) before the first request even arrives. The result is exactly what
we called **warm start** in the article about our stack's philosophy: the
first request doesn't pay any compilation latency, because everything is
already in memory.

## JIT ... all the way to machine code

OPcache stops at opcodes ... the Zend VM interprets those. **JIT** (Just In
Time, PHP 8.0+) goes further: it watches which parts of the code run over
and over and **compiles them into native machine code**. Then the CPU
executes them directly, without an interpreter.

PHP's JIT is a so-called **tracing JIT** ... it doesn't try to compile
everything, but tracks "hot" loops and compiles precisely those.
Compilation runs through dynasm directly in memory, so even the JIT
itself is C code producing machine code.

An important nuance: JIT isn't an automatic speedup for every website.
Compilation has overhead, so for content-heavy sites (blogs,
documentation), opcode interpretation + OPcache is often faster. JIT
shines where there's a lot of computation ... math, data processing, long
loops. Our stack is exactly the first case: we don't need another layer
trying to speed up what already runs as C calls.

## Why this cache doesn't bother us

In the philosophy article we threw out a cache that was slowing down LCP.
That wasn't a contradiction ... that was an **HTTP/response cache**, an
extra layer between the server and the visitor. OPcache and JIT are a
different thing: they're layers **underneath** our code that compile, not
between it and the user. One adds latency, the other removes it. That's
exactly the difference between "adding layers" and "moving work into C".

## Summary

- **OPcache** keeps compiled opcodes in shared memory ... requests 2..N
  skip lexing, parsing and compilation
- It's "precompilation" in the Astro sense, just at the language level
- **opcache.preload** loads key classes at process startup ... no cold
  start, no first-request latency
- **JIT (PHP 8.0+)** compiles hot loops all the way down to native
  machine code (tracing JIT, dynasm)
- JIT isn't for every website ... for content-heavy sites, interpretation
  + OPcache is usually faster
- OPcache/JIT are caches **underneath** the code, not between the code
  and the visitor ... they compile, they don't add a layer

OPcache remains the invisible performance layer. JIT is the optional accelerator. Both prove the same rule: speed comes from C, not from frameworks.

---

A cache that compiles is not a cache. It's a compiler that remembers.

---

→ [C extensions API](/blog/php-c-extensions-api) — how C code plugs into the Zend engine
→ [Fewer layers philosophy](/blog/philosophy-fewer-layers) — the final part of the series

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse tool
