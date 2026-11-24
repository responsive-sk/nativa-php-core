/**
 * vite.config.ts — the boson theme bundle (public, selectable).
 *
 * Boson's stylesheet and behaviour script are vendored by hand in the module
 * catalogue (`modules/Themes/Boson/assets/`), not written as a package: the
 * theme is served from the module, and a tree without `public/dist/boson/`
 * still renders it — `criticalCss()` inlines the stylesheet and `pageWrap()`
 * inlines the script. This package adds the build on top of those same bytes
 * instead of a second copy of them, so `make assets` produces the minified
 * bundle the production artifact ships while the module keeps working on its
 * own. The entry imports the catalogue, because that is the tracked copy and
 * the installed slice is derived from it (`src/Modules/*` is gitignored and,
 * in CI, does not exist yet when this build runs).
 *
 * Three entries: `boson` is the deferred classic script (a `<script defer>` on
 * the Chrome 70 baseline cannot execute ES module imports), `diagram` is a
 * module chunk that only the landing imports — lazily, when the stack figure
 * nears the viewport. Mermaid is 600 kB of gzip nobody should pay for before
 * scrolling, so it lives in its own entry instead of the theme script.
 *
 * `boson-article` is the article surface's stylesheet, a CSS-only input: the
 * manifest names it in `file` with no `css` array (measured 2026-11-24), and
 * `AssetManager::cssUrlsForSurface()` reads both shapes. The homepage, a
 * listing and a search page therefore stop paying for the reading rail, the
 * article heading scale and the ruled comment list.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'boson',
  inputs: {
    boson: 'src/boson.ts',
    diagram: 'src/diagram.ts',
    'boson-article': 'src/boson-article.css',
    'boson-home': 'src/boson-home.css',
    'boson-listing': 'src/boson-listing.css',
  },
});
