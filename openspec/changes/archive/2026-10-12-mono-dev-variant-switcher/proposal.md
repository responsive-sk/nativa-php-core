> ARCHIVED 2026-10-14 — implemented (ember/glacier variants,
> `mo-variant-switcher.js` and `MonoThemeTest` in tree). Migrated verbatim
> from `.ai-factory/plans/feature-mono-dev-variant-switcher.md`; do not
> edit, history only.

# Implementation Plan: Mono dev variant switcher

Branch: feature/mono-dev-variant-switcher
Created: 2026-10-12

## Original Request
super > vedeli by sme aplikovat aj id="dev-theme-switcher" pre mono varianty ?

## Settings
- Testing: yes
- Logging: verbose
- Docs: yes  # mandatory docs checkpoint at completion, routed through aif-docs

## Goal
Bring the upstream `navbar__actions` dev-preview switcher (evan70.github.io/mono) to the Mono
theme module: two variant buttons next to the dark/light toggle that re-skin the page through
an alternate brand palette. Custom Mono palettes (not upstream's neon/fire), always visible
like upstream, no build step — the same vendored no-build idiom as `mo-code-highlight.js`
and `mo-toc-scrollspy.js`.

## Constraints
- Mono has no Frontend package and no build: JS ships as vendored ES2015-safe files under
  `modules/Mono/assets/scripts/`, inlined through `InlineJs::file` with the CSP nonce
  placeholder, exactly like the highlighter and the scrollspy.
- Upstream variant stylesheets (`neon.css` 1092 B, `fire-show.css` 2865 B, fetched 2026-10-12)
  are `:root` variable overrides only — the port keeps that shape (no class prefixing needed),
  but with custom palettes and the webfont overrides dropped (no external dependencies;
  the system stack stays).
- The first button MUST carry `id="dev-theme-switcher"` (explicit request, upstream compat).
- Runtime serves the installed slice `src/Modules/Mono/` (gitignored output of `module:add`):
  after editing `modules/Mono/`, re-sync with `cp -r modules/Mono/. src/Modules/Mono/`
  (`module:add` refuses to overwrite) and confirm with `diff -r` before any live check.
- Persisted variant must apply pre-paint (no flash of the default palette).

## Decisions
- Two custom variants: `ember` (warm ember-orange on near-black) and `glacier` (ice-cyan).
  Concrete variable values are proposed in Task 1; the implementer finalises them.
- Selector contract: `html[data-variant="ember"]` / `html[data-variant="glacier"]` scoping
  `:root`-level overrides in a new `assets/critical/mo-variants.css`, appended to
  `MonoTheme::criticalCss()` (`InlineCss::files` concatenates the list).
- Buttons: `id="dev-theme-switcher"` (`data-variant-toggle="ember"`) and
  `id="dev-variant-switcher-2"` (`data-variant-toggle="glacier"`), class
  `mo-icon-btn mo-navbar__dev-toggle`, `aria-pressed` reflecting state; placed in
  `mo-navbar__actions` after the dark/light toggle, before the meatball label
  (the meatball stays the final control — pinned by an existing order test).
- Behaviour twin of upstream `theme-switcher.js`: mutually exclusive variants, second
  click toggles off, active button gets `mo-navbar__dev-toggle--active` (rule already in
  the snapshot), choice persisted to `localStorage` (`nativa-variant`), passive-safe
  immediate run (loader injects after content).
- `MonoTheme::themeInitScript()` stops returning `''` and returns the shared dark/light
  restore bytes PLUS a variant-restore snippet (the override replaces the shared block —
  it must reproduce it, not just append). No other theme's output moves.
- No-JS fallback: buttons are inert without JS, the page stays fully readable in the
  default palette (same contract as the TOC scrollspy).

## Open questions
- None blocking. Palette values are a design choice inside Task 1, not a plan risk.

## Commit Plan
- **Commit 1** (after tasks 1–4): "Mono navbar actions grow dev variant switcher"
- **Commit 2** (after tasks 5–6): tests, live verification, docs checkpoint
  (slice sync is local-only output, never committed)

## Tasks

### Phase 1: Variant stylesheets
- [ ] Task 1: Vendor the custom palettes as `modules/Mono/assets/critical/mo-variants.css`
  - Two blocks, `html[data-variant="ember"]` and `html[data-variant="glacier"]`, overriding
    only `:root` variables (`--brand-*`, `--color-bg*`, `--color-text*`, `--shadow-*`);
    propose concrete values, keep `--font-sans` on the system stack, keep base radii.
  - Append the file to `MonoTheme::criticalCss()`.
  - LOGGING REQUIREMENTS: none (static asset) — log nothing; verify by grepping the served
    `<style>` block for both selectors. Use log levels: N/A. Files:
    `modules/Mono/assets/critical/mo-variants.css`, `modules/Mono/Theme/MonoTheme.php`.

### Phase 2: Buttons + tracker + pre-paint restore
- [ ] Task 2: Render the switcher buttons in `MonoTheme::header()` (depends on 1)
  - `self::variantToggle()` helper emitting both buttons with the ids, data attributes,
    `aria-pressed="false"`, upstream lightning/flame SVGs (mo- prefix not needed inside
    SVG; classes `mo-navbar__dev-toggle`); order: dark/light toggle, variant buttons,
    meatball label.
  - LOGGING REQUIREMENTS: none (static markup) — log nothing. Files:
    `modules/Mono/Theme/MonoTheme.php`.
- [ ] Task 3: Vendor `modules/Mono/assets/scripts/mo-variant-switcher.js` (depends on 2)
  - IIFE, `'use strict'`, `var`-style, Chrome 70-safe; keys off `[data-variant-toggle]`;
    mutually exclusive `documentElement.dataset.variant`, `--active` class sync,
    `aria-pressed` sync, `localStorage` persist with try/catch, toggle-off on re-click,
    no-op when no buttons exist; loaded from `pageWrap()` UNGATED (navbar control lives
    on every page) via `InlineJs::file` + nonce placeholder.
  - LOGGING REQUIREMENTS: none in shipped JS (gated article scripts log nothing either);
    the node fake-DOM harness prints state transitions. Files:
    `modules/Mono/assets/scripts/mo-variant-switcher.js`, `modules/Mono/Theme/MonoTheme.php`.
- [ ] Task 4: Pre-paint variant restore in `MonoTheme::themeInitScript()` (depends on 3)
  - Return shared dark/light restore bytes verbatim + `nativa-variant` restore setting
    `documentElement.dataset.variant` (guarded try/catch, ignore unknown values).
  - LOGGING REQUIREMENTS: none (head inline script). Files:
    `modules/Mono/Theme/MonoTheme.php`.

### Phase 3: Verification
- [ ] Task 5: Tests in `modules/Mono/tests/MonoThemeTest.php` (depends on 2, 3, 4)
  - Buttons render with both ids + `data-variant-toggle` + order after the theme toggle;
    `mo-variants.css` selectors present in served critical CSS; init script contains the
    variant restore; node fake-DOM harness for the JS (activate A, switch to B, toggle
    off, persist calls).
  - LOGGING REQUIREMENTS: PHPUnit assertions only; harness prints transitions to stdout.
    Files: `modules/Mono/tests/MonoThemeTest.php`.
- [ ] Task 6: Live verify, gates, docs, commit (depends on 1–5)
  - Slice sync + `diff -r`; headless Chromium on a served Mono page: buttons in DOM,
    click path via CDP (node global WebSocket + `--remote-debugging-port`; fallback:
    fake-DOM harness + static render if CDP proves flaky), persistence across reload,
    no-flash spot check; `composer test`, `composer phpstan`, `composer cs-fix`,
    `composer rector-dry` all exit 0; docs checkpoint via aif-docs; commit per Commit Plan.
  - LOGGING REQUIREMENTS: verbose — log each gate's exit code; CDP session logs
    click/restore observations at INFO.
<!-- Commit checkpoint: tasks 1-4 -->
<!-- Commit checkpoint: tasks 5-6 -->
