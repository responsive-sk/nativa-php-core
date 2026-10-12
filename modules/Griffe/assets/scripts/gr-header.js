/**
 * gr-header.js — hide-on-scroll for the sticky header, without the framework.
 *
 * The reference drives this through Xtend UI (`data-xt-sticky`, fixed-always,
 * hide-on-scroll-down). The port keeps the CSS half (`position: sticky`) and
 * this ~20-line twin toggles `.gr-header--hidden` past a small threshold.
 * No-JS fallback: a plain sticky header that never hides.
 */
(function() {
    var header = document.querySelector('.gr-header');
    if (!header) {
        return;
    }
    var last = window.pageYOffset || 0;
    var ticking = false;
    function update() {
        ticking = false;
        var y = window.pageYOffset || 0;
        if (y > 160 && y > last) {
            header.classList.add('gr-header--hidden');
        } else {
            header.classList.remove('gr-header--hidden');
        }
        last = y;
    }
    window.addEventListener('scroll', function() {
        if (!ticking) {
            ticking = true;
            if (window.requestAnimationFrame) {
                window.requestAnimationFrame(update);
            } else {
                update();
            }
        }
    }, { passive: true });
})();
