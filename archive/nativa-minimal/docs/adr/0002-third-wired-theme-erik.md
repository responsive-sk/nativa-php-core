# ADR-0002: Third wired theme (erik) and admin-controlled public theme selection

- **Status:** Accepted (2026-09-29) — supersedes ADR-0001 on the theme-count
  and theme-selection points; amended 2026-10-02 twice (moonshine became
  dual-role, then magazine was wired — see “Amendment”)
- **Tags:** themes, architecture, frontend
- **Supersedes:** [ADR-0001](0001-theme-architecture-for-2-themes.md)
  (partially — see below)

## Context

ADR-0001 fixed the product at exactly two wired themes (`starter` public,
`moonshine` admin) and removed every selection mechanism that had accreted
around the four-theme dex era: `ThemeRegistry`/`ThemeManifest`, the
`?style=` query override, the `THEME` env var, the `site.theme` setting and
its select in `/mark/settings`. Two themes, one `match`, zero configuration.

The owner now wants a third wired theme — `erik`, a brand-new dark-first
public visual language — selectable without a deploy. Three themes crossed
the line where "no configuration" stops being simplicity and starts being a
constraint: a public theme choice is a product decision, not a build
artifact, and it belongs to the admin, not the release pipeline.

The choice is deliberate: a third **wired** theme (reachable from public
routes), not a third reference theme like the unwired `magazine`/`minimal`
dex experiments.

## Decision

1. **The wired set is three themes:** `starter` (public default),
   `moonshine` (admin, behind `/mark`), `erik` (public, selectable).
2. **Public theme selection is the whitelist-validated `theme` setting.**
   The `SettingsDatabaseMigrator` already seeds it
   (`['theme', 'starter', 'string', 'design', 0]`) — it has been dormant in
   `config.db` since ADR-0001 removed its reader. The setting is wired, not
   re-invented: `ThemeResolver` reads it for public surfaces with the
   whitelist `starter|erik` and falls back to `starter` on a missing or
   invalid value. No new key, no new migrator.
3. **`ThemeResolver` keeps its shape:** `/mark/*` → `moonshine`
   (path-based, unchanged — the admin chrome is not user choice),
   everything else → the `theme` setting. No query override, no env var —
   those stay dead as decided in ADR-0001.
4. **Theme-aware actions dispatch through a three-arm `match`** (starter /
   erik / defensive moonshine arm). The admin arm stays defensive: public
   routes never resolve to it.
5. **`erik` follows the section-directory layout** (`Shell.php`/`Renderer.php`
   facades, `Chrome/`, `Sections/`, `assets/critical/*.css`,
   `assets/scripts/*.js`), carries its own `er-*` BEM vocabulary and
   `--er-*` tokens, is dark-first with the shared `data-theme` toggle
   contract, and ships as its own hashed Vite entry.
6. **A `theme` setting change flushes the page cache** through the existing
   `CacheInvalidator` admin-write path — no second flush mechanism.
7. **`magazine`/`minimal` stay unwired reference code.** Unchanged from
   ADR-0001 — superseded for `magazine` by the 2026-10-02 amendment below;
   `minimal` still holds.

## Amendment (2026-10-02): moonshine is dual-role

The owner asked for `moonshine` in the `/mark/settings` theme select while
`/mark` keeps the moonshine UI. That is a change of moonshine's **role**, not
of the theme count: the wired set stays three.

**What changed:** `ThemeResolver::PUBLIC_WHITELIST` is
`['starter', 'erik', 'moonshine']`. `/mark/*` still resolves `moonshine`
path-based, so the admin chrome remains not a user choice.

**Why ADR-0002's phrasing was reading too narrowly.** Points 1 and 3 above
say “the admin chrome is not user choice” — true, and unchanged. They do not
claim moonshine has no public side, and the code agreed: all seven public
surface arms were already implemented, the shell markup existed, and the Vite
entry and critical-CSS chain were already built. What was missing was
reachability, plus two real defects left behind by the ADR-0003 theme-module
migration:

- `header()`/`footer()` returned `''` (the chrome stayed in the then-uncalled
  `Shell`), so a moonshine public page would have rendered bare content.
- `bundle()`/`jsKey()` returned the ADMIN entry keys `mark`/`main`. Because
  `AssetManager` maps a bundle onto a package dist directory, `mark` was not
  a valid bundle id and silently fell back to `starter` — a moonshine public
  page would have shipped starter CSS with nothing failing.

So the change gave moonshine a `PUBLIC_WHITELIST` seat, real chrome and the
correct bundle id. The count did not move: moonshine is the same theme,
now reachable from a second surface.

**Consequences:**

- A dual-role theme must expose the complete `ThemeModule` surface to both
  roles — asserted by `ThemeModuleContractTest`.
- `ThemeModuleContractTest::wiredThemes()` and
  `CriticalCssByteParityTest` follow moonshine's bundle id to `moonshine`;
  the byte-parity golden digest moved twice, both times for CSS this change
  added (the `ms-locale` rules, then `ms-toc__link--active`).
- The shared `theme-toggle.js` handler now binds every
  `[data-theme-toggle]` instance. Moonshine renders the control twice
  (sidebar foot + topbar actions); with the previous single-match binding
  plus moonshine's own handler, one click flipped the theme twice and the
  toggle looked inert. Guarded by `ThemeToggleHandlerTest`.
- The shared scrollspy and code-highlight helpers were duplicated into
  `Frontend/packages/moonshine/src/` so the public entry can call them
  without depending on another package — `FrontendPackageParityTest` keeps
  the copies byte-identical.

## Amendment (2026-10-02): magazine is wired (fourth theme)

The owner asked for “more themes to choose from” in `/mark/settings`.
`magazine` was the obvious candidate: the dex-era experiment that ADR-0001
left unwired, with a complete `mag-*` BEM vocabulary and a shipped Vite
package, but no `ThemeModule`, no `ThemeKernel` seat and no route that could
reach it. Point 7 above is therefore amended for `magazine` — the wired set
becomes four.

**What changed:**

- `ThemeResolver::PUBLIC_WHITELIST` is
  `['starter', 'erik', 'moonshine', 'magazine']`; the settings select renders
  from that same constant, so a resolvable theme is always an offered one.
- `ThemeKernel::MODULES` gains `MagazineTheme` — ADR-0003's connector stays
  the only file under `src/Modules` allowed to import `Themes\`.
- `src/Themes/magazine/` was restored from history and reshaped to the
  section-directory layout every wired theme follows: `MagazineTheme.php`
  (identity + assets + `header/footer/pageWrap` + one method per public
  surface), `Chrome/{Chrome,Nav,Controls}.php`, `Sections/{Home,Taxonomy,
  ArticleView,Cards,Index,Support,Widgets}.php` and nine critical-CSS files
  on top of the shared base chain.
- `magazine.ts` (its own hashed Vite entry, bundle id `magazine`, prefix
  `mag`) calls the mobile menu, back-to-top, the shared scrollspy and the
  shared code highlighter. `Sections/ArticleView.php` renders the
  `data-toc-link` opt-in the scrollspy queries.

**Why the wiring was not just a whitelist entry.** Two defects came with the
restored code, both invisible until a route reached it:

- `Chrome/Nav.php` and `Chrome/Controls.php` read the session through their
  own `sessionHasAuth()` helper — the themes must not touch session state
  directly; the flag arrives on `ShellContext`. Both now read the DTO.
- `assets/scripts/interaction.js` carried a second, byte-equivalent copy of
  the shared theme-toggle handler (and would have double-bound the toggle,
  the exact bug the moonshine amendment above describes). The magazine
  package reuses the Core-shared `theme-toggle.js`, and the duplicate file
  is gone.

`minimal` stays unwired reference code — it is a token vocabulary only, with
no server-side markup to give a block contract, so a row in
`docs/design-blocks.md` would document something no route renders.

**Consequences:**

- Every cross-theme guard gains a magazine column:
  `ThemeModuleContractTest` (header marker `class="mag-header"`, bundle
  `magazine`, prefix `mag`), `TocScrollspyContractTest` (`mag-toc__link`),
  `CriticalCssByteParityTest` (a golden digest over the nine-section chain,
  captured in the same commit) and `DesignBlockVocabularyTest`
  (`magazine` becomes a required matrix theme, nine new rows in
  `docs/design-blocks.md`).
- The byte-parity golden digests are re-captured, never edited by hand —
  `CriticalCssByteParityTest` fails on any unintended CSS change.
- `ThemesModelParityTest` compares the model enum against the runtime
  constants, so `lekalo/modules/themes/*.yaml` gained `magazine` in the same
  commit.

## Consequences

- The canonical spec requirements "Two wired themes…" and "Themes module in
  the two-theme Lekalo semantic model" are renamed to their three-theme
  wording through the OpenSpec change
  `2026-09-29-erik-third-wired-theme` (REMOVED + ADDED in one delta, trace
  anchors updated in the same commit).
- The Lekalo `themes` model gains the `erik` variant; the hardcoded runtime
  variant array in `ThemesModelParityTest` must change in the same commit
  as the `ThemeResolver` constants.
- The built bundle carries a third entry (`main`, `mark`, `erik`); the CSS
  audit covers erik's accent tokens in both dark and light modes from day
  one.
- `/mark/settings` regains a public-theme control (whitelist select), which
  ADR-0001 had removed together with the setting's reader.

## References

- [ADR-0001](0001-theme-architecture-for-2-themes.md) — the two-theme
  decision and registry removal (still valid for everything not named in
  the supersession)
- OpenSpec change `openspec/changes/2026-09-29-erik-third-wired-theme/`
- OpenSpec change `openspec/changes/2026-10-02-theme-as-module/` and
  `openspec/changes/2026-10-02-magazine-fourth-theme/`
