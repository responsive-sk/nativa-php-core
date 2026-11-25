---
title: "Performance for kids ... part 3: read the Lighthouse report like a detective"
date: "2026-08-13"
version: "1.0.0"
tags: ["performance", "lighthouse", "lcp", "mobile", "desktop"]
excerpt: "Part 3: Lighthouse isn't an oracle ... it's a detective that sometimes misses context. We show which audits affect the score and which are just recommendations, why the mobile audit claimed a 63 KiB saving that didn't exist, and how to read the report so you don't fix things that aren't broken."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "performance-pre-deti-diel-3-lighthouse-detektiv"
published: true
---

> This is part 3 of the **Performance for kids** series. In [part 1](/en/blog/lcp-srcset-basics)
> we fixed the LCP on the index, in [part 2](/en/blog/article-cover-lcp)
> on the article page. Both times Lighthouse showed us the way. Today we
> show how to read it **critically** ... because Lighthouse isn't an
> oracle, it's a detective that sometimes misses context. And if you
> don't believe it blindly, you won't fix things that aren't broken.

## A score that deceives you

You open the Lighthouse report and the first thing you see is a big
number ... Performance 97, 100, 82… It looks like a grade on a test. But
it isn't. The score is a weighted average of several **scored audits**
(LCP, CLS, TBT, FCP, SI), and each one is measured in an artificial,
throttled environment.

That means two things:

1. **The score changes from run to run** ... even without a code change.
   Throttled network, machine performance, temperature… Lighthouse is a
   lab measurement, not user reality. A 1–2 point difference between two
   runs is noise, not regression.
2. **The score measures one thing: a lab average.** A real user on a
   fast phone with a good network will experience completely different
   numbers.

Hence rule number one: **compare the same preset with the same preset.**
Desktop and mobile runs have different emulated conditions (mobile has a
4× slower network and DPR 2.625), so "desktop 100, mobile 99" isn't a
contradiction ... it's a different world.

## Scored audit vs. insight

This is where most people make a mistake. Lighthouse 13 has two kinds of
audits:

- **Scored audits** ... LCP, CLS, TBT, FCP, Speed Index. They carry
  weight in the Performance score. When one fails, the score drops.
- **Insight audits** ("LCP request discovery", "Improve image delivery")
  ... diagnostics. **They don't affect the score.** They're the
  detective's recommendations: "something could be improved here".

That's exactly what we saw in [part 1](/en/blog/lcp-srcset-basics). The
"LCP request discovery" insight showed `loading="lazy"` on the first
card. We fixed it ... and although the insight itself doesn't change the
score, **fixing the real problem** (a lazy LCP image) also improved the
scored LCP. Insights often lead to things that affect the score ... but
not always. And that's the trick: you need to know how to tell them
apart.

## Case: a saving that didn't exist

In [part 1](/en/blog/lcp-srcset-basics) we hit a perfect example. After
fixing srcset on the cards we ran Lighthouse on mobile and
`image-delivery-insight` reported:

```
Est savings of 63 KiB
This image file is larger than it needs to be (720x360) for its
displayed dimensions (362x181). Use responsive images.
```

It sounded like a clear rebuke: "you're still downloading images that
are too big!" But when we looked at the details, we found the catch:

- Lighthouse mobile emulates **DPR 2.625** (retina).
- The card displays at 362 CSS px, but retina needs ~950 physical
  pixels.
- Of our srcset candidates (360/634/720w), the browser correctly picked
  **720w** ... the closest to covering the need.
- But Lighthouse calculated the saving as if the 360-px version were
  enough.

In other words: **the audit claimed we were downloading too much, but
the browser was downloading exactly what it should.** If we'd blindly
believed the audit and shrunk the images, retina mobile would have
gotten blurry covers. And if we'd added an even bigger variant (e.g.
1080w), mobile would start downloading **more**, not less ... LCP would
get worse just to please the audit's "saving".

## How a detective verifies a suspicion

When Lighthouse claims something, don't accept it as a verdict. Verify
it:

1. **What exactly does the audit say?** Open the details ... which image,
   what size, what "saving".
2. **What's the reality?** In DevTools (Network tab), look at which
   variant the browser **actually** downloaded. If it downloaded 360w on
   desktop, srcset works. If it downloaded 720w on mobile DPR 2.625,
   that also works ... the audit just doesn't know about DPR.
3. **Are you fixing a real problem?** A fix must make sense regardless
   of the audit. "The LCP image is lazy" is a real problem. "Download a
   smaller image even though retina needs a bigger one" is not.

That's exactly how we handled `image-delivery-insight` on mobile: we
verified the network requests, found that the browser picked the right
variant, and **left it alone**. The desktop run (where DPR is 1)
meanwhile showed srcset working precisely: the browser downloaded 360w
instead of 720w, and `image-delivery-insight` passed.

## The detective's tools

- **Network tab** ... which requests actually went out and at what size.
- **Lighthouse JSON output** (`--output=json`) ... the `network-requests`
  audit shows exact URLs and sizes; `lcp-breakdown-insight` shows the
  LCP element and phase timings (TTFB, load delay, load duration, render
  delay).
- **Same URL, different presets** ... a desktop vs mobile comparison
  reveals whether the problem is universal or viewport-specific.
- **Before/after measurement** ... without "before" numbers you have no
  proof you improved anything. (In [part 2](/en/blog/article-cover-lcp)
  we showed a Before/After table precisely for that reason.)

## When the audit is worth believing

The rule isn't "never trust" ... it's "**trust, but verify**". There are
audits you can trust almost always:

- **`lcp-discovery-insight`** ... the LCP resource should be eager +
  fetchpriority. This is an unambiguous rule, no exceptions (and in this
  repo tests guard it ... [part 1](/en/blog/lcp-srcset-basics),
  [part 2](/en/blog/article-cover-lcp)).
- **`image-size-responsive`** ... an image bigger than its display on
  **desktop** (DPR 1) is a real loss.
- **`total-byte-weight`**, **render-blocking resources** ... universal
  truths.

And there are audits to take with a grain of salt:

- **`image-delivery-insight` on mobile** ... the DPR calculation ignores
  pixel density (our 63 KiB "saving").
- **Any "Est savings"** ... it's an estimate from a simplified model, not
  an invoice.

## A rule without a test isn't followed

As throughout the series: what we learned, we signed with a test. In
`BlogIndexTest`, `BlogCoverTest`, `BlogSearchTest` and
`AuthorProfileTest` live contract tests that guard that the LCP resource
has eager + fetchpriority and that Cloudinary covers carry srcset.
Lighthouse can lie about savings ... tests don't.

## What to take away

1. **The score isn't a grade** ... it's a lab average that changes from
   run to run. Compare the same preset with the same preset.
2. **Insight ≠ score** ... "LCP request discovery" doesn't change the
   score, but often leads to a fix that does.
3. **"Est savings" is an estimate, not an invoice** ... verify in the
   Network tab what the browser actually downloaded.
4. **The mobile audit doesn't know DPR** ... 720w on a retina mobile isn't
   excess, it's a need.
5. **Trust, but verify** ... a fix must make sense even without the
   audit. And when it does, sign it with a test.

In the next part we'll show what LCP looks like on pages without
images ... and what happens when the LCP element is text.

Lighthouse remains the best starting point — just not the final word. When you know which audits are scored and which are hints, the fix writes itself.

→ [Article cover LCP](/blog/article-cover-lcp) — the first image that matters
→ [LCP srcset basics](/blog/lcp-srcset-basics) — responsive images done right
→ [Text LCP](/blog/text-lcp) — when the LCP element isn't an image

## Sources

- [PHP Manual](https://www.php.net/manual/) — official PHP documentation
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP standards
- [Nativa Minimal Documentation](/docs/getting-started) — official getting started guide
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse tool
