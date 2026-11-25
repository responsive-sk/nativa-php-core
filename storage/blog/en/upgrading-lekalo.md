---
title: "Rust and Lekalo for kids ... part 10.2: an upgrade through three gates"
series: "Rust & Lekalo"
series-part: "10.2"
date: "2026-09-19"
version: "1.0.0"
tags: ["rust", "lekalo", "upgrade", "ci", "semantic-model"]
excerpt: "Upgrading the tool from v0.1.31 to v0.3.2 without changing a single definition in the model. Three gates between the old world and the new one, a registry that always knows why it says no, and a digest you cannot guess — only verify."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-upgrade-pre-deti"
published: true
---

In [part 10.1](/blog/lekalo-design-enters-the-model) the model gained its
fifth module and stood at 142 definitions. Today not one definition
arrives. Today the ground moves: the tool that compiles and checks the
model goes from version 0.1.31 to 0.3.2.

It sounds like a routine `cargo install`. It was not. The path led
through three gates, one registry that always knows why it says no, and
one digest you cannot guess — only verify.

## Why change the version at all

Upstream shipped M3: an adapter kernel, a TS symbol scanner and native
gates for pnpm workspaces. Our themes live in TypeScript and CSS
bundles, so we will use that sooner or later.

The rule from part 10.1 still stands: contract first, privacy second,
things third. An upgrade is a change to the conditions under which a
contract holds. Until you know what the new version changes on the wire,
do not touch the pin in CI.

## The registry that says no

The first command after the install failed. Classic.

```sh
// right after installing v0.3.2
lekalo validate --strict
// unsupported-version error [LEK-VER-018]
// versioning.unsupported-version: The contract version is outside
// the accepted registry.
```

The new version carries an internal registry of contracts. One command
prints it:

```sh
lekalo compatibility
// compatibility: model current 0.2.16 (0.2.16..0.2.16),
// ir current 0.2.16, protocol current 0.3.2
```

Our lock said `1.0.0`. The registry accepts `0.2.16`. No warning, no
fallback — a version outside the registry is an error that stops the
whole run. That is the mark of a good tool: silently declaring an old
format compatible would be a promise nobody wrote down.

How do you find the target shape? Bisection, the analogy from 10.1:
`lekalo init` in a temp directory generates a green project. Its
`schema_version` is the answer. `"0.2.16"` — and the world makes sense
again.

## Three gates

The path from the old pin to the new one went through three separate
gates. Each speaks a different language and each wants a different kind
of work.

| Gate | What was wrong | What it took |
|---|---|---|
| `schema_version` | 33 YAML files declared `1.0.0` | one `sed`, zero thinking |
| `authorization.yaml` | attachment speech changed: identity, `profile_ref` and the inner `model_ref` | three header lines |
| `lekalo.lock` | wire discriminator `lekalo/lock/v1.0.0` outside the registry | delete, then `lekalo lock` fresh |

The first gate is mechanics. The second is interesting: `authorization.yaml`
is an attachment with its own closed schema, and the new policy says the
attachment version follows the project version. `lekalo/authorization/v1.0.0`
became `lekalo/authorization/v0.2.16` — and the inner `model_ref` pin now
points at the model version, not at the document version.

The third gate is quiet. The lock is not regenerated incrementally — the
old wire discriminator blocked even writing. I deleted the file and let
the tool write it from the world it sees. That is what the lock is for.

## A digest you cannot guess

The strict profile remains. And it keeps score.

```yaml
// lekalo/authorization.yaml — the pin in the header
model_ref:
  schema_version: "0.2.16"
  ir_digest: "sha256:…"
```

Strict compares this pin against the digest of the canonical IR bytes of
the current model. Mismatch — `LEK-AUTH-010`, `state: stale-model`. Out
loud it sounds simple: compute a hash, paste it. The question is a hash
of what.

The first hypothesis failed. The `0bf1d827…` digest from `contracts.model`
in the lock is a schema constant — the planner fixture carries exactly the
same value as our project. The second hypothesis was a better lesson in
why tests exist: I pasted a git SHA into `observed_revision`, 40 hex
characters instead of 64. The result was a diagnostic registry invariant
error — even though the registry lives inside the binary. Format changes
the outcome, not just the value.

The truth could be read in the source: `to_canonical_json()` of the
compiled project. And it could be verified empirically. The planner
fixture pins `e909626a…` — if a procedure reproduces it on the fixture,
I have the algorithm and can run it on myself.

```sh
// the recipe — verified on the planner fixture and on our model
lekalo load --ir --json | jq -c '.ir' | tr -d '\n' | sha256sum
```

The key detail is `tr -d '\n'`: `jq` appends a newline, the canonical
bytes do not have one. A hash over bytes with a newline is a hash of a
different world — and strict can say that more precisely than I can.

Our pin: `540d1fff…`. Strict green after the paste.

## What the upgrade changed

It sounds like a `sed` and one hash did all the work. Almost true. The
changes that remain:

| Thing | Before | After |
|---|---|---|
| CI pin | `--tag v0.1.31` | `--tag v0.3.2` |
| Digest recipe | python one-liner over the envelope prefix | `jq -c '.ir' \| tr -d '\n' \| sha256sum` |
| `observed_revision` | git SHA | SHA-256 over the git SHA — per the wire format |
| Definitions in the model | 142 | 142 |

The last row is the important one. The model does not describe the old
or the new tool version — it describes the application. The ground
moved, the data did not. That is why an upgrade can look like mechanics
and still take a whole day: all three gates are about wire formats, not
about meaning.

## When to leave the upgrade alone

Honesty: if you do not need the M3 things, the old pin still runs and
checks your model correctly. The upgrade cost an afternoon and half a
day of documentation archaeology. That is a price, not a bug.

The time to upgrade is when upstream pulls your version out of the
registry — then the old pin means a world nobody compiles anymore. Or
when you need a feature. Not out of boredom. Boredom is not a reason to
touch wire formats.

## What comes next

The promise from 10.1 stands: blocks — grid, section, form, nav, footer
— first as a block matrix in documentation and an architecture test,
into the model only once the vocabulary crystallizes.

A small note on temperature: the block matrix survived today's upgrade
without a single change. The guard test read the document with the
`1.0.0` schema and with the `0.2.16` schema, and in both worlds it
failed exactly once — when I wilfully changed a name. That is the
crystallization signal: a vocabulary that resists for a while is a
contract, not a proposal.

## Sources

- [Lekalo](https://github.com/ichinya/lekalo) — the project repository (product 0.3.2, contract registry via `lekalo compatibility`)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — model documentation, including the verified recipe for refreshing `model_ref.ir_digest`
- [docs/design-blocks.md](https://github.com/evan70/nativa-minimal/blob/master/docs/design-blocks.md) — the nine-kind block matrix that survived the upgrade untouched
- [authorization.md upstream](https://github.com/ichinya/lekalo/blob/v0.3.2/docs/authorization.md) — the closed schema, strict review and mapping evidence
- [Part 10.1 — design enters the model](/blog/lekalo-design-enters-the-model) — the fifth module and the first grammar lesson
