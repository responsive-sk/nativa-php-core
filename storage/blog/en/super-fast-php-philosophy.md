---
title: "The vision of a super-fast PHP app ... a debate with DeepSeek about the philosophy of our stack"
series: "Why PHP is fast"
series-part: "1"
date: "2026-08-15"
version: "1.0.0"
tags: ["php", "performance", "philosophy", "ai"]
excerpt: "A debate with DeepSeek about the vision of a super-fast PHP app: why clean PHP with zero dependencies beats Next.js and Astro, why we threw out the cache and got 100/100 on PageSpeed, and what remains of that conversation as the philosophy of our stack."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "filozofia-super-rychleho-php"
published: true
---

Today I had an unusual debate. Not with a colleague, not with a client ... with
DeepSeek. It wasn't about a specific bug or another feature; it was about our
**super-fast PHP** vision and the philosophy behind building the web app we
run. The conversation turned into a surprisingly decent audit of our
stack ... and its conclusions deserve to be written down. This is that record.

## The vision: "PHP C chunks"

Our whole idea fits into one sentence: our web is **"PHP C chunks"** ...
what Astro does for static sites (precompiled HTML, minimal JavaScript), we
do in clean PHP right on the server.

Astro + Cloudflare are considered the golden standard of speed today: Astro
pre-renders pages into static HTML and Cloudflare delivers them from the
closest point on the network. No heavy client runtime, no hydration, no
overhead. Just finished HTML.

We have the same thing ... just without Astro, without a build process and
without a CDN:

| Aspect | Astro + Cloudflare | Our approach (PHP on the server) |
|---|---|---|
| "Precompilation" | Yes ... static HTML build | Not needed ... PHP is already ready to run |
| Runtime | Node.js / Deno | PHP interpreter (core written in C) |
| Content delivery | Global CDN | Own server, direct HTTP request |
| Overhead | Build process + CDN management | None ... the request goes straight into PHP |
| PageSpeed result | Great score | 100/100 |

What exactly is in the box?

- **Zero-dependency PHP 8.5 runtime** ... no Composer packages in production.
  By removing dependencies we eliminated a huge chunk of the overhead that
  slows down typical PHP apps.
- **Hand-rolled PSR-4 autoloader** ... no `vendor/autoload.php`; our own
  autoloader is faster and fully auditable.
- **Per-module SQLite** ... no DB server, no network latency. Data lives
  locally, which from a speed perspective is almost like working with files.
- **Native PHP templates** ... no Twig, no Blade, no template compilation. The
  code already is PHP; there is nothing to translate.

DeepSeek summed it up precisely: *"This is exactly the spirit of C chunks ...
only what's needed."*

## "PHP C chunks" is not a metaphor

It might sound like a slogan, but it's a literal description of how PHP
works. PHP is an interpreter written in C that carries a huge library of
**precompiled C functions**:

| Layer | What it is | Where it runs |
|---|---|---|
| Built-in functions (`str_replace`, `preg_match`, `json_encode`, `hash`, PDO…) | C code compiled directly into the interpreter | Native C code |
| Extensions (SQLite, mbstring, openssl…) | C libraries ... SQLite is entirely written in C | Native C code |
| Our `src/` | A thin orchestration layer between the request and the C functions | Interpreted PHP bytecode |

When we process a request, most of the actual work is done by C code ... PHP
is just the glue that calls the C functions. And since we have no Composer
runtime packages, there isn't a single extra interpreted layer between our
code and those C chunks. No framework, no vendor, no "PHP in PHP".

And PHP itself tries to be precompiled:

- **OPcache** ... compiles the script to bytecode once and then just runs it.
  That's "precompilation" in the Astro sense, just at the language level.
- **JIT (since PHP 8.0)** ... compiles hot paths at runtime all the way down
  to native machine code. At that point even our small PHP layer runs like C.

So "PHP C chunks" is not a slogan ... it's a description of the architecture.
We just removed everything from it that didn't belong.

## The cache was slowing down our LCP. We threw it out.

The most instructive part of the debate wasn't about what we added, but about
what we **removed**. We had the cache enabled for a long time ... and it was
paradoxically hurting us. When the server "fell asleep" after a long idle
period, the first page load took 3.8 seconds and the cache only made things
worse instead of helping.

We walked through all the classic solutions:

1. **Wake-up mechanism** ... regular pings so the server never sleeps. It
   works, but it's a band-aid, not a systemic fix.
2. **opcache.preload** ... load the key code into memory at startup. Ideal for
   our architecture, but it only fixes the warm start.
3. **PHP-FPM workers** ... keep a minimum number of workers ready. Again, it
   only masks the symptom, not the cause.

And then came the question that changed everything: **why solve this at all
when we can simply not solve it?**

We removed the cache. And for several days without it, our public pages score
**100/100 on performance tests** ... not because we used the cache more
cleverly, but because we stopped using it altogether. No extra layer, no
losses, no compilation. Native PHP templates running directly.

That experience gave us the motto **"Faster than cache"** and the rule we
apply to everything new: *add features, not layers.*

## Why not Next.js with node_modules on the server?

The debate also touched on a question I keep asking myself: **why does anyone
put SSR + node_modules on a server and then complain about dependency
injection?** It always seemed irrational to me ... and DeepSeek confirmed it.

The reasoning is simple: **compile → test → serve.** The build environment
(where you compile) and the runtime environment (where the app runs) are
completely separated. You ship only finished, working code to the production
server ... no compiler, no Node.js runtime, no thousands of folders in
`node_modules`.

Why doesn't this happen anyway? Because mainstream frameworks are built
differently:

- **React runtime and hydration** ... Next.js ships the React runtime to the
  client and "takes over" the HTML with JavaScript after load. That's
  overhead our PHP handles with none at all: we send finished HTML and
  nothing more.
- **Dependency hell** ... a simple SSR project means thousands of packages,
  build tools and version conflicts. We have zero dependencies in
  production.
- **Slow default paths** ... Next.js can be fast, but it requires a static
  export, a CDN and extreme discipline. Our default path is fast on its own.

And how much does it all weigh? The dev environment of this project takes
~215 MB ... 98 MB of `vendor/` and 112 MB of `node_modules/`. The production
dist ... what actually runs on the server ... is **1.7 MB**. The ratio is almost
**126:1**:

| What | Size |
|---|---|
| Dev environment (vendor + node_modules + src) | ~215 MB |
| Production dist (zero-dependency) | 1.7 MB |
| Ratio | ~126:1 |

Our entire production server is smaller than a single phone photo ... and it
runs faster than most apps with 200 MB of tooling behind them.

It's like food in a restaurant: we cook in the kitchen and serve the guest a
finished plate. Whoever compiles on the server for different browsers is
cooking at the guest's table ... and then wonders why it's slow and stressful.It's 2026
and compilers are still running in production. They don't have to
be.

## Cloudflare bought Astro. We're already on the edge.

In January 2026 Cloudflare acquired the team at The Astro Technology Company
... the creators of Astro. The framework stays open-source (MIT) and
platform-agnostic, but Cloudflare gained the whole stack from local
development to edge delivery. Their bet is clear: the world is moving toward
static precompiled HTML with minimal JavaScript, delivered from the closest
point on the network.

And in April 2026 they went further: **EmDash** ... a full-stack TypeScript CMS
built on Astro, the "spiritual successor to WordPress", whose README
literally says *"No PHP"*. Cloudflare's answer to the WordPress world, then,
is not to fix PHP but to replace it.

There's a beautiful irony here: EmDash is built on the same ideas as ours ...
SQLite, FTS5, RSS, RBAC, audit log ... but with a heavy JS toolchain and a
scale-to-zero architecture that brings cold starts. That is exactly the
3.8-second LCP problem we solved by removing layers. They layer caches to get
acceptable performance; we removed the cache and got 100/100.

And what about "PHP on the edge"? The honest answer: Cloudflare Workers has
no native PHP. PHP can run there only via WASM (php-wasm ... PHP compiled to
WebAssembly): it works for a demo, but with caveats ... no native extensions,
memory limits, cold start and WASM overhead. The production-realistic hybrid
for our stack looks like this:

| Layer | What it serves |
|---|---|
| Edge (Cloudflare or any CDN) | Static HTML, assets, cache ... a finished plate |
| PHP origin (our stack) | Dynamic chunks ... auth, comments, admin, full-text search |

That is exactly the conclusion from the DeepSeek debate ... "put only a CDN in
front of PHP". Nothing more, nothing less. We don't need to be bought: the
edge layer is just a proxy in front of a zero-dependency core that runs
anywhere.

## What DeepSeek said and what we took away

The debate wasn't just agreement. Two things were worth writing down:

**1. Scale matters.** Comparing our site to Google.com is like comparing a
100-meter sprint with a marathon. Google runs a marathon with a heavy
backpack; we sprint 100 meters unencumbered. Both results are good ... for
different purposes. Our 100/100 doesn't mean we're "better" than Google; it
means we do exactly what we need and nothing more.

**2. 100/100 isn't only about speed.** A perfect score also means zero
layout shift (CLS = 0) and instant interactivity (great INP). It's proof of
stability and discipline, not just fast hardware.

And one thing DeepSeek rightly called out: **geographic distribution.** Our
server is in one place; Cloudflare is everywhere. The fix, though, is simple
and doesn't break our philosophy: keep the PHP backend exactly as it is and
put a CDN in front of it. Result: Cloudflare's global speed + our PHP code's
performance.

## Summary

- **The vision** = "PHP C chunks" ... a static, precompiled
  approach in clean PHP, without a build process and without a heavy runtime
- **Zero-dependency core** ... own autoloader, per-module SQLite, native PHP
  templates
- **Cache is not a cure** ... when it slowed down LCP, we removed it and got
  100/100
- **Add features, not layers** ... compilation and dependencies belong in the
  build environment, not in production
- **Faster than cache** ... and if global delivery is needed, put only a CDN in
  front of PHP and keep the core dependency-free
- **The edge is not a competitor** ... Cloudflare bought Astro and is building a
  "No PHP" world (EmDash); our core runs anywhere, and PHP on Workers only
  works via WASM

→ [Fewer layers philosophy](/blog/philosophy-fewer-layers) — why fewer abstractions = fewer bugs
→ [PHP history](/blog/php-history-from-fi-to-8) — from FI to PHP 8.5
→ [OPcache and JIT](/blog/opcache-and-jit-precompilation) — precompilation and native machine code

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
