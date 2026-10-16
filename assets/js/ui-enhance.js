/**
 * assets/js/ui-enhance.js
 * Load after bootstrap.bundle.min.js (components/footer.php, after main.js).
 *
 * 1. Dropdown menus opened inside tables and cards were cut off by the
 *    container's overflow. Bootstrap's "fixed" Popper strategy positions the
 *    menu against the screen instead, so it always shows in full.
 * 2. Publishes the live height of the SOS banner as --sos-h so the MICA panel
 *    and toasts on phones can sit below it instead of underneath it.
 */
(function () {
  'use strict';

  /* ---- 1. dropdowns in clipped containers ------------------------ */
  var CLIPPING = '.table-responsive, .dataTables_wrapper, .app-card, .adm-card, .dbg-panel, .card, .app-collapse';

  // Capture phase: runs before Bootstrap's own click handler creates the instance.
  document.addEventListener('click', function (e) {
    var toggle = e.target.closest ? e.target.closest('[data-bs-toggle="dropdown"]') : null;
    if (!toggle || !window.bootstrap || !bootstrap.Dropdown) return;
    if (!toggle.closest(CLIPPING)) return;
    if (bootstrap.Dropdown.getInstance(toggle)) return;
    bootstrap.Dropdown.getOrCreateInstance(toggle, { popperConfig: { strategy: 'fixed' } });
  }, true);

  /* ---- 2. SOS banner height -------------------------------------- */
  var root = document.documentElement;
  var watched = null;
  var ro = ('ResizeObserver' in window) ? new ResizeObserver(publish) : null;

  function publish() {
    var el = document.querySelector('.sos-floating-widget');
    var h = el ? Math.ceil(el.getBoundingClientRect().height) : 0;
    root.style.setProperty('--sos-h', h + 'px');
  }

  function track() {
    var el = document.querySelector('.sos-floating-widget');
    if (el !== watched) {
      if (watched && ro) ro.unobserve(watched);
      watched = el;
      if (el && ro) ro.observe(el);
    }
    publish();
  }

  function start() {
    track();
    new MutationObserver(track).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
