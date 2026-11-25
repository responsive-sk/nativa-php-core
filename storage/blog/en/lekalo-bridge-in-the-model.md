---
title: "Rust and Lekalo for kids ... part 10.3: a bridge between modules and a radius you can measure"
series: "Rust & Lekalo"
series-part: "10.3"
date: "2026-09-20"
version: "1.0.0"
published: true
tags: ["rust", "lekalo", "semantic-model", "design", "ci"]
excerpt: "Two modules that don't know each other, and one theme change that should also interest the contrast audit. A scenario as a bridge, a one-way import because cycles are forbidden, and a radius that grew from 2 to 3 — measured, not declared."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-most-v-modele-pre-deti"
---

> [Part 9](/en/blog/lekalo-invariants) added invariants and [branch 9.2](/en/blog/lekalo-themes-get-routes) added routes. [Part 10](/en/blog/agent-state-protocol) was about how an agent remembers, [10.1](/en/blog/lekalo-design-enters-the-model) about the model's fifth module and [10.2](/en/blog/upgrading-lekalo) about an upgrade through three gates. Today 10.3 — a bridge between modules and a radius you can measure.

In [part 10.2](/en/blog/upgrading-lekalo) the model stood at 142
definitions and the tool had just cleared three gates. Today another one
came ... the model now has 143 definitions and ten scenarios. Not because
growth is a goal.

What arrived is a **bridge**: a scenario in the `design` module describing
the one thing the model had no way to express so far ... that a theme
change is also the contrast audit's business.

## Two modules, one question

The model has five modules today. For themes and design the split sounds
simple and behaves strictly:

| Module | Models | State | Parity test |
|---|---|---|---|
| `themes` | which theme renders | yes ... `themes.active_state` in `config.db` | `ThemesModelParityTest` |
| `design` | what a token must satisfy | no ... a pure read contract | `DesignModelParityTest` |

Both worlds overlap on the four public themes. And that is exactly where
a question without a model answer appeared: when an admin changes the
theme in `/mark/settings`, pages start rendering through a different
token family. Who in the model says that the new family is under the
WCAG AA contract?

Nobody. The answer lived in a test and in someone's head. Not in the
model.

## A scenario as the bridge

The fix is a `scenario` definition in `lekalo/modules/design/scenarios.yaml`:

```yaml
// lekalo/modules/design/scenarios.yaml — the bridge (abridged)
id: design.theme_change_reaudit_flow
kind: scenario
covers:
  - design.resolve_tokens        // the audit contract
  - themes.set_site_theme        // a command from another module
  - themes.effect_site_theme_set
  - themes.site_theme_set
```

The scenario `covers` the contract from its own module and three symbols
from the `themes` module ... the command, the effect and the event of one
persisted theme change. Nothing executes, nothing is generated. Lekalo is
declaration: the scenario says **what belongs together**, not how.

The tool's first response landed exactly where it should: `LEK-LOAD-015`,
`reference-without-import`. A reference into a foreign module without an
import is not shorthand, it is an error. The import goes into
`module.yaml`:

```yaml
// lekalo/modules/design/module.yaml
"imports": [
  "themes"
]
```

## Why one direction

The most natural idea ... a scenario in both modules, a two-way bridge ...
runs into a loader rule: **import cycles are forbidden**
(`loader.import-cycle`). `design` cannot import `themes` while `themes`
imports `design`.

Nothing to fix, because the dependency genuinely runs one way. The audit
contracts on the surfaces a theme change renders through — not the other
way around. A theme change needs to know nothing about the audit; the
audit needs to know about the theme change. The bridge is one-directional
and the quiet balance looks like this: one bridge in the model, a test
guarding it from the other side, and nothing sprinting for both.

## A radius you can measure

The most interesting question: does the bridge change anything, or is it
decoration? The answer was not argued ... it was measured. I compared
`lekalo impact themes.set_site_theme` on the commit with the bridge and on
its parent ... a temporary git worktree, two JSON envelopes, the diff
computed programmatically.

| Impact section | Before the bridge | With the bridge |
|---|---|---|
| direct | 2 | **3** |
| transitive | 0 | 0 |
| scenarios | 1 | **2** |
| path explanations | 2 | 3 |
| risks / gates | 6 / 9 | 6 / 9 |

The new edge says exactly what it should: `operation:themes.set_site_theme
→ scenario:design.theme_change_reaudit_flow`, relation `references`,
distance 1, confidence `canonical`. Whoever touches the theme-switch
command now sees the audit contract in the impact output. Risks and gates
stayed ... the bridge added **visibility**, not new risk.

So the bridge cannot rot silently, a parity test pins the exact `covers`
list: `DesignModelParityTest::testBridgeScenarioPinsTheThemesSymbolSet`
fails when the scenario disappears or its symbols change.

## A report, not a gate

The radius can be read automatically too. `lekalo impact --changed --base
origin/main --worktree` projects the working tree's changes against a
base ... precisely the pull request scenario. Tried live, and the decision
is: **a report, not a gate**.

The reasons are measured, not felt:

- the default profile only warns (`impact.evidence-unknown`) ... it never
  fails; a gate would be a no-op
- `--profile strict` is denied today (`LEK-IMPACT-010`) ... the evidence
  sources are not wired yet; a gate would always be red
- an empty changed set is itself an error (`LEK-IMPACT-002`) ... the step
  must first check that anything changed at all

So CI **prints** the radius on PRs touching `lekalo/`, and locally
`make impact-report BASE=<ref>` does the same. When upstream wires the
evidence sources, the hard-gate question comes back to the table. Today a
gate would either catch nothing or paint every run red ... that is not
discipline, that is theater.

## When not to build a bridge

Honestly: if your modules do not really relate, a bridge is noise. This
one makes sense because the surfaces are **the same four themes** ... the
audit audits exactly the identities the switch switches. The import runs
one way, it is not a pair of mirrored entities; if you wanted to model
two-way-ness, you would hit the cycle and have to find another shape.

The second honesty: the bridge **generates no work**. It adds no test, no
gate, no runtime change. It changes exactly one thing ... whoever reads
the impact sees a relation that used to live in a head. If that feels
small, remember the map from [part 11](/en/blog/map-and-terrain): the
terrain did not change, the map did. Sometimes that is precisely the kind
of change worth a commit.

## What comes next

The block vocabulary ... grid, section, form, nav ... waits for
crystallization in the docs, exactly as part 10.2 promised. In the
meantime the `themes`–`design` bridge demonstrated a shape you can reuse:
when two modules share a surface, one scenario and one import express the
relation cheaper than any comment.

## Sources

- [Lekalo](https://github.com/ichinya/lekalo) — the project repository (loader doc: visibility, `loader.import-cycle`, `LEK-LOAD-015`)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md) — the measured 2→3 radius and the CI report decision
- [loader.md upstream](https://github.com/ichinya/lekalo/blob/v0.3.2/docs/loader.md) — imports as a module property, direct visibility, cycles
- [Part 10.1 — design enters the model](/en/blog/lekalo-design-enters-the-model) — token identities, roles and the first grammar lesson
- [Part 10.2 — an upgrade through three gates](/en/blog/upgrading-lekalo) — wire formats, the registry and the digest
