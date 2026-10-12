/**
 * gr-slider.js — fullscreen home slider behaviour, without the framework.
 *
 * The reference autoplays case slides with a progress-loader pagination
 * (`data-xt-pag` + `.loader .filler`). This twin: adds `.gr-slider--live`
 * (CSS switches stacked slides to one active stage), auto-advances every
 * `data-gr-autoplay` ms with the filler's width as the progress bar, wires
 * pagination buttons + prev/next arrows + arrow keys, pauses on hover/focus
 * and when the tab hides. Slide changes force `.gr-in` on the incoming
 * slide's reveal lines (hidden slides never intersected the observer).
 *
 * Reduced motion or a single slide: stays static (no `--live`, slides stack,
 * controls stay hidden) — the no-JS fallback doubles as the calm one.
 */
(function() {
    var root = document.querySelector('.gr-hero-slider');
    if (!root) {
        return;
    }
    var slides = root.querySelectorAll('.gr-slide');
    if (slides.length < 2) {
        return;
    }
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    var delay = parseInt(root.getAttribute('data-gr-autoplay') || '6000', 10);
    if (!(delay > 0)) {
        delay = 6000;
    }
    var pag = root.querySelectorAll('.gr-pag');
    var index = 0;
    var timer = null;

    root.classList.add('gr-slider--live');

    function fill() {
        var active = root.querySelector('.gr-pag--active .gr-pag__fill');
        if (!active) {
            return;
        }
        active.style.transitionDuration = delay + 'ms';
    }

    function go(next) {
        next = (next + slides.length) % slides.length;
        if (next === index) {
            restart();
            return;
        }
        slides[index].classList.remove('gr-slide--active');
        if (pag[index]) {
            pag[index].classList.remove('gr-pag--active');
            pag[index].removeAttribute('aria-current');
        }
        index = next;
        slides[index].classList.add('gr-slide--active');
        var anims = slides[index].querySelectorAll('.gr-anim');
        for (var a = 0; a < anims.length; a++) {
            anims[a].classList.add('gr-in');
        }
        if (pag[index]) {
            pag[index].classList.add('gr-pag--active');
            pag[index].setAttribute('aria-current', 'true');
        }
        restart();
    }

    function restart() {
        stop();
        fill();
        timer = window.setTimeout(function() {
            go(index + 1);
        }, delay);
    }

    function stop() {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    }

    for (var i = 0; i < pag.length; i++) {
        (function(i) {
            pag[i].addEventListener('click', function() {
                go(i);
            });
        })(i);
    }

    var prev = root.querySelector('.gr-slider__arrow--prev');
    var nextBtn = root.querySelector('.gr-slider__arrow--next');
    if (prev) {
        prev.addEventListener('click', function() {
            go(index - 1);
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            go(index + 1);
        });
    }
    root.addEventListener('keydown', function(event) {
        if (event.key === 'ArrowLeft') {
            go(index - 1);
        } else if (event.key === 'ArrowRight') {
            go(index + 1);
        }
    });
    root.addEventListener('mouseenter', stop);
    root.addEventListener('mouseleave', restart);
    root.addEventListener('focusin', stop);
    root.addEventListener('focusout', restart);
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stop();
        } else {
            restart();
        }
    });

    restart();
})();
