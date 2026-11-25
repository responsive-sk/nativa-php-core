/**
 * griffe-home.ts — the landing's two custom elements, in their own entry.
 *
 * `hero-layout` and `horizontal-scroll-hero` are rendered by exactly one
 * surface: `GriffeTheme::homepage()`. Until 2026-11-25 they were imported by
 * `griffe.ts`, so every article, listing and ordinary page downloaded and
 * parsed the code that defines them and then upgraded nothing.
 *
 * The two elements are independent of the three behaviours in `griffe.ts`:
 * they share only `utils/css`, and `griffe.ts` does not import it. That is
 * what lets the build emit two self-contained classic scripts instead of a
 * shared chunk, which the deferred `<script>` (no `type="module"`) could not
 * execute on the Chrome 70 baseline.
 *
 * Register order is the order the elements appear in the document, and both
 * only define a tag; neither reads the DOM at import time.
 */

import './layout/hero-layout'
import './components/sections/horizontal-scroll-hero'
