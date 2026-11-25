---
title: "Rust and Lekalo ... part 6: Lekalo deep dive, a Rust tool analyzing a PHP project, live"
series: "Rust & Lekalo"
series-part: "6"
date: "2026-09-06"
version: "1.0.0"
tags: ["rust", "lekalo", "semantic-model", "impact", "architecture"]
excerpt: "The finale of the Rust series. Everything so far was preparation: the language, the tooling, the quality, the model layer. Today we run Lekalo end-to-end on the Users model in this very repository, validation, dependency graphs, impact analysis and context capsules, with live output, honest limitations included."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-pre-deti"
published: true
---

Five parts of preparation. The language ([part 1](/en/blog/why-rust)), the
mental model ([part 2](/en/blog/rust-ownership)), the tooling
([part 3](/en/blog/rust-cli-tooling)), the quality machinery
([part 4](/en/blog/rust-testing-quality)) and the model layer
([part 5](/en/blog/semantic-application-models)). Today we stop describing
and start running: Lekalo, live, on the Users model in this repository.

The model is the one we built as a working example,`lekalo/` at the repo
root: the `nativa` project, the `users` module, **53 definitions** covering
registration, authentication, sessions, RBAC and password reset. Every number
below comes from a fresh CLI run on this project. You can reproduce all of
it.

## 1. Validation first

```bash
$ lekalo validate --project .
validated default profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
```

Twenty-three semantic rules, references resolve to the right kinds,
identities name declared fields, type graphs stay acyclic, IDs are valid,
and the model is clean. This is the contract check from part 4 doing its
job: the model is *known* to be well-formed before anything consumes it.

## 2. The lock

`lekalo lock --check` verifies the committed lockfile pins the exact product,
contract and capability identities:

```
lock checked sha256:a30ce8… resolver 1.0.0 (adapters 0, generators 0, profiles 0, capabilities 0)
```

Reproducibility, from part 3, applied to the model layer: the same model,
same tool revision, same bytes.

## 3. The dependency graph

The model becomes a graph. `lekalo graph export --json` reports the whole
picture:

```
55 nodes, 106 edges
```

`lekalo graph show users.user` walks one symbol and shows what it depends on
and, more interestingly, what depends on *it*:

```
entity users.user
  dependent references effect:users.effect_user_created -> entity:users.user (canonical)
  dependent references entity:users.session -> entity:users.user (canonical)
  dependent returns operation:users.find_user_by_email -> entity:users.user (canonical)
  ...
```

And `lekalo graph callers users.register` shows who reaches the registration
command:

```
operation users.register
  caller exposes endpoint:users.endpoint_register -> operation:users.register (canonical)
  caller authorizes policy:users.password_min_length -> operation:users.register (canonical)
  caller authorizes policy:users.unique_email -> operation:users.register (canonical)
  caller references scenario:users.register_login_flow -> operation:users.register (canonical)
```

One command, three kinds of callers: the HTTP endpoint, the policies that
gate it, the scenario that covers it. That's the answer to "what touches
this?" that we promised in part 1.

## 4. Impact analysis

Now the headline feature. What happens if the `User` entity changes?

```bash
$ lekalo impact users.user --project .
impact symbol (entity:users.user)
  direct 7 transitive 12 mandatory-public 3
  risks 6
  gates 9
```

Seven symbols directly depend on `User`, twelve more transitively. Three are
mandatory public surfaces. Six risks and nine gates to review. Before
touching a line of code, you have a bounded change-radius, the exact answer
to "how big is this change going to be?" that this series promised in part 1.

The honest footnote: Lekalo distinguishes **declared** effects (what the
model says) from **detected** ones (what evidence shows), and the impact run
emits `evidence-unknown` warnings because no PHP source adapter exists yet.
The analysis is as good as the model, which is exactly the trade we
discussed in [part 5](/en/blog/semantic-application-models). Keep the model
honest and the impact report is honest.

## 5. Inspect: one symbol, everything about it

`lekalo inspect users.login` projects a single symbol with its contract,
policies, effects and dependents:

```
symbol: users.login
kind: command
policies: state=available
  - users.active_account_required deny
effects: state=available
  - create operation:users.login -> users.session (canonical)
  - emit-event operation:users.login -> users.session_started (canonical)
dependents: state=available
  - exposes users.endpoint_login (canonical)
  - authorizes users.active_account_required (canonical)
```

The login command's shape, what it accepts, which policy gates it, what it
creates, who calls it, in one deterministic projection.

## 6. Context for an AI agent

And the payoff for the tool's real audience:

```bash
$ lekalo context users.login --budget 1500 --project .
- budget: limit 1500, estimated 360, minimum required 360, fits true
- complete: true
```

A bounded context capsule for `users.login`: typed semantic facts, no
source bytes, no secrets, no absolute paths, fits comfortably in a 1500-token
budget. An agent gets the shape of the symbol in ~360 tokens, guaranteed
complete, from a validated contract instead of a crawl through files.

## How to model your own module

The recipe we used, which works for any PHP module in this kit:

1. **Entities first**: the things with identity (`User`, `Role`,
   `Session`), their fields and identity keys.
2. **Commands and queries**: the actions (`register`, `login`) and reads
   (`find_user_by_email`), each with inputs and effects.
3. **Policies**: the rules that gate commands (password rules, the
   admin-only RBAC policy).
4. **Endpoints and scenarios**: bind commands to routes, cover the flows
   end-to-end.
5. **Validate and lock**: `lekalo validate`, then `lekalo lock`, and keep
   both green like any other gate.

One naming note: symbols are **semantic IDs** (`users.user`), not PHP class
names. The tool models your application's vocabulary, not its FQCNs, which
is precisely what makes it language-neutral.

## Closing the series

Six parts, one arc: Rust isn't a threat to PHP, it's a complement. PHP
remains the right tool for the web application itself, this kit is proof.
Rust earns its place in the *tooling* around the application, and Lekalo is
the demonstration: a Rust-built, quality-obsessed, deterministically
outputting analyzer whose entire job is to understand a PHP project better.

We went from "why would I learn Rust?" through ownership, tooling and
quality, to a model layer that makes an application machine-readable, and
finally to a live tool answering "what breaks if I change `User`?" on this
very repository. That's the whole pitch, proven on our own code.

To re-walk the series: [part 1, why Rust](/en/blog/why-rust),
[part 2, ownership](/en/blog/rust-ownership), [part 3, rust tooling](/en/blog/rust-cli-tooling),
[part 4, rust quality](/en/blog/rust-testing-quality),
[part 5, semantic models](/en/blog/semantic-application-models).

## Sources

- [Lekalo](https://github.com/ichinya/lekalo): project repository (product 0.1.26)
- [Lekalo, CLI contract](https://github.com/ichinya/lekalo/blob/main/docs/cli.md): commands, exit codes, JSON wire
- [Lekalo, Impact](https://github.com/ichinya/lekalo/blob/main/docs/impact.md): impact projections and risk/gate vocabularies
- [Lekalo, Graph](https://github.com/ichinya/lekalo/blob/main/docs/graph.md): dependency graph guarantees
- [Lekalo, Context](https://github.com/ichinya/lekalo/blob/main/docs/context.md): bounded context capsules
