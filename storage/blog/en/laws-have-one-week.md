---
title: "Laws have one week ... contract archaeology"
date: "2026-09-17"
version: "1.0.0"
tags: ["git", "archaeology", "testing", "architecture", "workflow"]
excerpt: "A pickaxe (`git log -S`) over tests/ and bin/ dug up the roots of every contract in this repo — and found a pattern: practice ran since August, the laws arrived in a single week of September. A contract without practice is air, practice without a contract is silence. Plus two false trails that proved even grep needs a type."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "zakony-maju-jeden-tyzden"
published: true
---

I dug up the history of my own repository. Not all of it — only the
roots of the laws that guard CI today.

The tool: a one-off script with a pickaxe (`git log -S`) that finds
commits changing an occurrence count. The question was simple: *when
did every contract that forbids me something today enter history?*

The answer had nothing to do with what I expected.

## Two days worth a look

The roots of the code contracts in `tests/` and `bin/`:

| contract | root | story |
|---|---|---|
| `trait FindsIndexPage` | 09-07 | index pagination, Chrome 70 fallbacks |
| `STATE_DIR` + `Never mtimes` | 09-16 | revision report — agent-state is born |
| `const GATES` | 09-16 | gate runner with failure records |
| `CoverDeterminismTest` | 09-16 | CoverGenerator v2 |
| `CoverPinPolicyTest` | 09-17 | part 13, the fallback ladder |
| `BlogVoiceTest` | 09-17 | the voice contract in CI |

Look at those dates. Three legislators in three days.

09-16 is the day agent-state was born — three contracts in one day.
09-17 is the day of the voice contract — three more.

And the oldest contract of the whole layer, the pagination trait? Nine
days older.

## Practice ran first. The laws came later

The blog corpus has existed since August. Articles were written,
translated, published ... a whole month without a single one of these
laws.

And nothing fell apart.

Because a contract without practice is air. I would have had nothing to
forbid emoji with, had the tables not contained the first emoji. Nothing
to guard `series-part` uniqueness with, had the 8/9 collision not
happened. The fragile page-one assumption would have stayed a healthy
hunch, had the corpus grown slowly.

Practice fed them first. Then it wrote them down.

This is not an indictment of rules ... it's their genealogy. Every good
test in this repo has a harmful event in its birth record. A contract
that can't say what it prevents protects nothing.

## But watch out — practice without a law stays silent

The second half of the pattern is sharper.

All of it ran without laws. Pagination worked, covers generated, gates
ran. Nobody failed. Only nobody could say *why* it worked.

Without `FindsIndexPage`, every new index test would repeat the same
mistake today — "the card is on page 1", written with determination and
naivety.

Without gate records we'd repeat every failure blindly.

Without the pickaxe anchor, history would speak Noise. Practice without
a law works — until the world around it changes. Then it goes silent
exactly on the day you need it to scream.

A law is the memory of a harmful event ... written so it cannot be
forgotten.

## Two false trails

The dig itself cost two corrections — and both are lessons about type.

The first pattern was `class FindsIndexPage`. The pickaxe stayed
silent. A trait is declared `trait`, not `class` — I was searching the
wrong word in the right place.

The second pattern was `never mtimes`. Silence again. The docblock says
`Never` with a capital — the start of a sentence.

Two null results, two causes ... and both of the same kind: a pattern
without an anchor. The same trap `^version:` set for the front-matter
archaeology — a loose string reads noise, an anchored one reads truth.
Even grep needs a declared type.

## When it doesn't hold (the mandatory honesty)

Be honest: one week is not a pattern. It's a cluster.

Three contracts in one day doesn't mean laws should arrive in batches.
It means their writing had a month of practice behind it — and that I
wrote them down when my hands were free. The next contract arrives when
the next damage occurs. Maybe in a month. Maybe tomorrow.

The second honesty: this archaeology is a tool for a human, not for CI.
It runs once, from cache, in 0.3 seconds and 12 git calls. Nobody will
run it on every push — that would be mining without ore.

## Practically

1. **Open your tests' birth records** ... `git log -S
   'class SomeTest' -- tests/` — if a contract can't say what it
   protects against, it may protect nothing.
2. **Anchor the pattern before you dig** ... `^`, whole word, exact
   string. A null result of a bad pickaxe is information, not
   emptiness.
3. **Wait for practice to bring damage** ... the best test reacts to a
   real failure. Anticipatory laws are predictions — and predictions
   tend to be air.

Laws then don't grow from the sky ... they grow from git history.

## Sources

- [Git — pickaxe, -S](https://git-scm.com/docs/git-log) ... searching occurrence-count changes through history
- [Martin Fowler — bliki](https://martinfowler.com/) ... essays on the gap between practice and contract
- [Nativa Minimal Documentation](/docs/getting-started) ... this kit's official getting started guide
- [docs/blog-voice.md](/docs/blog-voice) ... the voice guide whose mechanical half the test guards
