---
title: "Rust and Lekalo for kids ... part 18: the gate nobody ran"
series: "Rust & Lekalo"
series-part: "18"
date: "2026-10-10"
version: "1.0.0"
tags: ["rust", "lekalo", "ci", "gates", "model", "makefile"]
excerpt: "A gate was sitting in the repository that knew how to refuse. It had a body, an exit 1, a comment of its own, and nobody ran it, because it read like a report. The three conditions that make a gate a gate, and the four places where one gets lost."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-brany-pre-deti"
published: true
---

> This is part 18 of the **Rust & Lekalo** series. [Part 17](/en/blog/lekalo-three-versions)
> is about an upgrade where nothing moved. [The article about the new
> commands](/en/blog/lekalo-new-commands) says what the 0.6.5 surfaces
> answered about our model. The subject today is gates ... and one that
> nobody ran.

A gate was lying in the repository.

It had a body. It had `exit 1`. It had a comment explaining why it was there.

Did anyone call it? No.

`make pre-commit` did not name it. The CI job did not run it. Not one document, script or test referred to it.

Its name is `make workspace-audit`.

## Fog: a gate as four lines

Its body looks like this:

```make
workspace-audit:
	@$(LEKALO) init --adopt --dry-run --json \
		| jq -e '.detection | (workspaceRoots == ["Frontend/packages"] and monorepoTools == [] and ...)' > /dev/null \
		&& echo "workspace-audit: OK" \
		|| { echo "workspace-audit: FAILED" >&2; exit 1; }
```

`jq -e` is the whole point.

Without `-e`, `jq` returns zero even when the predicate does not hold ... it prints `false` and exits happily.

With `-e`, the result becomes a decision.

The rest is `&&` and `||`, that is, what happens when the predicate holds and what happens when it does not.

Out of that comes the question that separates a gate from a report: **can it refuse?**

This one can. And nobody asked it.

## Where it hid

It stood in the `Makefile` between `lekalo-doctor` and `impact-report`.

Both neighbours carry "informational, not a gate" in their description.

Reports are deliberately wired nowhere ... they drop a line into the CI summary and move on. A gate in that neighbourhood is missed by anyone reading the target list with their eyes.

The whole mechanism of the loss is that nothing happened.

It did not break. It did not crash. It did not fail.

Nobody simply called it.

## Sun: three conditions

Three things together make a gate a gate:

| Condition | `workspace-audit` today |
|---|---|
| It can refuse | yes ... `jq -e` and `exit 1` |
| Somebody runs it | yes ... `make pre-commit` and the `lekalo` CI job |
| It cannot pass vacuously | yes ... the predicate reads three concrete fields |

I measured the first condition by handing the predicate eight shapes of an answer.

The real CLI output passed. Everything else fell:

| Payload | Result |
|---|---|
| real `lekalo init --adopt --dry-run --json` output | accepted, exit 0 |
| a root elsewhere in the tree | rejected |
| a root moved outside `Frontend/packages` | rejected |
| `monorepoTools` carrying a tool | rejected |
| `packageManagers` without pnpm | rejected |
| `{}` | rejected |
| an answer with no `detection` | rejected |

The last two rows are not a formality.

A predicate that passes vacuously is a green table ... and a green table over an empty input is the same lie as the zero from `ai-lint`.

The third condition is held by `tests/Architecture/MakeTargetsWiredTest.php`.

That test does nothing smarter than asking every `Makefile` target whether it is wired into `pre-commit` or into CI, and when it is not, whether the `NOT_WIRED` list records a reason.

The waiver list is a ledger of decisions, not a list of oversights: a key pointing at a target that does not exist fails the test. A wired target sitting in the waiver list fails it too.

On its first run it found two more things, both real.

### A target that is not phony is skipped in silence

`.PHONY` looks like decoration for a `Makefile`.

It is a lock: without it, a target is skipped and returns zero the moment a file of that name exists in the tree.

`hooks-install`, `acceptance` and `impact-report` were missing from the list.

Today that is a test, not a note.

### A target that lives only in a comment

The CI step ran `lekalo verify …` directly.

The comment above it named `make lekalo-verify`.

A guard looking for an invocation has to read executable lines, because a comment is prose.

This is the same class of defect as the whole article: a call that does not exist, and a mention that reads like a call.

## Rust: one file instead of a runtime

The tool behind that gate is a single binary.

No runtime, no dependencies, no `node_modules`. That is the whole reason a PHP project can let somebody else's tool into its CI: an archive comes down, a `sha256` gets verified, and one executable file comes out.

```sh
# .github/workflows/ci.yml
sha256sum -c -                                 # the pinned archive hash
install -m 0755 "…/lekalo" "$HOME/.local/bin/lekalo"
echo "$HOME/.local/bin" >> "$GITHUB_PATH"
```

On my machine that file is 34 MB and it holds the whole tool.

The same property has a second side, and I paid for it today.

Rust binaries install into `~/.cargo/bin`, and that is not necessarily on `PATH`. So `make` picks the tool in two steps:

```sh
# Makefile
LEKALO ?= $(shell command -v lekalo 2>/dev/null || echo "$$HOME/.cargo/bin/lekalo")
```

`command -v lekalo` came back empty for me. `$HOME/.cargo/bin/lekalo --version` prints `lekalo 0.6.5`.

Out of that comes a rule that belongs with gates: when a gate fails, the first question is not "what is in the model", it is "which binary ran".

`lekalo --version` is cheaper than an hour of hunting a bug in the model.

And honestly: Rust does not make a gate correct. The binary answers exactly what the predicate asks it. If the predicate were wrong, it would fail just as reliably, only faster.

## Night: why nobody wired it

The reason is not laziness. It is reading.

When a surface is called `verify`, `doctor` or `audit`, a person expects a report. A report has nothing to spoil, so it is left unwired.

It gets wired the moment somebody reads its body.

## Three things that look like a gate

This week I measured three surfaces playing at being a gate:

| Surface | What it says | What it really returns |
|---|---|---|
| `verify --ci-policy strict` | report says `blocked (exit 4)` | **exit 0**, envelope `verdict: ready` |
| `ai-lint --module users` | `0 findings` | exit 0 with `LEK-AILINT-003 coverage-incomplete` |
| `architecture-profile assess` | 15 rules | advisory, one rule with evidence |

The first row is the most interesting, because the disagreement is inside it.

The report heading carries `blocked (exit 4)`. The process returns zero. I ran all three policies and all three returned zero:

| Policy | Report heading | Process exit |
|---|---|---|
| `default` | `degraded (exit 0)` | 0 |
| `strict` | `blocked (exit 4)` | 0 |
| `lenient` | `degraded (exit 0)` | 0 |

Whoever gates on the exit code gets nothing refused by `strict`.

Whoever gates on the report heading has to read the report first. Two different disciplines, and one of them fits inside a `&&`.

That is why I wrote what I measured into the comment.

## Two traps the gate paid for

### A report cannot be overwritten

`make lekalo-verify` runs `lekalo verify --report-file storage/cache/lekalo-verify.md`.

The first run passed.

The second failed with `LEK-CI-001 ci.report-write-failed`.

`--report-file` writes only to a path that does not exist yet, and refuses an existing file with exit 4. So the target passes once and then never again ... which is exactly what happened the moment the CI step was pointed at it.

The fix is one line: `rm -f` before the write.

I looked for the rule elsewhere too. A fresh path inside the repository, at the project root and in `$HOME` all wrote without complaint.

So the rule is overwrite refusal, not a ban on writing into the project.

### The Makefile is a digested source

Adding a target to the `Makefile` rewrites eight trace manifests.

The tool cannot work around it: the `Makefile` is the evidence source for nodes of the `make-<target>` kind.

That fixes the order: the target, then `python3 bin/trace/build.py`, and only then `--check`.

## When this does not hold

**The gate measures a shape, not a result.** `workspace-audit` guards that the workspace root is `Frontend/packages`, that no monorepo tool sits in the tree and that pnpm is among the package managers. It does not say whether the build passes. Another gate says that.

**The candidate for a second gate is green mostly because it has nothing to measure.** `lekalo architecture-profile assess --module users --check` returns exit 0 and the assessment flips from `advisory` to `enforced-core`. Of the fifteen rules in the catalog, one carries evidence. Fourteen have nothing to be evaluated against, because the project has no adapter ... and a rule with no measurement does not decide against the project, it simply is not evaluated.

A green zero is an old acquaintance here: `ai-lint` returns `0 findings` with the same admission in the same answer.

**The model knows eight modules.** `users`, `blog`, `comments`, `audit`, `pages`, `themes`, `design` and `forms`. Outside it stand `media`, `settings`, `locales`, `outbox`, `security` and the core.

## What comes next

The adoption rule lives in `docs/lekalo-model.md` and it is short: a surface becomes a gate when it has been run against this model and exited zero.

`assess --check` met that condition today. `verify --ci-policy strict` did not, because its exit code refuses nothing.

Wiring a gate has four places: `pre-commit`, a CI step, a guard and the documentation.

Skipping the third one means somebody else loses the next gate.

Three more modules have earned their place in the model ... the ones that already broke a rule we believed was permanent. And then reader questions.

## The object we set aside

The adapter remains good work. Just not for us.

We adopt the model and the trace manifests, not the tools that stare into somebody else's source.

Without it fourteen rules of the catalog stay unevaluated and `verify` writes `coverage incomplete`.

The green that comes out of it is a green about shape, not about truth: honest work, honestly set aside.

And the next time the model lands in a project that has an adapter, the same surface says more.

## Theme

A tool that can refuse and a decision that it will are two different things.

The distance between them is one line in the `Makefile` and somebody who reads its body.

→ [Part 17 ... three versions and no change](/en/blog/lekalo-three-versions) — the upgrade where nothing moved
→ [Part 15 ... a chain with hashes on both ends](/en/blog/the-trace-manifest-a-chain-with-hashes-on-both-ends) — where a manifest gets its evidence
→ [What the commands say about a project](/en/blog/lekalo-new-commands) — the seven 0.6.5 surfaces and what they really returned

## Sources

- [Lekalo](https://github.com/ichinya/lekalo) ... the semantic model, the CLI and the neutral trace manifests
- [Release v0.6.5](https://github.com/ichinya/lekalo/releases/tag/v0.6.5) ... checksummed archives and seven new surfaces
- [jq manual](https://jqlang.github.io/jq/manual/) ... what `-e` does exactly, and why a predicate without it is decoration
- [actions/runner-images — Ubuntu 24.04](https://github.com/actions/runner-images/blob/main/images/ubuntu/Ubuntu2404-Readme.md) ... where the `jq` on the runner comes from, the one the gate needs
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) ... the surface table with its verdict, and the adoption rule
- [Nativa Minimal ... getting started](/docs/getting-started) ... how to run all of this locally
