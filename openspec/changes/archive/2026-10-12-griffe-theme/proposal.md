> ARCHIVED 2026-10-14 — implemented (module `Griffe` in tree, build frozen
> in `public/dist/griffe/`, `GriffeBuildFreezeTest` green). Migrated verbatim
> from `.ai-factory/plans/feature-griffe-theme.md`; do not edit, history only.

# Implementation Plan: Griffe theme (webgriffe.com idiom)

Branch: feature/mono-dev-variant-switcher
Created: 2026-10-12

## Original Request
potrebujem aif plan pre novu temu podla > https://www.webgriffe.com

## Settings
- Testing: yes
- Logging: standard
- Docs: yes  # mandatory docs checkpoint at completion, routed through aif-docs

## Goal
New module-carried public theme `griffe` (module `Griffe`, prefix `gr`, bundle `griffe`)
that renders the kernel's public surfaces in the webgriffe.com agency idiom: sticky
text-nav header, FULL-SCREEN HOME SLIDER (editorial hero slide + case-study slides,
autoplay pagination with progress loader), scroll-down reveal effects (line-mask
text reveals), case-study card grid, minimal legal footer. Layout and motion first —
colours are explicitly NOT a priority (user: "farby nie su dolezite"). No-build
vendored idiom like Mono (critical CSS + ES2015-safe scripts through
`InlineJs::file`); no kernel edits except the Settings theme-select widening in
Task 7 (or an explicit scope-out).

## Constraints
- `ThemeModule` contract (`src/Themes/ThemeModule.php`) is fixed: name, bundle,
  jsKey, criticalCss, themeInitScript, prefix, header, footer, pageWrap, homepage,
  blogIndex, blogSearch, blogArticle, blogTags, blogCategories, authorProfile,
  errorContent — the theme must answer ALL of them.
- No imports from feature modules (Blog/Locales/Users): article shapes via
  `get_object_vars` (Erik/Mono precedent); data arrives in `ShellContext` /
  `PageContext` only.
- Discovery is automatic (`ThemeRegistry` scans `src/Modules` for
  `implements ThemeModule`; `ThemeKernel` falls back to it; `AssetManager`
  derives bundles from it) — no whitelist/registry/kernel-table edit needed to
  RENDER. Activation travels with the module's own `ShellContextProvider`
  fixture (`theme()` vote, priority 0, Mono precedent); never install two voting
  fixtures at once.
- Runtime serves the installed slice `src/Modules/Griffe/` (gitignored output of
  `module:add`): after editing `modules/Griffe/`, re-sync with
  `cp -r modules/Griffe/. src/Modules/Griffe/` and confirm with `diff -r`
  before any live check.
- `ThemeAssetCompletenessTest` is fail-closed: every `criticalCss()` path must
  exist on disk, and the theme must ship highlight markers (`tok-`/`Highlight`
  in module CSS/JS outside `tests/`) or be listed in `WITHOUT_HIGHLIGHT` with a
  reason. `ThemeBundleDiscoveryTest` covers bundle resolvability automatically.
- No external dependencies (fonts, CDN): system stack only (Mono precedent —
  webfont overrides dropped). Design values below are PROPOSALS measured from
  markup; the implementer finalises them against a screenshot (read_url strips
  stylesheets, so exact brand hexes were not recoverable from text).
- The reference is built on Xtend UI (Tailwind-CSS component library + vanilla
  JS: `data-xt-sticky` fixed-hide-on-scroll header, overlay/drop/slider
  components, `btn--link` idiom). The port does NOT vendor Tailwind or Xtend
  JS — no build, no CDN — it re-expresses the USED components in hand-written
  `gr-` CSS + ES2015-safe JS: `position: sticky` + hide-on-scroll for the
  header, checkbox hamburger instead of the overlay menu, plain anchor grid
  instead of the case slider. Xtend is the visual reference, not a runtime.

## Decisions
- Slug `griffe` / module `Griffe` / prefix `gr-` / bundle+jsKey `griffe`. If the
  user wants another slug, it is a rename-only change (paths + name() + fixture).
- Design DNA, measured from the live homepage HTML (fetched 2026-10-12):
  - Sticky fixed header (Xtend `data-xt-sticky`: fixed-always, hide-on-scroll-
    down): brand left (text wordmark; the SVG logo is theirs, not vendored),
    text links right — Work, Services, Blog, About, Contact — in the
    `btn--link` text-button idiom, + social icon links, checkbox-driven
    hamburger under ~768px (Mono meatball idiom, DOM order input → menu →
    label for the `~` selector). Hide-on-scroll is a ~20-line vendored
    `gr-header.js` twin (scroll listener + `.gr-header--hidden`), progressive
    enhancement over `position: sticky`.
  - Hero: editorial display H1 ("Tailored B2B E-commerce Platforms" shape) +
    lede paragraph + link-style CTA button; homepage maps `PageContext::$copy`
    (`title`, lede) and `$blogHref` onto it.
  - HOME IS A FULL SLIDER (measured: `.slider.slider--home > .slides >
    ul.slides_inner > li.slide[data-light][data-dark]`, 13 slides — slide 1 is
    the editorial hero `h1.h1-slider` + `h2.h2-slider`, slides 2–13 are case
    studies linking `/en/work/<client>`). Port as `gr-hero-slider`: viewport-
    height track, one active slide (fade/slide), per-slide theme tint via
    `data-` attributes, autoplay pagination (`.gr-pag` buttons with progress
    filler, the `data-xt-pag` + `.loader .filler` idiom), prev/next arrows,
    keyboard support. Articles map to case slides (title → client headline,
    slug → link); with no articles the hero slide still renders alone.
    No-JS fallback: slides stack vertically, all readable, pagination hidden.
  - Scroll-down effects (measured: `anim-block_outer > .anim-block >
    .anim-block_line + .anim-block_inner > span` line-mask reveals, 37× page-
    wide — headings reveal line by line on slide activation AND on scroll for
    below-fold blocks). Port as `gr-reveal`: headings split into masked line
    spans server-side, a ~30-line vendored `gr-reveal.js` (IntersectionObserver
    adds `.gr-in`; slide activation re-triggers it) drives the CSS transition.
    No-JS / reduced-motion fallback: text fully visible, no animation
    (`prefers-reduced-motion` also kills autoplay — static first slide).
  - Signature surface below the slider: case-study card grid — client name,
    service line ("B2B E-commerce & System Integration" shape), "Read More"
    link — fed by `$latestArticles`/`$articles` (slug+title via
    `get_object_vars`); empty state copy in-idiom ("Nothing published yet."
    equivalents).
  - Footer minimal: © line + year + site name, Privacy/Cookie/Legal Notices
    from `$footerNav`, `it`/`en`-style locale switcher (from
    `ShellContext::$availableLocales`, hidden when < 2).
  - Shared `[data-theme-toggle]` handler stays (one copy inlined by
    `FrontendLayout`); `themeInitScript()` returns `''` to keep the shared
    pre-paint bytes byte-identical (Mono override precedent NOT needed — no
    variant state; FrontendLayout documents why `''` keeps golden masters).
  - Palette proposal (implementer finalises): paper `#ffffff`, ink navy
    `#16294d` (markup shows `#173668`-family dark blues), accent coral
    `#f6533f` (`#f95941`/`#ff334b` family present in markup), muted `#626264`,
    line `#e8e6e1`; typeface Inter-referenced → system stack
    (`-apple-system, "Segoe UI", Inter, Roboto, sans-serif`). Dark mode via the
    shared `data-theme` flip with a `gr` dark palette override block.
- Highlight: vendor the Mono twin (`gr-code-highlight.js` + `tok-*` rules in
  `griffe.css`, `pageWrap` ungated load like `mo-code-highlight.js`) rather
  than a `WITHOUT_HIGHLIGHT` exemption — an agency theme showing code articles
  unhighlighted is a visible gap, and the twin is a known-good copy.
- `jsKey()` returns `griffe` with NO Frontend package and no manifest: `jsTag`
  degrades to empty and scripts inline via `InlineJs::file` (Mono precedent).

## Open questions
- Slug rename (`griffe` → e.g. `webgriffe`/`agency`)? Default `griffe`; rename
  is cheap before Task 1, expensive after (CSS prefix + tests).
- Should Task 7 (Settings select/save widening to registry themes) ship in this
  plan or split into its own? Default: ship here — without it the theme is
  votable but not clickable/saveable in admin (save falls back to kernel theme).

## Commit Plan
- **Commit 1** (after tasks 1–3): "feat: add griffe theme shell (header, hero slider, case grid)"
- **Commit 2** (after tasks 4–5): "feat: griffe blog surfaces, slider/reveal/highlight scripts"
- **Commit 3** (after tasks 6–8): "feat: wire griffe provider, settings select, tests and docs"

## Tasks

### Phase 1: Shell

- [ ] Task 1: Critical CSS `modules/Griffe/assets/critical/griffe.css` + `GriffeTheme::criticalCss()`
  - Token block (`:root` spacing/radius/neutral tokens — colours are
    placeholders, explicitly deprioritised), base reset, sticky header
    (brand/nav/social), FULL-SLIDER layout (viewport-height stage, absolute
    slides, active-slide fade/slide transition, pagination buttons + progress
    filler, arrows), line-mask reveal classes (`.gr-anim` masked line spans,
    `.gr-in` open state), case-card grid (3→2→1 columns), minimal footer,
    `tok-*` highlight rules for Task 5, `[data-theme="dark"]` overrides,
    `:focus-visible` states, `prefers-reduced-motion` guard (kills reveals +
    autoplay → static first slide).
  - `GriffeTheme` skeleton (`name/bundle/jsKey/prefix`, `criticalCss()`
    listing the file, `themeInitScript()` → `''`) implementing `ThemeModule`
    with remaining surfaces as plain in-idiom placeholders (replaced in
    Tasks 3–4, never another theme's class names).
  - LOGGING REQUIREMENTS: no runtime logging (static assets); log nothing.
    Verify with `ThemeAssetCompletenessTest::everyDiscoveredThemesCriticalCssExistsOnDisk`
    resolving the new path (exit status via `PIPESTATUS`, not output filter).
  - Files: `modules/Griffe/assets/critical/griffe.css`,
    `modules/Griffe/Theme/GriffeTheme.php`

- [ ] Task 2: Header + footer + pageWrap (depends on 1)
  - `header()`: sticky `gr-header` — brand link `/`, `gr-nav` from
    `headerNav` (`aria-current="page"` on active, `rel` on external, children
    flattened like Erik), locale switcher (hidden when < 2 locales),
    `data-theme-toggle` button, social links (omitted when nav provides
    none — no hardcoded URLs), checkbox hamburger (`gr-nav-toggle` input →
    menu → label order, Meatball/Close icons, `aria-label` on the input).
  - `footer()`: `gr-footer` — © year + site name, `footerNav` links,
    locale pair; `pageWrap()` → `<main class="gr-main">`.
  - `modules/Griffe/assets/scripts/gr-header.js`: vendored hide-on-scroll
    twin (Xtend sticky behaviour without the framework — `position: sticky`
    in CSS, JS only toggles `.gr-header--hidden`; no-JS fallback keeps a
    plain sticky header). Loaded ungated via `InlineJs::file` like the Mono
    twins.
  - LOGGING REQUIREMENTS: no runtime logging; invisible states (empty nav →
    no toggle, < 2 locales → no switcher) are covered by test asserts, not logs.
  - Files: `modules/Griffe/Theme/GriffeTheme.php`,
    `modules/Griffe/assets/scripts/gr-header.js`

- [ ] Task 3: Homepage full slider + case grid (depends on 1, 2)
  - `homepage()`: `gr-hero-slider` — slide 1 is the editorial hero (H1 from
    `$copy['title']` split into masked line spans, lede, CTA link to
    `$blogHref`), slides 2+ are case slides from `$latestArticles` ?:
    `$articles` (headline → client, no service field on the article shape →
    service line from `$copy['case_service']` fallback, "Read More" →
    `/blog/<slug>`); per-slide tint `data-gr-light`/`data-gr-dark` (neutral
    defaults, colours deprioritised); pagination nav (`gr-pag` buttons with
    filler spans, `aria-labels`, active `aria-current`) + prev/next arrows;
    `gr-slider.js` autoplay hook (`data-gr-autoplay`, default ~6s, pause on
    hover/focus, keyboard arrows). `gr-cases` grid below the slider reuses the
    same article mapping; empty-state paragraph when both lists are empty
    (hero slide still renders). No Blog imports. Progressive enhancement:
    pagination/arrows hidden until `gr-slider.js` adds `gr-slider--live` on
    the slider root (the theme cannot touch `<html>` — `FrontendLayout` owns
    it — so no `no-js` class trick; without JS the slides stay stacked and
    controls stay hidden by default).
  - `gr-slider.js` + `gr-reveal.js` vendored twins (ES2015-safe,
    `InlineJs::file` + nonce, ungated `pageWrap` injection): slider
    (autoplay/progress/pagination/arrows/keyboard, reduced-motion → static)
    and reveal (IntersectionObserver → `.gr-in`, re-triggered on slide change).
  - LOGGING REQUIREMENTS: log nothing per render; an article object missing
    slug/title is skipped silently (Erik `links()` precedent) — count of
    skipped items is assertable in tests, not logged.
  - Files: `modules/Griffe/Theme/GriffeTheme.php`,
    `modules/Griffe/assets/scripts/gr-slider.js`,
    `modules/Griffe/assets/scripts/gr-reveal.js`

### Phase 2: Surfaces + client behaviour

- [ ] Task 4: Blog family + article + errorContent (depends on 1)
  - `blogIndex` (grid + pagination via `$page`/`$totalPages`), `blogSearch`
    (`"Search: <query>"` heading, `$results`), `blogArticle` (`gr-article`:
    H1 + metaLine + cover + bodyHtml + tagChips + TOC `$headings` + views +
    commentsHtml — reuse Mono's article anatomy, renamed to `gr-`), `blogTags`
    / `blogCategories` (cloud from `$tags`/`$categories` + `$counts`, active
    term heading), `authorProfile` (name/avatar/memberSince + grid),
    `errorContent` (status + title + detail + home link, `gr-` classes only).
  - LOGGING REQUIREMENTS: no runtime logging; escaping via the theme's `e()`
    twin (untrusted title/detail/query), raw HTML fields (`bodyHtml`,
    `tagChips`, `commentsHtml`) passed through by contract.
  - Files: `modules/Griffe/Theme/GriffeTheme.php`

- [ ] Task 5: Client-behaviour twins + GriffeThemeTest (depends on 1, 2, 3, 4)
  - `modules/Griffe/assets/scripts/gr-code-highlight.js`: vendored twin of
    `mo-code-highlight.js` (ES2015-safe, `InlineJs::file` + nonce, ungated
    `pageWrap` injection, no-JS page stays readable); `tok-*` CSS already in
    Task 1 so `moduleShipsHighlight` detects it (no `WITHOUT_HIGHLIGHT` entry).
  - Slider/reveal behaviour proven headless: fake-DOM harness (node, no
    deps) for `gr-slider.js` (advance wraps, pagination click activates,
    autoplay pauses on hover, reduced-motion → no advance) and `gr-reveal.js`
    (observer adds `.gr-in`); `node --check` on all three scripts.
  - `GriffeThemeTest`: mirror Mono's order pins — checkbox/label `for`/`id`
    pairing, input → menu → label DOM order, no-toggle when nav empty,
    stylesheet keys open state off `:checked`; slider asserts (slide count =
    1 + articles, first slide is hero, pagination button count matches,
    masked line spans present, `data-gr-*` tints present); header/footer/blog
    smoke asserts (`gr-` vocabulary present, no `er-`/`mo-`/`st-` leakage),
    empty-grid state, escaping of title/query.
  - LOGGING REQUIREMENTS: test-only; on failure PHPUnit output is the log
    (no extra logging scaffolding in the theme).
  - Files: `modules/Griffe/assets/scripts/gr-code-highlight.js`,
    `modules/Griffe/tests/GriffeThemeTest.php` (slider/reveal scripts ship in
    Task 3; their headless harnesses live beside the test)

### Phase 3: Wiring, gates, docs

- [ ] Task 6: Provider fixture + slice sync + discovery proof (depends on 2)
  - `GriffeShellContextProvider` (`#[AsShellContextProvider(priority: 0)]`,
    answers `theme()` → `griffe`, abstains otherwise — Mono fixture twin) with
    the NEVER-two-voting-fixtures warning in its docblock.
  - Sync `cp -r modules/Griffe/. src/Modules/Griffe/`, `diff -r` clean;
    prove `ThemeRegistry::has('griffe')`, `ThemeKernel::for('griffe')` returns
    the theme, `AssetManager::knownBundles()` contains `griffe`;
    `ThemeBundleDiscoveryTest` + `ThemeAssetCompletenessTest` green.
  - LOGGING REQUIREMENTS: `ThemeRegistry` already logs unconstructable themes
    via `error_log`; the task adds no new log calls — a discovery miss shows
    in the test output naming the theme.
  - Files: `modules/Griffe/Interfaces/HTTP/GriffeShellContextProvider.php`

- [ ] Task 7: Settings theme select/save widen to registry themes (depends on 6)
  - `SettingsAction::themeSelect()` options = `PUBLIC_WHITELIST` +
    `ThemeRegistry::all()` keys (dedupe, `ucfirst` labels preserved);
    `saveSite()` validation accepts whitelist ∪ registry, still falls back to
    `PUBLIC_THEME` otherwise. Kernel-side test: select lists `griffe` when
    installed, save persists it (and still rejects unknown names). If the
    widening is rejected on review, scope-out alternative: document
    provider-vote activation and leave Settings kernel-only.
  - LOGGING REQUIREMENTS: keep the existing radius — no new log lines; the
    audit trail (`settings.save`) already records the change. Log levels
    unchanged. Verify via the new kernel test, exit status via `PIPESTATUS`.
  - Files: `modules/Cardboard/Interfaces/HTTP/Actions/SettingsAction.php`
    (+ its test)

- [ ] Task 8: Gates, browser verify, docs checkpoint (depends on 1–7)
  - `composer test` (exit 0; exit status via `${PIPESTATUS[0]}`), `composer
    phpstan`, Rector dry-run, `cs-fix` — fix causes, no weakened asserts or
    suppressions (the only allowed test-list change is a `WITHOUT_HIGHLIGHT`
    entry, and only if Task 5's twin is scoped out with a reason).
  - Chromium/CDP end-to-end against local `php -S`: slider auto-advances
    (active slide changes + progress filler runs), pagination click jumps,
    scroll triggers `.gr-in` reveals below the fold, dark/light toggle flips
    `data-theme` pre-paint without FOUC, hamburger opens at 390px, an article
    page highlights code, reload persists theme; slice `diff -r` clean
    before the check.
  - Docs checkpoint through aif-docs: `docs/modules.md` theme table row for
    Griffe (Manage-style entry: name, surfaces, assets, fixture, activation).
  - LOGGING REQUIREMENTS: standard — gate failures are reported with the
    failing command + first error block; CDP script logs each assertion
    (pass/fail + selector) at INFO-equivalent stdout lines.
  - Files: `docs/modules.md` (+ fixes wherever the gates point)
<!-- Commit checkpoint: tasks 1–3 -->
<!-- Commit checkpoint: tasks 4–5 -->
<!-- Commit checkpoint: tasks 6–8 -->
