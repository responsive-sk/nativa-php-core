/**
 * gr-slider.ts — fullscreen home slider behaviour, without the framework.
 *
 * Ported from Modules/Griffe/assets/scripts/gr-slider.js; behaviour is
 * identical. The reference autoplays case slides with a progress-loader
 * pagination (`data-xt-pag` + `.loader .filler`). This twin: adds
 * `.gr-slider--live` (CSS switches stacked slides to one active stage),
 * auto-advances every `data-gr-autoplay` ms with the filler's width as
 * the progress bar, wires pagination buttons + prev/next arrows + arrow
 * keys, pauses on hover/focus and when the tab hides. Slide changes force
 * `.gr-in` on the incoming slide's reveal lines (hidden slides never
 * intersected the observer).
 *
 * Self-gating: stays static (no `--live`, slides stack, controls stay
 * hidden) on pages without a slider, with a single slide, or under
 * reduced motion — the no-JS fallback doubles as the calm one.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */

export function initGrSlider(): void {
  const root = document.querySelector('.gr-hero-slider') as HTMLElement | null
  if (!root) {
    return
  }
  const slides = root.querySelectorAll('.gr-slide')
  if (slides.length < 2) {
    return
  }
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return
  }
  let delay = parseInt(root.getAttribute('data-gr-autoplay') || '6000', 10)
  if (!(delay > 0)) {
    delay = 6000
  }
  const pag = root.querySelectorAll('.gr-pag')
  let index = 0
  let timer: number | null = null

  root.classList.add('gr-slider--live')

  const fill = (): void => {
    const active = root.querySelector('.gr-pag--active .gr-pag__fill') as HTMLElement | null
    if (!active) {
      return
    }
    active.style.transitionDuration = delay + 'ms'
  }

  const stop = (): void => {
    if (null !== timer) {
      window.clearTimeout(timer)
      timer = null
    }
  }

  const restart = (): void => {
    stop()
    fill()
    timer = window.setTimeout(() => {
      go(index + 1)
    }, delay)
  }

  const go = (next: number): void => {
    next = (next + slides.length) % slides.length
    if (next === index) {
      restart()
      return
    }
    slides[index].classList.remove('gr-slide--active')
    if (pag[index]) {
      pag[index].classList.remove('gr-pag--active')
      pag[index].removeAttribute('aria-current')
    }
    index = next
    slides[index].classList.add('gr-slide--active')
    const anims = slides[index].querySelectorAll('.gr-anim')
    for (let a = 0; a < anims.length; a++) {
      anims[a].classList.add('gr-in')
    }
    if (pag[index]) {
      pag[index].classList.add('gr-pag--active')
      pag[index].setAttribute('aria-current', 'true')
    }
    restart()
  }

  for (let i = 0; i < pag.length; i++) {
    const button = pag[i]
    const target = i
    button.addEventListener('click', () => {
      go(target)
    })
  }

  const prev = root.querySelector('.gr-slider__arrow--prev')
  const nextBtn = root.querySelector('.gr-slider__arrow--next')
  if (prev) {
    prev.addEventListener('click', () => {
      go(index - 1)
    })
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', () => {
      go(index + 1)
    })
  }
  root.addEventListener('keydown', (event) => {
    if ('ArrowLeft' === event.key) {
      go(index - 1)
    } else if ('ArrowRight' === event.key) {
      go(index + 1)
    }
  })
  root.addEventListener('mouseenter', stop)
  root.addEventListener('mouseleave', restart)
  root.addEventListener('focusin', stop)
  root.addEventListener('focusout', restart)
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stop()
    } else {
      restart()
    }
  })

  restart()
}
