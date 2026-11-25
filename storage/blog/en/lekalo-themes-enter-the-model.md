---
title: "Rust and Lekalo for kids ... part 9.1: themes enter the model"
series: "Rust & Lekalo"
series-part: "9.1"
date: "2026-09-13"
version: "1.0.0"
tags: ["rust", "lekalo", "semantic-model", "themes", "architecture"]
excerpt: "The fourth module in the semantic model: six definitions for themes, a seeds-first scope, strict-ready from the first commit, and a lesson about the pinned IR digest, earned the hard way against LEK-AUTH-010. Plus the \"gate cannot read PHP, the test can\" pattern."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-temy-v-modele-pre-deti"
published: true
---

In [part 7](/blog/lekalo-model-grows) the model doubled to 106 definitions
and the toolchain became a gate. Today the model grows again, to
**130 definitions**: and the fourth module has a name you know from the
whole frontend-refactor series: **themes**.

## What's new in the model

Six definitions in `lekalo/modules/themes/`:

| Definition | Kind | What it says |
|---|---|---|
| `themes.variant` | enum | `starter \| magazine \| minimal \| moonshine`: the whitelist that must mirror `Themes\ThemeRegistry` |
| `themes.active_state` | entity | a singleton, the persisted theme, the `theme` row in config.db |
| `themes.resolve_active` | query | the active theme for a request, with the pinned precedence: `?style=` → `THEME` env → setting → `starter` |
| `themes.set_site_theme` | command | the admin write via `/mark/settings` |
| `themes.effect_site_theme_set` | effect | the config.db write |
| `themes.site_theme_set` | event | "the theme was changed", payload `variant` |

Plus two policies in `authorization.yaml`: reading the active theme is
`public` (every visitor sees which theme renders, including via the
`?style=` override), writing is `users.role.admin`. A mirror of the blog
policies from part 7.

## Seeds-first: what the model deliberately does NOT declare

The honest distinction, as always. The model today has **no**:

- **endpoint bindings**: a theme is not a new route. It is a *dimension*
  of existing endpoints (`/`, `/blog`, `/blog/{slug}` exist regardless of
  the theme); bindings would only duplicate what blog and users already
  declare,
- **scenarios**: they arrive together with bindings in a later change; a
  scenario without an attached endpoint would be decoration.

Zero runtime touches, the model only declares what PHP already enforces
by hand.

## The lesson: LEK-AUTH-010 and the pinned digest

The first `lekalo validate --strict` run after adding the module fell over:

```
denied error [LEK-AUTH-010] authorization.mapping-stale:
Adapter mapping evidence is missing, stale, partial, or unsupported
and cannot pass strict review.
```

The JSON diagnostic envelope says exactly why: `"state": "stale-model"`.
`authorization.yaml` carries `model_ref.ir_digest`: a pin that says
"these policies were written against exactly this model". Adding a module
changes the model → the pin is old → strict cannot trust the document.

And here is the trap: the digest is **not** the sha256 of the raw
`lekalo load --ir` output. It is the sha256 over the **compact canonical
IR JSON**, the exact bytes between `"ir":` and the closing `}` inside
the JSON envelope. The envelope embeds the canonical bytes verbatim, so
the recipe looks like this:

```sh
lekalo load --ir --json | python3 -c '
import sys, hashlib
raw = sys.stdin.buffer.read().decode("utf-8")
prefix = "{\"status\":\"valid\",\"modelVersion\":\"1.0.0\",\"ir\":"
body = raw[len(prefix):].rstrip("\n")
print("sha256:" + hashlib.sha256(body[:-1].encode()).hexdigest())'
```

The most important step: **verify the recipe against a known-good pin**
before trusting a fresh value. We ran it on the committed model, compared
with the pinned `c0eaab…`: an exact match, and only then swapped the
digest for the new `e9df0e…`. A verified tool, not faith.

A curiosity: `lekalo lock` did **not** change. It freezes resolution
identities (versions, adapters, contracts), not model content,
`lock --check` stayed green with the new module in place.

## The pattern: "gate cannot read PHP, the test can"

The `themes.variant` enum is a hand-written mirror of the runtime
whitelist. Lekalo validates the model in isolation, it can never import
PHP symbols. So how do we keep the enum and `Themes\ThemeRegistry` from
drifting apart?

A new test: `tests/Integration/ThemesModelParityTest.php`.

- it parses `lekalo/modules/themes/entities.yaml` (canonical JSON, a
  valid YAML subset, so `json_decode` suffices, zero dependencies),
- extracts the `themes.variant` enum values,
- asserts **set-equality** with `Themes\ThemeRegistry::all()`.

A new theme without a model entry → the test fails in CI. A model entry
without the runtime → same. The gate cannot read PHP; the test can. We
will reuse this pattern everywhere the model and the runtime meet by hand.

## Live output

```
validated default profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
validated strict profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
lock checked sha256:bc4dbad9… resolver 1.0.0
```

130 definitions (30 scalar, 18 command, 18 effect, 15 endpoint, 11 query,
9 entity, 9 policy, 6 event, 6 scenario, 4 enum, 2 value-object,
2 target-binding) across four modules: users, blog, comments, **themes**.

## Wake up

- The model now covers the frontend surface too, not just data and APIs.
- The pinned digest is a living thing: every model change requires a pin
  refresh. The recipe is documented in `docs/lekalo-model.md`.
- The parity-test pattern is general: whatever the gate cannot see, a
  test guards.

Next part: endpoint bindings and scenarios for themes, when a
"dimension" of endpoints becomes a full-blown part of the contract.

## Sources

- [Lekalo](https://github.com/ichinya/lekalo): the project repository (product 0.1.31, authorization #25)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): model documentation, including the `model_ref.ir_digest` refresh recipe
- `lekalo/modules/themes/*.yaml`: the Themes module semantic model (6 definitions)
- `lekalo/authorization.yaml`: `themes.policy.public_read` and `themes.policy.admin_editing`
- [Part 7, the model grows](/blog/lekalo-model-grows): authorization, blog and gates on every push
