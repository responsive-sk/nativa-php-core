/**
 * gr-reveal.js — line-mask scroll reveals, without the framework.
 *
 * The reference reveals headings line by line (`anim-block` mask spans) on
 * slide activation and on scroll. Server markup always carries the masked
 * spans; this script FIRST arms `.gr-reveal-armed` on <html> (only then does
 * CSS hide unrevealed lines — no-JS readers never see hidden text) and then
 * adds `.gr-in` through an IntersectionObserver. Below-fold blocks reveal on
 * scroll; above-fold ones intersect immediately. The slider force-adds
 * `.gr-in` on activation, so this file never needs to know about it.
 */
(function() {
    var lines = document.querySelectorAll('.gr-anim');
    if (!lines.length) {
        return;
    }
    document.documentElement.classList.add('gr-reveal-armed');
    if (!('IntersectionObserver' in window)) {
        for (var i = 0; i < lines.length; i++) {
            lines[i].classList.add('gr-in');
        }
        return;
    }
    var observer = new IntersectionObserver(function(entries) {
        for (var e = 0; e < entries.length; e++) {
            if (entries[e].isIntersecting) {
                entries[e].target.classList.add('gr-in');
                observer.unobserve(entries[e].target);
            }
        }
    }, { threshold: 0.2 });
    for (var j = 0; j < lines.length; j++) {
        observer.observe(lines[j]);
    }
})();
