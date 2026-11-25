/**
 * vite.config.ts — the erik theme bundle (public, selectable).
 *
 * Erik builds as its own package and its own single-entry bundle: sharing
 * `code-highlight` with the starter entry used to make Rollup extract a
 * shared chunk, and a shared chunk turns every entry into an ES module —
 * which the classic deferred `<script>` cannot execute on the Chrome 70
 * baseline. The duplicated `code-highlight.ts` in `src/` keeps the two
 * bundles independent; the factory guarantees no shared chunk either way.
 *
 * `erik-article` is the article surface's entry: a classic script that also
 * imports the surface stylesheet, so the manifest has one row for it and
 * `AssetManager::jsUrlsForSurface()` / `cssUrlsForSurface()` read it from
 * there. Every other surface tags `erik` alone.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'erik',
  inputs: { erik: 'src/erik.ts', 'erik-article': 'src/erik-article.ts' },
});
