/* Uses only the disposable database/server supplied by mis_admin_http.php. */
const fs = require('node:fs');
const base = process.env.MIS_TEST_BASE;
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base || '')) throw Error('Loopback fixture required');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const info = await (await fetch('http://127.0.0.1:9337/json/version')).json();
  const ws = new WebSocket(info.webSocketDebuggerUrl);
  await new Promise(resolve => ws.addEventListener('open', resolve, {once: true}));
  let id = 0; const pending = new Map();
  ws.addEventListener('message', event => { const m = JSON.parse(event.data), p = pending.get(m.id); if (p) { pending.delete(m.id); m.error ? p.reject(Error(m.error.message)) : p.resolve(m.result); } });
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => { const n = ++id; pending.set(n, {resolve, reject}); ws.send(JSON.stringify({id: n, method, params, ...(sessionId ? {sessionId} : {})})); });
  const {browserContextId} = await send('Target.createBrowserContext');
  const {targetId} = await send('Target.createTarget', {url: 'about:blank', browserContextId});
  const {sessionId} = await send('Target.attachToTarget', {targetId, flatten: true});
  const call = (method, params) => send(method, params, sessionId);
  const ev = async expression => { const r = await call('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true}); if (r.exceptionDetails) throw Error('Browser evaluation: ' + r.exceptionDetails.text); return r.result.value; };
  const check = (ok, label) => { if (!ok) throw Error(label); console.log('PASS ' + label); };
  async function navigate(path, selector) {
    await call('Page.navigate', {url: base + path});
    for (let n = 0; n < 200; n++) { await sleep(100); if (await ev(`document.readyState==='complete'&&!!document.querySelector(${JSON.stringify(selector)})`)) { await sleep(400); return; } }
    throw Error('Page unavailable: ' + path);
  }
  const errors = [];
  ws.addEventListener('message', event => { const m = JSON.parse(event.data); if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text); });
  try {
    await call('Page.enable'); await call('Runtime.enable');
    fs.mkdirSync('scratch/shared-ui', {recursive: true});
    for (const [role, username, password, dashboard] of [
      ['mis', 'mis_fixture', 'FixturePass!42', '/pages/admin/dashboard.php'],
      ['doctor', 'accept_http', 'ChangedPass!42', '/pages/doctor/dashboard.php'],
      ['student', 'deny_student', 'FixturePass!42', '/pages/student/dashboard.php'],
      ['teacher', 'deny_teacher', 'FixturePass!42', '/pages/adviser/dashboard.php'],
      ['nurse', 'deny_nurse', 'FixturePass!42', '/pages/nurse/account.php'],
    ]) {
      await send('Storage.clearCookies', {browserContextId});
      await navigate('/auth/login.php', '#loginForm');
      check(await ev(`(()=>{const i=document.getElementById('password'),b=i.parentElement.querySelector('button');i.value='Example!42';b.click();const shown=i.type==='text';b.click();return shown&&i.type==='password'&&i.value==='Example!42'&&document.querySelectorAll('.password-visibility').length===1})()`), role + ' login visibility preserves value');
      await ev(`document.querySelector('[name="identifier"]').value=${JSON.stringify(username)};document.querySelector('[name="password"]').value=${JSON.stringify(password)};document.getElementById('loginForm').requestSubmit()`);
      await sleep(700);
      await navigate(dashboard, '.sidebar');
      const links = await ev(`[...document.querySelectorAll('.sidebar a[href]')].map(a=>a.getAttribute('href')).filter(h=>h!=='#')`);
      check(await ev(`!document.querySelector('.sidebar').textContent.includes('MICA')&&document.querySelectorAll('.mica-fab').length===1`), role + ' no sidebar MICA and one floating launcher');
      for (const width of [320, 375, 390, 430, 768, 1024, 1440, 1920]) {
        await call('Emulation.setDeviceMetricsOverride', {width, height: 844, deviceScaleFactor: 1, mobile: width < 768});
        await sleep(150);
        check(await ev(`document.documentElement.scrollWidth<=innerWidth+1`), role + ' ' + width + 'px dashboard has no overflow');
        if (width < 1200) {
          await ev(`document.getElementById('sidebarToggle').click()`);
          check(await ev(`document.body.classList.contains('sidebar-open')&&!document.querySelector('.sidebar').inert&&document.activeElement.classList.contains('sidebar-close')&&getComputedStyle(document.body).overflow==='hidden'`), role + ' ' + width + 'px drawer opens and locks background');
          check(await ev(`(()=>{const s=document.querySelector('.sidebar'),n=s.querySelector('.sidebar-nav'),f=s.querySelector('.sidebar-footer');n.scrollTop=n.scrollHeight;return s.getBoundingClientRect().right<=innerWidth&&n.getBoundingClientRect().bottom<=f.getBoundingClientRect().top+1})()`), role + ' ' + width + 'px independently scrolling navigation clears footer');
          await call('Input.dispatchKeyEvent', {type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9,modifiers:8});
          check(await ev(`document.activeElement===document.querySelector('.sidebar .logout-link')`), role + ' ' + width + 'px keyboard focus wraps inside drawer');
          await ev(`document.querySelector('.sidebar-close').click()`);
          check(await ev(`!document.body.classList.contains('sidebar-open')&&document.querySelector('.sidebar').inert`), role + ' ' + width + 'px close restores page');
        }
      }
      await call('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
      for (const url of [...new Set(links)]) {
        const path = new URL(url).pathname + new URL(url).search;
        await navigate(path, '.sidebar');
        for (const width of [320, 375, 390, 430, 768, 1024, 1440, 1920]) {
          await call('Emulation.setDeviceMetricsOverride', {width,height:844,deviceScaleFactor:1,mobile:width<768});
          await sleep(70);
          check(await ev(`document.documentElement.scrollWidth<=innerWidth+1`), role + ' ' + width + 'px link resolves without overflow: ' + path);
        }
        await call('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
        check(await ev(`(()=>{const fields=[...document.querySelectorAll('input[type="password"]')];return fields.every(i=>i.parentElement.querySelectorAll('.password-visibility').length===1)})()`), role + ' password fields have one control: ' + path);
        await ev(`document.querySelectorAll('input[type="password"]').forEach(i=>{const b=i.parentElement.querySelector('button');b.click();if(i.type!=='text')throw Error('Cannot reveal');b.click();if(i.type!=='password')throw Error('Cannot hide')})`);
      }
      const shot = await call('Page.captureScreenshot', {format:'png'});
      fs.writeFileSync('scratch/shared-ui/' + role + '-390.png', Buffer.from(shot.data,'base64'));
      await ev(`document.querySelector('.mica-fab').click()`); await sleep(250);
      check(await ev(`document.querySelector('.mica-widget').classList.contains('open')`), role + ' floating MICA opens');
    }
    check(errors.length === 0, 'No uncaught browser exceptions: ' + errors.join('; '));
  } finally {
    await send('Target.closeTarget', {targetId}); await send('Target.disposeBrowserContext', {browserContextId}); ws.close();
  }
})().catch(error => {console.error('FAIL ' + error.message); process.exit(1);});
