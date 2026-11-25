---
title: "Lekalo 0.6.5 for kids ... what commands say about a project"
date: "2026-10-10"
version: "1.0.0"
tags: ["lekalo", "rust", "architecture", "model", "ci"]
excerpt: "Lekalo 0.6.5 added seven surfaces. We tried every one against our model: three run, two are refused, one returns a zero you must not believe, and one needs an input we do not keep. Plus two traps we paid for on the way."
author: "nativa.dev@example.test"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-nove-prikazy"
published: true
---

A new tool is a claim.

A claim nobody ran against their own project is `mixed` ... it looks like everything and is worth nothing.

Lekalo 0.6.5 added seven surfaces: `trace assess`, `coupling`, `ai-lint`, `waivers`, `architecture-profile`, `metrics` and `requirements`.

We tried every one against the model of this repository.

So we are not saying "we support it". We are saying what it returned.

## Start with which `lekalo` you actually run

The first thing we paid for was not in the model.

`make` picks the binary like this:

```sh
# Makefile
LEKALO ?= $(shell command -v lekalo 2>/dev/null || echo "$$HOME/.cargo/bin/lekalo")
```

`~/.cargo/bin` was not on our `PATH`, so `command -v` found nothing and `make` used the second half.

Version `0.6.3` was sitting there.

In it the new commands do not exist and `lock --check` returns 1.

| Binary | `--version` | `coupling`, `ai-lint`, `architecture-profile` | `lock --check` |
|---|---|---|---|
| `~/.cargo/bin/lekalo` then | `0.6.3` | missing | exit 1 |
| `~/.cargo/bin/lekalo` now | `0.6.5` | present | exit 0 |

The difference lives entirely in what you see.

"The new surfaces are broken" and "you are running the old CLI" look identical from the outside.

Whoever trusts a red gate without `lekalo --version` looks for the bug in the model.

The fix is one bet: the release archive with a verified `sha256`, into `~/.cargo/bin/lekalo`.

`cargo uninstall lekalo-cli` ran first, because cargo managed the original file at `v0.3.2` ... and letting a record claim one version while the binary reports another is the same disagreement between a note and the thing it notes, which we met twice more.

## What actually runs

Every surface and what it returned:

| Surface | Command | Result against our model |
|---|---|---|
| `coupling` | `lekalo coupling --module users` | runs, exit 0, advisory |
| `architecture-profile` | `lekalo architecture-profile assess --module users` | runs, exit 0, advisory |
| `verify` | `make lekalo-verify` | runs, exit 0, verdict `degraded` |
| `trace assess` | `lekalo trace assess` | blocked, `LEK-TRACE-008` |
| `coupling --all` | `lekalo coupling --all` | blocked, `LEK-COUPLING-001` |
| `waivers list` | `lekalo waivers list` | refused, `LEK-AILINT-004` |
| `ai-lint` | `lekalo ai-lint --module users` | runs and **proves nothing** |
| `metrics` | `lekalo metrics` | needs `.lekalo/history/` |

Three rows in that table deserve their own section.

## Coupling ... what it says about one module

`lekalo coupling --module users` returns a picture of a module's dependencies in about two seconds:

| Module | Subjects | Public contracts | Supporting | Internal | Cycles | Findings |
|---|---:|---:|---:|---:|---:|---:|
| `users` | 117 | 94 | 30 | 0 | 0 | 97 |
| `blog` | 106 | 71 | 18 | 0 | 0 | 78 |

The report carries two codes, and both are real warnings rather than a stamp:

- `coupling.evidence-incomplete` (`LEK-COUPLING-012`) ... the report is honest about the evidence it lacks
- `coupling.shared-mutable-state` (`LEK-COUPLING-006`) ... several declared operations change the same resource

The second code is why this is worth running at all.

It is not a list of bugs, it is the place where two owners fight over one thing.

`fan-in` and `fan-out` at the level of a single subject say what moves when that subject moves.

## Architecture profile ... one rule with evidence

`lekalo architecture-profile assess --module users` returns 15 rules from the `architecture-core/1` catalog.

The state looks like this:

| Module | Assessment | Rules with evidence | Rules without evidence | Required with evidence |
|---|---|---:|---:|---:|
| `users` | advisory | 1 | 14 | 1 |
| `blog` | advisory | 1 | 14 | 1 |

That one rule is `architecture.stable-semantic-ids` ... 56 declared identities for `users` and 57 for `blog`.

Fourteen rules without evidence sounds alarming and is not a finding.

It is a missing adapter.

A rule with nothing to measure by does not decide against the project, it simply is not evaluated.

That is exactly why the surface is advisory and not a gate.

## A zero you must not believe

`lekalo ai-lint --module users` exits 0 and prints `0 findings`.

That number is treacherous.

The same output carries `LEK-AILINT-003 ai-lint.coverage-incomplete` ... that is, "collect evidence first, then claim a clean result".

The zero is not a clean bill of health. It is an empty table.

Anyone who gated on it would install a gate that is green because it measures nothing.

## When this does not hold

This section belongs in every article, so it is here today too.

`coupling --all` over the whole project **does not work**: it wants a coupling profile of its own, and without one it returns `LEK-COUPLING-001`.

And there is one extra unpleasantness in it: with `--json` it returns `status: invalid` **and exits 0**.

Whoever gates on the exit code misses that.

`trace assess` **does not work** and will not until a neutral document with validation evidence exists. Even the `verify` report is rejected by it, which is the more precise shape of the same missing thing.

`waivers list` **has nothing to list** ... the waiver set is validated against the ai-lint family, so an empty set is `LEK-AILINT-004`, not an empty list.

`metrics` needs `.lekalo/history/`, which nobody here keeps. It is not a tool defect, it is a missing input.

And the last one, the most important: **not one of these surfaces denies anything today.**

They are reports. The gates are `make lekalo-check` and `make lekalo-trace`.

## Two traps we paid for

### The Makefile is a digested source of every trace manifest

Each of the eight manifests in `docs/lekalo/trace/` carries an `evidenceDigest` ... the `sha256` of the real file behind a node.

For the `make-<target>` gates that file is the **`Makefile`**.

We added one target to it and all eight digests were rewritten at once.

Locally `build.py --check` was clean only until we made that commit.

CI caught it correctly and the `Lekalo` job went red.

The `Makefile` digest before: `a212e116…`, after: `185aabbb…`.

Today a note in the `Makefile` says this out loud, so the next person does not pay for it again.

### A recorded number ages the moment its source moves

`docs/lekalo-model.md` held a "Canonical digests" table with seven `manifestDigest` values.

After that commit **all of them were wrong** ... for exactly the same reason.

The table looked authoritative and was a photograph.

Today it carries the revision it came from and the command that derives it again.

## How we wired it

The two surfaces that run clean are wired like this:

```sh
make lekalo-reports                                  # users + blog, as markdown
make lekalo-reports LEKALO_MODULES=users,blog,forms  # a wider set
```

`bin/ops/lekalo-reports.php` runs both commands for each module, reads the JSON and renders one table per surface.

The codes and their meanings go **below the table**, not into the bin ... a green row that hides `coupling.shared-mutable-state` teaches the reader that a green row means a clean report.

The CI `lekalo` job appends that markdown to `$GITHUB_STEP_SUMMARY`.

Two modules are the floor and not one: a single module says nothing about whether the shape holds where the model is different.

And one thing in that step is deliberate: `LEKALO` is passed as a path, not through `command -v`.

An empty `command -v` would override the Makefile default with nothing and fail every row.

## In practice

1. Before you believe a red gate, run `lekalo --version`. The pinned version is `0.6.5`, and anything else is a different question.

2. When you want to know what moves when a module moves, run `lekalo coupling --module <module>`. It is advisory and it is fast.

3. When you add a target to the `Makefile`, run `make trace-build`. Eight manifests digest that file.

4. When you write down a number that came from a measurement, write down the revision next to it. Without it the number is a photograph, and photographs age.

## Sources

- [lekalo (GitHub)](https://github.com/ichinya/lekalo) ... the semantic model, the CLI and trace manifests
- [Lekalo v0.6.5 ... release](https://github.com/ichinya/lekalo/releases/tag/v0.6.5) ... checksummed archives and the new surfaces
- [GitHub Actions ... workflow syntax](https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions) ... where the report reaches the job summary
- [Semantic Versioning](https://semver.org/) ... why `0.6.5` and `0.6.4` are compatible with each other
- [Nativa Minimal Documentation](/docs/getting-started) ... how to run all of this locally
