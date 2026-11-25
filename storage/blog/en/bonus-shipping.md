---
title: "Members-only bonus ... shipping: an article as a numbered product"
date: "2026-09-17"
version: "1.0.0"
tags: ["publishing", "versioning", "series", "workflow", "architecture"]
excerpt: "Eighth members-only bonus. Software does not say 'version: whatever' ... and neither do articles. What a publish workflow looks like when drafts carry 0.x, first release is 1.0.0, collisions branch instead of overwriting, and even MINOR is a freeze links can trust, on this repo's real front matter and tests."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-vydavanie-pre-deti"
published: true
---

> This is the eighth **members-only bonus**. Previous parts: [the
> members-only lock](/blog/bonus-members-only), [the immutable audit
> log](/blog/bonus-audit-log), [FTS5 full-text
> search](/blog/bonus-fts-search), [comments with
> moderation](/blog/bonus-comments), [media as foreign
> files](/blog/bonus-media), [debug as a
> retro-scope](/blog/bonus-debug) and [languages as a
> contract](/blog/bonus-locales). Today, the workflow the bonuses
> themselves are born through ... an article as a numbered product.

You are a developer. You ship software, versions, tags, changelogs.
And next to that, you write articles that "just go out".

The distance between those two worlds is smaller than it looks.

Software without a version cannot be linked,`latest` is a promise,
not an address. An article without a version is the same thing:
today it holds, tomorrow it changed, and the link in your slides
says nothing.

This repository therefore ships articles like software. Here is the
workflow, from the inside.

## Pipeline: draft → release → freeze

Every article in the corpus passes through three states, and each
state has its number:

| state | number | example from this repo |
|---|---|---|
| draft | `0.x.y` | 5 drafts carry `0.1.0` today |
| first release | `1.0.0` | most of the 191 articles |
| freeze (LTS) | even MINOR | `1.2.0`, `1.4.0` |

A draft is a pre-release. `published: false` in the front matter
tells the world "not for eyes", and the tests leave it alone until
it decides.

First release is the moment a stranger reads the article. From then
on it is a contract, it may change, but publicly and labelled.

And a freeze is a deliberate act: a content sweep (rhythm, Sources,
front matter) counts as a MINOR, you bump to an even number, and from
that point you ship patches only. Part 1 of the Rust & Lekalo series
did exactly this,`1.2.0` → a section added → `1.4.0` → frozen
again.

## A collision branches, it does not overwrite

Numbers in a series are not decoration, they are addresses. And
addresses are never occupied twice.

It really happened: the themes article came out after part 9, but
nine was taken. A linear world would renumber half the series, and
about 30 external "part 9" links would betray their readers.

This repo opened a branch:

```text
// storage/blog/sk/lekalo-temy-v-modele-pre-deti.md
series: "Rust & Lekalo"
series-part: 9.1
```

`9.1` is not "the tenth part". It is a sibling of nine, it continues
it without taking the next slot. The ghost number on the cover and
the `PART 9.1` badge were rendered by the existing generator with no
code change, because `series-part` is read as a string.

`BlogVoiceTest::testSeriesPartPairsWithSeriesAndIsUnique` guards it,
on collision it says exactly: *branch it (9.1), do not reuse*.

## The machine guards shape, the editor owns meaning

The boundary between what CI verifies and what is discipline is
clear:

| the test checks | the human checks |
|---|---|
| `version:` exists and is semver | whether the bump matches the real change |
| published ≥ `1.0.0`, drafts `0.x` | whether an even MINOR deserves a freeze |
| `series-part` paired with `series:` | whether the branch is the right place |
| `series-part` unique within (series, language) | whether the content is ready |

The test does not know whether an article is good. It only knows the
numbers have shape, and that a collision never fails silently.
Just like the [voice contract](/blog/voice-rules-become-a-test): shape is
a contract, meaning is craft.

## The cover as a changelog

Every release deserves visibility, that is the stamp's job on the
cover.

`CoverGenerator` renders `version:` into the bottom-right corner:
an LTS part (even non-zero MINOR) gets the accent and the label
`v1.4.0 · LTS`, development versions and drafts stay muted. The
version is render-only, a bump changes the stamp and nothing else,
which the determinism test holds.

At first glance a reader sees what they are linking: a frozen part,
or a workshop. The same principle as an expiry date on a package,
information on the wrapper, not in an appendix.

## When the workflow does not apply (mandatory honesty)

Versioning does not make an article good.

`1.4.0` can be dry as paper and `0.1.0` can sing. The number
describes change, not quality, for quality, you read.

Second honesty: without release history, a bump is just a claim. The
test verifies shape, not the truth about content, "I earned a
MINOR" can also mean "I mistyped a number". The guide says it
plainly: what you bump is editorial discipline.

And third: legacy series deliberately carry no
`series:`/`version:` migration. Introducing new IDs into old
articles would repaint ~50 covers for zero gain, the workflow
applies to new parts; the past is not excavated.

## In practice

1. **Write as a draft with `0.x`** ... `published: false` +
   `0.1.0`: nothing is lost, nothing betrayed.
2. **Release as `1.0.0`** ... the first stranger's read is a
   release, not a continuation of the draft.
3. **Link even MINORs** ... "part 9.1 at version 1.2.0" is an
   address that will not betray you in a year. An odd minor is a
   workshop.

An article then does not fly into the world ... it ships.

## Sources

- [Semantic Versioning 2.0.0](https://semver.org/) ... the schema the articles adopted wholesale
- [Keep a Changelog](https://keepachangelog.com/) ... release discipline
- [docs/blog-voice.md](/docs/blog-voice) ... the guide, home of the Series and Article versions sections
- [Nativa Minimal Documentation](/docs/getting-started) ... this kit's getting-started guide
