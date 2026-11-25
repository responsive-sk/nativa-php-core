---
title: "Rust and Lekalo for kids ... part 17: three versions and no change"
series: "Rust & Lekalo"
series-part: "17"
date: "2026-10-05"
version: "1.0.0"
tags: ["rust", "lekalo", "upgrade", "ci", "semantic-model", "lock"]
excerpt: "The tool grew by three minor versions and the model moved not one line. How to find out which versions move and which stand still before an upgrade, and why a verdict that looks like your fault often is not."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-tri-verzie-pre-deti"
published: true
---

In [part 10.2](/blog/upgrading-lekalo) the model stood on 142 definitions
and the tool went from 0.1.31 to 0.3.2. Since then 104 more definitions
arrived, and every one of them is ours. They came in with the modules we
let into the model. Today the tool went from 0.3.2 to 0.6.4 and not one
of the 246 moved.

Not by accident. Because of one digest that stayed the same in the lock.

## Fog: four numbers, three versions, one confusion

The project now carries four different version numbers, and none of
them is the other:

| Number | What it is |
|---|---|
| `v0.6.4` | the git tag you install from |
| `lekalo 0.6.3` | what `--version` prints |
| `0.3.2` | the target protocol version and the lock wire schema |
| `0.2.16` | the model and IR version |

A month ago this was one number. Now it is four, and a reader of the
docs has to guess which belongs in CI, which belongs in the lock, and
which belongs nowhere.

Worst of it: two of them are the same number with two different
meanings. `0.3.2` is today both the protocol version and the lock wire
schema the new tool just wrote. One string, two worlds.

## Sun: the one command that answers before anything breaks

The cheapest thing I could do today was to not start. Read the contract
registry that ships inside the binary and see which versions actually
move:

```sh
lekalo compatibility
// compatibility: model current 0.2.16 (0.2.16..0.2.16),
// ir current 0.2.16, protocol current 0.3.2
```

All three read `current` at the version the project already had. Across
0.4, 0.5 and 0.6 the model, the IR and the protocol did not move by a
single increment. So the plan fit in one sentence: change the pin,
regenerate the lock, touch nothing else.

That is how it went. The difference between today's upgrade and part
10.2 is that back then the contracts and the wire formats moved; today
nothing moved except one thing:

```sh
git diff lekalo.lock
- "schema_version":"lekalo/lock/v0.2.16"
+ "schema_version":"lekalo/lock/v0.3.2"
- "core":{"version":"0.3.2"}
+ "core":{"version":"0.6.3"}
- "request_digest":"sha256:63db07fa…"
+ "request_digest":"sha256:8f194d07…"
```

Three fields. And one field is **missing** from that diff, which is the
interesting one: the model digest. Before the upgrade and after it, the
same bytes, `sha256:0bf1d827…`, because the canonical form of our 246
definitions did not change.

That is a stronger proof than a table. A table tells you what moved. A
digest tells you what did **not** move, and it says so even about the
thing I forgot to read in the diff.

No YAML, no trace manifest, not one model definition. When
`bin/trace/build.py --check` reports seven clean manifests I know the
generated things still stand, because the generator did not ask a
different world for them.

## Night: three versions live at three speeds

The tool versions fast, because its features grow with every line of
code it has to understand in someone else's project. The model
versions slowly, because it describes **our application**, and an
application does not change just because the tool reading it changed.

The third is the lock wire schema. It versions when the shape of the
record the tool writes to disk changes. And that is the most
interesting of the three, because it is **the only one that cares about
your computer**. A model is comparable anywhere; a contract can be sent
to a colleague. A lock is a file in version control that exactly one
program on earth reads.

When that program rejects a lock it wrote itself six weeks ago, that is
not a bug in your model. That is a bug in the calendar.

The word `future` in the error says it out loud:

```sh
lekalo lock
// unsupported-version error [LEK-LOCK-020]
// lock.unsupported-schema-version: The lock wire carries a
// future schema discriminator.
```

The tool does not know that the `v0.2.16` discriminator is last month.
All it knows is that the discriminator is not in its registry. To the
tool, that really is the future.

## The object we set aside

v0.6.4 itself deserves its moment. It just does not belong to us.

It shipped Node and TypeScript bindings for Hono and Drizzle, a local
run history, and a brownfield pilot on a real repository. That is good
work, honest work, and it will matter to whoever runs Hono. We run
PHP, we run SQLite, and we fear exactly one thing: someone shipping
`debug_backtrace()` to production.

So we set v0.6.4 aside. With respect. And the next time our model lands
in a project that runs Hono, the same command tells us again whether
anything moved.

## Whose fault is it

The popular question after a new tool arrives: does `doctor` complain?
And the second one: is that my fault?

The answer has one handle and takes forty seconds. Before blaming the
upgrade, check whether the verdict was already there. The previous
version's source sits on disk:

```sh
grep -rn "targets-without-adapters" \
  storage/cache/lekalo/lekalo-src/crates/lekalo-core/
// crates/lekalo-core/src/doctor/checks.rs:583
```

That line exists in 0.3.2 too. The upgrade did not add the verdict.
The verdict was there the whole time and we never read it, because
`doctor` is informational and not a gate. The two blocked checks say we
have no adapter installed. We have none on purpose: this project
adopts the model and the trace manifests, not adapters. Nothing is
wrong.

The distance between `blocked` and `broken` is exactly that sentence.

## What comes next

Two roadmaps. Both small. Both honest.

Upstream v0.6.4 solves what we did not: it ground against real
projects to learn what can be automated and what cannot, and wrote the
answers down as findings F1 through F7. One of them matters to us
anyway. Hono extraction degrades when a project has no
`node_modules`, and the tool admits it with honest markers instead of
quiet skips. We solved the same problem by writing a test that throws.

Our own plans are humbler and the list is long. The model knows eight
modules: users, blog, comments, audit, pages, themes, design and forms.
Outside it stand media, settings, locales, the outbox with UnitOfWork,
security and the core. Three of them have earned a place in the model,
because each has already broken a rule we believed was permanent.

Then reader questions. When a guest asks a question the article cannot
answer, that corner fills itself. The model needs one more definition:
the subject of a question is a pair, not a slug. The same lesson as
locales.

## Theme

The tool that changed and the document that stayed are two different
kinds of knowledge. The first is written fast and read hard. The second
is written slowly and read easily. The second is the one people stand
on.

## Sources

- [Lekalo](https://github.com/ichinya/lekalo): the product repository (release v0.6.4 from 1 October 2026, `lekalo compatibility` is the contract registry)
- [Release v0.6.4](https://github.com/ichinya/lekalo/releases/tag/v0.6.4): what landed: Node/TypeScript bindings, local run history, a brownfield pilot, findings F1 to F7
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): our upgrade record, including the trap where `lekalo lock` refuses the lock it is meant to replace
- [lekalo/modules](https://github.com/evan70/nativa-minimal/tree/master/lekalo/modules): the eight modules the model knows, and the list of the ones it does not
- [Part 10.2, an upgrade through three gates](/blog/upgrading-lekalo): the previous upgrade, where something really did change
- [Part 16, comments as the last chain](/blog/comments-the-last-chain): where this row is headed