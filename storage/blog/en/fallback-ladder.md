---
title: "Rust and Lekalo for kids ... part 13: the fallback ladder"
series: "Rust & Lekalo"
series-part: "13"
date: "2026-09-17"
version: "1.0.0"
tags: ["rust", "lekalo", "fallback", "degradation", "architecture"]
excerpt: "A cover pin the CSP blocks is a failure that never announces itself. A fallback ladder isn't an argument about order — it's an order, and it must have a name. The contract gives silent failures a voice."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "rebrick-zlyhania-pre-deti"
published: true
---

In [part 12](/blog/covers-as-contracts) we moved identities into the
things themselves. Today a thing that had been silent for years spoke
up: an article pinning its cover to an imgur URL. The browser refused
it. Nobody found out.

## What happened

The article template knows an `image:` line — a pin to one specific
cover. One older article carried `i.imgur.com/placeholder.png`. A literal
placeholder. The browser will never show it: CSP allows images only from
our own server and from cloudinary. Imgur dies at the door.

And more: the failure was silent. No error in the log, no alert. The
page looks healthy — until someone looks exactly where the image should
be. The most expensive failures are the ones that never announce
themselves.

## The ladder that should have been there

A pin isn't the only way an article gets its cover. `resolveCover()`
walks a ladder: the explicit pin first, then the local SVG with its
`?v=` hash, and the cloudinary pool as a safety net at the bottom.
A fallback ladder — the order that says who steps in when the previous
step falls:

```php
// 1. pin from front matter — when present and non-empty
// 2. local SVG + ?v= hash — a fresh URL after every regeneration
// 3. cloudinary pool — the safety net when nothing else exists
```

Every system that tries something and doesn't plan for the first
attempt to fall has a ladder — written down, or hidden in the code.
The question isn't whether a ladder exists. The question is whether
you can say who steps in, and why. We could. We also knew what the
ladder didn't know: **how a pin fails when the browser refuses it.**
It fails silently — and silence is a hole in the ladder.

## The contract gives the failure a name

The fix isn't to change the CSP or the ladder. The fix is to give the
silent failure a voice — a contract that catches a broken pin before
it leaves the building. `CoverPinPolicyTest` runs over every article
and checks two rules:

```php
// 1. the pin must be renderable (CSP allowlist)
$allowed = '/' === $host
    || in_array($host, self::CSP_ALLOWED_HTTPS_HOSTS, true);

// 2. the pin must not point at generator output
//    — the ladder versions that URL through ?v=
self::assertFalse(
    str_starts_with($pin, '/storage/blog-images/'),
    'pinning your own SVG bypasses cache-busting',
);
```

The first rule: an imgur pin dies in the test, not in a reader's
browser. The second: nobody pins their own SVG — that would bypass the
`?v=` hash and a regeneration would serve year-old bytes again, the
case from [part 12](/blog/covers-as-contracts). A failure with a name
can be fixed. A failure without one can only be found — if someone
looks.

## Why the CSP isn't enough

Why a contract when the CSP decides anyway? Because the CSP is the
last rung of the ladder — it stops a bad URL but says nothing. The
message arrives in the reader's browser, and only as an image that
isn't there. Rust has the same instinct: `Result<T, E>` won't let a
failure pass without a name — the language requires you to type the
error. Our silent image was a fall without a type. The CSP is a sound
policy, not a report. The report has to come earlier — where you write,
not where you read.

A ladder is order, not an argument. Whoever has a place in the order
steps in — and a failure that announces itself is part of the order.

## Cloudinary in the ladder

Cloudinary stays excellent — just not as an article's identity. The
pool holds the mood and handles a genuinely unique image for an article
that earns one. Which is exactly why it isn't the place for a
deterministic cover that must survive regeneration unchanged. The pool
decides by chance, the ladder by order — and identity is order.

---

## A human note

A ladder doesn't make a system indestructible. It guarantees one thing:
when the first step falls, the next steps in by order, and the failure
announces itself by name, not by silence. The next person who comes
after you sees the ladder and understands the order. And the reader
sees the image — because the failure that never announced itself,
this time, wasn't there.

---

→ [Part 12 — covers as contracts](/blog/covers-as-contracts) — the
identity of a thing lives in the thing; regeneration changes the URL
→ [Part 11 — the map says 393, the terrain 962](/blog/map-vs-terrain) —
what an agent may trust when it reads a map
→ [MDN: Content-Security-Policy img-src](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/img-src)
— the browser as the last rung of the ladder

## Sources

- `tests/Unit/CoverPinPolicyTest.php` — the contract: CSP renderability
  + no pinning your own SVG
- `src/Modules/Blog/Application/Services/BlogArticlesService.php` —
  the `resolveCover()` ladder: pin → local SVG with `?v=` → cloudinary pool
- `src/Modules/Core/Interfaces/HTTP/Middleware/SecurityHeadersMiddleware.php`
  — the CSP `img-src` allowlist
- [Part 12 — covers as contracts](/blog/covers-as-contracts)
- [MDN: Content-Security-Policy img-src](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Content-Security-Policy/img-src)
