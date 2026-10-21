# Themes — where a theme lives and what a theme module must carry

Measured 2026-10-21 on `master`. This page is the audit that `temy-v-nativa-themes.md`
(Fáza 0) asks for before any theme moves out of this repository. Nothing here is a
wish: every number is a command's output, and the command is named next to it.

## Four places, one theme

| place | what it holds | tracked? |
|---|---|---|
| `modules/Themes/<Name>/` | the **source**: `Theme/<Name>Theme.php`, `assets/**`, `tests/**` | yes |
| `src/Modules/Themes/<Name>/` | the **installed slice** — what the app loads | no (gitignored, `module:add` writes it) |
| `Frontend/packages/<theme>/` | the **build source** — Vite entry + CSS/TS, `outDir: public/dist/<theme>/` | yes |
| `public/fonts/` (+ `public/fonts/mark/`) | the **font bytes** — 11 woff2, **0** under `Frontend/` | yes |

A theme is installed by the same tool as any module — `php bin/cms module:add <Name>` copies
the catalogue slice; `module:remove` deletes it. There is **no `module.json` anywhere in the
tree**: `ModuleManifest` is derived from the module's own files (routes from `#[Route]`, a
theme from `implements ThemeModule`, assets from `assets/`, dependencies from `use`). A
directory in `modules/Themes/` is therefore a complete theme as far as `bin/cms` is concerned.

## Contract of a theme module

Everything below is enforced by a gate that already exists; a new theme cannot be added
without it.

| requirement | enforced by |
|---|---|
| class implements `Themes\ThemeModule`, is constructible with no required arguments, `name()` is non-empty | `ThemeRegistry::loadThemeClass()` |
| `name()`, `bundle()`, `jsKey()` agree and the bundle directory exists under `public/dist/` when built | `ThemeClassOwnershipTest`, `ThemeBundleDiscoveryTest` |
| every `criticalCss()` path resolves through `AppPaths::moduleFile()` | `ThemeAssetCompletenessTest` |
| the CSS a theme declares as preload is preloaded once, same-origin, `as="font"`, `type="font/woff2"` | `FontPreloadTest` |
| syntax highlighting ships in the package or the module, or the theme is listed in `WITHOUT_HIGHLIGHT` with a reason | `ThemeAssetCompletenessTest` |
| no import of a feature module enters the install closure | `KernelCouplingTest`, `ThemeClassOwnershipTest` |

Measured file sets (all five satisfy the contract today):

```
Boson     4 files  180 KB   Theme/BosonTheme.php, assets/critical/boson.css (2 906 r.),
                            assets/scripts/boson.js (498 r.), tests/BosonThemeTest.php
Erik      3 files   52 KB   Theme/ErikTheme.php, assets/critical/erik.css (355 r.), tests
Griffe    4 files   76 KB   Theme/GriffeTheme.php, assets/critical/griffe.css (281 r.), 2 tests
Jednotka  3 files   88 KB   Theme/JednotkaTheme.php, assets/scripts/theme-init.js, tests
Mono      5 files  115 KB   Theme/MonoTheme.php, assets/critical/{mono,mo-variants}.css, 2 tests
```

Two asymmetries the contract does **not** cover, and they are the reason a separate theme
repository is not free:

1. **`criticalCss()` is a fallback, not always the truth.** For `boson` and `mono` the module
   CSS is the served stylesheet; for `erik` and `griffe` it is a hand-written subset and the
   full stylesheet lives in `Frontend/packages/<theme>/src`; for `jednotka` it returns the
   kernel's nine sheets (`FrontendLayout::BASE_CRITICAL_CSS`), so the theme ships no CSS of
   its own.
2. **The build source is outside the module.** `module:add` copies the module only, so an
   installed theme is renderable but not rebuildable.

## What breaks when a theme is not installed

Command, in an isolated copy of the tree (`rsync` to `/tmp`, no `.git`), so nothing here
touches the working checkout:

```bash
for t in Boson Erik Griffe Jednotka Mono; do php bin/cms module:remove $t --force; done
./vendor/bin/phpunit
```

| | before | after removing all five |
|---|---|---|
| tests | 579 | 579 (theme tests live in the catalogue, so they still run) |
| assertions | 5 216 | 4 333 |
| skipped | 1 | **127** |
| failures | 0 | **1** |

Skipped, by class (`--log-junit`, 127 total): `BosonThemeTest` 31, `MonoThemeTest` 25,
`GriffeThemeTest` 21, `ErikThemeTest` 13, `JednotkaThemeTest` 13, `GriffeBuildFreezeTest` 8,
`MonoBuildFreezeTest` 7, `FontPreloadTest` 2, `ThemeClassOwnershipTest` 2, then one each:
`ModuleCatalogueShapeTest`, `KernelOnlyBootTest`, `PreferenceContentionTest`,
`ThemeBundleDiscoveryTest`, `LocalesShellContextProviderTest`.

The one failure is not about themes being absent — it is about the catalogue disagreeing with
the installed tree:

```
ModuleCatalogueShapeTest::theCatalogueAndTheInstalledTreeDescribeTheSameModules
A module is catalogued but not installed, or installed with no catalogue entry …
```

Consequence for any move: a synced catalogue **must** be followed by `module:add` (which is
already what CI's modules job does), or this gate fails — correctly. The other 126 skips are
the silent risk: a green build with fewer assertions, not a red one. `FontPreloadTest`,
`ThemeAssetCompletenessTest` and `ThemeClassOwnershipTest` all call `markTestSkipped()` when
`ThemeRegistry::all()` is empty, which cannot distinguish "kernel-only checkout" from "the
theme moved and nobody installed it".

## What breaks when a theme has no Frontend package

Same isolated copy, `Frontend/packages/boson` moved away and `public/dist/boson` deleted:

```bash
cd Frontend/packages && pnpm install --frozen-lockfile && pnpm build
```

| step | result |
|---|---|
| `pnpm install --frozen-lockfile` | **exit 0** — no lockfile complaint about the missing package |
| `pnpm build` (`pnpm -r --sequential run build`) | **exit 0**, 9 bundles instead of 10 |
| `public/dist/boson` | **absent** — silently, with no warning |

So the bundle is the only thing that disappears; PHP keeps rendering from the module's
`criticalCss()` and inline script. For `boson`/`mono` that is a degraded but correct page
(unminified CSS, no deferred `<script src>`); for `erik`, `griffe` and `jednotka` it would be
a page without its full stylesheet. A move therefore needs a gate that names the missing
package — a green `make assets` is not evidence that every theme was built.

## The gallery in `evan70/nativa-themes`

Measured at `b4084df` (117 files): a static gallery for GitHub Pages, **not** a theme source.
Its `RULES.md` states that `src/` and `build/` are copies written by `scripts/sync-themes.mjs`
from an app checkout (`NATIVA_MINIMAL`, default `../nativa-minimal`), and `sync --check`
compares a SHA-256 per file, so a hand edit fails `pnpm verify`.

What a "theme repository" would have to preserve:

- `vite.config.ts` lists **17 inputs by path** (`index.html` + 5 `starter/…` + 5 `erik/…` +
  5 `magazine/…` + `moonshine/index.html`), `publicDir: 'public'`, `outDir: 'dist'`,
  `base: './'` for Pages.
- `pages.yml` uploads `dist` from the repo root, and deliberately does **not** run
  `sync:check` (the upstream checkout is private, so on a runner it can only report
  "skipped").
- The sync map addresses the app root: `Frontend/packages/<theme>/src`, plus
  `Frontend/packages/build/strip-at-layer.ts` and `public/images/erik-hero-underlay.webp`.
  Gilroy is deliberately not copied (commercial licence, public repository).

Adding `modules/Themes/<Name>/` at the repo root **does not collide** with any of that
(`modules/` is not a pnpm package, and Vite reads only the listed inputs), so the gallery can
stay where it is. Moving it into `gallery/` would instead require editing all 17 input paths,
`publicDir`, the sync map and the Pages artifact path — cost with no benefit. What the gallery
cannot do is show a theme whose build source stayed behind in this repository, which is the
same asymmetry as above.

The gallery covers `starter`, `erik`, `magazine`, `moonshine`; it has no page for `boson`,
`griffe` or `jednotka`, and `minimal` has no build entry at all.

## Open, with the numbers that decide them

1. **Does `Boson` belong to `nativa-minimal` or to this tree?** The showcase's default is
   `starter`; `boson` appears in neither the showcase's package list nor the gallery. If it
   moves to a theme repo, its provenance has to be written down somewhere.
2. **Build source inside the module?** `4.1` of the plan. Until it is answered, a theme repo
   distributes a renderable theme that nobody can rebuild — and `make assets` says "OK".
3. **Fonts stay here.** 11 woff2 live in `public/fonts` and none under `Frontend/`; Gilroy is
   licensed and already excluded from the public gallery. A theme repo that also carried fonts
   would have to solve redistribution, which this tree has not.
