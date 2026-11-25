/**
 * main.ts — the jednotka frontend entry.
 *
 * `jednotka/index.css` is this theme's OWN design system, not a stamped copy
 * of the starter base: `bin/generate/stamp-theme-base.mjs` derives a caricature's
 * stylesheet, and this theme is not a caricature. Nothing under `src/jednotka/`
 * inherits a rule from starter's base.
 *
 * Imported CSS is bundled and emitted alongside the JS; the PHP layout
 * resolves both via the Vite manifest (AssetManager).
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

import './jednotka/index.css';
// The form block is per theme, not a shared base section — see form.css.
import './form.css';
// The comment and reader-questions blocks are article-only: they live in
// `src/main-article.css`, the surface entry, not in this base bundle.
// The owner-edit affordance is per theme too — see edit-link.css.
import './edit-link.css';
// The inline Markdown editor is module markup with this theme's prefix.
import './inline-edit.css';
import { containerQueryManager } from './container-query';
import { initTocScrollspy } from './toc-scrollspy';
import { initCodeHighlight } from './code-highlight';

// The author's Markdown editor is NOT here: `main-article.ts` holds it,
// beside the stylesheet of the only surface that renders its textarea.

// ── PWA manifest ───────────────────────────────────────────────

const injectManifest = (): void => {
  if (document.querySelector('link[rel="manifest"]') !== null) return
  const link = document.createElement('link')
  link.rel = 'manifest'; link.href = '/site.webmanifest'
  document.head.appendChild(link)
}

// The contents sidebar ships OPEN: a closed <details> cannot be forced open
// by CSS (Chrome hides the slot via ::details-content), and the summary
// that would open it is hidden on wide screens — so a closed sidebar is an
// empty column beside the prose. Below 62em the index grid goes single
// column and the sidebar wants its native disclosure instead, and that is
// a viewport question the server cannot answer. Without JS the list simply
// stays open: visible contents, which is the harmless direction to fail in.
const collapseTocOnNarrowScreens = (): void => {
  const strip = document.querySelector('.je-article-toc__disclosure') as HTMLDetailsElement | null
  if (strip === null) return
  if (window.matchMedia('(max-width: 61.99em)').matches) strip.open = false
}

// ── Init ───────────────────────────────────────────────────────

const onDomReady = (): void => {
  containerQueryManager.init()
  collapseTocOnNarrowScreens()
  initTocScrollspy()
  initCodeHighlight()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady)
} else {
  onDomReady()
}

window.addEventListener('load', injectManifest)
