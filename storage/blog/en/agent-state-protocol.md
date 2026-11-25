---
title: "Rust and Lekalo for kids ... part 10: an agent needs four breaths"
series: "Rust & Lekalo"
series-part: "10"
date: "2026-09-16"
version: "1.0.0"
tags: ["rust", "lekalo", "agents", "hash", "architecture"]
excerpt: "An idea from the Rust fingerprint became a tool: a revision report from git, records of failed gates, and resuming from the stored tail. Fewer iterations not because the agent guesses better — because it reads state instead of guessing."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "agent-state-pre-deti"
published: true
---

In [part 9.2](/blog/lekalo-themes-get-routes) themes got their routes in
the model. Today nothing is added to the model — today something rarer
happens: **an idea from a note became a tool**. The original text
promised a revision hash would cut agent iterations from eleven to four.
The numbers were invented. The idea was not.

## What happened

An agent returning to a task spends its first iteration on a question:
*what changed?* A gate fails, and the next iteration spends itself on
the second question: *where?* Both answers exist deterministically. The
agent just had to re-derive them every time.

Three tools emerged (no new hashing code, no edge graph):

| Tool | What it does |
|---|---|
| `bin/revision-report.php` | groups `git status` changes into modules + digest |
| `bin/gate-run.php` | runs a gate, records failures (exit + digest + tail) |
| `make revision-report` | both on one command, `--json` for machines |

The report header carries a simple contract: *informational — quality
gates always run in full*. A failure record is a reading aid, not a
replacement for verification.

## What was not built

The original plan had `openspec-hash.php` and `edge-diff.php`. Rejected
deliberately:

- **git already knows the tree state** — content-based, immune to
  mtimes; a second hash would only risk disagreeing with git's view,
- **`lekalo.lock` is already the contract hash** — copying it into
  another file means two truths instead of one,
- **edges need a graph** — a PHP monolith has none; inventing one for a
  report is a mini-Bazel.

Exactly the fifth agreement: solve architecture at one hundred percent —
or not at all. One hundred percent here meant less code, not more.

---

The revision report stays great at sizing a change — just not at
deciding what is correct. Deciding belongs to tests and to people.

---

## A live run — four breaths

The day after deployment came the first real test: a file deliberately
dropped into `src/` that PHPStan level 9 refuses.

```
$ make revision-report
Core: 1
  ?? src/Modules/Core/Application/Services/DemoResumeLoopViolation.php
digest: sha256:922c78a4…

$ make gate-run GATES="phpstan"
FAIL (exit 1) — recorded; resume: php bin/gate-run.php --output phpstan

$ php bin/gate-run.php --output phpstan
== recorded failure: phpstan (exit 1) ==
Line   src/Modules/Core/Application/Services/DemoResumeLoopViolation.php
...    Method broken() has parameter $noType with no value type specified.

$ rm src/Modules/Core/Application/Services/DemoResumeLoopViolation.php
$ make gate-run GATES="phpstan"
OK (1.0s)

$ php bin/revision-report.php
(no failed-gates section — the record cleared itself)
```

Four steps: report, failure, reading the tail, fix. No guessing, no
re-running whole gates. And notice the last step — the record deletes
itself when the gate passes. A tool that cleans up after itself.

## Why it works

Because the state is deterministic. Same tree → same report → same
digest. Docker or CI resets mtimes? The report does not move. A HashMap
changes order? Sort before hashing. A gate fails on output with timing
in it? The digest identifies the exact recorded output — the latest
record wins, a pass clears it.

The agent's step number one stopped being a guess and became a read.
The whole trick rests on determinism — exactly how [Cargo's build
cache](https://doc.rust-lang.org/cargo/reference/build-cache.html)
works: do not rebuild what is fresh.

## Does the record replace the gate?

Never. `make pre-commit` always runs in full — lint, analysis, tests,
lekalo. A failure record is a margin note, not a decision. When the
report says "phpstan failed", the agent reads the tail and fixes; when
it says "clean", the agent runs pre-commit as always. The difference
between an AI agent and an AI agent with a contract is not intelligence
— it is what it is allowed to remember and what it is allowed to trust.

---

→ [Part 9 — themes get routes](/blog/lekalo-themes-get-routes) —
bindings, scenarios and the grammar limit
→ [Cargo build caching](https://doc.rust-lang.org/cargo/reference/build-cache.html)
— the fingerprint mechanics the idea came from

## Sources

- `bin/revision-report.php` + `bin/gate-run.php` — agent-state tooling
  (zero-dep, outside phpstan paths, following the `audit-rhythm.php`
  precedent)
- [Part 9 — themes get routes](/blog/lekalo-themes-get-routes) —
  bindings, scenarios and the grammar limit
- [Cargo build caching](https://doc.rust-lang.org/cargo/reference/build-cache.html)
  — the fingerprint mechanics the idea came from
