---
title: "Rust and Lekalo for kids ... part 11: the map says 393, the terrain 962"
series: "Rust & Lekalo"
series-part: "11"
date: "2026-09-16"
version: "1.0.0"
tags: ["rust", "lekalo", "agents", "documentation", "architecture"]
excerpt: "A morning health check of the repository ran green, and still found a bug. Not in the code: in the map. AGENTS.md promised 393 tests, reality had 962. On maps that age slower than the terrain, and on what an agent may trust when it reads."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "mapa-a-teren-pre-deti"
published: true
---

In [part 10](/blog/agent-state-protocol) an idea from a Rust
fingerprint became a tool. Today nothing new was built, today an
existing tool spoke up. The morning health check of the repository:
`make pre-commit`, six gates, all green. And yet one number in the
report did not sit right.

## What happened

The work was done, the tree clean, so the check should have been a
formality. It was, until the agent summarized the result and stopped
at one line: `AGENTS.md`, in the quality-gates section, promised 393
tests; PHPUnit fired 962. The claim about assertions: 1220 versus
3145.

The number 393 was once true, in August, when the minimal theme
closed its milestone. Since then came media, lekalo, comments, TOC,
audit, the agent-state tooling; every milestone added tests and nobody
redrew the map. The bug was not in the code and not in the gates. The
number simply aged.

```
$ make pre-commit
...
OK (962 tests, 3145 assertions)
```

The worst part: **no gate failed**. Lint, analysis, rector, cs-fixer,
tests, lekalo, everything green. Because no gate measures the
distance between the map and the terrain. That is a problem that does
not report itself. It stays silent and waits to be read.

## The fix: one line

The fix cost one replacement:

```diff
- PHPUnit (unit + integration + css + architecture; 393 tests, 1220 assertions, incl. …
+ PHPUnit (unit + integration + css + architecture; 962 tests, 3145 assertions, incl. …
```

We did not remove the numbers, a number in documentation is often the
most useful thing the map says. The answer was not to drop the claim
but to make it true again. And to write down a rule: **a number in
documentation is a claim with an expiration date.** Which claims are
still alive is decided by gates, not by the map.

## Why this is not cosmetics

The terrain moves with every milestone. The map is drawn by hand and
nothing measures the distance between them. Two honest ways out:
either generate every documented number from tools, or keep
documentation free of numbers entirely.

We rejected generation. It is a mini-Bazel, and the fifth agreement
says: one hundred percent or nothing. A numberless document is also
not the answer, numbers tend to be the most useful claims a map
makes. "Zero-dependency runtime" is a number too; the map does not
test it, the gates do. We chose the middle: numbers in the map are
allowed, and they are allowed to age. But everyone reading the map
must know they are looking at a photograph with a date on it, not a
live feed.

And here part 10 returns. The whole agent-state protocol stands on
the agent reading state instead of guessing. The trick only works
when the state itself is true. A stale number in the map quietly
poisons every decision the agent derives from it.

Rust solves this outright:
[documentation tests](https://doc.rust-lang.org/rustdoc/write-documentation/documentation-tests.html)
are compiled and run inside `cargo test`. Documentation cannot lie
about code behavior, because the compiler and the test runner both
touch it.

The closest things this kit has are tests that read
"documentation" surfaces as data: the CSS audit computed from the
actually-built tokens, the smoke test that boots the dist, and the
rule that every article must carry sources, guarded by
`BlogArticleSourcesTest`.

---

The number 962 stays true only until tomorrow, and that is fine. A
photograph remains a photograph even after the terrain changes shape.
Contracts are a different department: the gates handle those.

---

## A human note

The map is not the terrain. When they disagree, you do not fix the
terrain, you fix the map. And when the next person, or the next
agent, reads it, they trust it exactly the way we trusted it this
morning: that someone walked it yesterday.

Live proof: before this article shipped, the number above had already
aged. Adding the piece to the test surface brought two new tests and
962 became 964. The map got its fix on publication day. The terrain
did not stand still for an hour.

---

→ [Part 10, an agent needs four breaths](/blog/agent-state-protocol),
the revision report, gate resume and determinism
→ [Rust documentation tests](https://doc.rust-lang.org/rustdoc/write-documentation/documentation-tests.html),
documentation the compiler also runs

## Sources

- `AGENTS.md`: the "Build & Quality Gates" line (corrected to 962
  tests / 3145 assertions)
- `composer test`: morning run: 962 tests, 3145 assertions
  (PHPUnit 12)
- [Part 10, an agent needs four breaths](/blog/agent-state-protocol)
- [Rust documentation tests](https://doc.rust-lang.org/rustdoc/write-documentation/documentation-tests.html)
