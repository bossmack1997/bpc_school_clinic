<!doctype html><meta charset="utf-8"><title>Registration scroll probe</title>
<pre id="results">running</pre>
<script>
(async function () {
  const widths = [320, 360, 375, 390, 414, 768, 1366];
  const results = [];
  const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
  for (const width of widths) {
    const frame = document.createElement('iframe');
    frame.style.cssText = `width:${width}px;height:640px;border:0`;
    frame.src = '../auth/register.php?probe=' + Date.now();
    document.body.appendChild(frame);
    await new Promise((resolve, reject) => { frame.onload = resolve; setTimeout(() => reject(new Error('load timeout')), 8000); });
    await wait(350);
    const win = frame.contentWindow;
    const doc = frame.contentDocument;
    const root = doc.documentElement;
    const body = doc.body;
    const wrapper = doc.querySelector('.auth-wrapper--register');
    const card = doc.querySelector('.auth-card--register');
    const panel = doc.querySelector('.auth-card--register .auth-form-side');
    const submit = doc.getElementById('submitBtn');
    const maxScroll = Math.max(root.scrollHeight, body.scrollHeight) - win.innerHeight;
    win.scrollTo(0, maxScroll);
    await wait(120);
    const bottomY = win.scrollY;
    const submitReachable = submit.getBoundingClientRect().bottom <= win.innerHeight + 2;
    doc.getElementById('firstNameInput').focus();
    await wait(40);
    const focusWorked = doc.activeElement === doc.getElementById('firstNameInput');
    const roleResults = {};
    for (const role of ['teacher', 'nurse', 'student']) {
      doc.querySelector(`[data-role="${role}"]`).click();
      await wait(60);
      win.scrollTo(0, Math.max(root.scrollHeight, body.scrollHeight));
      await wait(40);
      roleResults[role] = win.scrollY > 0;
    }
    win.scrollTo(0, 0);
    await wait(80);
    results.push({
      width,
      viewport: win.innerWidth,
      documentScrollHeight: Math.max(root.scrollHeight, body.scrollHeight),
      maxScroll,
      bottomY,
      reachedBottom: maxScroll > 0 && bottomY >= maxScroll - 2,
      returnedTop: win.scrollY === 0,
      submitReachable,
      focusWorked,
      noHorizontalOverflow: root.scrollWidth <= win.innerWidth + 1,
      rolesScrollable: roleResults,
      overflow: {
        html: getComputedStyle(root).overflowY,
        body: getComputedStyle(body).overflowY,
        wrapper: getComputedStyle(wrapper).overflowY,
        card: getComputedStyle(card).overflowY,
        panel: getComputedStyle(panel).overflowY
      }
    });
    frame.remove();
  }
  document.getElementById('results').textContent = JSON.stringify(results);
  document.title = 'DONE';
})().catch(error => { document.getElementById('results').textContent = JSON.stringify({error:error.message}); document.title='DONE'; });
</script>
