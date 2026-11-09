# Boot 54: the content-locale rule leaves the kernel

`ContentLocaleMapper` is the shared rule for the default locale, a content
family's storage directory and its URL prefix. `ContentServices` was its lazy
factory for actions and themes. Both were kernel-owned because every theme
asked them; the themes are now edited upstream, and the rule lives in
`modules/ContentLocale` with no routes, tables or admin screen.

## Before

- `src/Modules/Core`: **294 208 B / 61 files** (9 interface / 50 concrete).
- `KernelShapeTest::MAX_APPROVED`: **49**.
- Source suite: **1099 tests / 7272 assertions / 1 skipped**.

## What moved

| Surface | Bytes | New owner |
|---|---:|---|
| `ContentLocaleMapper` | 4 366 | `modules/ContentLocale/Application/Services` |
| `ContentServices` | 1 551 | `modules/ContentLocale/Interfaces/HTTP/ContentLocaleServices` |

The mapper is the same rule, with its docblock corrected to name the module.
The factory keeps the cached instance and the `reset()` seam, but now asks the
kernel's `ShellContextProvider` port for the default locale. The module has
two files and no storage; `lekalo/modules/content_locale/module.yaml` records
it in the model.

## Wiring

Every content module (Blog, Docs, Forms, PageCache, Pages, Questions) and every
theme now calls `ContentLocaleServices::mapper()`. The theme catalogue was
edited in `evan70/nativa-themes` (`f956a99`), synced here with `make
themes-sync`, and verified with `make themes-check`: **23 identical, 0
diverged**. `KernelTheme` keeps a `class_exists` fallback, so a kernel-only tree
without ContentLocale still renders its locale links.

`HomepageAction` no longer names a mapper: it reads
`ShellContextProviderRegistry::defaultLocale()` and falls back to `sk_SK` when
no provider is installed. `SitemapAction` lists `/` plus the locale roots the
shell port reports, so a kernel with no locale provider no longer advertises a
path it does not serve. Neither file imports the module, and `mago-guard`
stays green.

The minimal export now carries three modules: `Themes/Nativa`, `Settings` and
`ContentLocale`. The module is the theme's locale dependency, not an admin
stack: it adds two files and no database.

## After

- `src/Modules/Core`: **288 590 B / 59 files** (9 interface / 48 concrete).
- `KernelShapeTest::MAX_APPROVED`: **49 -> 48**.
- Source suite: **1099 tests / 7279 assertions / 1 skipped**.
- Fresh kernel-only checkout: **1059 tests / 3728 assertions / 867 skipped**,
  zero errors; `/` and `/sitemap.xml` answer 200, `/mark` answers 404.
- `make extract-check`: **198 identical / 90 recorded divergences**; both old
  Core paths are `DROPPED` with `modules/ContentLocale` named.
- `make lekalo-check`: **36 modules**.
- `make themes-check`: **23 identical / 0 diverged** against the local theme
  repository.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- Export: **183 files / 169 tests / 436 assertions / 52 skipped**;
  `make core-export-check` is green.

## Still open

- `ThemeResolver`, `ThemeKernel` and `ThemeRegistry` remain on the render path;
  they are the theme-discovery contract the kernel renders through.
- `ShellContextProviderRegistry` remains the kernel-owned discovery surface
  every layout reads through the shell port.
