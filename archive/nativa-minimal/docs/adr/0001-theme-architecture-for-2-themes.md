# ADR-0001: Theme architecture for 2 themes

- **Status:** Accepted (2026-09-22) — partially superseded by
  [ADR-0002](0002-third-wired-theme-erik.md) (2026-09-29): the wired set is
  three themes and public theme selection is the whitelist-validated
  `theme` setting; the registry removal, moonshine identity and
  reference-theme decisions carry over
- **Tags:** themes, architecture, frontend

## Context

The public frontend grew four themes behind `Themes\ThemeRegistry` (glob
auto-discovery of `src/Themes/*/Manifest.php`), a `ThemeManifest` interface,
a `?style=` query override, the `THEME` env var and a `site.theme` setting
editable in `/mark/settings`:

- `starter` — public default, classic `FrontendLayout` shell, works without
  a Vite build (inline critical CSS)
- `magazine` — prototype/dex experiment (`mag-*` classes, deferred CSS)
- `minimal` — prototype/dex experiment (`minimal-*` classes, deferred CSS)
- `moonshine` — MoonShine-style chrome (`ms-*` classes, `--m-*` tokens)

The extra three were built as design experiments ("dex"). The final product
ships exactly two themes: the public site and the admin panel. `/mark`
already renders the MoonShine-pattern chrome (fixed sidebar, topbar, burger
drawer, `.m-*` components, `--m-*` tokens) through `AdminLayout` — the admin
theme exists and is wired; it was just never named as one.

For two themes, an auto-discovering registry is over-engineering: a `glob()`
scan, manifest classes and a validation whitelist replace what a two-arm
`match` expresses directly.

## Decision

1. **`ThemeRegistry` + `ThemeManifest` are removed** (including the
   per-theme `Manifest.php` files). No registry, no `glob()`, no
   `require_once` in production code.
2. **The `ThemeRenderer` interface stays.** It remains the contract between
   action classes and markup (six methods: homepage, blogIndex,
   blogArticle, blogTags, blogCategories, authorProfile).
3. **Theme switching is a `match ($theme)` with two arms** — admin first,
   `default` = public. Adding a third theme means editing these match arms;
   accepted, the product is designed for two.
4. **`moonshine` keeps its identity and is the admin theme.** Its chrome
   (`ms-*` classes, `--m-*` tokens, `.m-*` components — vendored from `ui/`,
   which stays untouched) is the `/mark` chrome through `AdminLayout`.
   No rename to "admin".
5. **`starter` is the public theme** — the default fallback that renders
   through the classic `FrontendLayout` shell.
6. **The `?style=` query override is removed**, together with the `THEME`
   env var, the `site.theme` setting and the theme select in
   `/mark/settings`. Two themes need no configuration.
7. **`ThemeResolver` shrinks to path-based resolution:** `/mark/*` →
   `moonshine` (admin theme), everything else → `starter` (public theme).
8. **`magazine` and `minimal` stay under `src/Themes/` as unwired reference
   code** — renderers, shells, sections and CSS remain in the tree for
   future reference, but no route reaches them and their Vite entries are
   dropped (the built bundle carries only `main` + `mark`).

## Consequences

- Every theme-aware public action dispatches through a two-arm match. The
  admin arm is defensive: `/mark` renders through `AdminLayout`, not
  `ThemeRenderer`, so public routes always resolve to `starter`.
- The Lekalo `themes` model shrinks accordingly: `themes.variant` becomes
  `starter | moonshine`, and the admin-switch flow (`themes.active_state`,
  `themes.set_site_theme`, `themes.admin_switch_flow`) is removed together
  with its binding.
- `magazine`/`minimal` code is deliberately unreferenced by routes; it stays
  compilable under the `ThemeFileSizeTest` cap but is excluded from the
  built-bundle CSS audit.
- The blog articles describing the four-theme era get a Production note
  (version bump to 1.1.0) pointing at this ADR.
- Canonical spec sync (`openspec/specs/starter-kit/spec.md`) goes through a
  follow-up OpenSpec change, not a direct edit.
