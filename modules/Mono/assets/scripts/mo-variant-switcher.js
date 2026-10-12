/**
 * mo-variant-switcher.js — dev palette switcher for the Mono navbar.
 *
 * Behavioural twin of the upstream theme-switcher.js
 * (evan70.github.io/mono/theme-switcher.js), minus the build: where
 * upstream injects a `<link>` to neon.css / fire-show.css, Mono's variants
 * already ship in the critical CSS under `html[data-variant="…"]`, so the
 * tracker only flips the attribute. Variants are mutually exclusive, a
 * second click on the active button toggles back to the default palette,
 * and the choice persists in localStorage (pre-paint restore lives in the
 * theme init script, like the dark/light mode).
 *
 * Wired to every `[data-variant-toggle]` on the page (not just the first),
 * runs immediately — the loader injects it after the content, so the
 * navbar buttons precede it.
 *
 * ES2015-safe: no `?.`, `??` or other Chrome 70-unsupported syntax.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'nativa-variant';

  function stored() {
    try {
      return window.localStorage.getItem(STORAGE_KEY);
    } catch (e) {
      return null;
    }
  }

  function persist(variant) {
    try {
      if (null === variant) {
        window.localStorage.removeItem(STORAGE_KEY);
      } else {
        window.localStorage.setItem(STORAGE_KEY, variant);
      }
    } catch (e) {}
  }

  function paint(buttons, variant) {
    var i;

    if (null === variant) {
      document.documentElement.removeAttribute('data-variant');
    } else {
      document.documentElement.setAttribute('data-variant', variant);
    }

    for (i = 0; i < buttons.length; i++) {
      var on = buttons[i].getAttribute('data-variant-toggle') === variant;

      buttons[i].classList.toggle('mo-navbar__dev-toggle--active', on);
      buttons[i].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  }

  function initMoVariantSwitcher() {
    var buttons = document.querySelectorAll('[data-variant-toggle]');
    var i;

    if (0 === buttons.length) return;

    paint(buttons, stored());

    for (i = 0; i < buttons.length; i++) {
      buttons[i].addEventListener('click', function () {
        var want = this.getAttribute('data-variant-toggle');
        var next = document.documentElement.getAttribute('data-variant') === want ? null : want;

        paint(buttons, next);
        persist(next);
      });
    }
  }

  initMoVariantSwitcher();
})();
