/**
 * vite.config.ts — the mono theme bundle (public, selectable).
 *
 * Single entry, like griffe: one self-contained file, no shared chunks —
 * the deferred `<script>` on the Chrome 70 baseline cannot execute
 * ES module imports. The three behaviours (variant switcher, TOC
 * scrollspy, highlight) are self-gating inits inside the entry, which
 * replaces the per-surface PHP gating MonoTheme::pageWrap() used to do.
 *
 * `mono-article` is the article surface's stylesheet, a CSS-only input: the
 * manifest names it in `file` with no `css` array (measured 2026-11-24), and
 * `AssetManager::cssUrlsForSurface()` reads both shapes.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'mono',
  inputs: { mono: 'src/mono.ts', 'mono-article': 'src/mono-article.css' },
});
