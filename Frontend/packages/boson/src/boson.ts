/**
 * boson.ts — boson theme frontend entry.
 *
 * Both sources stay in the module: `Modules/Boson/assets/critical/boson.css`
 * is the stylesheet `criticalCss()` inlines when there is no build, and
 * `Modules/Boson/assets/scripts/boson.js` is the vendored script `pageWrap()`
 * inlines in the same situation. Importing them here is what makes one pair of
 * files serve both worlds — the artifact gets a minified, hashed bundle, the
 * module still renders without one, and there is nothing to keep in sync.
 *
 * `AssetManager` reads the emitted manifest: `cssUrls('boson')` is inlined in
 * `<head>` by FrontendLayout and `jsUrl('boson')` becomes the deferred
 * `<script src="/dist/boson/assets/boson-*.js">`. `pageWrap()` therefore
 * stops inlining the script once the bundle resolves, so the behaviour runs
 * once either way.
 */

import '../../../../src/Modules/Themes/Boson/assets/critical/boson.css'
import '../../../../src/Modules/Themes/Boson/assets/scripts/boson.js'
