(function () {
  'use strict';
  const modal = document.getElementById('addAdminModal');
  const feedback = document.getElementById('doctorInvitationFeedback');
  function fitModal() {
    if (!modal) return;
    modal.style.setProperty('--doctor-modal-height', (window.visualViewport?.height || window.innerHeight) + 'px');
    modal.style.setProperty('--doctor-modal-top', (window.visualViewport?.offsetTop || 0) + 'px');
  }
  fitModal();
  window.addEventListener('resize', fitModal);
  window.visualViewport?.addEventListener('resize', fitModal);
  window.visualViewport?.addEventListener('scroll', fitModal);
  if (modal?.dataset.reopen === 'true') bootstrap.Modal.getOrCreateInstance(modal).show();
  document.querySelectorAll('#addAdminForm, .doctor-resend-form').forEach(function (form) {
    if (form.dataset.invitationBound) return;
    form.dataset.invitationBound = 'true';
    const button = form.querySelector('button[type="submit"]');
    const original = button.innerHTML;
    function restore() { form.dataset.busy = ''; button.disabled = false; button.innerHTML = original; }
    window.addEventListener('pageshow', restore);
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (form.dataset.busy === 'true' || !form.reportValidity()) return;
      form.dataset.busy = 'true';
      button.disabled = true;
      button.textContent = 'Sending invitation…';
      const notice = form.id === 'addAdminForm' ? feedback : (function () {
        let el = form.querySelector('[role="alert"]');
        if (!el) { el = document.createElement('div'); el.className = 'small text-danger mt-1'; el.setAttribute('role', 'alert'); form.appendChild(el); }
        return el;
      })();
      notice.hidden = true;
      try {
        const response = await fetch(form.action || window.location.href, {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}});
        if (response.redirected || !(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Your session may have expired. Sign in again, then reopen this form.');
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'The invitation could not be completed. Please retry.');
        window.location.assign(result.redirect || 'admin_accounts.php');
      } catch (error) {
        notice.textContent = error.message || 'The request could not be completed. Check your connection and retry.';
        notice.hidden = false;
        notice.scrollIntoView({block: 'nearest'});
      } finally { restore(); }
    });
  });
})();
