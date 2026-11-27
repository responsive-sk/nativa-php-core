# Boot 75: the kernel has one home

`README.md` has said for a long time that the kernel is `Modules\Core` and
`Modules\Database` and that everything else is a module.
`src/` did not say that.
Five kernel-owned namespaces sat beside `src/Modules`: `Discovery` with two
files, `Seo` with five, `Shell` with eleven, `Themes` with fourteen and `View`
with four.
None was an accident.
`docs/kernel-shape.md` records rounds 9 to 14 moving them out of `Modules\Core`
so that the HTTP kernel would stop carrying theme, shell and view code.
The rounds did what they said and left the tree with two taxonomies, only one
of which the declaration named.
This round chooses the declaration: all 36 files return to `Modules\Core`, and
`src/` is `Modules` plus the three entry files.

## Where the paths went

The target path restores the pre-round location wherever the movement table
records one.
That table is why 21 of the 36 target rows already existed: the baseline
inventory never deletes a row, and the classes were heading home to it.
The remaining fifteen, created after those rounds, took the layer matching
their role: the sitemap port in `Domain\Contracts`, its registry in
`Application\Services`, the fallback theme under `Interfaces\HTTP\Theme`.

Short class names do not change, so every catalogue import changes its
namespace only.
The five PSR-4 roots leave `composer.json`, `src/init.php`, `phpstan.neon`,
`mago.toml`, `.php-cs-fixer.dist.php` and `rector.php`, and the perimeter keeps
three rules instead of eight, because `Modules\Core` now contains what the
other five rules described.

Two seams needed behavior, not a rename.

`ThemeRegistry` walks every PHP file under `src/Modules` and loads classes that
implement `ThemeModule`.
`KernelFallbackTheme` implements it and used to sit outside that tree on
purpose, so the move would have registered the fallback as a selectable theme.
The registry now skips paths inside `Modules\Core`.
`Modules\KernelTheme` names its theme `kernel` as well, so the test asserts the
discovered class is never the fallback rather than that the key is absent.

The critical sheets and inline scripts are read through `src/`-relative
strings, not `__DIR__`.
Ten CSS files and three scripts moved from `src/Themes/assets` to
`Modules/Core/Interfaces/HTTP/assets`, and the prefix changed at every call
site, including the three catalogue themes that inline `theme-init.js`.

## Measured

`make kernel-size` after the move:

| figure | before | after |
|---|---:|---:|
| total | 313 459 B / 64 files | 495 767 B / 100 files |
| Core types | 8 interface / 28 concrete | 15 interface / 57 concrete |
| `MAX_APPROVED` | 28 | 56 |

The total rises because the measurement covers the kernel slice and the 36
files are kernel code again; nothing was added to the tree.
`KernelShapeTest` finds 56 concrete classes and approves 56.
`make kernel-size` reports 57, one more than the allow-list, because its
counter treats an abstract declaration as concrete while the shape test reads
the token kind first.
The ceiling moved with the same commit as the 29 rows, as the ratchet requires.

## What stayed

Rendering, routing, sitemap output, asset bytes and the module lifecycle are
unchanged.
The catalogue was re-installed, the kernel-only boot answers `/` 200 and
`/mark` 404, and the export profiles name the fallback theme at its new path.
No new runtime dependency, make target or CI job was added: the new guard,
`tests/Architecture/KernelRootLayoutTest.php`, runs inside `composer test` and
fails on a directory or root PHP file that is not the declared entry surface.

The four gates that decide whether this is landable were run together:
`make lint`, `composer phpstan`, `composer cs-fix-dry`, `composer rector-dry`,
plus `make mago-lint` at zero.
`NameOwnershipContractTest` was already above its literal ceiling before the
round (506 against 495, the same number on the commit this started from); the
fold adds no literal place and leaves the ceiling alone.
