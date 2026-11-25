---
title: "Performance for kids ... part 2: LCP on the article page and text LCP elements"
date: "2026-08-13"
version: "1.0.0"
tags: ["performance", "lcp", "fetchpriority", "lighthouse", "php"]
excerpt: "Part 2: we fixed the LCP cards on the index, but the article page had its own problem ... a cover image without fetchpriority=high. We show how LCP differs from page to page, what a text LCP element is and why one pattern isn't enough."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "performance-pre-deti-diel-2-cover-lcp"
published: true
---

> This is part 2 of the **Performance for kids** series. In [part 1](/en/blog/lcp-srcset-basics)
> we showed what LCP is, why the LCP image must never be lazy and how
> `srcset` + `sizes` save half the bytes on blog cards. Today we look at
> the **article page** ... because LCP isn't once and for all, it changes
> from page to page. And we'll also show what to do when the LCP isn't an
> image at all.

## Every page has its own LCP

In [part 1](/en/blog/lcp-srcset-basics) we fixed the LCP on `/blog` ... the
first three cards got `eager` + `fetchpriority="high"`, and srcset shrank
the images by half. Lighthouse on the index jumped to 100. Done? **No.**

LCP isn't a property of the website ... it's a property of **one specific
page**. What's the LCP on the index (the first card) doesn't have to be
the LCP on the article page. There, the largest piece of content is the
**cover image** ... 720 × 360 px up above the title. And when we ran
Lighthouse on it, we found another wrinkle.

## The cover had eager, but no fetchpriority

The cover on the article page had `loading="eager"` ... so it wasn't
loading lazily, that was fine. But it was missing
**`fetchpriority="high"`**. Lighthouse `lcp-discovery-insight` showed
exactly this:

```
priorityHinted:  fetchpriority=high should be applied  → FALSE
requestDiscoverable: Request is discoverable in initial document → TRUE
eagerlyLoaded:   LCP resources should not use loading=lazy → TRUE
```

Two of the three checklist items passed, but the most important one
didn't. Why does `fetchpriority` matter when the image is already
eager? Because eager says "download now", but says nothing about
**priority**. On an article page the cover competes with CSS, fonts and
other resources for the network ... and `fetchpriority` gives it a VIP
pass: "this resource matters more than the others, download it first."

The fix was one line in `BlogArticleAction`:

```php
// The cover is the article page's LCP candidate ... eager with
// fetchpriority=high so it loads first (same pattern as the card
// grid and the homepage hero).
return '<img class="st-article__cover" src="' . FrontendLayout::e($image)
    . '" alt="' . FrontendLayout::e($meta['title'] ?? $slug) . '" width="720" height="360"'
    . ' loading="eager" fetchpriority="high" decoding="async">';
```

And the result after a re-run:

| Audit | Before | After |
|---|---|---|
| `lcp-discovery-insight` | FAIL (priorityHinted) | **PASS** |
| Performance | 100 | **100** |
| LCP | 0.6 s | **0.6 s** |

The LCP time didn't change ... the page was already fast. But the audit
that measures whether the LCP resource loads correctly stopped
complaining. And that matters: **Lighthouse measures not only how fast
the page loads, but also whether resources are prioritized correctly.**

## Why the cover has no srcset

In [part 1](/en/blog/lcp-srcset-basics) we gave srcset to the cards,
because they display at 330 px but downloaded at 720 px. The cover on
the article page doesn't have that problem ... its CSS is `width: 100%`
and it displays at basically the full article width (~768 px on
desktop), so the 720-px version is exactly the right one. No wasted
bytes, no srcset.

That's an important lesson: **srcset isn't mandatory equipment for
every image.** It's a solution to a specific problem ... "the image
displays smaller than it downloads." When it displays at its native
size, srcset only adds noise. Likewise the SVG cards have no srcset ...
a vector scales arbitrarily.

## Text LCP element

So far we've handled LCP images. But LCP doesn't have to be an image at
all. When a page has a big heading above an image, the LCP can be
**text** ... an `<h1>` or `<p>`. Lighthouse picks the largest rendered
element, no matter whether it's an image or text.

Different rules apply to a text LCP:

1. **Text can't be lazy-loaded** ... no `loading`, no `fetchpriority`.
   Text is in the HTML immediately, so its LCP is directly proportional
   to how fast the **HTML** arrives (TTFB) and how fast the CSS renders.
2. **No web font in the way** ... if the heading uses a font that gets
   downloaded, the browser may wait for the font (FOIT) and the text
   renders late. Solutions: `font-display: swap` or system fonts.
3. **Rendering-blocking CSS** ... the less CSS blocks rendering, the
   sooner the text appears. Inline critical CSS is exactly for that.

In this repo the LCP on the article page is the cover image, so the text
rules aren't critical here ... but knowing about them is half the battle.
The day you add a page without a cover (like an error page), the LCP
moves to text and suddenly TTFB and fonts matter.

## A rule without a test isn't followed

As always in this series ... we sign the contract with a test. A contract
test joined `BlogCoverTest` that would fail if someone took
`fetchpriority` away from the cover:

```php
// The article page cover is its LCP candidate ... eager + fetchpriority
// =high, discoverable in the initial document (same LCP pattern as
// the card grid and homepage hero).
self::assertStringContainsString('loading="eager" fetchpriority="high" decoding="async"', $html);
```

Now we have **three places with the same pattern** ... the index cards,
the homepage hero and the article page cover ... and each has a test that
guards the LCP resource loading correctly.

## What to take away

1. **LCP is per-page** ... what's the LCP on the index doesn't have to be
   the LCP on an article. Measure every template separately.
2. **`eager` and `fetchpriority` are two different things** ... eager says
   "when", fetchpriority says "in what order". An LCP resource needs
   both.
3. **srcset isn't for everyone** ... only for images that display smaller
   than they download.
4. **LCP can be text** ... then TTFB, fonts and rendering-blocking CSS
   matter, not image attributes.
5. **One pattern, three places, three tests** ... shared logic is great,
   but every use deserves its own contract.

In the next part we'll show how to read a Lighthouse report like a
detective ... which audits are "scored" and which are just
recommendations, and why it's sometimes worth ignoring "Est savings".

Cover image LCP remains the fastest way to tank your Lighthouse score — just not the only one. When you know the contract, the fix is one attribute.

→ [LCP srcset basics](/blog/lcp-srcset-basics) — responsive images done right
→ [Reading Lighthouse report](/blog/reading-lighthouse-report) — detective work on audits
→ [Text LCP](/blog/text-lcp) — when the LCP element isn't an image

## Sources

- [W3C — ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance) — ACID definition
- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [SQLite Documentation](https://www.sqlite.org/docs.html) — SQLite reference manual
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
