(() => {
  const email = document.getElementById('provision-email');
  const state = document.getElementById('provision-email-state');
  if (!email || !state) return;
  let timer, controller;
  email.addEventListener('input', () => {
    clearTimeout(timer); controller?.abort();
    const value = email.value.trim();
    state.textContent = value ? (email.validity.valid ? 'Checking email availability…' : 'Enter a valid email address.') : '';
    if (!value || !email.validity.valid) return;
    timer = setTimeout(async () => {
      controller = new AbortController();
      try {
        const url = new URL(`${APP_URL}/api/account_provisioning.php`);
        url.search = new URLSearchParams({ action: 'email_check', email: value });
        const response = await fetch(url, { signal: controller.signal, cache: 'no-store' });
        const data = await response.json();
        if (email.value.trim() !== value) return;
        state.textContent = response.ok && data.success ? (data.available ? 'Available. Final validation runs before account creation.' : 'This email already belongs to an account.') : 'Email availability could not be checked. Retry or use preview.';
      } catch (error) {
        if (error.name !== 'AbortError' && email.value.trim() === value) state.textContent = 'Email availability could not be checked. Use preview to validate.';
      }
    }, 350);
  });
})();
