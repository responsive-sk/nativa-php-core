# Boot 74: a page tags only the script it renders markup for

The last round gave a page only its own stylesheet and left JavaScript
alone, because JavaScript had no split worth making: a theme shipped one
script and every surface tagged it.
Two themes made that expensive in two different ways.
Griffe's base entry carried the landing's two custom elements, and erik's
and jednotka's carried the author's Markdown editor.
Neither file had markup to act on anywhere but one surface.
This round moves both out, and the base entry becomes what every page can
use.

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

## The same defect in the editor

Erik and jednotka both imported `initInlineEditor()` into their base entry.
The editor is the author's toolbar and preview over an article's
`<textarea name="body">`, and that textarea exists only on an article the
reader may edit.
So a homepage, a listing and every ordinary page downloaded a Markdown
renderer, a toolbar and a preview panel with nothing to attach to.

The editor moved to each theme's article surface entry, which now imports
its own stylesheet instead of being a CSS-only input: one manifest row
carries the surface's CSS and its script.

| theme | surface | script bytes before | after |
|---|---|---|---|
| erik | page, home, listing | 22 322 | 10 176 |
| erik | article | 22 322 | 22 412 |
| jednotka | page, home, listing | 22 687 | 10 537 |
| jednotka | article | 22 687 | 22 773 |

Non-article surfaces drop 12 146 B on erik and 12 150 B on jednotka, 54 %
of the script they tagged.
An article pays 90 and 86 B more than before: the second entry repeats the
`DOMContentLoaded` wrapper, and that is the whole cost of the split.

`inline-edit.css` stays in the base sheet, which is a decision Boot 73 made
and this round does not revisit.

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
| `pnpm --filter @nativa/theme-erik build`, `... theme-jednotka build` | no `imports` key in either manifest, so neither entry depends on a shared chunk |
| `composer test modules/Themes/Erik/tests modules/Themes/Jednotka/tests` | 37 tests, 272 assertions |

## Still open

- Mono and nativa are unsplit. Nativa's 1 176 B is one TOC scrollspy that
  only an article renders, which leaves non-article nativa pages tagging a
  script with nothing in it; mono's nine behaviours would need a surface per
  behaviour measured first, and its bundle is frozen.
- The starter theme (`Frontend/packages/basic`) imports the same editor into
  its base entry. It is the kernel's own bundle rather than one of the six
  themes, and `config/page-budget.json` does not cover it, so this round
  measured it and left it alone.
- Naming the editor's entry in each theme rather than once is a copy of a
  name: `inline-editor.ts` is already kept byte-identical across four
  packages, and the surface entry is a fourth copy of that knowledge.
- `Frontend/packages/build/vite-theme-factory.ts` cites
  `bin/build/verify-build.php` as the thing that asserts entries carry no
  shared JavaScript. No such file exists in the tree, so until this round
  the rule was enforced by a docblock and, now, by the griffe test alone.
