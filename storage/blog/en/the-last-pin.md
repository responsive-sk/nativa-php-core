---
title: "Rust and Lekalo for kids ... part 14: the last pin"
series: "Rust & Lekalo"
series-part: "14"
date: "2026-09-20"
version: "1.0.0"
published: true
tags: ["rust", "lekalo", "determinism", "contracts", "blog"]
excerpt: "One hundred twenty-five pool pins left and the test remembered their ban. One pin stayed — a legal upload outside the pool. The story of the last exception, which retired itself, and the test that will never allow another."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "posledny-pin-pre-deti"
---

> [Part 12](/en/blog/covers-as-contracts) made the cover a contract and
> [part 13](/en/blog/fallback-ladder) built the failure ladder. Today
> part 14 — about the pin that bypassed the ladder legally, and the
> migration that took its excuse away.

You have an old article open and an `image:` line in its front matter.
The image renders, the card looks like every other card, nothing is
wrong.

And that is exactly the catch. Broken things announce themselves ...
legal exceptions arrive with a smile and you keep them gladly.

## One hundred twenty-five left, one stayed

The pool era is closed in this corpus. Four frozen URLs, a 63/30/22/10
drift, the old resolver's output hardcoded — that was the previous two
parts. The pins left gradually, through content passes, and the test
remembered their ban.

The same sweep today returns one file:

```bash
grep -l '^image:' storage/blog/*/*.md
storage/blog/sk/hromadne-mazanie-dry-pasca.md
```

One line in the whole corpus. And it is not a pool URL:

```yaml
// storage/blog/sk/hromadne-mazanie-dry-pasca.md — front matter
image: "https://res.cloudinary.com/epithemic/image/upload/nativa-media/ne3ulmsddkg1dwb6cj8s"
```

The pool lives in the `blog/` folder and carries transform parameters ...
`c_fill,w_720,h_360,f_webp,q_auto`. This pin points into `nativa-media/`
and carries nothing. It is a real upload — a screenshot of an admin
table, chosen by hand.

The old rule allowed exactly that: "the only cloudinary pin allowed is
a real upload outside the pool folder". This pin was precisely that.
Legal.

## What the pin bypasses

The `resolveCover()` ladder has three rungs. Two behave like a contract,
one like an exception:

| Ladder rung | What it returns | `?v=` cache-buster |
|---|---|---|
| 1. `image:` pin | the exact URL from front matter | no |
| 2. local SVG | `/storage/blog-images/{slug}.svg` + hash | yes |
| 3. cloudinary pool | 1 of 4 frozen URLs | no |

The hash in rung two is the first eight hex characters of the file's
md5. Change the SVG — the URL changes — the browser never serves
year-old bytes from the immutable cache. That is the whole mechanism.

The pin bypasses that mechanism. Not because it is bad — because
regeneration changes files, not front matter lines. A pin's URL is as
immutable as the article's text. And the text is not what gets
generated.

## The SVG already exists

Here is the thing that makes this migration almost fun: the cover for
that article has been sitting on disk for a while. Born during the mass
regeneration — the same as every other article's.

I ran the generator before deleting the pin and compared bytes:

```bash
php bin/cms generate-images --slug hromadne-mazanie-dry-pasca
git status --short storage/blog-images/hromadne-mazanie-dry-pasca.svg
# empty output — a byte-identical regeneration
```

Determinism is not a theory. Same article, same SVG, zero differences.

So the pin was not hiding a missing cover. It was hiding a finished one
... and the `?v=` hash that would keep it fresh in browsers.

## The migration — one line

The change itself is almost disrespectful:

```diff
--- storage/blog/sk/hromadne-mazanie-dry-pasca.md
+++ storage/blog/sk/hromadne-mazanie-dry-pasca.md
 category: "Clean Code"
-image: "https://res.cloudinary.com/epithemic/image/upload/nativa-media/ne3ulmsddkg1dwb6cj8s"
 translation: "htmx-bulk-actions-dry-trap"
```

One removed line. From that moment `resolveCover()` returns rung two ...
`/storage/blog-images/hromadne-mazanie-dry-pasca.svg?v=…` — and the
article carries the same kind of identity as its one hundred and
seventeen siblings.

The English twin never had a pin. Its cover went through the ladder
from day one. The corpus's only holdout was the Slovak file.

## The rule hardens

Deleting the line is half the work. The other half is making sure it
never comes back — that a year from now nobody says "a legal upload,
surely that is fine":

```php
// tests/Unit/CoverPinPolicyTest.php — before part 14
self::assertFalse(
    in_array($pin, self::poolUrls(), true),
    'a frozen resolver output is not a choice',
);

// after part 14 — uploads outside the pool too
self::assertFalse(
    str_contains($pin, '/nativa-media/'),
    'covers are self-hosted; cloudinary is for content, not identity',
);
```

Before: the test knew four frozen URLs and let everything else pass.
After: every pin into `nativa-media/` fails. Pool URLs keep failing too
... a different folder, the same mistake in a different coat.

The rule now says what it always meant but could not say: a cover is
identity. Identity is not picked from a gallery — it is generated from
the thing itself.

## When to pin (and when not)

Honestly: this pin was not a bug. The old rule explicitly allowed it
and the bulk-delete screenshot had editorial value ... it showed exactly
the UI the article talks about. We changed a rule, we did not fix a
bug. That is a difference and it deserves to be named.

The second honesty: this is not a war on cloudinary. The article keeps
its screenshot in the body — content images are a different contract:
the media module uploads them, the CSP knows their host, and nobody
generates them. The ladder is about the cover, and the cover is an
identity contract, not a gallery of illustrations.

The third: a generated SVG is more anonymous than a screenshot. We
traded editorial uniqueness for one identity system across the corpus.
That is not a clean win ... it is a trade. The corpus made it by having
one hundred and seventeen articles already on the deterministic side;
a single holdout is not diversity, it is noise.

## The last pin

The pin did not own its identity — it rented it, for a year, without
cache-busting. Today it got it back. The same one every other article
has: generated from content, versioned by hash, readable as proof.

A rule with zero exceptions fits in one sentence. A rule with one
exception needs this article.

---

→ [Part 13 — the fallback ladder](/en/blog/fallback-ladder) — what
happens when every rung fails differently
→ [MDN: Cache-Control](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control)
— why a regeneration needs a new URL

## Sources

- `tests/Unit/CoverPinPolicyTest.php` — the pin contract, including the
  new invariant
- `src/Modules/Blog/Application/Services/BlogArticlesService.php` — the
  `resolveCover()` ladder: pin → local SVG with `?v=` → pool
- `docs/blog-covers.md` — cover policy and cache-busting
- [Part 12 — covers as contracts](/en/blog/covers-as-contracts)
- [MDN: Cache-Control](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Cache-Control)
