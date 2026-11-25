---
title: "Performance for kids ... part 1: LCP, srcset and why images shouldn't download more than they need"
date: "2026-08-13"
version: "1.0.0"
tags: ["performance", "lcp", "srcset", "lighthouse", "php"]
excerpt: "A new series about web performance. Part 1 explains what LCP is, why loading=lazy on the most important image is a betrayal, and what fetchpriority=high and srcset do ... backed by real measurements from this repo (desktop LCP 1.2s → 0.8s, images half the size)."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "performance-pre-deti"
published: true
---

> This is part 1 of a new series, **Performance for kids**. In this series
> we show what makes a website fast ... and why this repo **measures and
> enforces it with tests**, not recommendations. Today we start from the
> very basics: **why the first image on the page matters so much** and how
> to hand it to the browser so it never downloads more than it needs.

## What is LCP

Imagine opening a page. What do you see first? A title, a few words…
and some image. That **largest piece of content rendered in the first
viewport** is called LCP ... *largest contentful paint*. The browser
measures it, and Google treats it as one of the main signals of how
fast a page "feels".

The point: until the LCP paints, the page looks empty to you. It's not
about loading all 20 images ... it's about **that one** you see right
away. That's why performance audits (Lighthouse, PageSpeed Insights)
pick exactly one element and say: "this is the LCP, and it's your
problem."

## loading="lazy" is a betrayal for LCP

When an image has `loading="lazy"`, the browser only downloads it when
the user scrolls near it. That's great for below-the-fold images ... why
download the twentieth image if it will never be seen? But for the
**LCP image it's a disaster**: you're waiting for the most important
piece of content and the browser is deliberately postponing it.

That's exactly what happened in this repo, on the blog. The article
cards all had `loading="lazy"` ... and Lighthouse showed precisely this:

```
LCP request discovery ... Optimize LCP by making the LCP image
discoverable from the HTML immediately, and avoiding lazy-loading
```

The fix was simple: the LCP candidate got `loading="eager"` (download
now) plus `fetchpriority="high"` (and download it first):

```html
<img loading="eager" fetchpriority="high" decoding="async"
     width="720" height="360">
```

## One image? Or the whole first row?

Here comes the first wrinkle. On mobile the blog has one column ... the
LCP candidate is unambiguously the first card. But on desktop the blog
has **three columns**, and the first three cards sit in one row, all
the same size (329 × 165 px). And LCP is won by whichever paints
last ... which you can't know in advance.

So we gave `eager` + `fetchpriority="high"` to the **entire first row**,
not just the first card. In code it looks like this (`BlogArticlesService`):

```php
// The first grid row (3 cards on desktop) holds the LCP candidate ...
// all its images are the same size, so the LCP winner is whichever
// paints last. Eager + fetchpriority=high for the whole row.
$cards .= $this->renderCard($article, $index < self::FIRST_ROW_CARDS);
```

The second and third images on mobile are below the fold ... they load a
little earlier than they strictly need to. That's the ~25 KiB price we
pay so the heuristic stays simple and always hits the LCP on desktop.

## srcset ... don't send 720 when 330 will do

The second problem was sneakier. A card on desktop is ~330 px wide, but
the image downloaded at the full 720 × 360. Lighthouse called it out
exactly:

```
This image file is larger than it needs to be (720x360) for its
displayed dimensions (634x317). Use responsive images to reduce the
image download size.
```

Downloading 26 KiB when 12 KiB is enough is like carrying a whole
toolbox when you need one screwdriver. The solution is called `srcset`
... you offer the browser **several sizes** and it picks the one that
fits its screen (and pixel density):

```html
<img src=".../w_720,h_360.webp"
     srcset=".../w_360,h_180.webp 360w,
             .../w_634,h_317.webp 634w,
             .../w_720,h_360.webp 720w"
     sizes="(min-width: 1080px) 330px, (min-width: 640px) 520px, 100vw">
```

- `360w` / `634w` / `720w` ... three physical image widths that
  Cloudinary generates on demand (crop `c_fill,w_360,h_180`).
- `sizes` ... I tell the browser how wide the image will be in each
  layout, so it knows which variant to pick.

## Why srcset alone isn't enough

`srcset` without `sizes` is half-baked ... the browser would guess the
image takes 100% of the screen width, and on mobile it would reach for
the largest variant. `sizes` gives it context: "on large screens the
card will be 330 px." And `width`/`height` (720 × 360) reserve the
space in the layout up front, so the page doesn't jump when the image
loads.

For vector SVG images srcset is pointless ... a vector is
resolution-independent, it scales arbitrarily. That's why the code
deliberately skips them:

```php
// Local SVG covers are vector and resolution-independent ... no variants.
if (!str_contains($image, 'res.cloudinary.com') || !str_contains($image, 'w_720,h_360')) {
    return '';
}
```

## What we measured

We validated the optimization with Lighthouse, not by feel. Desktop
before and after:

| Metric | Before | After |
|---|---|---|
| Performance | 97 | **100** |
| LCP | 1.2 s | **0.8 s** |
| First card | 25.6 KiB | **12.3 KiB** |
| image-delivery | FAIL | **PASS** |

The second card downloads in the `w_360` crop instead of `w_720` ... a
file half the size, and on desktop you can't even tell, because 360 px
is exactly enough for a 330-px card. (On a mobile retina display the
browser correctly reached for `w_720` ... there the need is ~950 px.)

## A rule without a test isn't followed

As throughout this series: **what can be measured and tested is not
left to good intentions.** In `tests/Integration/BlogIndexTest.php`
there is a test that would fail if someone added srcset only to the
first card or forgot the `fetchpriority`:

```php
// The eager images are exactly the first row (3 cards).
self::assertSame(3, substr_count($html, 'loading="eager"'));
self::assertStringContainsString('loading="eager" fetchpriority="high" decoding="async"', $html);
```

And `BlogCoverTest` guards that SVG cards carry no srcset. The same
pattern covers search results and author profiles ... they all render
through the same shared `renderCards()`, so a fix in one place applies
everywhere.

## What to take away

1. **LCP is one element** ... the largest piece of content in the first
   viewport.
2. **The LCP image must never be lazy** ... `loading="eager"` +
   `fetchpriority="high"`, and if you're unsure which card in a row
   wins on desktop, make the whole first row eager.
3. **Don't send 720 when 330 will do** ... `srcset` + `sizes` let the
   browser pick the right size and save half the bytes.
4. **Measure, don't guess** ... Lighthouse will tell you exactly which
   image and how much it downloads needlessly.
5. **Sign the contract with a test** ... otherwise in six months someone
   will "improve" their way back to a lazy LCP.

In the next part we'll look at how LCP works on an article page and
what to do when the LCP element isn't an image but text.

Srcset remains the simplest responsive image tool, just not one you should skip. When you know the contract, the fix is one attribute.

→ [Article cover LCP](/blog/article-cover-lcp): the first image that matters
→ [Reading Lighthouse report](/blog/reading-lighthouse-report): detective work on audits
→ [Text LCP](/blog/text-lcp): when the LCP element isn't an image

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
