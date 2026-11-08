# Part 11 > the space the subset left out, and the fallback that needed it

**Boot 48.** Three rounds of font work left one question this round actually
paid for: *a subset was rebuilt to carry the Slovak letters, so why does a
heading still sit wrong on its line?* The file carried the letters and not the
space between them, and every number computed from that file inherited the
hole.

## What was read first

| read | what it settled |
|---|---|
| `storage/blog/optimalizacia-font-setov.md` | the only written claim about the subsets: `cmap 363` and a file named `-65efc7a5` |
| the three woff2 files, through `fontTools` | 249 / 250 / 254 codepoints, and which of them carries `U+0020` |
| `Frontend/packages/griffe/src/styles/fonts.css` | griffe loads the very files Boson ships, so its fallback numbers are the same numbers, not similar ones |
| `Frontend/packages/erik/src/erik/tokens.css` | a fallback derived from x-height, with the Chromium measurement that disproved it written above the block |
| `modules/Themes/Griffe/tests/GriffeBuildFreezeTest.php` | the ceiling the built stylesheet is held to, and the docblock that spells out the byte count |

The article was read as a claim, not as a contract: two of its numbers did not
survive the font table.

## What was measured

| measured | value |
|---|---|
| `roboto-condensed-400-c05481f9.woff2` | 249 codepoints, no `U+0020` |
| `roboto-condensed-400-99aca383.woff2` | 250 codepoints, `U+0020` at 470/2048 |
| `inter-400-0c9c05bd.woff2` | 254 codepoints, `U+0020` at 576/2048 |
| space width at 100 px, Chromium | webfont 22.953 px; the table says 470/2048 = 22.949 |
| the same space through `Roboto Condensed Fallback` | 24.625 px, 7 % wider, and still on the same line |
| line box from 280 to 560 px wide, webfont blocked | 192 / 154 / 154 / 154 / 115 / 115 / 115 / 115 px, identical to the loaded run |
| `public/dist/griffe/assets/griffe-KiqZ3wI6.css` | 25 426 B against a 27 000 budget; the two fallback rules are 204 B and 215 B of it |
| themes shipping a metric fallback | griffe, boson, erik; mono ships none because it ships no `@font-face` |
| JetBrains Mono against the mono fallback, 100 px in Chromium | 1080.188 px wide and 113 px tall either way |
| the same, from the tables | 0.6 em per glyph against Liberation Mono's 0.6001 em |

The fallback numbers, per theme and per face:

| theme | face | size-adjust | ascent | descent |
|---|---|---|---|---|
| griffe, boson | `Inter Fallback` | 107.6 % | 90.03 % | 22.42 % |
| griffe, boson | `Roboto Condensed Fallback` | 88.61 % | 104.7 % | 27.55 % |
| erik | `Gilroy Fallback` 400 | 101.06 % | 91.04 % | 23.75 % |
| erik | `Gilroy Fallback` 700–900 | 102.72 % | 92.10 % | 25.60 % |

`line-gap-override` is 0 % in all six, because every file involved has
`hhea.lineGap = 0`: the line box is the ascent plus the descent, and nothing
else.

## What the measurement changed

**The glyph, not the weights.** The repaired subset fixed the missing Slovak
letters and dropped `U+0020`, so a space in a Roboto Condensed heading was
painted by the next family in the stack, at its own width, while the letters
around it came from the webfont. The file is rebuilt with the space in it, and
the two subsets are now named in the article instead of described: `-c05481f9`
carried the letters, `-99aca383` carries the letters and the space.

**A fallback is a number, not a nicety.** A `font-display: swap` face paints
text in the local fallback first. Without a metric-matched face that local
font has its own widths and its own line height, so the swap moves every line
below it. Griffe loaded the same two files as Boson and had no fallback pair
at all, which is why the pair was copied with the reasoning rather than with
the values alone, and why the numbers are identical in both themes.

**Erik's fallback was derived from the wrong table.** The first block went by
x-height: size-adjust 94.64 %, ascent 97.21 %, descent 25.36 %. It matched the
line box and still reflowed. Measured in Chromium, 48 px in a 600 px box,
Gilroy set the sentence in four lines of 56 px and that fallback in three, so
the swap GREW the block by one line. The block is advance-based now, and there
is one face per weight: Gilroy Regular and Bold are 1.6 % apart in width, and
a shared face left the bold headings narrow enough that the browser
synthesised the weight and widened them again by an amount no font table
describes.

**The mono face needs no pair, and that is measured.** JetBrains Mono advances
0.6 em per glyph; Liberation Mono, the local face that answers on a Linux
desktop, advances 0.6001 em, so the two are 0.02 % apart and a `size-adjust`
for them would sit at 100 % and move nothing. Chromium agrees: the same string
at 100 px measures 1080.188 px wide and 113 px tall with the webfont and with
the request blocked. That is why `--font-mono` is the one griffe stack
without a `Fallback` family: an omission would be a defect, and this is the
measurement that makes it a decision.

**The budget moved with a reason.** Griffe's frozen stylesheet had a 23 000
byte ceiling from before it shipped the question and form blocks. It measures
18 243 B, 20 954 B and 25 426 B in the three states the freeze test records.
The fallback pair inside the last one is 204 B and 215 B, 419 B together, while
the rebuild that added them moved the file by 305 B, so the two numbers count
different things and the docblock in `nativa-themes` that said "the last 305 B"
was reading the delta as if it were the block. The ceiling is 27 000. The
number lives in `nativa-themes`, not here: the copy under `modules/Themes/`
is that repository's, and `make themes-check` fails on a hand edit. It is now
`identical=19 diverged=0` against `nativa-themes@8d4a813`.

**The article.** Its table counted 363 codepoints where the font reports 249,
because 363 is `maxp.numGlyphs` (the glyphs a subset keeps before `cmap`
prunes them), and the file it named was a URL no file on disk carried. Both
are corrected, and the paragraph that said griffe loads those two URLs today
is now a sentence about when it stopped.

## How it is held

- `GriffeBuildFreezeTest` holds the bundle to the ceiling and asserts that
  every `gr-*` class in the critical fallback exists in the build.
- `tests/Theme/FontPreloadTest.php` reads the `@font-face` urls the theme's
  inlined CSS declares and asserts each of them is preloaded once, before the
  stylesheet, and exists on disk. That is what holds the new URL: a rename
  that leaves the old file behind fails there.
- `SlovakProseContractTest` and the prose-rhythm contract hold the article to
  the glossary and to the line-break rule; the first draft of the corrected
  paragraph ended a line on `nie` and was rejected.

**What no test holds** is the font stack. Nothing in `modules/Themes/*/tests`
or `tests/Theme` reads a theme's `font-family` or its `size-adjust`, so a
stack that loses `Roboto Condensed Fallback`, or a fallback pair whose
percentages drift from the file, passes the whole suite. Nor does anything
read the space glyph itself: a rebuild that drops `U+0020` returns silently,
because the stylesheet still parses, the bundle still fits and the page still
looks almost right. Both are cheap tests and the cheapest open items in this
round.

## What the round did not close

- **No assertion that the shipped woff2 carries `U+0020`.** A test reading the
  `cmap` of `public/fonts/roboto-condensed-400-*.woff2` would have caught this
  round's defect before an article had to.
- **Boson's half lives elsewhere.** The critical stylesheet under
  `modules/Themes/Boson/` is a copy of `nativa-themes`; the same URL and the
  same budget had to be pushed there first, and this tree only received it
  through `make themes-sync`.
- **Mono is not measured, it is out of scope.** It ships no `@font-face` and
  sets `system-ui`, so there is no webfont to match a fallback to. If it ever
  vendors a face, it needs a block of its own.
