/* Modified provisioning, nursing and patient flows in disposable browser contexts. */
const fs = require('node:fs');
const base = process.env.FINAL_CLINICAL_BASE;
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base || '')) throw Error('Disposable loopback fixture required');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const info = await (await fetch('http://127.0.0.1:9337/json/version')).json();
  const socket = new WebSocket(info.webSocketDebuggerUrl);
  await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
  let id = 0; const pending = new Map();
  socket.addEventListener('message', event => { const m = JSON.parse(event.data), task = pending.get(m.id); if (task) { pending.delete(m.id); m.error ? task.reject(Error('CDP failed')) : task.resolve(m.result); } });
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => { const n = ++id; pending.set(n, { resolve, reject }); socket.send(JSON.stringify({ id: n, method, params, ...(sessionId ? { sessionId } : {}) })); });
  const assert = (ok, label) => { if (!ok) throw Error(label); console.log('PASS ' + label); };
  fs.mkdirSync('scratch/final-clinical-20261028/browser', { recursive: true });
  try {
    for (const [user, paths] of [
      ['mis_fixture', ['/pages/admin/provision_accounts.php', '/pages/report_exports.php']],
      ['ui_nurse', ['/pages/nurse/queue.php', '/pages/report_exports.php']],
      ['ui_student', ['/pages/student/queue.php']],
      ['ui_teacher', ['/pages/adviser/queue.php']]
    ]) {
      const { browserContextId } = await send('Target.createBrowserContext');
      try {
        const { targetId } = await send('Target.createTarget', { url: 'about:blank', browserContextId });
        const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
        const call = (method, params) => send(method, params, sessionId);
        const evaluate = async expression => { const result = await call('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }); if (result.exceptionDetails) throw Error('Browser expression failed'); return result.result.value; };
        const errors = []; const listener = event => { const m = JSON.parse(event.data); if (m.sessionId === sessionId && m.method === 'Runtime.exceptionThrown') errors.push(m.params?.exceptionDetails?.text || 'exception'); };
        socket.addEventListener('message', listener); await call('Page.enable'); await call('Runtime.enable');
        async function navigate(path, selector = '#mainContent') {
          await call('Page.navigate', { url: base + path });
          for (let n = 0; n < 200; n++) { if (await evaluate(`document.readyState==='complete'&&!!document.querySelector(${JSON.stringify(selector)})`)) { await sleep(600); return; } await sleep(100); }
          throw Error('Page did not load: ' + path);
        }
        await navigate('/auth/login.php', '[name="identifier"]');
        await evaluate(`document.querySelector('[name="identifier"]').value=${JSON.stringify(user)};document.querySelector('[name="password"]').value='FixturePass!42';document.querySelector('form').submit()`); await sleep(900);
        for (const width of [320, 390, 768, 1440]) {
          await call('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
          for (const path of paths) {
            await navigate(path);
            assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),`${user} ${width}px no overflow ${path}`);
            if (path.includes('provision_accounts')) {
              await evaluate(`var e=document.getElementById('provision-email');e.value='invalid';e.dispatchEvent(new Event('input'))`);
              assert(await evaluate(`document.getElementById('provision-email-state').textContent.includes('valid email')`),`${width}px immediate email syntax feedback`);
              await evaluate(`var e=document.getElementById('provision-email');e.value='mis@example.invalid';e.dispatchEvent(new Event('input'))`); await sleep(800);
              assert(await evaluate(`document.getElementById('provision-email-state').textContent.includes('already belongs')`),`${width}px debounced authorized duplicate check`);
            }
            if (path.includes('/nurse/queue')) {
              await evaluate(`document.querySelectorAll('details').forEach(e=>e.open=true)`);
              assert(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1&&!document.querySelector('[name="diagnosis"]')&&!document.querySelector('[name="digital_signature"]')`),`${width}px expanded nursing controls retain scope and fit`);
            }
            if (path.includes('/student/queue') || path.includes('/adviser/queue')) {
              assert(await evaluate(`document.body.textContent.includes('preliminary')&&!!document.querySelector('[name="priority"][value="emergency"]')&&!!document.getElementById('queueAvailabilityNotice')`),`${user} ${width}px urgency and availability explained`);
            }
            const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
            fs.writeFileSync(`scratch/final-clinical-20261028/browser/${user}-${width}-${path.includes('report_exports') ? 'exports' : 'workflow'}.png`, Buffer.from(screenshot.data, 'base64'));
          }
        }
        assert(errors.length === 0, user + ' modified pages have no uncaught JavaScript errors');
        socket.removeEventListener('message', listener);
      } finally { await send('Target.disposeBrowserContext', { browserContextId }); }
    }
  } finally { socket.close(); }
})().catch(error => { console.error('FAIL ' + error.message); process.exitCode = 1; });
