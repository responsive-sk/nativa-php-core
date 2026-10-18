/**
 * vite.config.ts — the boson theme bundle (public, selectable).
 *
 * Boson's stylesheet and behaviour script are vendored by hand in the module
 * (`Modules/Boson/assets/`), not written as a package: the theme is served
 * from the module, and a tree without `public/dist/boson/` still renders it —
 * `criticalCss()` inlines the stylesheet and `pageWrap()` inlines the script.
 * This package adds the build on top of those same bytes instead of a second
 * copy of them, so `make assets` produces the minified bundle the production
 * artifact ships while the module keeps working on its own.
 *
 * Single entry, like griffe and mono: the deferred `<script>` is a classic
 * script on the Chrome 70 baseline and cannot execute ES module imports.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'boson',
  inputs: { boson: 'src/boson.ts' },
});
