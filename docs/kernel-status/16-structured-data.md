# Boot 53: the blog's structured data leaves the kernel

The kernel carried a JSON-LD service for four Blog surfaces. Blog is the only
caller of three of them and the only caller of the fourth (`person`); only the
homepage's Organization + WebSite block is the kernel's own. The builders now
live with the module that renders the pages, and the kernel keeps the one block
it must still answer without a module.

## Before

- `src/Modules/Core`: **298 995 B / 61 files** (9 interface / 50 concrete).
- `KernelShapeTest::MAX_APPROVED`: **49**.
- Source suite: **1099 tests / 7272 assertions / 1 skipped**.

## What moved

| Surface | New owner |
|---|---|
| `article()`, `collection()`, `category()`, `person()` | `modules/Blog/Application/Services/StructuredDataService.php` |
| `website()` | `src/Modules/Core/Application/Services/WebsiteStructuredData.php` |

The Blog service is the moved file with its old `website()` method removed;
its article, listing, category and person builders are unchanged. The kernel's
replacement is deliberately smaller: `WebsiteStructuredData::website()` emits
the Organization + WebSite pair and the `SearchAction` the home surface already
advertised. It has no Blog knowledge left.

## Wiring

The four Blog actions (`AuthorProfileAction`, `BlogArticleAction`,
`BlogCategoriesAction` and `BlogIndexAction`) now import
`Modules\Blog\Application\Services\StructuredDataService`. `HomepageAction` and
Pages' `PageViewAction` use `WebsiteStructuredData`; Pages never needed the
article builders. No kernel file imports Blog, and no theme changes were needed.

The installed Blog and Pages slices were reinstalled after the catalogue edits,
so the installed tree and `modules/` agree.

## After

- `src/Modules/Core`: **294 208 B / 61 files** (9 interface / 50 concrete).
- `KernelShapeTest::MAX_APPROVED`: **49** (one runtime file replaced another).
- Source suite: **1099 tests / 7272 assertions / 1 skipped**.
- Fresh kernel-only checkout: **1059 tests / 3721 assertions / 867 skipped**,
  zero errors; `/` answers 200 with the Organization block, `/mark` answers 404.
- `make extract-check`: **199 identical / 89 recorded divergences**; the old
  Core path is `DROPPED` and `WebsiteStructuredData` is `OWNED`.
- `make lekalo-check`: **35 modules**.
- `make lint`, `composer phpstan`, `make analyze`, `make mago-lint` and
  `make mago-guard` are green.
- Export: **183 files / 169 tests / 439 assertions / 52 skipped**;
  `make core-export-check` is green.
- Source CI: **16/16 jobs**, run `42638787661` on `b40f30d`.
- Published export: `responsive-sk/nativa-core@567bb5c`, generated from source
  `b40f30d`; public CI is **9/9 jobs**, run `42639024978`.
- Published kernel-only clone: `/` = 200 with Organization + WebSite, `/mark` =
  404; `modules/Blog` is absent.

## Still open

- `ThemeResolver`, `ThemeKernel` and `ThemeRegistry` remain on the render path.
- `ContentServices` remains because every theme asks it for a locale URL prefix;
  moving it would change the theme catalogue, which is authored upstream.
- `ShellContextProviderRegistry` remains the kernel-owned discovery surface
  every layout reads through the shell port.
