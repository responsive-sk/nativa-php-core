/**
 * gr-reveal.ts — line-mask scroll reveals, without the framework.
 *
 * Ported from Modules/Griffe/assets/scripts/gr-reveal.js; behaviour is
 * identical. The reference reveals headings line by line (`anim-block`
 * mask spans) on slide activation and on scroll. Server markup always
 * carries the masked spans; this script FIRST arms `.gr-reveal-armed` on
 * <html> (only then does CSS hide unrevealed lines — no-JS readers never
 * see hidden text) and then adds `.gr-in` through an
 * IntersectionObserver. Below-fold blocks reveal on scroll; above-fold
 * ones intersect immediately. The slider force-adds `.gr-in` on
 * activation, so this file never needs to know about it.
 *
 * Self-gating: returns early when the page renders no `.gr-anim`, so the
 * single griffe bundle entry can call it on every page.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

export function initGrReveal(): void {
  const lines = document.querySelectorAll('.gr-anim')
  if (0 === lines.length) {
    return
  }
  document.documentElement.classList.add('gr-reveal-armed')
  if (!('IntersectionObserver' in window)) {
    for (let i = 0; i < lines.length; i++) {
      lines[i].classList.add('gr-in')
    }
    return
  }
  const observer = new IntersectionObserver((entries) => {
    for (let e = 0; e < entries.length; e++) {
      if (entries[e].isIntersecting) {
        entries[e].target.classList.add('gr-in')
        observer.unobserve(entries[e].target)
      }
    }
  }, { threshold: 0.2 })
  for (let j = 0; j < lines.length; j++) {
    observer.observe(lines[j])
  }
}
