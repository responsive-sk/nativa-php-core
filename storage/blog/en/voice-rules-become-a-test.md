---
title: "Voice rules for kids ... when style becomes a test"
date: "2026-09-17"
version: "1.0.0"
tags: ["writing", "clean-code", "testing", "architecture"]
excerpt: "A style guide nobody enforces is a wish. This blog's voice just became an architecture test ... Sources must be a real section, emoji are banned, locale pairs must link both ways. And yes, this article has to survive the test too."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Writing"
translation: "pravidla-hlasu-pre-deti"
published: true
---

You're a developer who writes. Someone says "style guide" and your brain
quietly files it under "documents nobody opens".

We know that drawer. That's why the guide became something a drawer can't
hold: an **architecture test**.

## A guide nobody enforces is a wish

This blog has a voice guide — [`docs/blog-voice.md`](/docs/blog-voice).
Three rules: the friend who doesn't flatter. Truth with a declared type.
And a cast to real code from this repository.

For about a week it lived as plain Markdown.

And for exactly that long it was a wish. A guide that CI doesn't enforce
works exactly like a "refactor this later" comment ... good intentions
waiting for a reader who never comes.

This project has a rule for that: **what can be checked as a contract
doesn't stay on good will.** `bin/verify-build.php` contracts the build.
`BuildCssAuditTest` computes WCAG contrasts instead of trusting them.
Why should the voice of the articles be the exception?

## What the test measures ... and what it doesn't

The new test (`tests/Architecture/BlogVoiceTest.php`) guards three
contracts.

**First: Sources is a real section.** The `## Sources` heading must stand
on its own line and the section must contain at least one link. That's
not pedantry ...

Just this week grep told us all 191 articles have Sources. It was a half
truth: the old test looked for a substring, not a section. Two articles
had the heading swallowed into a wall of text — `... the performance of
our PHP code. ## Sources - [DeepSeek]...` in a single line. The substring
test saw `## Sources` and stayed silent.

**Second: zero emoji.** The green check, the red cross and the gear don't
belong in comparison tables — words carry status better and survive the
terminal, the feed and the search index.

Watch one detail: **the arrow → is allowed.** It belongs to the voice's
handwriting (`→ [Part 2]` before links). What's banned is pictographs and
symbols (U+1F000+, U+2600–27BF), not U+2192. A contract that bans its own
handwriting isn't a contract — it's a mistake.

**Third: locale pairs point both ways.** `translation:` must resolve to an
existing file in the other language, and the sibling must link back. Only
then are per-language slugs (`preco-rust-pre-deti` ↔ `why-rust`) honest.

And one exception the test respects: **drafts.** `published: false` means
"work in progress" — the test leaves them alone until the decision is
made.

## Who tests the test that writes about itself?

And here comes the moment that deserves its own paragraph.

This article just passed the very test it describes. Otherwise you
wouldn't be reading it.

I'm not mad at it. The test knows no guide — it knows lines. It didn't
know I was writing them. And still it's not a circle: the guide is prose,
the test is a contract. A text about a contract that breaks the contract
would be a worse joke than any emoji.

It fits in two lines:

```
a rule without a test is a wish
a test without a rule is a censor
```

The test doesn't judge taste — not jokes, not metaphors, not whether a
sentence could be said more simply. It judges only what can be checked
line by line. Taste stays with the reader. The contract stays with CI.

## How it went ... numbers, not impressions

An empty claim doesn't belong here ... so it gets a number.

Before the test, there was an audit: 191 articles, 3 without a real
Sources section, 11 files with emoji, 0 broken locale pairs. All fixes,
no exceptions: two articles had to be rewritten (text wall → real
Markdown), token-harbor renamed `## References` to `## Sources`, and the
tables in eight files traded checks and crosses for `yes`/`no`.

All of it rests on one instinct this repository repeats to exhaustion:
**a contract, verified by the build, that screams when broken.** Like
`bin/verify-build.php`, like `CoverCodeIsFromArticleTest`, like
`BlogSearchTest`. The only difference: this time it guards words, not code.

## When the rule doesn't hold (the mandatory honesty)

Be honest: a mechanical test can't tell whether an article is good.

It can surface a text wall, flag emoji, catch a broken locale pair. It
can't tell whether the rhythm works, whether the honesty section is
actually honest, or whether a sentence says something deep ... or just
sounds like it. Line rhythm stays a hand craft — the test at best checks
that the source isn't a wall.

The test also can't tell when an exception is justified. That's why
drafts are exempt and the arrow → is allowed — a contract scoped to
defend against the wrong, not against everything.

The rest — the friend who doesn't flatter — no test checks. That one
stays with you.

## Practically

1. **Write the article as before** ... the guide lives in
   `docs/blog-voice.md`, the test requires no new syntax.
2. **Check three things before you push** ... `## Sources` on its own
   line with at least one link, zero emoji (the arrow → is fine), and a
   `translation:` pointing at an existing mirror that points back.
3. **If the test fails, read the message** ... it always says exactly
   what the article is missing. I didn't write it for myself.

The test was written by the same voice it enforces. And this article
about it speaks that same voice. If you've read this far, the contract
holds from both ends.

## Sources

- [PHPUnit — Data Providers](https://docs.phpunit.de/en/12/data-provider.html) ... the pattern this test stands on
- [Markdown — Daring Fireball](https://daringfireball.net/projects/markdown/) ... the format the whole corpus lives in
- [Nativa Minimal Documentation](/docs/getting-started) ... this kit's official getting started guide
- [docs/blog-voice.md](/docs/blog-voice) ... the guide whose mechanical half is now a contract
