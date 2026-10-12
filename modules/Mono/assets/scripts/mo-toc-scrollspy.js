/**
 * mo-toc-scrollspy.js — highlights the Mono TOC link of the section
 * currently in view.
 *
 * 1:1 no-build twin of the shared toc-scrollspy.ts (see
 * Frontend/packages/starter/src/toc-scrollspy.ts): a passive scroll
 * listener compares each heading's offset against a marker at 1/3 of the
 * viewport and toggles the active class on the last heading above it.
 * Links opt in with data-toc-link + data-heading, and the active class is
 * read off each link's own BEM block (mo-toc__link → mo-toc__link--active),
 * so this file never names the theme.
 *
 * Loaded inline by MonoTheme::pageWrap, and only on pages that actually
 * render a TOC aside — pages without one ship no JS at all. Runs
 * immediately (the loader injects it after the content, so the TOC markup
 * precedes it) and also computes once on init, so a #fragment deep link
 * opens with the right link highlighted before any scrolling happens.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */
(function () {
  'use strict';

  function activeClassFor(link) {
    var block = '';
    var i, name;

    for (i = 0; i < link.classList.length; i++) {
      name = link.classList[i];

      // Skip BEM modifiers (`…--sub`) and keep the plain block (`…__link`).
      if (name.indexOf('__') !== -1 && name.indexOf('--') === -1) {
        block = name;
        break;
      }
    }

    return '' === block ? 'toc-link--active' : block + '--active';
  }

  function collectEntries() {
    var entries = [];
    var links = document.querySelectorAll('[data-toc-link][data-heading]');
    var i, id, heading, existing, j;

    for (i = 0; i < links.length; i++) {
      id = links[i].getAttribute('data-heading');

      if (null === id) continue;

      heading = document.getElementById(id);

      if (null === heading) continue;

      existing = null;

      for (j = 0; j < entries.length; j++) {
        if (entries[j].heading === heading) {
          existing = entries[j];
          break;
        }
      }

      if (null !== existing) {
        existing.links.push(links[i]);
      } else {
        entries.push({ heading: heading, links: [links[i]] });
      }
    }

    return entries;
  }

  function markActive(entries, marker) {
    var activeIndex = 0;
    var i, j, isActive;

    for (i = 0; i < entries.length; i++) {
      if (entries[i].heading.getBoundingClientRect().top <= marker) {
        activeIndex = i;
      }
    }

    for (i = 0; i < entries.length; i++) {
      for (j = 0; j < entries[i].links.length; j++) {
        isActive = i === activeIndex;

        entries[i].links[j].classList.toggle(activeClassFor(entries[i].links[j]), isActive);

        // Surface the current section to screen readers alongside the
        // visual highlight (the links are already labelled by their text).
        if (isActive) {
          entries[i].links[j].setAttribute('aria-current', 'location');
        } else {
          entries[i].links[j].removeAttribute('aria-current');
        }
      }
    }
  }

  function initMoTocScrollspy() {
    var entries = collectEntries();

    if (0 === entries.length) return;

    function update() {
      markActive(entries, window.innerHeight / 3);
    }

    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update, { passive: true });
    update();
  }

  initMoTocScrollspy();
})();
