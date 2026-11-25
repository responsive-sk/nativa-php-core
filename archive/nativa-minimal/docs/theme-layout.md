# Theme File Layout (section directories)

No theme file may grow past **600 lines** — enforced by
`tests/Architecture/ThemeFileSizeTest.php` (architecture suite, part of
`composer test`). The legacy-monolith exemption list is **empty**: every theme
stylesheet ships as ordered section files reached through an `index.css` that
holds nothing but the `@import` chain, so the cap is an absolute rule rather
than a ratchet. A file that needs an exemption must be split instead, and the
guard fails on any baseline entry whose file no longer exists, so the list
cannot silently grow back.

**Wired themes:** the product ships `starter` (public, default), `erik`,
`moonshine` (**dual-role**: admin behind `/mark` AND publicly selectable),
`magazine` (public, selectable), `basic` and `jednotka` (public,
selectable), the public ones through the whitelisted `theme` setting in
`/mark/settings`. There is
deliberately **no fixed count** — a theme is a module (ADR-0003), so wiring
another one is an ordinary change, not an exception that needs its own ADR.

`basic` (`ba-`) and `jednotka` (`je-`) are **caricatures of `starter`**: the
same design under different prefixes.
It exists so the theme factory has a working example whose correct rendering is
already known — which is why it is carried through every registration point
(`ThemeResolver::PUBLIC_WHITELIST`, `ThemeKernel`, `AssetManager::knownBundles`,
the Vite factory, the parity guards, the form/comments generators, the pnpm
workspace) rather than special-cased in one of them. `jednotka` was produced by running the same factory from nothing but a name,
which is what proves the guards are derived from the runtime theme set rather
than from a list somebody remembers to update. See
`bin/generate/stamp-theme-base.mjs` for why their `src/base/` copies are stamped rather
than byte-pinned.

A dual-role theme resolves twice — `/mark/*` reaches it path-based, public
surfaces reach it through the setting — so its module must implement the
COMPLETE `ThemeModule` surface, chrome included (`moonshine`:
`Chrome/Chrome.php` for the topbar + footer, `Chrome/LocaleSwitcher.php` for
the `ms-locale` links). `minimal` is a **token vocabulary without markup**: no
`src/Themes/` source survives for it (no route could reach it), but it still
has its own package and builds into `public/dist/minimal/` so its CSS is held
to the same old-browser baseline and its accent tokens are audited from real
built output. The old
`ThemeRegistry`/`ThemeManifest` auto-discovery and the per-theme
`Manifest.php` files are removed — every wired theme implements the
`Themes\ThemeModule` contract (ADR-0003: `docs/adr/0003-theme-module-contract.md`)
and theme-aware actions resolve the active module through the single
`ThemeKernel::for($theme)` connector, the only file under `src/Modules`
allowed to import `Themes\` (guard `ThemeOwnershipTest`). The kernel owns
the data (`ShellContext`/`PageContext` payloads); the theme owns ALL markup.

## Layout per theme

Every theme under `src/Themes/{name}/` follows the same seams:

```
src/Themes/{name}/
├── <Name>Theme.php       # the Themes\ThemeModule implementation — the theme's single entry point
├── Shell.php             # facade — delegates chrome assembly to Chrome/*
├── Renderer.php          # static facade over Sections/ — NO __call
├── Chrome/               # shell internals
│   ├── Nav.php           # navHtml, userLink, sessionHasAuth
│   ├── Controls.php      # themeButton, chromeLabels, deferredCss
│   └── Scripts.php       # inline script tags (bodies from assets/scripts/*.js)
├── assets/
│   ├── critical/         # one .css file per section (tokens first, then layout, content, article…)
│   └── scripts/          # inline <script> bodies as .js files (theme-init, interaction)
└── Sections/             # page-surface markup
    ├── Support.php       # t()/formatDate/articleHref/section/layoutWithSidebar
    ├── Cards.php         # card/metaRow/grid/relatedGrid
    ├── Widgets.php       # sidebar/popular/tags/authorBio/cta
    ├── Hero.php          # hero/primaryButton/outlineButton
    ├── BlogIndex.php     # categorySections/blogIndex
    └── ArticleView.php   # articlePage/header/tags/pager/tocMobile
```

CSS bundles: `magazine.css` and `minimal.css` were split by their own
section comments into `src/magazine/*.css` and `src/minimal/*.css`
(tokens/shell/content/article/…); the `{name}.ts` entry imports the
`index.css` barrel. Vite still emits one hashed file per theme — the split
is source-only. (The `minimal` Vite entry is dropped since ADR-0001 — that
bundle stays as an audited token vocabulary even though its markup is gone.
Magazine was revived as a wired theme on 2026-10-02, so its entry is built
again: `magazine.ts` wires the mobile menu, back-to-top, the shared
scrollspy and the shared code highlighter.)

## Where to add a new surface

| What | Where |
|------|-------|
| New page-surface markup (e.g. a gallery page) | `Sections/` — new method on an existing class, or a new `Sections/*.php` class when the old one would exceed ~60 lines |
| New shell chrome (e.g. a banner) | `Chrome/Nav.php` or a new `Chrome/*.php` class |
| New critical CSS rules | the matching `assets/critical/*.css` section file, named after the **block kind**, not the page (byte-parity with the built stylesheet is tested; no dead duplicates allowed) |
| New stylesheet section | new file `src/{theme}/{section}.css`, imported from `src/{theme}/index.css` |
| Whole new theme | one directory under `src/Themes/` implementing `Themes\ThemeModule` (identity, assets, chrome, one method per surface) + one entry in `ThemeKernel::MODULES` (+ whitelist touch if publicly selectable); mirror the layout above; see `.agents/skills/moonshine-patterns/SKILL.md` §11 checklist |
| Which structural blocks the theme must provide | `docs/design-blocks.md` — the cross-theme block vocabulary (kinds, variants, surfaces); guard test `DesignBlockVocabularyTest` |

## Rules

- `Renderer.php` is an explicit facade over the `Sections/` classes — `__call` is
  banned by the architecture suite. The pre-module `Themes\ThemeRenderer`
  contract it used to implement is gone: two implementors, zero call sites, and
  a second array-shaped data path alongside the `Article` VO. One contract now
  (`ThemeModule`), one data shape. `UnconsumedInterfaceTest` is the net.
- **The theme module itself is a composition layer, not a page builder.** Every
  wired `<Name>Theme.php` holds identity (`name`/`bundle`/`jsKey`/`prefix`),
  asset wiring (`criticalCss`/`themeInitScript`), the shell arms
  (`header`/`footer`/`pageWrap`) and one short composition method per public
  surface — each a call into `Sections/`. `starter` moved from 562 lines to 292
  this way (`Sections/{Support,Hero,BlogIndex,Widgets,Chrome}`); `erik` is 227.
  A surface method that grows markup inline is a signal to push it into a
  `Sections/` class, not a reason to grow the module.
- `Sections/Article.php` is named `ArticleView.php` in erik to dodge the PHP
  8.5 import collision with `Entities\Article`.
- Critical-CSS sections live as `assets/critical/*.css` files, concatenated
  by the Core `View` helpers in the original section order; reordering or
  re-uniting rules causes post-flip layout shift, so parity tests guard it.
  One section file carries **one block kind from `docs/design-blocks.md`** —
  the erik chain is `tokens / shell / content / blog / widgets / sections /
  cards / controls / responsive / article`, and the naming follows the block
  vocabulary rather than the page that happens to show it. The shared
  `st-author-link` author line lives in `article.css`, not in a blog file,
  because it also renders on author profiles.
- **No dead duplicates in a critical chain.** A later file must never
  re-declare a selector that an earlier file in the same chain already sets at
  the same breakpoint: the later copy can only rewrite identical values.
  `CriticalCssByteParityTest::testErikCriticalChainDeclaresNoDeadDuplicate`
  enforces it for erik; the other chains audit clean (0 findings).
- **Critical CSS stays inline in PHP, not in the theme's `dist` bundle.** Three
  reasons, in order of weight:
  1. *It must not need a build.* `bin/build/build-dist.sh` and the production Docker
     image ship `src/`, so the chain is present in a dist built with
     `--skip-assets`. Moving it into `dist/<theme>/` would make the inline
     `<style>` block depend on a Vite run having happened.
  2. *It is inlined per response.* `InlineCss` reads the bytes per request with a
     static cache; a hashed `dist` URL would turn an inline block into a second
     render-blocking request — the opposite of what critical CSS is for.
  3. *It is a copy, not the source.* erik's chain is 30 kB against a 22 kB
     minified bundle and shares 168 of 169 selectors with it. It is the
     subset chosen for first paint, hand-maintained; deriving it mechanically
     from the bundle would need a real critical-CSS extractor — a dependency
     the zero-dependency runtime policy forbids.
- The 600-line cap counts PHP + CSS + TS under `src/Themes/**` and under every
  `Frontend/packages/<theme>/src` directory. `ThemeFileSizeTest::LEGACY_BASELINE`
  is empty. Adding a theme means adding a package: copy the layout above into
  `Frontend/packages/<theme>/`, build it through `createThemeConfig`, and add
  the bundle name to `AssetManager::BUNDLES`.
- Theme stylesheets are split the same way: `src/base/{tokens,chrome,home,article,toc,blog,cards,comments}.css`
  under the shared base, `src/moonshine/{chrome,content,article,toc}.css`, and
  `src/mark/{tokens,base,chrome,components,data,utilities}.css` for the admin
  design system. The `@import` chain preserves the original section order, so
  splitting leaves the built CSS byte-identical — verified by content hash.
- The base stylesheet is duplicated across the four packages that compose on it;
  `tests/Architecture/FrontendPackageParityTest.php` pins every section file
  byte-for-byte, so a fix applied to one copy fails CI until the others match.
- The three runtime helpers (`code-highlight.ts`, `container-query.ts`,
  `toc-scrollspy.ts`) are carried per public package the same way, and pinned
  byte-for-byte. A shared module would make Rollup extract a chunk, and a chunk
  turns every entry into an ES module that a classic deferred `<script>` cannot
  execute on the Chrome 70 baseline. Every one of them is now active in both
  public themes; only `container-query.ts` has no surface to drive yet (no
  erik surface emits `data-cq`).
- **A helper must not hardcode a theme's BEM prefix.** `toc-scrollspy.ts`
  shipped a `.st-article-toc__link[data-heading]` selector, so in erik it
  matched nothing: the TOC rendered correctly, looked correct, and never moved
  on scroll — the feature was simply absent there and no test failed. Links now
  opt in with `data-toc-link` (plus `data-heading` for the anchor), and the
  helper derives the active class from each link's own BEM block, so one copy
  of the bytes serves every theme. `tests/Integration/TocScrollspyContractTest`
  fails when a theme renders TOC markup without the opt-in attribute, when a
  link points at an anchor the page does not render, or when the theme's CSS is
  missing the `<block>--active` rule it would toggle. The second half matters
  as much as the first: toggling an unstyled class looks exactly like a broken
  scrollspy.
- Markup that opts in still needs a real scroll to be proven — the helper reads
  `getBoundingClientRect()`, a layout API, so a DOM-emulating test would pass a
  build where the listener never fires. `bin/checks/toc-scrollspy-check.mjs` drives a
  headless Chrome over CDP, scrolls the page, and asserts the active index
  actually moved:
  `node bin/checks/toc-scrollspy-check.mjs <url> <toc-link-class>`.
