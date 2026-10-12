/**
 * griffe.ts — griffe theme frontend entry.
 *
 * One self-contained bundle for the deferred `<script>`: the four
 * behaviours GriffeTheme::pageWrap() used to inline per surface (header,
 * slider, reveal, highlight) are self-gating inits here — each returns
 * early when its markup is absent, so pages without a slider ship the
 * bytes but run nothing. Keep the bundle independent from the other
 * theme stylesheets; the PHP layout selects it through
 * AssetManager('griffe').
 */

import './griffe/index.css'
import { initGrHeader } from './gr-header'
import { initGrSlider } from './gr-slider'
import { initGrReveal } from './gr-reveal'
import { initGrCodeHighlight } from './gr-code-highlight'

// Same order the inline scripts used to run in: header, slider, reveal,
// highlight. The slider force-adds `.gr-in` on activation and the
// highlighter mutates code blocks — neither depends on the other's
// output, but the order keeps the waterfall familiar.
const onDomReady = (): void => {
  initGrHeader()
  initGrSlider()
  initGrReveal()
  initGrCodeHighlight()
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', onDomReady)
} else {
  onDomReady()
}
