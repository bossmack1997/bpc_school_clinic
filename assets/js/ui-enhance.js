/**
 * assets/js/ui-enhance.js
 * Load after bootstrap.bundle.min.js (components/footer.php, after main.js).
 *
 * 1. Dropdown menus opened inside tables and cards were cut off by the
 *    container's overflow. Bootstrap's "fixed" Popper strategy positions the
 *    menu against the screen instead, so it always shows in full.
 * 2. Publishes the live height of the SOS banner as --sos-h so the MICA panel
 *    and toasts on phones can sit below it instead of underneath it.
 * 3. Adds one accessible show/hide control to password fields that do not
 *    already have an application-provided control.
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
    enhancePasswords(document);
    new MutationObserver(function (records) {
      track();
      records.forEach(function (record) {
        record.addedNodes.forEach(function (node) {
          if (node.nodeType === 1) enhancePasswords(node);
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  var passwordId = 0;

  function enhancePasswords(scope) {
    var fields = [];
    if (scope.matches && scope.matches('input[type="password"]')) fields.push(scope);
    if (scope.querySelectorAll) fields = fields.concat(Array.from(scope.querySelectorAll('input[type="password"]')));

    fields.forEach(function (input) {
      if (input.dataset.passwordToggleBound === 'true') return;

      var group = input.closest('.input-group');
      var existing = group && group.querySelector('button[type="button"][aria-label*="password" i], button[type="button"][id*="toggle" i]');
      if (existing) {
        if (!input.id) input.id = 'bpc-password-' + (++passwordId);
        if (!existing.hasAttribute('aria-controls')) existing.setAttribute('aria-controls', input.id);
        if (!existing.hasAttribute('aria-pressed')) existing.setAttribute('aria-pressed', 'false');
        input.dataset.passwordToggleBound = 'true';
        return;
      }

      if (!input.id) input.id = 'bpc-password-' + (++passwordId);
      var host = group;
      if (!host) {
        host = document.createElement('div');
        host.className = 'bpc-password-field';
        input.parentNode.insertBefore(host, input);
        host.appendChild(input);
      }

      var button = document.createElement('button');
      button.type = 'button';
      button.className = group ? 'btn btn-outline-secondary bpc-password-toggle' : 'bpc-password-toggle';
      button.setAttribute('aria-controls', input.id);
      button.setAttribute('aria-label', 'Show password');
      button.setAttribute('aria-pressed', 'false');
      button.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';
      button.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', String(show));
        button.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
      });
      host.appendChild(button);
      input.dataset.passwordToggleBound = 'true';
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
