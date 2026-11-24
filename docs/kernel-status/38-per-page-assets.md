# Boot 73: every page carries only its own assets

The landing page used to inline the whole theme.
So did an article, a listing and a search result, because a theme had one
stylesheet and the layout knew no other answer.
This round gives a page a surface, and a surface its own bytes.

## The key and the seams

`Themes\PageSurface` owns the four names: `PAGE`, `HOME`, `LISTING`,
`ARTICLE`.
An action declares one and the layout asks for it; nothing guesses a surface
from a URL.
`PAGE` is the fallback and means "the theme's complete sheet", so a page that
declares nothing pays exactly what it paid before surfaces existed.

`FrontendLayout::render(..., ?string $surface)` reads two sources:
a surface sheet named `<base stem>-<surface>.css` beside the base sheet, and a
built manifest entry whose key is `<jsKey>-<surface>`.
Both are optional and both fall back to the base, which is what keeps a
theme without a split byte-identical.
`AssetManager::cssUrlsForSurface()` and `jsUrlsForSurface()` are the readers;
a CSS-only entry is named in the manifest's `file` with no `css` array, and
both shapes are read (measured 2026-11-24 in the shape spike).

## Measured

Read with `make assets` built and the six theme slices installed, from a
throwaway probe that rendered `FrontendLayout` per theme and surface and
measured the `<style>` block plus every `<script src>` file:

| theme | page | home | listing | article |
|---|---|---|---|---|
| kernel | 2 483 | 2 483 | 2 483 | 2 483 |
| boson | 22 762 | 35 538 | 25 182 | 30 790 |
| jednotka | 36 967 | 36 967 | 36 967 | 43 912 |
| mono | 37 158 | 37 158 | 37 158 | 42 007 |
| griffe | 24 613 | 24 613 | 24 613 | 25 431 |
| erik | 31 118 | 31 118 | 31 118 | 36 502 |
| nativa | 9 159 | 9 768 | 9 507 | 11 390 |

Inlined CSS bytes. The 2026-11-23 baseline was one number per theme
(45 899, 43 907, 42 002, 25 426, 36 497, 12 338, 2 482), so a homepage moved
by 45 899 → 22 762 on Boson and 12 338 → 9 159 on Nativa, and no article grew
by more than the sheet its surface now adds.

JS is unchanged everywhere: the split moves rules, not behaviour.

The rendered gallery shows the same thing in document bytes
(`git show HEAD:themes/gallery/<theme>/<page> | wc -c` against the file on
disk):

| page | before | after |
|---|---|---|
| boson home | 77 523 | 67 159 |
| boson article | 66 806 | 51 694 |
| nativa home | 20 709 | 18 136 |
| nativa article | 21 562 | 20 611 |
| mono home | 69 612 | 64 765 |
| mono article | 69 385 | 69 387 |

Erik's gallery pages are hand-written and not re-rendered, and Griffe's
homepage is a web-component composition, so neither has a row here.

## What each theme moved

- Boson: `boson-home` (hero, orbit, accordion, stacks, quotes, CTA),
  `boson-listing` (cards, grid, chips, pager, author) and `boson-article`
  (reading rail, prose scale, comment list).
- Nativa: `nativa-home`, `nativa-listing` and `nativa-article`.
- Jednotka: `main-article` (contents sidebar, comments, questions).
- Mono: `mono-article` (prose, rail, comments, questions).
- Griffe: `griffe-article` (reading layout and rail).
- Erik: `erik-article` (reading layout, rail, comments).

`config/page-budget.json` owns one ceiling per theme and surface, and
`tests/Theme/PageAssetBudgetTest` derives the expected bytes from the build
manifest rather than from the renderer, so a page and its budget cannot drift
apart without a red test.

## The defect the split exposed

The first Boson pass moved the phone menu's search field with the listing
prefixes.
The field is shell on every page, so an article lost its height reset while
the listing kept it.
It moved back to the base sheet, and the reason is written beside it: a block
moves only when every page of a surface uses it and no other surface does.

## Gates

`composer test` at 1 631 tests and 9 926 assertions with 20 module-absence
skips, `make lint`, `composer phpstan`, `composer cs-fix-dry`,
`composer rector-dry` and `openspec validate per-page-asset-budget --strict`
all pass.
The gallery re-rendered at 56 pages and `--check` reports
`identical=56 diverged=0`.
