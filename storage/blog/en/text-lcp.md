---
title: "Performance for kids ... part 4: when the LCP isn't an image (text LCP)"
date: "2026-08-13"
version: "1.0.0"
tags: ["performance", "lcp", "text", "ttf", "fonts", "lighthouse"]
excerpt: "Part 4: all those image tricks from the previous parts are useless when a page has no image at all. On the login page the LCP is text ... and it's measured completely differently. We show TTFB, FOIT, font-display: swap and why text LCP is the fastest thing on the web."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "performance-pre-deti-diel-4-textovy-lcp"
published: true
---

> This is part 4 of the **Performance for kids** series. In [part 1](/en/blog/lcp-srcset-basics)
> we fixed the LCP images on the index, in [part 2](/en/blog/article-cover-lcp)
> on the article page, and in [part 3](/en/blog/reading-lighthouse-report)
> we learned to read Lighthouse critically. Today we show the opposite
> case: a page that **has no image at all**. What's the LCP there? And
> why does that mean half the tricks from the previous parts suddenly
> become useless?

## A page without images: the login

This repo has two pages with not a single `<img>`: `/login` and
`/register`. No hero, no cover, no card. Just a heading, a form and a
few words. What's the LCP there?

We ran Lighthouse and the result was surprisingly boring:

| Metric | Value |
|---|---|
| Performance | **100** |
| LCP | **0.2 s** |
| FCP | **0.2 s** |
| HTML size | 12 KiB |
| `lcp-discovery-insight` | **N/A** (no image) |

And the LCP element? It's even **text, not a heading** ... `auth-footnote`,
a small note under the form. On a page with a single box and a form, the
"largest" rendered content is a plain paragraph. LCP 0.2 s means: the
text painted before any image could even have been downloaded.

## Text LCP is measured completely differently

Here's the key idea of this part: **an image LCP waits for a network
request, a text LCP waits for nothing.** Text is right in the HTML. The
browser can paint it as soon as the HTML arrives ... no extra file needed.

That means a text LCP is a function of three things:

1. **TTFB (Time To First Byte)** ... how fast the server sends the first
   byte of HTML. This is now your biggest lever: a fast server response
   = fast text LCP.
2. **Rendering-blocking CSS** ... if the style is in an external file that
   blocks rendering, the text waits until the CSS arrives. Solution:
   inline critical CSS right in the HTML (this repo does that ... see
   `FrontendLayout`).
3. **Web fonts** ... and here's the sneakiest piece of the puzzle.

## FOIT: when a font holds text hostage

Have you ever noticed a page loading where the text is first invisible,
then "jumps" into a different font? That's **FOIT** ... *flash of
invisible text*.

When a heading (or paragraph) uses a web font that gets downloaded, the
browser faces a dilemma: "Should I show the text immediately in a system
font, or wait for the web font so it looks exactly like the design?"

By default, many browsers wait ... and while waiting, the text is
**invisible**. For a text LCP that's a disaster: the LCP element is in
the DOM, but hasn't painted because it's waiting for a font. Lighthouse
measures LCP as **visible** paint, so the text LCP moves to the time the
font arrives.

The solution is `font-display`:

```css
@font-face {
    font-family: 'MyFont';
    src: url('/fonts/my-font.woff2') format('woff2');
    font-display: swap;  /* show text immediately in a system font,
                            swap it when the web font arrives */
}
```

- `font-display: swap` ... the text renders immediately in a fallback
  font (FOUT ... *flash of unstyled text*), and the web font swaps in when
  it arrives. LCP is fast, text is readable right away.
- `font-display: block` (or the default) ... text waits for the font, LCP
  suffers.
- `font-display: optional` ... if the font doesn't arrive very fast, the
  browser gives up on it entirely and keeps the system one. Extremely
  fast LCP, but the web font may never show.

In this repo it's simple: we use no web fonts at all, only system ones
(`system-ui`). That's why the text LCP on `/login` has nothing to block
it and is 0.2 s. But if we added a font without `font-display: swap`,
this page would suddenly slow down ... without a single new image.

## Why lcp-discovery-insight disappeared

Notice `lcp-discovery-insight: N/A` in the table. In the previous parts
this audit told us "the LCP image should be eager + fetchpriority". On
the login page **there is no image**, so the audit has nothing to say ...
it's not a fail, it's **not applicable**. Lighthouse can tell "you
didn't manage it" from "it doesn't apply to this page".

That's exactly what [part 3](/en/blog/reading-lighthouse-report)
was about: reading an audit by context. `fetchpriority="high"` on text
makes no sense (text is in the HTML immediately, there's nothing to
prioritize). Adding it just to keep the audit quiet would be fixing a
problem that doesn't exist.

## Same pattern, different toolkit

In [part 2](/en/blog/article-cover-lcp) we said every
page has its own LCP. Now we see it in full:

| Page | LCP element | What speeds it up |
|---|---|---|
| `/blog` | first card's cover (image) | `eager` + `fetchpriority`, srcset |
| `/blog/{slug}` | article cover (image) | `fetchpriority="high"` |
| `/` (home) | hero (image) | `eager` + `fetchpriority`, srcset |
| `/login` | text (note under the form) | fast TTFB, inline CSS, no blocking fonts |

Four pages, four LCP elements, two completely different toolkits. Image
tricks do nothing on a text page ... and text tricks do nothing on an
image page. **LCP can't be solved once; it must be measured on every
template.**

## How to speed up a text LCP

1. **Speed up TTFB** ... cache what you can, minimize server work. Text
   waits for the first byte more than anything else.
2. **Inline critical CSS** ... text won't paint until the
   rendering-blocking CSS arrives. Critical styles inline in `<head>`,
   load the rest later.
3. **`font-display: swap`** ... a web font must not hold text hostage.
   And the fastest font is the one you don't have to download: a system
   font.
4. **Don't mix toolkits** ... don't add `fetchpriority` to text and don't
   add srcset for a page without images. Context decides.

## A rule without a test isn't followed

Text LCP is basically "free" in this repo ... system fonts, inline CSS,
small pages. But there's still a test guarding that the auth pages have
nothing blocking rendering: `FrontendLayout` tests check that critical
CSS is inline (not an external render-blocking file), and
`BuildCssAuditTest` verifies that the built CSS is valid and
self-hosted. No web font = no FOIT = text LCP at max.

## What to take away

1. **Text LCP doesn't wait for the network** ... it's directly
   proportional to TTFB and rendering-blocking CSS.
2. **FOIT is the enemy of text** ... a web font without
   `font-display: swap` keeps text invisible and LCP stuck.
3. **N/A ≠ FAIL** ... when an audit isn't applicable, don't fix it. Don't
   add `fetchpriority` to text.
4. **Four pages, two worlds** ... image tricks vs text tricks. LCP is
   measured on every template separately.
5. **The fastest font is no font** ... system fonts are LCP friend number
   one.

With this we close the first "contract" of the series: image LCP
(parts 1–2), critical Lighthouse reading (part 3) and text LCP
(part 4). In the final part of the series we change the subject: we
built a fast website ... and now let's see what to do with the traffic it
brings. [Part 5](/en/blog/members-only-business-pattern)
is about members-only bonus articles as a business pattern.

Text LCP remains the trickiest performance metric — just not one you should ignore. When you know the font contract, the fix is one CSS line.

→ [Article cover LCP](/blog/article-cover-lcp) — the first image that matters
→ [LCP srcset basics](/blog/lcp-srcset-basics) — responsive images done right
→ [Reading Lighthouse report](/blog/reading-lighthouse-report) — detective work on audits

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — security best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web standards
