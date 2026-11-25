# Boot 74: the landing's script stops loading on every page

The last round gave a page only its own stylesheet and left JavaScript
alone, because JavaScript had no split worth making: a theme shipped one
script and every surface tagged it.
Griffe's script carried the two landing custom elements, and those two
elements were the reason the file was as large as it was.
This round moves them out of the base entry and lets the homepage tag a
second script.

## Where the bytes were

`Frontend/packages/griffe/src/griffe.ts` imported four things: three
self-gating behaviours (header, reveal, highlighter) and the two custom
elements `hero-layout` and `horizontal-scroll-hero`.
Only `GriffeTheme::homepage()` renders those elements.
An article, a listing and an ordinary page downloaded the code that
defines them, parsed it, and upgraded nothing, because the markup it looks
for was not in the document.

The split is clean by construction: the two elements import only
`utils/css`, and the three behaviours import nothing.
So two self-contained classic scripts can be emitted instead of one file
plus a shared chunk, which the deferred `<script>` cannot execute on the
Chrome 70 baseline.

`Themes\PageSurface` already knew about surface scripts:
`AssetManager::jsUrlsForSurface()` tags `<jsKey>-<surface>` when the
manifest names it, and falls back to the base entry when it does not.
The landing entry is therefore a build input named `griffe-home`, and no
PHP changed.

## Measured

`make assets` is not what rebuilt this bundle; the package did, with
`pnpm --filter @nativa/theme-griffe build`, and the frozen `public/dist/griffe/`
directory is what it wrote.

| entry | bytes | gzip -9 |
|---|---|---|
| `griffe.js` before | 28 889 | not measured |
| `griffe.js` after | 9 439 | 3 699 |
| `griffe-home.js` (homepage only) | 19 450 | 5 637 |

Per surface, from the renderer:

| surface | script bytes before | after |
|---|---|---|
| page | 28 889 | 9 439 |
| listing | 28 889 | 9 439 |
| article | 28 889 | 9 439 |
| home | 28 889 | 28 889 |

The homepage pays exactly what it paid before, split across two files, and
every other surface drops 19 450 B, which is 67 % of the script it used to
tag.
The `js` columns in `config/page-budget.json` record the new numbers, and
the file's `measured` date moves to 2026-11-25 because this is the round
that re-measured every row through the suite.

The other five themes are recorded here and deliberately unchanged:
boson 11 255, erik 22 322, jednotka 22 687, mono 17 875, nativa 1 176.
Erik and jednotka carry the Markdown inline editor in the same bundle
every public page tags, which is a larger question than a split: the
editor belongs to an admin surface, not to a page surface.

## The test that could not have caught it

`PageAssetBudgetTest` measures a page's CSS against the manifest byte for
byte, but its JavaScript check was `js < ceiling`.
A homepage that stopped tagging `griffe-home.js` would have measured
9 439 B, stayed under the ceiling and passed.
The homepage case now compares the tagged scripts with the manifest's base
entry followed by its home entry, the same shape the article case already
had, so a lost landing script is a failure and not a saving.

`GriffeBuildFreezeTest::theFrozenScriptIsASingleFileWithNoModuleSyntax`
read the base entry alone, which was every script while there was one
script.
`everyFrozenScriptEntryIsAClassicScript` now walks the manifest and
asserts the no-import, no-export rule on each `.js` entry it names, so a
shared chunk leaking into the second file fails.

## Gates

| gate | result |
|---|---|
| `composer test tests/Theme/PageAssetBudgetTest.php` | 6 tests, 73 assertions, 1 skip (the pre-existing fallback case) |
| `composer test modules/Themes/Griffe/tests/GriffeBuildFreezeTest.php` | 9 tests, 53 assertions |
| `pnpm --filter @nativa/theme-griffe typecheck` | clean |
| `composer phpstan`, `composer cs-fix-dry` | clean |
| live server | `/dist/griffe/assets/griffe-U-QwI81-.js` 200 at 9 439 B, `/dist/griffe/assets/griffe-home-Dy3-WO6T.js` 200 at 19 450 B |

## Still open

- The other five themes are unsplit, and nativa's script is 1 176 B of TOC
  scrollspy that no listing or homepage uses.
- The inline editor in `erik` and `jednotka` is tagged on public pages and
  has no markup to act on there. That is a surface question the module
  contract does not answer yet.
- `Frontend/packages/build/vite-theme-factory.ts` cites
  `bin/build/verify-build.php` as the thing that asserts entries carry no
  shared JavaScript. No such file exists in the tree, so until this round
  the rule was enforced by a docblock and, now, by the griffe test alone.
