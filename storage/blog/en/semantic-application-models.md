---
title: "Rust and Lekalo ... part 5: semantic application models, describing an app without describing code"
series: "Rust & Lekalo"
series-part: "5"
date: "2026-09-06"
version: "1.0.0"
tags: ["rust", "lekalo", "semantic-model", "architecture", "ai"]
excerpt: "Part 5 of the Rust series. Before the Lekalo finale: what a semantic application model actually is. A model is not source code and never parses source code, it's a language-neutral description of entities, commands, policies and endpoints, living in fixed YAML/JSON homes. We use this repo's own lekalo/ directory as the example."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "semanticky-model-pre-deti"
published: true
---

Four parts in, everything we've discussed has been about the *language*:
what Rust is, how ownership works, how its tooling and quality machinery
behave. Now we step one level up, to the layer that makes the final part
possible: **semantic application models**.

Here's the core claim, and it's worth sitting with for a moment: a model of
an application is *not* the application's source code. And a tool that works
with models does **not** parse source code. Lekalo's effect engine is
documented as "never detection from source and never inference from names".
Everything it knows, it knows because somebody **declared** it, in YAML or
JSON documents that describe the application on a semantic level.

## What a model describes

If source code answers *how*, a semantic model answers *what*. Lekalo's model
vocabulary has fourteen definition kinds, and they map neatly onto how a PHP
module like this kit's `Users` actually behaves:

| Kind | What it declares | Example from this repo |
|---|---|---|
| `entity` | a thing with identity and fields | `User`, `Role`, `Permission`, `Session` |
| `scalar` / `enum` / `value-object` | field types | `email`, `password`, `account_state` |
| `command` | an action with inputs and effects | `register`, `login`, `reset_password` |
| `query` | a read with declared reads | `find_user_by_email`, `current_user` |
| `policy` | a rule on a command (allow/deny) | `password_min_length`, `admin_only_rbac` |
| `event` | something that happened | `user_registered`, `session_started` |
| `effect` | create/update/delete on an entity | `effect_user_created` |
| `endpoint` | HTTP surface invoking a command/query | `POST /login` → `login` |
| `scenario` | a user-visible flow covering symbols | register → login, password reset |

Notice what's absent: no classes, no packages, no framework concepts, no
implementation details. The model contract is explicit that it "contains no
target-language class, package, framework or runtime concepts", the same
vocabulary describes a TypeScript, PHP or Go application. That's what
*language-neutral* means: you model the *application*, and the implementation
language is an afterthought.

## The fixed homes

Because the model is documents, not code, its location is a contract.
Lekalo projects follow a fixed layout:

```
lekalo/
├── project.yaml                 # exactly one project definition
├── modules/<module>/
│   ├── module.yaml              # exactly one module definition
│   ├── entities.yaml            # scalar, enum, value-object, entity
│   ├── commands.yaml            # command, effect
│   ├── queries.yaml             # query
│   ├── policies.yaml            # policy
│   ├── events.yaml              # event
│   ├── scenarios.yaml           # scenario
│   └── bindings.yaml            # endpoint, target-binding
└── targets/<target>.yaml        # target configuration
```

Every definition is a closed object with a `schema_version`: and the loader
accepts exactly two literals: `0.1.0` and `1.0.0`. Anything else is
`unsupported-version`, exit 5. A definition in the wrong file (an entity in
`commands.yaml`, say) is rejected. The model is strict because it's a
contract: if the documents are ambiguous, every downstream consumer,
including an AI agent, pays the price.

You can open this repo's own `lekalo/` directory and see all of this in the
flesh: the `nativa` project, the `users` module, 53 definitions describing
auth, RBAC and password reset, JSON content inside `.yaml` files (the
loader accepts a strict JSON frontend), validated by `lekalo validate` with
23 rules and zero errors. It's a working example of the format, committed
next to the PHP code it describes.

## Why model an application at all?

If the model doesn't parse code, keeping it in sync is manual work. So why
bother? Three answers, all aimed at the tool's real audience, AI-assisted
development:

**Determinism.** An AI agent can't safely read your whole codebase; it can
safely consume a validated model with byte-identical canonical output. The
agent's facts come from a contract, not from guessing at source files.

**Context.** `lekalo context` projects the minimal-but-sufficient context
capsule for one symbol under a token budget, protected semantic facts as
typed records, never collapsed into prose, with no source bytes, secrets or
absolute paths leaking into the capsule. For an agent with a token window,
that's precisely the shape of useful context.

**Traceability.** The model connects requirements → symbols → tests → gates
in a neutral trace manifest, so "does this change have evidence?" is a query,
not an archaeology project.

The honest cost is the one you've spotted: the model must be maintained. The
counterargument is that this kit already maintains a *canonical spec* in
`openspec/specs/` and a roadmap, the project's conventions treat
"describe the intent separately from the code" as normal practice. Lekalo
takes the same instinct and makes it machine-readable.

## The finale is one part away

You now have every ingredient: the language (parts 1-2), the tooling and
quality (parts 3-4), and the model layer (this part). Part 6 runs the whole
thing end-to-end on the Users model in this repository, validation,
dependency graphs, impact analysis and context capsules, live:

[next: Lekalo deep dive, the finale](/en/blog/lekalo-semantic-model)

## Sources

- [Lekalo, Model contract](https://github.com/ichinya/lekalo/blob/main/docs/model.md): definition kinds and semantics
- [Lekalo, Loader](https://github.com/ichinya/lekalo/blob/main/docs/loader.md): YAML/JSON frontends, canonical model
- [Lekalo, Canonical project structure](https://github.com/ichinya/lekalo/blob/main/docs/canonical-structure.md): model homes and root discovery
- [This repository, lekalo/ model](/blog/lekalo-semantic-model): the working Users model the article references
