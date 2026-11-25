---
title: "Articles as versions ... even MINORs are LTS"
date: "2026-09-17"
version: "1.0.0"
tags: ["writing", "clean-code", "publishing", "architecture"]
excerpt: "A part number says when an article was born. A version says what changed inside it ... and an even MINOR is a release you may link without fear. The 8 and 9 collision was resolved here by branching to 9.1 — and the machine now guards the shape, not the judgment."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Writing"
translation: "clanky-ako-verzie-pre-deti"
published: true
---

You're a developer. You write an article, ship it, and a year later someone
links it from their slides.

That's when the question arrives — the one a part number cannot answer:
which of it still holds? Has anything changed since? Will the link betray
you in another year?

Software answered this question more than a decade ago: **versions**.

This repository arrived at the same place — an article is a product. It has
readers, revisions, releases. And now it has numbers too.

## The number says when ... the version says what

Part 9 was born on September 11th. That's everything the number tells you.

A version is a different animal. `1.0.1` says a typo was fixed. `1.1.0`,
that content was added. `2.0.0`, that the article outgrew its structure.

The scheme is borrowed whole — three positions, three kinds of change:

| Change | Bump | Example from this repo |
|---|---|---|
| typo, typography, broken link | **PATCH** (1.0.**1**) | "part 8" → "part 9.1" in a link |
| content: a section, table, proof | **MINOR** (1.**1**.0) | an added comparison table |
| structure: a new point, merged sections | **MAJOR** (**2**.0.0) | rewritten into a new structure |
| work in progress | **0.x.y** | 5 drafts carry `0.1.0` today |

The first published version is `1.0.0`. Not `0.9`, not "something" — an
article a stranger has read is a release.

## Even MINORs are LTS

And here comes the rule that sets this scheme apart from everyday semver.

**An even MINOR is an LTS release.** `1.2.0`, `1.4.0` — versions that now
change only by patches. The content is frozen.

We didn't pick even numbers for beauty. Parity is binary — visible at a
glance in the number, on the cover, in the URL.

An odd MINOR is a workshop. That's where drilling, moving sections and
rewriting tables still happens. So `1.3.0` tells you: read it, but don't
sign a contract with this article.

`1.4.0` says the opposite: you can print it.

## Collisions branch, they don't overwrite

First we admit the mistake that started all this versioning.

The Rust and Lekalo series has 15 parts, but the numbers 8 and 9 exist
twice. Two newer articles about themes shipped on September 13th and 14th
— chronologically after part 9 — and received already-taken numbers.

The linear-numbering world would respond by renumbering: half the series
moves down a number, ~30 links shift, and every external "part 9" link
betrays its reader.

This place did something else — a branch:

```text
// storage/blog/en/lekalo-themes-enter-the-model.md — front matter
series: "Rust & Lekalo"
series-part: 9.1

// storage/blog/en/lekalo-themes-get-routes.md — front matter
series: "Rust & Lekalo"
series-part: 9.2
```

The themes duology follows part 9, so it got the nine with a minor — the
same idea you know from version branches.

And the cover? `CoverGenerator` (`src/Modules/Blog/Application/Services/CoverGenerator.php`)
reads `series-part` as a string — the ghost number and the `PART 9.1` badge
rendered without a single line changed in the code.

A test guards it. `BlogVoiceTest::testSeriesPartPairsWithSeriesAndIsUnique`
says exactly that on collision: *branch it (9.1), do not reuse*.

## What the machine guards ... and what you do

The boundary is clear and worth writing down.

The machine guards the shape: `version:` exists, it's semver, published
articles carry `1.0.0` and up, drafts carry `0.x`. Plus the uniqueness of
`series-part` within a series and locale. `testArticleVersionFollowsSemverScheme`
and `testSeriesPartPairsWithSeriesAndIsUnique` — that's the whole
mechanical half.

The judgment stays yours. What you bump, whether an even MINOR deserves
its parity, whether `1.4.0` is truly frozen — the machine has no eyes
for that.

That part is not a nice compromise. It's the same pattern as the voice
test: a contract enforces shape, taste stays with the reader, discipline
stays with the author.

## When versions don't hold (the mandatory honesty)

Be honest: a version doesn't make an article good.

`1.4.0` can be dry as paper and `0.1.0` can be alive. A version describes
change, not quality — for that, you'll have to read.

The second honesty is harsher. Without release history, a bump is just a
claim. The test verifies the number is there and has the right shape — it
doesn't verify the content really grew by a MINOR and not a MAJOR. The
guide says it plainly: what you bump is editorial discipline.

And the third: just like with the voice, a rule you only follow because
CI guards it isn't a rule. Versioning without the will to remember
changes is decoration in front matter.

## Practically

1. **Give the article a version** ... `version:` in front matter; the
   first published one is `1.0.0`, work in progress carries `0.x.y`.
2. **Bump by the table** ... typo = PATCH, new content = MINOR, new
   structure = MAJOR. And content others should link to ships as an
   even MINOR.
3. **Link by the LTS number** ... "read part 9.1 at version 1.2.0" is a
   link that won't betray you in a year. Link odd minors only when you
   know what you're reading.

The version then answers the question "what changed" ... not "when it
was born".

## Sources

- [Semantic Versioning 2.0.0](https://semver.org/) ... the scheme the articles borrowed whole
- [Keep a Changelog](https://keepachangelog.com/) ... the release discipline that makes a bump more than a claim
- [Nativa Minimal Documentation](/docs/getting-started) ... this kit's official getting started guide
- [docs/blog-voice.md](/docs/blog-voice) ... the guide where the article-versions section lives
