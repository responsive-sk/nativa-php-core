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

## `evan70/nativa-themes`: the theme repository, measured 2026-10-21

Updated after the repository became the themes' home (`af593f7`, `134c79a`). It holds **224
tracked files** — 19 of them the theme modules — and two things, and the split is the point:

| path | what it is | edited there? |
|---|---|---|
| `modules/Themes/<Name>/` | the five kernel themes — the **source** | yes |
| `boson/`, `jednotka/`, `mono/`, `starter/`, `erik/`, `magazine/`, `moonshine/`, `font-set/` | the static gallery (60 pages) | generated, or hand-written against the theme's class names |
| `src/`, `build/` | the gallery's copies of `nativa-minimal`'s frontend sources | no — `pnpm run sync` deletes and recopies them |

A theme is edited there and arrives here as a copy: `bin/build/sync-themes.sh --check`
(`make themes-check`) compares `modules/Themes/<Name>/` by SHA-256 and names every differing
path — `missing`, `changed`, `only-here`, `theme-only-here`; `--write` (`make themes-sync`)
replaces the catalogue with the repository's copy. The gate has two directions on purpose: a
missing file is drift, and so is a file upstream no longer has.

`bin/cms module:add <Name>` installs the catalogue into `src/Modules/Themes/<Name>/`, exactly
as before — nothing about the tool changed. What changed is where a fix belongs.

**Why a script and not a submodule.** A submodule pins one commit, but `module:add` and
`make assets` need files, not a pin: every clone would still need `--recursive` before either
worked, and a missing initialisation reads as "no themes". The script needs the checkout only
when it runs, accepts both a sibling clone (`../nativa-themes`) and the CI location
(`.upstream/nativa-themes`), and its `--check` is one command with a non-zero exit. The cost is
that no commit id is written down anywhere — the catalogue's bytes *are* the pin, and `--check`
is what compares them. That is a weaker guarantee than a submodule's pin, so it has to run in
CI (Fáza 3.3), not only on the machine that remembers it.

The gallery stays at the repository root and is a **consumer**: `modules/` is not a pnpm
package and Vite reads only its explicit inputs, so nothing collides; moving the gallery into
`gallery/` would rewrite every input path, `publicDir`, the sync map and the Pages artifact
path for no benefit. Its own gate, `pnpm run sync:check`, protects the gallery's `src/`
against `nativa-minimal` — **it does not protect the five theme modules**, and `RULES.md`
says so in those words. `pages.yml` uploads `dist/` from the root and deliberately does not run
`sync:check` (the upstream checkout is private, so a runner could only report "skipped").

Coverage, measured: `boson`, `jednotka` and `mono` are **generated** — 7 surfaces × 2 locales =
42 documents, re-rendered by `bin/build/render-theme-pages.php`; `starter`, `erik`, `magazine`
and `moonshine` are hand-written; `font-set/` is one page. `griffe` has **no** page: its
homepage is composed from attributes by its own web components, so a static render is empty.
`erik` has pages but its gallery CSS is `nativa-minimal`'s full stylesheet, not this module's
355-line critical fallback — a gallery page is not evidence of what the module serves.
`minimal` has no build entry at all.

## Open, with the numbers that decide them

1. ~~**Does `Boson` belong to `nativa-minimal` or to this tree?**~~ Settled by the move: it
   lives in `nativa-themes/modules/Themes/Boson/` as a kernel theme, and the gallery renders
   its 14 pages from the theme itself. Its provenance is the repository that now owns it.
2. **Build source inside the module?** `4.1` of the plan. Until it is answered, a theme repo
   distributes a renderable theme that nobody can rebuild — and `make assets` says "OK".
3. **Fonts stay here.** 11 woff2 live in `public/fonts` and none under `Frontend/`; Gilroy is
   licensed and already excluded from the public gallery. A theme repo that also carried fonts
   would have to solve redistribution, which this tree has not.
