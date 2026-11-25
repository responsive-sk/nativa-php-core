---
title: "Rust and Lekalo for kids ... part 9.2: themes get routes"
series: "Rust & Lekalo"
series-part: "9.2"
date: "2026-09-14"
version: "1.0.0"
tags: ["rust", "lekalo", "semantic-model", "themes", "architecture"]
excerpt: "The model grows to 135 definitions: themes receive endpoint bindings and scenarios. Two representative bindings instead of six surfaces, a route-parity test extending the \"gate cannot read PHP, the test can\" pattern, and a grammar that does not know the root path."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "lekalo-bindings-pre-deti"
published: true
---

In [part 9.1](/blog/lekalo-themes-enter-the-model) themes became the model's
fourth module, enum, entity, query, command. Today they receive the other
half of the deal: **bindings and scenarios**. The model grows from 130 to
**135 definitions**.

## What's new

`lekalo/modules/themes/` gains two files:

| Definition | Kind | What it says |
|---|---|---|
| `themes.endpoint_blog_index` | endpoint | `GET /blog` → `themes.resolve_active` |
| `themes.endpoint_settings_switch` | endpoint | `POST /mark/settings/site` → `themes.set_site_theme` |
| `themes.binding_php` | target-binding | module ↔ `php-nativa` (parity with blog) |
| `themes.read_flow` | scenario | a visitor loads a page, the resolver picks the theme |
| `themes.admin_switch_flow` | scenario | an admin submits the form, the theme is persisted |

Themes are now the first module after blog with the full vocabulary:
endpoints, a target-binding and scenarios.

## Two bindings instead of six: and why

A theme is a *dimension* of existing endpoints: `/blog`, `/blog/{slug}`,
`/authors/{slug}`, tags, categories, the homepage, everything renders
through the active theme. Binding all six would mean every future route
change becomes a mandatory model edit, for zero added contract value.

Hence the **representative pair**: `GET /blog` (the read path that goes
through the resolver) and `POST /mark/settings/site` (the write path,
`SettingsAction::saveSite` persists the validated `theme` key). Those two
carry the whole fact: "a theme is a dimension, not a new route."

## Route parity: the pattern grows again

Part 9.1's pattern was: *the gate cannot read PHP, the test can*, applied
to the enum. Today the same pattern applies to **routes**:

`tests/Integration/ThemesBindingParityTest.php` (4 tests, 46 assertions):

- it parses `bindings.yaml` (`json_decode`: canonical JSON, zero
  dependencies),
- for every `kind: endpoint` it finds the runtime `#[Route]` declarations
  (regex over the action classes, the same discovery technique
  `Themes\ThemeRegistry` uses),
- it asserts **method+path equality** for every binding.

Rename a route without the model → the test fails. Change the model
without the runtime → same. And the test also pins *what* the bindings
invoke: `/blog` must invoke `themes.resolve_active`, the settings POST
must invoke `themes.set_site_theme`.

## Grammar: the root path does not exist

An honest find from the implementation: the Model 1.0.0 endpoint-path
grammar is `^(/[a-z0-9:_{}-]+)+$`: segments must be **non-empty**. The
root path `/` (the homepage, equally theme-aware) cannot be expressed.
The validator said it precisely:

```
invalid error [LEK-IR-006] ir.value-invalid
lekalo/modules/themes/bindings.yaml:11:15:
A value violates its grammar or rule.
```

The first thing I wrote was `path: "/"`: and the CLI refused it. The
fix: the homepage is represented by the `GET /blog` binding (valid
grammar, same fact), and the grammar limit is documented in
`docs/lekalo-model.md` for the day upstream widens it.

## The digest recipe, second time

Adding bindings changed the IR → `LEK-AUTH-010` again. The part 9.1 recipe
works exactly as documented: first verify against the known-good pin on
HEAD (`e9df0e…`: an exact match), then apply the fresh `23bdb373…`.
A twice-verified recipe is no longer luck; it is a procedure. And
`lekalo.lock` stood still again, it freezes resolution identities, not
content.

## Live output

```
validated default profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
validated strict profile v1.0.0: 23 rules, 0 errors, 0 warnings, 0 info
lock checked sha256:bc4dbad9… resolver 1.0.0
composer test: OK (919 tests, 3048 assertions)
```

135 definitions (17 endpoint, 8 scenario, 3 target-binding, of which
themes: 11 definitions, 2 endpoints, 2 scenarios, 1 target-binding)
across four modules.

## Wake up

- A representative subset is legitimate modeling, binding everything is
  blind obedience, not a contract.
- The parity pattern grows with the model: enum → routes. Every place
  where the model and the runtime meet by hand gets a test.
- Grammars have blind spots; an honest model documents them instead of
  routing around them.

Next part: when themes get their own admin UI (theme preview, per-locale
variants), the model will be waiting.

## Sources

- [Lekalo](https://github.com/ichinya/lekalo): the project repository (product 0.1.31, endpoint grammar in `ir/grammar.rs`)
- [docs/lekalo-model.md](https://github.com/evan70/nativa-minimal/blob/master/docs/lekalo-model.md): model documentation including the bindings section and the grammar limit
- `lekalo/modules/themes/bindings.yaml` + `scenarios.yaml`: 2 endpoints, a target-binding, 2 scenarios
- `tests/Integration/ThemesBindingParityTest.php`: the route-parity guard (4 tests, 46 assertions)
- [Part 9.1, themes enter the model](/blog/lekalo-themes-enter-the-model): enum, entity, query, command and the digest lesson
