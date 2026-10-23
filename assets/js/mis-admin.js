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
    if (!button) return;
    const original = button.innerHTML;
    let outcomeUnknown = false;
    function restore() { form.dataset.busy = ''; form.removeAttribute('aria-busy'); button.disabled = outcomeUnknown; button.innerHTML = original; }
    window.addEventListener('pageshow', restore);
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      if (outcomeUnknown || form.dataset.busy === 'true' || !form.reportValidity()) return;
      form.dataset.busy = 'true'; form.setAttribute('aria-busy', 'true');
      button.disabled = true; button.textContent = 'Sending invitation…';
      outcomeUnknown = true;
      let notice; let deadline;
      const controller = new AbortController();
      try {
        notice = form.id === 'addAdminForm' ? feedback : form.querySelector('[role="alert"]');
        if (!notice) { notice = document.createElement('div'); notice.className = 'alert alert-danger small mt-2'; notice.setAttribute('role', 'alert'); (form.querySelector('.modal-body') || form).appendChild(notice); }
        notice.hidden = true;
        deadline = window.setTimeout(() => controller.abort(), 45000);
        // The hidden input named "action" shadows HTMLFormElement.action.
        const response = await fetch(form.getAttribute('action') || window.location.href, {method: 'POST', body: new FormData(form), credentials: 'same-origin', signal: controller.signal, headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}});
        if (response.redirected) throw new Error('The invitation request was redirected. Refresh the account list before retrying.');
        if (!(response.headers.get('content-type') || '').toLowerCase().includes('application/json')) throw new Error('The server returned an unexpected response. Refresh the account list before retrying.');
        let result;
        try { result = await response.json(); } catch (_) { throw new Error('The server response could not be read. Refresh the account list before retrying.'); }
        if (result?.success === false && result.error_code !== 'INVITATION_OUTCOME_UNKNOWN' && [401, 403, 409, 422, 428].includes(response.status)) outcomeUnknown = false;
        if (response.status === 401 && result?.success === false && result.error_code !== 'INVITATION_OUTCOME_UNKNOWN') throw new Error('Your session expired. Sign in again, then reopen this form.');
        if (!response.ok || result?.success !== true) throw new Error(typeof result?.message === 'string' ? result.message : 'The invitation could not be completed. Refresh the account list before retrying.');
        const destination = new URL(result.redirect || 'admin_accounts.php', window.location.href);
        if (destination.origin !== window.location.origin) throw new Error('The server returned an invalid destination. Refresh the account list.');
        window.location.assign(destination.href);
      } catch (error) {
        const message = error.name === 'AbortError' ? 'The request timed out. The account may already be pending. Refresh the account list before resending.' : (error instanceof TypeError ? 'Connection failed. Refresh the account list before retrying; the account may already be pending.' : error.message);
        if (notice) { notice.textContent = message || 'The request could not be completed. Refresh the account list.'; notice.hidden = false; notice.scrollIntoView({block: 'nearest'}); }
        if (outcomeUnknown && notice) {
          const link = document.createElement('a');
          link.href = window.location.href; link.className = 'd-block mt-2';
          link.textContent = 'Refresh and check the account list before retrying';
          notice.appendChild(link);
        }
      } finally { window.clearTimeout(deadline); restore(); }
    });
  });
})();
