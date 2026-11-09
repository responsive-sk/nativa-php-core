# Boot 56: the view helpers leave the HTTP kernel

`Modules\Core` was carrying the template mechanism as well as the HTTP kernel:
`ViewRenderer`, the template and asset path resolver, the inline CSS and JS
loaders, and the Vite manifest reader. Every module template and every theme
rendered through those files, but none of them dispatches a request.

The four view helpers moved to a new kernel-owned `src/View` namespace. The
manifest reader followed the theme contract to `src/Themes`: it resolves a
bundle from `ThemeRegistry` and `ThemeResolver`, so it belongs beside them.

This is not a module extraction. Both roots ship whole, the kernel-only tree
still renders, and the only new namespace is a directory plus a PSR-4 line.

## Before

- `src/Modules/Core`: **266 093 B / 53 files** (9 interface / 42 concrete).
- `KernelShapeTest::MAX_APPROVED`: **42**.
- Source suite: **1099 tests / 7269 assertions / 1 skipped**.

## What moved

| Surface | Bytes | Old path | New path |
|---|---:|---|---|
| `ViewRenderer` | 2 040 | `Core/Application/Services/View` | `src/View/ViewRenderer.php` |
| `Paths` | 2 198 | `Core/Application/Services/View` | `src/View/Paths.php` |
| `InlineCss` | 4 233 | `Core/Application/Services/View` | `src/View/InlineCss.php` |
| `InlineJs` | 1 346 | `Core/Application/Services/View` | `src/View/InlineJs.php` |
| `AssetManager` | 6 275 | `Core/Application/Services` | `src/Themes/AssetManager.php` |

The classes keep their names and behaviour. `Paths` still delegates to
`AppPaths`; the loaders still read the committed files; `AssetManager` still
falls back to the kernel bundle and still discovers module-carried bundles.

## Wiring

`composer.json` gained the `View` PSR-4 root pointing at `src/View/`. PHPStan,
PHP CS Fixer and `mago.toml` gained the same root,
`NameOwnershipContractTest` counts it, and the Mago perimeter gained a `View`
rule that permits the kernel and global functions but no module. Core gained
the `View` namespace in its permit list.

The imports changed in Core, the catalogue, all installed slices, tests,
`public/`, `bin/` and `.github/workflows/ci.yml`. The theme repository was
edited in `evan70/nativa-themes` (`e271fef`), synced here, and verified with
`make themes-check`: **23 identical, 0 diverged**.

There is no compatibility alias: the old Core view names now fail loudly
instead of loading a second copy.

## After

- `src/Modules/Core`: **249 691 B / 48 files** (9 interface / 37 concrete).
- `KernelShapeTest::MAX_APPROVED`: **37**.
- Source suite: **1099 tests / 7265 assertions / 1 skipped**.
- Fresh kernel-only checkout, no `vendor/`: **1059 tests / 3714 assertions /
  867 skipped**, zero errors; `/` and `/sitemap.xml` answer 200, `/mark`
  answers 404.
- `make extract-check`: **197 identical / 91 recorded divergences**; the five
  old Core paths are `DROPPED` to `src/View` or `src/Themes`.
- `make themes-check`: **23 identical / 0 diverged** against
  `evan70/nativa-themes@e271fef`.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- Export: **183 files / 169 tests / 421 assertions / 52 skipped**;
  `make core-export-check` is green.

## What the first CI run found

The first source run on `daed694` was red on exactly the shape this move
changed: the kernel-only boot and the dist artifact answered 500 without
`vendor/`, because `src/init.php`'s zero-dependency PSR-4 fallback knew
`Modules`, `Templates` and `Themes` but not the new `View` prefix. The PHPUnit
job passed because Composer's autoloader supplied the class; only the no-vendor
jobs could see the gap. `baac1ee` adds the `View` prefix to that map, and the
next run is **16/16 green** (run `42647635406`).

## Published

- Source CI: **16/16 jobs**, run `42647635406` on `baac1ee`.
- Published export: `responsive-sk/nativa-core@10dba50`, generated from source
  `baac1ee`; public CI is **9/9 jobs**, run `42648007691`.
- Published kernel-only clone: `/` and `/sitemap.xml` = 200, `/mark` = 404; the
  shipped catalogue is `ContentLocale`, `Settings` and `Themes/Nativa`.

## Still open

- `ShellContextProviderRegistry` remains the kernel-owned discovery surface
  every layout reads through the shell port.
- `FrontendLayout` is still the largest Core file; it owns the public shell and
  is the next inversion candidate rather than another directory move.
