# Boot 55: the theme runtime returns to the Themes namespace

The theme contract already lives in `src/Themes`: `ThemeModule`, the optional
`ThemeSettings` interface and `KernelFallbackTheme`. The resolver, the discovery
registry and the connector that maps a theme name to its implementation were
still in `src/Modules/Core`, so every module that ships a theme imported a Core
namespace even though it was talking to the theme contract.

The runtime and its three value objects now live with the contract. This is not
a module extraction: `src/Themes` is kernel-owned and ships whole, so the
kernel-only tree keeps rendering and no new module dependency appears.

## Before

- `src/Modules/Core`: **288 590 B / 59 files** (9 interface / 48 concrete).
- `KernelShapeTest::MAX_APPROVED`: **48**.
- Source suite: **1099 tests / 7269 assertions / 1 skipped**.

## What moved

| Surface | Bytes | Old path | New path |
|---|---:|---|---|
| `ThemeResolver` | 2 953 | `Core/Interfaces/HTTP` | `src/Themes/ThemeResolver.php` |
| `ThemeRegistry` | 7 813 | `Core/Interfaces/HTTP` | `src/Themes/ThemeRegistry.php` |
| `ThemeKernel` | 1 798 | `Core/Interfaces/HTTP` | `src/Themes/ThemeKernel.php` |
| `ThemePreference` | 1 523 | `Core/Domain` | `src/Themes/ThemePreference.php` |
| `ThemeSetting` | 3 207 | `Core/Domain` | `src/Themes/ThemeSetting.php` |
| `ThemeSettingValues` | 4 981 | `Core/Domain` | `src/Themes/ThemeSettingValues.php` |

The classes are the same code with the namespace changed to `Themes`. The
resolver still reads the kernel's `ShellContextProvider` port, `AdminRoutePolicy`
and `Request`; those edges are kernel-to-kernel and do not cross a module
boundary. `ThemeRegistry` still discovers installed `ThemeModule` classes, and
`ThemeKernel` still falls back to `KernelFallbackTheme`.

## Wiring

Every import in Core, the catalogue, the installed slices, `bin/`, the test
suite and `.github/workflows/ci.yml` now names `Themes\…`. The theme repository
was edited in `evan70/nativa-themes` (`b3ff468`), synced here; the catalogue
is byte-identical. There is no compatibility alias: the old
`Modules\Core\Interfaces\HTTP\Theme*` and `Modules\Core\Domain\Theme*` names are
gone, so a stale import fails loudly instead of loading a second copy.

`composer.json` already maps `Themes\` to `src/Themes/`; no autoload change was
needed. `KernelShapeTest` lost the six allow-list rows, and `MAX_APPROVED`
moved 48 -> 42.

## After

- `src/Modules/Core`: **266 093 B / 53 files** (9 interface / 42 concrete).
- `KernelShapeTest::MAX_APPROVED`: **42**.
- Source suite: **1099 tests / 7269 assertions / 1 skipped**.
- Fresh kernel-only checkout: **1059 tests / 3718 assertions / 867 skipped**,
  zero errors; `/` and `/sitemap.xml` answer 200, `/mark` answers 404.
- `make extract-check`: **198 identical / 90 recorded divergences**; the two
  old Core paths are `DROPPED` to `src/Themes`, and the registry plus the three
  value objects are `OWNED` under their new paths.
- `make themes-check`: **23 identical / 0 diverged** against
  `evan70/nativa-themes@b3ff468`.
- `make lekalo-check`: **36 modules**.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- Export: **183 files / 169 tests / 426 assertions / 52 skipped**;
  `make core-export-check` is green.
- Source CI: **16/16 jobs**, run `42645195537` on `c0d381b`.
- Published export: `responsive-sk/nativa-core@ba1945c`, generated from source
  `c0d381b`; public CI is **9/9 jobs**, run `42645476950`.
- Published kernel-only clone: `/` and `/sitemap.xml` = 200, `/mark` = 404; the
  shipped catalogue is `ContentLocale`, `Settings` and `Themes/Nativa`.

## Still open

- `ShellContextProviderRegistry` remains the kernel-owned discovery surface
  every layout reads through the shell port.
- The theme resolver still runs on the render path; it is now a kernel-owned
  service under the contract it serves, not a Core module member.
