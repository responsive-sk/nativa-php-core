# ADR-0003: Themes as self-contained modules behind the ThemeKernel connector

- **Status:** Accepted (2026-10-02) — supplements ADR-0002 (theme set and
  selection are unchanged; this ADR fixes where theme code lives and how it
  is dispatched)
- **Tags:** themes, architecture, frontend, open-spec
- **Supplements:** [ADR-0002](0002-third-wired-theme-erik.md),
  [ADR-0001](0001-theme-architecture-for-2-themes.md)

## Context

After ADR-0002 the theme *set* was right — three wired themes, one setting —
but the theme *boundary* was not. A theme was a diff spread across the tree:

1. **`FrontendLayout` branched on theme names** — five `'erik' === $bundle`
   checks selected header/footer chrome, BEM prefixes and the critical-CSS
   fallback inside `Modules/Core`. Touching a theme meant editing the shared
   kernel — the opposite of a module boundary.
2. **Actions assembled theme pages** — `HomepageAction::erikHome()`,
   `BlogIndexAction::erikIndexPage()/erikSearchPage()` and the
   starter/moonshine siblings imported `Themes\*` section classes and glued
   hero + sections + widgets inline. The Blog/Core modules owned markup
   they should not know about.
3. **`ThemeRenderer` was a legacy interface** — array-shaped contracts with
   a `callable $t` parameter, no shell or asset ownership, not implemented
   by starter as a wired surface.

The result: a theme was not a unit you could copy, test or replace — it was
a diff. Adding one surface method meant edits in up to ten files.

## Decision

1. **Every wired theme implements `Themes\ThemeModule`** — the theme-level
   entry contract: `name()`, `bundle()`, `jsKey()`, `criticalCss()`,
   `themeInitScript()`, `prefix()`, `header(ShellContext)`,
   `footer(ShellContext)`, `pageWrap(string)`, and one rendering method per
   public surface (`homepage`, `blogIndex`, `blogSearch`, `blogArticle`,
   `blogTags`, `blogCategories`, `authorProfile`). Explicit named methods
   only — no `__call` (C-pattern guardrails).
2. **`ThemeKernel` is the single connector** (`Modules/Core/Interfaces/HTTP/ThemeKernel.php`)
   — a static match table mapping a resolved theme name to its module
   class, falling back to `PUBLIC_THEME`. It is the ONLY file under
   `src/Modules` allowed to import `Themes\`; `ThemeOwnershipTest` fails on
   any other kernel→theme edge.
3. **Kernel owns data, theme owns ALL markup.** The layout/actions compute
   `ShellContext` (menus, locale data, auth state) and per-surface
   `PageContext` payloads (typed named constructors); the module composes
   every HTML element — chrome, locale switcher, user link, page bodies.
4. **`FrontendLayout` is a theme-agnostic head/document connector** — SEO
   head (canonical, hreflang, OG, JSON-LD, feeds, CSP nonce placeholder),
   body class derived from the module (`<body class="{name}-shell">`), and
   chrome/assets read from the resolved module. Zero theme-name branches.
5. **Placement stays `src/Themes/<name>/`** — NOT `Modules/` DDD slices.
   Themes carry no routes, no migrations, no middleware, no DB; forcing a
   module-registration contract on them would be speculative scaffolding,
   and the Domain/Application/Interfaces layering makes no sense for pure
   presentation code. `magazine`/`minimal` stay unwired reference code.
   (That module-registration contract, `Modules\Core\Interfaces\HTTP\
   ModuleInterface`, had no implementor and no consumer — the kernel wires
   middleware through `#[AsMiddleware]` and migrations through
   `#[AsMigrator]` attribute discovery instead. It was removed rather than
   left as scaffolding; see `UnconsumedInterfaceTest`.)
6. **Output is byte-identical across the migration.** Acceptance bar for
   the move: no visual or markup change, only file ownership. Verified per
   extraction step during the refactor session; the permanent net is
   `ThemeModuleContractTest` (7 surfaces × wired themes + kernel
   invariants) plus the per-theme surface tests.

## Consequences

- Adding a wired theme = one directory under `src/Themes/` implementing the
  contract + one entry in `ThemeKernel::MODULES` (+ whitelist/resolver
  touch if it is publicly selectable). Removing one = delete the directory
  and the map entry.
- The one-way edge `Modules → Themes` is now guarded, not conventional:
  any new import outside `ThemeKernel` fails the architecture suite.
- `ThemeRenderer` (legacy interface) intentionally survives — the
  `Renderer.php` facades keep its six methods as one-line delegates. A
  later cleanup may fold it into `ThemeModule`; not done here because the
  delegates still serve as explicit seams for the section classes.
- Tests that asserted action-internal helpers (`BlogIndexTest` series
  helpers) follow the markup into the themes.
- Page-cache flush and theme switching behaviour are unchanged (ADR-0002
  decision 6 still applies).

## References

- [ADR-0001](0001-theme-architecture-for-2-themes.md) — two-theme decision,
  registry removal (selection points later superseded by ADR-0002)
- [ADR-0002](0002-third-wired-theme-erik.md) — third wired theme + setting
  selection
- OpenSpec change `openspec/changes/theme-as-module/` — proposal, design,
  delta spec
- `docs/theme-layout.md` — section-directory layout for theme files
