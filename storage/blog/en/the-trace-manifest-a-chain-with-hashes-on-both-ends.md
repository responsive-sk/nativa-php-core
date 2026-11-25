---
title: "Rust & Lekalo ... part 15: the trace manifest — a chain that ties the spec to the tests"
series: "Rust & Lekalo"
series-part: "15"
date: "2026-09-26"
version: "1.0.0"
published: true
tags: ["rust", "lekalo", "trace-manifest", "ci", "openspec"]
excerpt: "Documentation claims tests guard something. The trace manifest shows it as a chain: requirement → symbol → file → scenario → test → gate. Every node must exist, every relation carries a hash — and a guard test checks the chain at both ends."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-trace-manifest-pre-deti"
---

> [Part 10.2](/en/blog/upgrading-lekalo) went through the upgrade's three
> gates, [10.3](/en/blog/lekalo-bridge-in-the-model) bridged two modules.
> [Part 14](/en/blog/the-last-pin) closed the covers story.
> Today 15 — the trace manifest: a document where documentation about
> tests finally carries evidence, not just words.

You are reading `docs/lekalo-model.md`. The trace table says the blog
chain ties FTS, feeds, covers, comments and the publish switch to
`BlogSearchTest`, `BlogFeedTest`, `BulkActionsTest`.

Sounds good. One thing is missing there ... **evidence**. Nothing tells
you the chain that sentence describes still stands. Who checks that
today — you, at every release, from memory?

The **trace manifest** solves exactly this. It adds no runtime code.
It is a single JSON document writing the chain "requirement → symbol →
file → scenario → test → gate" as data. And every relation in the chain
carries a hash that can be recomputed.

## The chain and its links

A manifest is nodes and relations. A node is one thing from the chain.
There are six kinds and the list is **closed** — nothing in between
exists:

| Node | What it is | Example from this repo |
|---|---|---|
| `requirement` | what should be | `publish-draft-switch-on-admin-articles-and-pages-tables` |
| `symbol` | a declaration in the model | `blog.publish_article` |
| `artifact` | the file that carries it | `articlepublishtoggleaction-php` |
| `scenario` | a flow declared in the model | `blog.admin_editing_flow` |
| `native_test` | a PHPUnit class | `nativa.BulkActionsTest` |
| `gate` | a CI gate | `nativa.phpunit` |

Relations tie the nodes into a chain. Their kinds are closed too, and
each has an allowed direction:

| Relation | Direction | Meaning |
|---|---|---|
| `implements` | symbol → requirement | the symbol fulfills the requirement |
| `binds` | symbol → artifact | the symbol lives in this file |
| `covers` | scenario → symbol or requirement | the flow passes through the symbol |
| `verifies` | test → scenario or symbol | the test proves the flow |
| `evidences` | gate → test | the gate runs the test |

You cannot declare that a test verifies a requirement directly. The path
goes through a scenario or a symbol — so the chain forces you to say
**what** covers the requirement, not just that it is covered.

## A hash on both ends

This is the core of the idea. Every relation carries a `relationId` —
a SHA-256 over the tuple `[wire, kind, from, to, occurrence]`:

```json
{
  "relationId": "sha256:1bff85d05efabbcfa3a65f3a1c34e3ddb7389dc78fe38a5fa8efa17b5dc243ce",
  "relationKind": "verifies",
  "fromNode": "test:bulk-actions-test",
  "toNode": "symbol:blog.publish_bulk",
  "occurrence": "verifies.blog.publish-bulk"
}
```

That relation is real — it lives in `docs/lekalo/trace/blog.trace.json`,
in the chain we extended for the publish switch.

The rule is the one you know from covers: content determines identity.
Rename a node — you must recompute the hash. Redirect a relation — the
hash changes. You cannot rename the chain "a bit" in one place and leave
the other standing.

This is not cryptography. This is **referential integrity** like in a
database: the foreign key must exist on the other side, and the hash
must hold at both ends of the relation.

## Two layers of validity

A manifest passes two checks, and each hunts a different kind of error.

The first is the **wire gate** — `lekalo trace validate` in CI. It checks
grammar: closed node kinds, allowed relation directions, canonical
hashes, provenance. A check of shape.

The second is the **guard test** — `TraceManifestTest`. The same pattern
you know from covers: "gate cannot read, test does". It reads the
manifests directly in PHPUnit and checks the chain still points at
reality:

| Check | What happens when you break it |
|---|---|
| symbols resolve | a symbol the model doesn't know → fail |
| paths exist | an artifact pointing at a missing file → fail |
| tests are real | a `native_test` with no PHPUnit class → fail |
| anchors are verbatim | a requirement not matching the spec → fail |
| relation hash | a recomputed hash mismatch → fail |
| completeness stays `partial` | `full` would be a lie → fail |

The wire gate says "the chain is grammatically fine". The guard says
"the chain is still true". You need both — either alone verifies half
the story.

## The publish chain from the repo

Take the chain built for the publish switch in the admin tables — not
an abstract example, a concrete commit from this week.

Requirement `publish-draft-switch-on-admin-articles-and-pages-tables`
→ symbols `blog.publish_article` and `blog.publish_bulk` with both
effects → actions `ArticlePublishToggleAction` and `BulkPublishAction`
→ scenario `blog.admin_editing_flow` → `nativa.BulkActionsTest`
→ gate `nativa.phpunit`.

Seventeen new relations, each with its own hash. The manifest grew to
47 nodes, 66 relations and 7 gaps. Nothing happened in PHP — the
runtime stays hand-written. The map changed, not the terrain, exactly
like in [part 11](/en/blog/map-vs-terrain).

Now imagine someone renames `blog.publish_bulk` next week. The guard
test fails in CI that very second — the model no longer knows the
symbol the manifest declares. Without the manifest you would find the
breakage while reading documentation nobody trusts on its word.

## When a manifest is not proof

The honest section — when this does **not** hold.

First honesty: a manifest is not coverage. It says "this is the chain";
it does not say "this chain is complete". That is why it carries
**gaps** — typed holes admitting what it does not know. Ours carries
`missing-gate` (native gate receipts are blocked upstream by #89),
`missing-native-test` and `unsupported-provider`. Completeness `full`
would be a lie here — the test forbids it outright.

Second honesty: the manifest does not update itself. It is a
hand-written document the validator only checks. Rename a symbol and
the manifest does not decay — the validator and the guard fail, and you
pull the chain straight by hand. That is not a flaw, it is a contract.

Third honesty: the hash protects a relation's identity, not its truth.
You can declare `verifies` from a test that never exercises the flow —
and both gates let it pass, because grammatically it is fine. The guard
checks nodes exist; whether the relation is **true** is on the one who
writes it. The machine still guards cohesion, not judgment.

## What comes next

The model gained the `audit` and `pages` modules — their chains await
their own manifests in the next part. The block vocabulary meanwhile
awaits crystallization in docs, exactly as part 10.2 promised.

The rule to take home: when you write that a test guards something,
write it as a chain with hashes. A sentence can be quietly rewritten.
A chain with hashes reports every change — something moved, go look.

## Sources

- [Lekalo](https://github.com/ichinya/lekalo) — the project repo (`lekalo trace validate|export|query`)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — manifests, conventions and digests of both layers
- `tests/Integration/TraceManifestTest.php` — the guard checking the chain at both ends
- [Part 10.3 — the bridge between modules](/en/blog/lekalo-bridge-in-the-model) — a scenario as a bridge, radius 2→3
- [Part 14 — the last pin](/en/blog/the-last-pin) — where "content determines identity" grows from
