/**
 * vite.config.ts — the griffe theme bundle (public, selectable).
 *
 * Single entry, like erik: one self-contained file, no shared chunks —
 * the deferred `<script>` on the Chrome 70 baseline cannot execute
 * ES module imports. The four behaviours (header, slider, reveal,
 * highlight) are self-gating inits inside the entry, which replaces the
 * per-surface PHP gating GriffeTheme::pageWrap() used to do.
 */
import { createThemeConfig } from '../build/vite-theme-factory.ts';

export default createThemeConfig({
  theme: 'griffe',
  inputs: { griffe: 'src/griffe.ts' },
});
