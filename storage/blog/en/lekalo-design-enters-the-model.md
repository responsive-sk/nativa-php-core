---
title: "Rust and Lekalo for kids ... part 10.1: design enters the model"
series: "Rust & Lekalo"
series-part: "10.1"
date: "2026-09-19"
version: "1.0.0"
tags: ["rust", "lekalo", "semantic-model", "design", "wcag"]
excerpt: "The fifth module in the semantic model: token identities instead of colors, the seven roles the WCAG audit guards, and two grammar lessons. Snake_case enums, an empty applies_to the closed schema refuses, and the pinned-digest recipe verified once again."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-dizajn-v-modeli-pre-deti"
published: true
---

In [part 9.2](/blog/lekalo-themes-get-routes) themes got their routes and
the model stood at 135 definitions. Today the fifth module arrives:
**design**. The model grows to **142 definitions** and for the first time
it does not describe behaviour but something a regular person pictures
under the word design: colors, surfaces and contrast.

## What's new in the model

Seven definitions in `lekalo/modules/design/`. The core is not a color
but a **token identity**: a surface-and-mode pair plus a CSS custom
property name.

| Definition | Kind | What it says |
|---|---|---|
| `design.role` | enum | the 7 roles the CSS audit actually contracts on |
| `design.mode` | enum | `dark \| light`: the two audited blocks |
| `design.variant` | enum | 6 surfaces: 4 themes + admin + auth |
| `design.token_name` | scalar | a CSS custom property name, e.g. `--st-accent-strong` |
| `design.identity` | entity | identity `[variant, mode, token]` + a role |
| `design.resolve_tokens` | query | reads the identities, pins the WCAG AA contract |
| `design.contrast_flow` | scenario | the mode flips → the contrast holds in both |

Plus two policies in `authorization.yaml` (13 in the project now):
reading is `public`, the admin carries a policy on the same read surface.

## The role, not the value

The most important decision of this part: the model declares **which
identities exist and under which role**, not which color they carry.
Colors are bytes in CSS and they stay in CSS.

`design.role` is not an invented list. It is exactly the seven suffixes
`BuildCssAuditTest` guards with real WCAG computations: `accent_strong`,
`accent_soft`, `accent_hover`, `primary_ink`, `error_ink`, `surface`,
`tag_bg`. The decorative amber `--mag-accent` (about 3:1, icons and
borders only) is deliberately not a role. Its contract is a text ban,
not a floor.

And why `accent_strong` instead of `accent-strong`? Exactly. The first
grammar lesson.

## Two grammar lessons

**Lesson 1: enum values are snake_case.** The first `lekalo validate`
run fell over on every `accent-strong` with `LEK-IR-006`. The analogy
helped: the blog model has `members_only`, not `members-only`. The value
grammar wants underscores and the model will not route around it.

**Lesson 2: the closed schema refuses an empty `applies_to`.** We wanted
an honest admin policy without a command, so we wrote `applies_to: []`.
`LEK-AUTH-001`, no explanation. Bisecting: the block without the admin
policy passes, the empty list does not, a filled list does. The schema
does not want an empty commitment.

The fix is more honest than a placeholder: `design.policy.admin_editing`
got the same read surface, `design.resolve_tokens`. The admin only reads
the surface anyway. No fictional command was invented and the write side
stays unmodeled because the runtime has none.

## The pinned digest, this time in two places

After adding the module, `validate --strict` fell over with `LEK-AUTH-010`
(`state: stale-model`). The same lesson as in [part
9.1](/blog/lekalo-themes-enter-the-model), with extra weight:

1. verify the recipe **before** using it → on the committed model it
   produced exactly the pinned `23bdb373…`,
2. the new pin `6afbf0eb…` → `authorization.yaml`,
3. the same compact IR digest also goes into the `irRef.digest` of the
   `docs/lekalo/invariant-transition.nativa.json` attachment (its old
   value matched the same recipe, so the recipe is twice-verified),
4. the attachment's `modelRef.digest` is a lock contract and stays.

And `lekalo.lock`? Once again **byte-identical**. It freezes resolution
identities, not model content.

## The gate cannot read CSS, the test can

The pattern from part 9.1 got its second deployment:
`tests/Integration/DesignModelParityTest.php`, 5 tests.

- `design.variant` must cover every surface the audit touches (parsed
  from the audit source: `--st-`, `--mag-`, `--min-`, `--m-` from the
  bundle plus the `adminCss()` and `authCss()` inline blocks),
- `design.role` must be set-equal with the audited role list,
- `design.mode` must be exactly `dark|light`,
- the contract shape: the identity fields, the query `reads` and
  `returns`.

A new audited role without a model entry → CI fails. A model entry
without CSS → same. Lekalo validates the model in isolation and never
sees CSS.

## Live output

```
validated default profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
validated strict profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
lock checked sha256:bc4dbad9… resolver 1.0.0
```

142 definitions (31 scalar, 18 command, 18 effect, 17 endpoint, 12 query,
10 entity, 9 policy, 9 scenario, 7 enum, 6 event, 3 target-binding,
2 value-object) across five modules: users, blog, comments, themes,
**design**. The parity test: 5 tests, 55 assertions, green.

## Wake up

- The model now describes the design layer too. The vocabulary has
  5 modules.
- The unit of modeling is an identity and a role, not a color. CSS
  remains the single source of truth for bytes.
- The grammar has guts: snake_case values and no empty `applies_to`.
  Bisection and analogy can rescue both mistakes.
- Always verify the digest recipe against a known-good pin.

Next part: blocks. Grid, section, form, nav, footer. First as a block
matrix in the documentation and an architecture test, entering the model
only once the vocabulary crystallizes.

## Sources

- [Lekalo](https://github.com/ichinya/lekalo): the project repository (product 0.1.31, authorization #25)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): model documentation, including the design section and the `model_ref.ir_digest` refresh recipe
- `lekalo/modules/design/*.yaml`: the Design module semantic model (7 definitions)
- `tests/Integration/DesignModelParityTest.php`: the parity guard (5 tests)
- [Part 9.1, themes enter the model](/blog/lekalo-themes-enter-the-model): the first time the pinned-digest recipe appeared
- [Part 9.2, themes get routes](/blog/lekalo-themes-get-routes): endpoint bindings for themes
