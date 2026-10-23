/* Shared visibility controls, including forms inserted into modals. */
(function () {
  'use strict';
  let sequence = 0;
  function enhance() {
    document.querySelectorAll('input[type="password"]:not([data-password-ready])').forEach(function (input) {
      input.dataset.passwordReady = 'true';
      if (!input.id) {
        do { input.id = 'password-field-' + (++sequence); }
        while (document.querySelectorAll('#' + input.id).length > 1);
      }
      const group = input.parentElement.classList.contains('input-group') ? input.parentElement : null;
      let button = group && group.querySelector('button[type="button"][aria-label*="password" i], button[type="button"][id*="toggle" i]');
      if (!button) button = document.createElement('button');
      const wrapper = document.createElement('span');
      wrapper.className = 'password-control';
      input.before(wrapper);
      wrapper.append(input, button);
      button.type = 'button';
      button.className = 'password-visibility';
      button.setAttribute('aria-controls', input.id);
      const label = (input.name || 'password').replace(/_/g, ' ');
      function update() {
        const shown = input.type === 'text';
        button.setAttribute('aria-label', (shown ? 'Hide ' : 'Show ') + label);
        button.setAttribute('aria-pressed', String(shown));
        button.innerHTML = '<i class="bi bi-eye' + (shown ? '-slash' : '') + '" aria-hidden="true"></i>';
      }
      update();
      button.addEventListener('click', function () {
        input.type = input.type === 'password' ? 'text' : 'password';
        update();
        input.focus({preventScroll: true});
      });
      if (group) group.classList.add('password-input-group');
    });
  }
  function start() {
    enhance();
    new MutationObserver(enhance).observe(document.body, {childList: true, subtree: true});
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
