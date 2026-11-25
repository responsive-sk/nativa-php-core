---
title: "Rust and Lekalo for kids ... part 12: covers as contracts"
series: "Rust & Lekalo"
series-part: "12"
date: "2026-09-16"
version: "1.0.0"
tags: ["rust", "lekalo", "determinism", "blog", "architecture"]
excerpt: "A morning \"regenerate all covers\" commit wiped the identity of an entire series, because the only truth about it lived in the hands of whoever ran the CLI. The new cover generator moves identity where the thing lives — into the article — and turns the cover into evidence."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "obalky-ako-kontrakt-pre-deti"
published: true
---

In [part 11](/en/blog/map-vs-terrain) the map was stale and the terrain
was true. The same week continues: a series of eleven parts stood in
front of us, wanting to look like a series — and the tool deciding that
had no idea it existed.

## What happened

The blog covers come from a CLI. It has switches: template, color. In
the morning a regeneration of everything ran — and the switches fell
back to their defaults. The series' rust template (hexagons, orange)
was wiped to blue code. Nobody wanted that; the tool had no way of
knowing those twenty articles belonged together. The only truth about
the series' identity lived in the hands of whoever was typing the
command.

That is not a one-run bug. It is a missing contract.

## The four rules of the new generator

The generator was rewritten. Not for prettier hexagons — for four rules
that make it a tool you can trust. The solution did not start with code,
but with a question: where should each truth live?

**One: same article, same bytes.** No timestamps, sorted listings,
positional rules instead of a "random line". Two runs must produce an
identical file. Verified by md5.

**Two: a series' identity is a property of the article.** Two front
matter lines and the tool knows what to do even without switches:

```diff
 title: "Rust and Lekalo for kids ... part 12"
+series: "Rust & Lekalo"
+series-part: 12
```

The series identity hashes to one template-color pair shared by every
member. The CLI switches remain only as an override — "regenerate all"
can no longer wipe anything, because the default is read from the
article, not from the operator's hands.

**Three: the cover is evidence.** The cover carries a terminal window
with a snippet from the article — chained as `cover-code:` → first
`diff` block → first code block. And a contract test that runs over
every published article, checking the lines on the cover are literally
in the article. Cover code cannot be decoration; it is a quote with a
known source.

**Four: regeneration changes the URL.** Covers ship with a year-long
`Cache-Control: immutable` — and we just experienced a browser serving
a year-old truth after regeneration. A local cover now carries a `?v=`
hash of its content: new bytes, new address.

## Why this is not cosmetics

Part 11 ended with a question: what may an agent trust when it reads a
map? The answer was: contracts — things something checks. The generator
is a small specimen of a large principle: **a property of a thing
belongs to the thing, not to the operator's hands.** Rust has the same
instinct elsewhere — nothing is carried in the compiler's "head"; types,
trait bounds and doc-tests live where the code lives, and the machine
checks them on every run. A contract nobody checks is decoration. A
contract something checks is a map that ages together with the terrain.

And the terrain checked in immediately. The number 964, which
[part 11](/en/blog/map-vs-terrain) had just written into the map that
morning, was stale by noon: the suite gained the generator's contract
tests and the map says 1353 tests. The pattern from part 11 repeated
live — a number in documentation is a claim with an expiry date, and
the only thing keeping it true is running the gates. Real-time proof:
before this text shipped, 1353 went stale too — the series articles
brought six more tests and the map says 1359.

---

## A human note

When things belong together, do not write it into a command. Write it
where the thing lives — so whoever comes after you reads it, and the
machine checks it for you. The CLI override remains a great tool — just
not for identities that must outlive a single run. An identity held only
in the memory of one run is not an identity. It is an accident that has
not yet repeated itself.

---

→ [Part 11 — the map says 393, the terrain 962](/en/blog/map-vs-terrain) —
on numbers with an expiry date
→ [Rust documentation tests](https://doc.rust-lang.org/rustdoc/write-documentation/documentation-tests.html)
— documentation checked by a machine

## Sources

- `src/Modules/Blog/Application/Services/CoverGenerator.php` — rules 1–4 in code
- `tests/Unit/CoverCodeIsFromArticleTest.php` + `tests/Unit/CoverDeterminismTest.php`
  — the contracts (snippet provenance, byte determinism, cache-buster)
- `docs/blog-covers.md` — generator documentation
- [Part 11 — the map says 393, the terrain 962](/en/blog/map-vs-terrain)
- [Rust documentation tests](https://doc.rust-lang.org/rustdoc/write-documentation/documentation-tests.html)
