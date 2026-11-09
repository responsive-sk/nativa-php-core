# Boot 57: the public shell leaves the HTTP kernel

`FrontendLayout` was the largest file left in `Modules\Core` and the one every
public action reached for. It renders the document head, resolves the manifest,
composes the active theme's chrome, and carries the docs reader layout. None of
that dispatches a request, and all of it depends on the theme runtime.

The class moved to `Themes\FrontendLayout`, beside `ThemeModule`, `ThemeKernel`
and `AssetManager`. Its nine critical CSS sheets and two inline scripts moved
with it to `src/Themes/assets/`. The namespace is the only API change; every
class and method name is unchanged.

This is not a module extraction. The renderer ships whole, the kernel-only tree
still renders, and no new autoload root was needed because `Themes` already
owned the theme runtime.

## Before

- `src/Modules/Core`: **249 691 B / 48 files** (9 interface / 37 concrete).
- `KernelShapeTest::MAX_APPROVED`: **37**.
- Source suite: **1099 tests / 7265 assertions / 1 skipped**.

## What moved

| Surface | Bytes | Old path | New path |
|---|---:|---|---|
| `FrontendLayout` | 39 623 | `Core/Interfaces/HTTP` | `src/Themes/FrontendLayout.php` |
| nine critical CSS sheets | 22 278 | `Core/Interfaces/HTTP/assets/critical` | `src/Themes/assets/critical` |
| `theme-toggle.js`, `docs-reader.js` | 5 120 | `Core/Interfaces/HTTP/assets/scripts` | `src/Themes/assets/scripts` |

The class file is 395 B smaller than before because the asset paths in its
constant and two calls are shorter. The CSS and JS bytes are unchanged.

## Wiring

The imports changed in Core (`HomepageAction`, `SitemapAction`,
`ErrorPageRenderer`), every content module, the theme catalogue, the tests and
`bin/build/render-theme-pages.php`. The theme repository was edited in
`evan70/nativa-themes` (`541fb59`), synced here, and verified with
`make themes-check`: **23 identical, 0 diverged**.

Two implicit references the old same-namespace position had hidden were found
by the first focused run: `FrontendLayout` used `ShellContext` unqualified, and
`ErrorPageRenderer` used `FrontendLayout` unqualified. Both now carry explicit
imports. The second is why the kernel theme's error-page test failed before the
fix; the failure was the test doing its job, not a regression in the move.

`KernelShapeTest` lost the `FrontendLayout` allow-list row and lowered
`MAX_APPROVED` from 37 to 36. `bin/build/kernel-divergence.txt` records the old
Core class and the ten upstream asset files as `DROPPED`; the moved files under
`src/Themes` are owned by this repository outright. The old `docs-reader.js`
`OWNED` row is gone because its new home needs no exception.

## After

- `src/Modules/Core`: **209 629 B / 47 files** (9 interface / 36 concrete).
- `KernelShapeTest::MAX_APPROVED`: **36**.
- Source suite: **1099 tests / 7260 assertions / 1 skipped**.
- Fresh kernel-only checkout, no `vendor/`: **1059 tests / 3709 assertions /
  867 skipped**, zero errors; `/` and `/sitemap.xml` answer 200, `/mark`
  answers 404.
- `make extract-check`: **187 identical / 101 recorded divergences**. Ten
  upstream asset files left the compared slice for the owned `src/Themes`
  tree, which is the whole 10-file shift; the old layout class was already a
  recorded divergence and is now a recorded drop.
- `make themes-check`: **23 identical / 0 diverged** against
  `evan70/nativa-themes@541fb59`.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- `make dist-check`: clean clone -> **199 files**, no `vendor/`, smoke clean.
- Export: **183 files / 169 tests / 416 assertions / 52 skipped**;
  `make core-export-check` is green.
- Published export: `responsive-sk/nativa-core@302c111`, generated from source
  `903f1d6`; public CI is **9/9 jobs**, run `42670542573`. The public clone
  answers `/` and `/sitemap.xml` with 200 and `/mark` with 404.

## Still open

- `ShellContextProviderRegistry`, `ShellContextProvider`,
  `AsShellContextProvider`, `ShellContext`, `PageContext` and `ShellNavItem`
  still sit in `Modules\Core`. They are the shell port and its values, not the
  HTTP kernel; they are the next coherent namespace move.
- `FrontendLayout` is still a 39 KB static renderer. The move fixed ownership,
  not its size; the reader layout and theme-init script are the two seams a
  later split would use.
