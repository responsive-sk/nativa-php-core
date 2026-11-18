# Themes > where a theme lives and what a theme module must carry

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

Measured file sets (all six satisfy the contract today):

```
Boson     4 files  180 KB   Theme/BosonTheme.php, assets/critical/boson.css (2 906 r.),
                            assets/scripts/boson.js (498 r.), tests/BosonThemeTest.php
Erik      3 files   52 KB   Theme/ErikTheme.php, assets/critical/erik.css (355 r.), tests
Griffe    4 files   76 KB   Theme/GriffeTheme.php, assets/critical/griffe.css (281 r.), 2 tests
Jednotka  3 files   88 KB   Theme/JednotkaTheme.php, assets/scripts/theme-init.js, tests
Mono      5 files  115 KB   Theme/MonoTheme.php, assets/critical/{mono,mo-variants}.css, 2 tests
Nativa    4 files   68 KB   Theme/NativaTheme.php, assets/critical/nativa.css (862 r.), 2 tests
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

## The theme modules are authored here, measured 2026-11-18

Until 2026-11-18 the six themes were authored in `evan70/nativa-themes` and
arrived as a copy; `bin/build/sync-themes.sh` compared the bytes and
`make themes-check` was the gate. The catalogue and that repository were
byte-identical at the move (`identical=23 diverged=0`), so the import was
history only: the slices now live here, at `modules/Themes/<Name>/`, with the
theme repository's commits behind them, and its gallery came with them under
`themes/gallery/`.

The gallery is a consumer, not a second source: it renders the theme class
names and reads `nativa-minimal`'s frontend sources through `pnpm run sync`,
and `pnpm run sync:check` protects the gallery's `src/` against that upstream.
It does not protect the six theme modules, and it never did; `RULES.md` said so
in those words. The gallery's own gate is unchanged by the move.

What this changes for an author: edit `modules/Themes/<Name>/` here, reinstall
the slice (`php bin/cms module:remove <Name> --force` then
`php bin/cms module:add <Name>`) and let the theme tests run. There is no sync
step and no gate comparing two checkouts, because there is one checkout.

## Open, with the numbers that decide them

1. ~~**Does `Boson` belong to `nativa-minimal` or to this tree?**~~ Settled by the move: it
   lives in `nativa-themes/modules/Themes/Boson/` as a kernel theme, and the gallery renders
   its 14 pages from the theme itself. Its provenance is the repository that now owns it.
2. **Build source inside the module?** `4.1` of the plan. Until it is answered, a theme repo
   distributes a renderable theme that nobody can rebuild — and `make assets` says "OK".
3. **Fonts stay here.** 11 woff2 live in `public/fonts` and none under `Frontend/`; Gilroy is
   licensed and already excluded from the public gallery. A theme repo that also carried fonts
   would have to solve redistribution, which this tree has not.
