# Boot 58: the shell port leaves the HTTP kernel

The shell port was the last kernel-owned discovery surface inside
`Modules\Core`: the registry that scans for providers, the attribute that
declares one, the interface a module implements, and the three value objects
the renderer passes to a theme. None of them dispatches a request.

All six moved to the new kernel-owned `Shell` namespace. The class and method
names are unchanged; every module and theme now imports the port from
`Shell\`.

This is the same boundary as the `Themes` and `View` rounds: the kernel owns
the contract and the values, a module answers through it, and no kernel file
names a module.

## Before

- `src/Modules/Core`: **209 629 B / 47 files** (9 interface / 36 concrete).
- `KernelShapeTest::MAX_APPROVED`: **36**.
- Source suite: **1099 tests / 7260 assertions / 1 skipped**.

## What moved

| Surface | Bytes | Old path | New path |
|---|---:|---|---|
| `ShellContextProviderRegistry` | 14 837 | `Core/Application/Services` | `src/Shell/ShellContextProviderRegistry.php` |
| `ShellContextProvider` | 5 534 | `Core/Domain/Contracts` | `src/Shell/ShellContextProvider.php` |
| `PageContext` | 10 998 | `Core/Interfaces/HTTP` | `src/Shell/PageContext.php` |
| `ShellContext` | 2 677 | `Core/Interfaces/HTTP` | `src/Shell/ShellContext.php` |
| `ShellNavItem` | 1 048 | `Core/Domain` | `src/Shell/ShellNavItem.php` |
| `AsShellContextProvider` | 808 | `Core/Infrastructure/Attributes` | `src/Shell/AsShellContextProvider.php` |

The rewritten files are 35 572 B together, 330 B less than the Core copies,
because the same-namespace imports and fully-qualified paths disappear.

## Wiring

`composer.json` gained the `Shell` PSR-4 root and `src/init.php` gained the
same prefix in its zero-dependency fallback, so the no-vendor boot can load
`Shell\PageContext` and `Shell\ShellContextProviderRegistry`. PHPStan,
PHP CS Fixer, `mago.toml` and `NameOwnershipContractTest` gained the new root.

The Mago perimeter gained a `Shell` rule that permits `Modules\Core` and
global functions only: the port reads kernel paths and values, and a module
is on the answering side of the port rather than a dependency. Core's permit
list gained `Shell\**`.

Imports changed in Core, every content module, the kernel theme, the theme
catalogue, the tests and `bin/build/render-theme-pages.php`. The theme
repository was edited in `evan70/nativa-themes` (`17ebe48`), synced here, and
verified with `make themes-check`: **23 identical, 0 diverged**.

`KernelShapeTest` lost five concrete allow-list rows (`ShellContextProvider`
is an interface and needed none), lowered `MAX_APPROVED` from 36 to 31, and
lowered its walk floor from 40 to 35 because Core now holds 39 PHP files.
`bin/build/kernel-divergence.txt` records `PageContext` and `ShellContext` as
`DROPPED` and removes the four `OWNED` rows for paths that no longer exist in
the compared slice; `src/Shell` is owned by this repository outright.

## After

- `src/Modules/Core`: **173 675 B / 41 files** (8 interface / 31 concrete).
- `KernelShapeTest::MAX_APPROVED`: **31**.
- Source suite: **1099 tests / 7251 assertions / 1 skipped**.
- Fresh kernel-only checkout, no `vendor/`: **1059 tests / 3700 assertions /
  867 skipped**, zero errors; `/` and `/sitemap.xml` answer 200, `/mark`
  answers 404.
- `make extract-check`: **187 identical / 101 recorded divergences**; the two
  upstream files that moved were already `DIVERGED`, so they became recorded
  drops without changing either count.
- `make themes-check`: **23 identical / 0 diverged** against
  `evan70/nativa-themes@17ebe48`.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- `make dist-check`: clean clone -> **200 files**, no `vendor/`, smoke clean.
- Export: **183 files / 169 tests / 406 assertions / 52 skipped**;
  `make core-export-check` is green.
- Source CI: **16/16 jobs**, run `42676943979` on `dc79fde`.
- Published export: `responsive-sk/nativa-core@bc65f64`, generated from source
  `dc79fde`; public CI is **9/9 jobs**, run `42677329306`. The public clone
  answers `/` and `/sitemap.xml` with 200 and `/mark` with 404.

## Still open

- `AdminRoutePolicy`, `Permissions` and `AdminUnavailableMiddleware` remain in
  Core because the kernel must answer the admin prefix without the Auth or
  Cardboard modules. They are the admin boundary, not a discovery surface.
- `FrontendLayout` still owns the document head, the reader layout and the
  theme-init script in one 39 KB static class. The move fixed ownership; the
  split is still a separate piece of work.
