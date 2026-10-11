
(function() {
    var tabs = document.getElementById('settings-tabs');
    if (!tabs) return;
    var btns = tabs.querySelectorAll('.m-tabs__btn');
    var panels = tabs.querySelectorAll('.m-tab-panel');
    // Show the default (active) panel on load.
    var activeBtn = tabs.querySelector('.m-tabs__btn--active');
    var defaultTab = activeBtn ? activeBtn.getAttribute('data-tab') : null;
    if (defaultTab) {
        panels.forEach(function(p) {
            p.style.display = p.getAttribute('data-tab-panel') === defaultTab ? 'block' : 'none';
        });
    }
    btns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = this.getAttribute('data-tab');
            btns.forEach(function(b) { b.classList.remove('m-tabs__btn--active'); });
            this.classList.add('m-tabs__btn--active');
            panels.forEach(function(p) {
                p.style.display = p.getAttribute('data-tab-panel') === target ? 'block' : 'none';
            });
        });
    });
})();
