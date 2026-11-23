# Boot 72: CSS and JS leave PHP

Thirteen files built CSS or JS inside PHP.
The bodies were heredocs in a method, `<style>` blocks in a panel template, or a
stylesheet concatenated from string fragments.
None of them was a file, so no editor, linter or cache could see them, and the
tokenizer scan that found them counted 19 tag bodies and 37 621 bytes.

## What was measured before

The scan ran 2026-11-23 over `src/**` and `modules/**`, skipping `tests/`,
installed `src/Modules/` copies and asset directories.
It removed comments, stripped `<?php ... ?>` output from templates, and counted
a file once.
It found 13 files:

| file | code in PHP | bytes |
|---|---|---|
| `modules/Site/Interfaces/HTTP/SiteHomepageSurface.php` | homepage CSS and diagram loader | 13 689 |
| `modules/Media/Interfaces/HTTP/templates/media-picker.phtml` | picker JS | 8 432 |
| `modules/Cardboard/Interfaces/HTTP/templates/compact.phtml` | critical CSS and `var recent` | 7 183 |
| `modules/Users/Interfaces/HTTP/AuthTemplate.php` | `pageCss()`, no tag | 4 300 |
| `modules/Cardboard/Interfaces/HTTP/templates/layout.phtml` | critical CSS and `var recent` | 2 771 |
| `src/Themes/FrontendLayout.php` | reader CSS and theme-init JS | 2 078 |
| `modules/Themes/Mono/Theme/MonoTheme.php` | three pre-paint scripts | 1 235 |
| `modules/Cardboard/Interfaces/HTTP/CardboardLayout.php` | `toast()` JS | 594 |
| `modules/Media/Interfaces/HTTP/templates/media-picker-target.phtml` | field picker JS | 537 |
| `modules/Themes/Griffe/Theme/GriffeTheme.php` | pre-paint script | 462 |
| `modules/Themes/Nativa/Theme/NativaTheme.php` | pre-paint script | 462 |
| `modules/Cardboard/Interfaces/HTTP/CompactLayout.php` | htmx config JS | 409 |
| `modules/Media/Interfaces/HTTP/templates/media-upload-form.phtml` | limits assignment | 124 |

`AuthTemplate::pageCss()` was the shape a tag-only scanner cannot see: a
complete rule set assembled from quoted fragments, never wrapped in `<style>`.

## What replaced it

Every body is an asset now, read through `View\InlineCss::file()` or
`View\InlineJs::file()`; the `<style>` and `<script>` tags stay in PHP, because
the CSP nonce placeholder is a PHP literal that the security middleware
replaces per response.
The assets total 36 142 bytes:

| asset | bytes |
|---|---|
| `modules/Site/Interfaces/HTTP/assets/styles/homepage.css` | 12 183 |
| `modules/Media/Interfaces/HTTP/assets/scripts/media-picker.js` | 7 906 |
| `modules/Cardboard/Interfaces/HTTP/assets/styles/mark-compact-critical.css` | 6 172 |
| `modules/Users/Interfaces/HTTP/assets/styles/auth.css` | 3 629 |
| `modules/Cardboard/Interfaces/HTTP/assets/styles/mark-critical.css` | 2 303 |
| `src/Themes/assets/critical/docs-reader.css` | 1 323 |
| `modules/Media/Interfaces/HTTP/assets/scripts/media-picker-target.js` | 529 |
| `modules/Cardboard/Interfaces/HTTP/assets/scripts/toast.js` | 496 |
| `modules/Cardboard/Interfaces/HTTP/assets/scripts/htmx-config.js` | 488 |
| `modules/Site/Interfaces/HTTP/assets/scripts/homepage-diagram.js` | 317 |
| `src/Themes/assets/scripts/theme-init.js` | 305 |
| `modules/Themes/Mono/assets/scripts/theme-variant.js` | 257 |
| `modules/Themes/Mono/assets/scripts/hero-staging.js` | 234 |

The CSS files read smaller than the PHP that built them because `InlineCss`
strips comments before inlining; the rules are unchanged.
`homepage.css` is the clearest case: 12 183 bytes on disk against 13 208 bytes
of CSS in the method, and the difference is the comment block that explained
the orb.

Dynamic values moved to `data-*` attributes instead of being spliced into a
function body: the picker's textarea id, index URL and upload URL; the upload
form's limits; Cardboard's `recent` list; the htmx CSRF token; the Site
diagram chunk; the target field's input id.
`CardboardLayout::script()` is still the generic tag wrapper, because Blog
passes it finished JavaScript, and that caller did not change.

## The gate

`tests/Architecture/NoCssJsInPhpTest.php` scans PHP and PHTML sources for both
shapes: a `<style>` or `<script>` body whose text survives the removal of PHP
output, and a string literal that is a stylesheet (two or more custom
properties inside braces).
It ignores JSON-LD, `style="..."` attributes and comments, and its fixtures
prove each direction.
The baseline was 13 and is now 0, asserted as an exact count: a new body fails
immediately, and a migration that is not reflected in the number fails too.

Two tests moved with the code rather than around it.
`SiteHomepageTest` now reads the diagram chunk from `data-diagram-chunk` and
asserts the module imports it from that attribute; the old assertion looked for
a literal URL inside the emitted script, which only an inline body could
produce.
`MarkClassContractTest` reads the two Cardboard critical stylesheets from
`assets/styles` now that the layouts no longer carry them.

## Measured after

`make lint`, PHPStan level 9, PHPUnit, both dry runs, `make mago-lint`,
`make mago-guard` and `make modules-install` were run on the finished tree.
The contract test reports 0 offending files over a scan of several hundred
sources, and its scanned-file floor keeps a green result from meaning "read
nothing".

## Still open

- `style="..."` attributes stay where they are. They carry per-element values,
  not stylesheets, and moving them would be a different change with a
  different risk.
- The Vite bundles under `public/dist` are unchanged. This round moved CSS and
  JS out of PHP; it did not merge the inline fallbacks into the bundle.
- No performance claim is made. The measured facts are file counts and bytes,
  not paint timings.
