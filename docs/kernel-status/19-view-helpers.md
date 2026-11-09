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

`composer.json` gained `View\\` -> `src/View/`. PHPStan, PHP CS Fixer and
`mago.toml` gained the same root, `NameOwnershipContractTest` counts it, and
the Mago perimeter gained a `View` rule that permits the kernel and global
functions but no module. Core gained `View\\**` in its permit list.

The imports changed in Core, the catalogue, all installed slices, tests,
`public/`, `bin/` and `.github/workflows/ci.yml`. The theme repository was
edited in `evan70/nativa-themes` (`e271fef`), synced here, and verified with
`make themes-check`: **23 identical, 0 diverged**.

There is no compatibility alias: the old
`Modules\Core\Application\Services\View\…` names now fail loudly instead of
loading a second copy.

## After

- `src/Modules/Core`: **249 691 B / 48 files** (9 interface / 37 concrete).
- `KernelShapeTest::MAX_APPROVED`: **37**.
- Source suite: **1099 tests / 7265 assertions / 1 skipped**.
- `make extract-check`: **197 identical / 91 recorded divergences**; the five
  old Core paths are `DROPPED` to `src/View` or `src/Themes`.
- `make themes-check`: **23 identical / 0 diverged** against
  `evan70/nativa-themes@e271fef`.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.

## Still open

- `ShellContextProviderRegistry` remains the kernel-owned discovery surface
  every layout reads through the shell port.
- `FrontendLayout` is still the largest Core file; it owns the public shell and
  is the next inversion candidate rather than another directory move.
