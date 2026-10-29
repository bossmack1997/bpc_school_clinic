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
    for (const [user, paths] of (process.env.FINAL_ACTIVATION_ONLY ? [] : [
      ['mis_fixture', ['/pages/admin/provision_accounts.php', '/pages/admin/settings.php', '/pages/admin/activity_logs.php', '/pages/report_exports.php']],
      ['accept_http', ['/pages/doctor/dashboard.php', '/pages/doctor/queue.php', '/pages/doctor/patients.php', '/pages/doctor/consultations.php?tab=consultations', '/pages/doctor/nurse_verification.php', '/pages/doctor/create_nurse.php']],
      ['ui_nurse', ['/pages/nurse/queue.php', '/pages/report_exports.php']],
      ['ui_student', ['/pages/student/queue.php']],
      ['ui_teacher', ['/pages/adviser/queue.php']]
    ])) {
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
        async function submit(selector, expected) {
          await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});(e.form||e).requestSubmit()})()`);
          for(let n=0;n<300;n++) {
            if(await evaluate(`document.readyState==='complete'&&document.body.textContent.includes(${JSON.stringify(expected)})`))return;
            await sleep(100);
          }
          throw Error('Form result missing: '+expected);
        }
        await navigate('/auth/login.php', '[name="identifier"]');
        await evaluate(`document.querySelector('[name="identifier"]').value=${JSON.stringify(user)};document.querySelector('[name="password"]').value='FixturePass!42';document.querySelector('form').submit()`); await sleep(900);
        for (const width of [320, 390, 768, 1440]) {
          await call('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
          for (const path of paths) {
            await navigate(path, '.content-area');
            assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),`${user} ${width}px no overflow ${path}`);
            if (path.includes('provision_accounts')) {
              await evaluate(`var e=document.getElementById('provision-email');e.value='invalid';e.dispatchEvent(new Event('input'))`);
              assert(await evaluate(`document.getElementById('provision-email-state').textContent.includes('valid email')`),`${width}px immediate email syntax feedback`);
              await evaluate(`var e=document.getElementById('provision-email');e.value='mis@example.invalid';e.dispatchEvent(new Event('input'))`); await sleep(800);
              assert(await evaluate(`document.getElementById('provision-email-state').textContent.includes('already belongs')`),`${width}px debounced authorized duplicate check`);
              if(width===390||width===1440) {
                const role=width===390?'student':'teacher', name=`browser_${role}_${width}`;
                await evaluate(`(()=>{const f=document.querySelector('[name="action"][value="preview_single"]').form;Object.entries(${JSON.stringify({role,username:name,email:name+'@example.invalid',first_name:'Browser',last_name:'Fixture',institutional_id:name})}).forEach(([k,v])=>f.elements[k].value=v)})()`);
                await submit('[name="action"][value="preview_single"]','Review before creating accounts');
                assert(await evaluate(`!!document.querySelector('[name="nonce"]')`),`${width}px ${role} requires confirmed preview`);
                await submit('[name="action"][value="create"]','Import / invitation summary');
                assert(await evaluate(`document.body.textContent.includes('Sending failed: 1')`),`${width}px MIS ${role} creation reports blocked SMTP accurately`);
                await navigate(path,'.content-area');
                const ext=width===390?'csv':'xlsx';
                if(ext==='csv') {
                  await evaluate(`(()=>{const d=new DataTransfer();d.items.add(new File(['role,username,email,first_name,last_name,institutional_id\\nstudent,browser_csv,browser_csv@example.invalid,Browser,CSV,BROWSER-CSV\\nteacher,browser_csv_teacher,browser_csv_teacher@example.invalid,Browser,Teacher,BROWSER-CSV-T\\n'],'accounts.csv',{type:'text/csv'}));document.getElementById('accounts-csv').files=d.files})()`);
                } else {
                  const {root}=await call('DOM.getDocument');const {nodeId}=await call('DOM.querySelector',{nodeId:root.nodeId,selector:'#accounts-csv'});
                  await call('DOM.setFileInputFiles',{nodeId,files:[process.env.VERIFICATION_XLSX]});
                }
                await submit('[name="action"][value="preview_csv"]','Review before creating accounts');
                assert(await evaluate(`document.body.textContent.includes('Ready: 2')&&!!document.querySelector('[name="nonce"]')`),`${width}px ${ext} bulk upload previews both roles`);
                await submit('[name="action"][value="create"]','Import / invitation summary');
                assert(await evaluate(`document.body.textContent.includes('Sending failed: 2')`),`${width}px ${ext} creates two accounts and reports failed delivery`);
              }
            }
            if(path.includes('create_nurse')&&(width===390||width===1440)) {
              const name='browser_nurse_'+width;
              await evaluate(`(()=>{const f=document.querySelector('[name="action"][value="preview"]').form;Object.entries(${JSON.stringify({username:name,email:name+'@example.invalid',first_name:'Browser',last_name:'Nurse',institutional_id:name})}).forEach(([k,v])=>f.elements[k].value=v)})()`);
              await submit('[name="action"][value="preview"]','Review Nurse account');
              await submit('[name="action"][value="create"]','Invitation result');
              assert(await evaluate(`document.body.textContent.includes('Email sending failed')&&document.body.textContent.includes(${JSON.stringify(name+'@example.invalid')})`),`${width}px Doctor Nurse invitation appears pending with truthful delivery status`);
            }
            if (path.includes('/nurse/queue')) {
              await evaluate(`document.querySelectorAll('details').forEach(e=>e.open=true)`);
              assert(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1&&!document.querySelector('[name="diagnosis"]')&&!document.querySelector('[name="digital_signature"]')`),`${width}px expanded nursing controls retain scope and fit`);
            }
            if (path.includes('/student/queue') || path.includes('/adviser/queue')) {
              assert(await evaluate(`document.body.textContent.includes('preliminary')&&!!document.querySelector('[name="priority"][value="emergency"]')&&!!document.getElementById('queueAvailabilityNotice')`),`${user} ${width}px urgency and availability explained`);
            }
            const screenshot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
            fs.writeFileSync(`scratch/final-clinical-20261028/browser/${user}-${width}-${path.split('/').pop().split('?')[0].replace('.php','')}.png`, Buffer.from(screenshot.data, 'base64'));
          }
        }
        assert(errors.length === 0, user + ' modified pages have no uncaught JavaScript errors');
        socket.removeEventListener('message', listener);
      } finally { await send('Target.disposeBrowserContext', { browserContextId }); }
    }
    if(process.env.FINAL_ACTIVATION_ONLY) {
      const invitations=JSON.parse(process.env.VERIFICATION_ACTIVATIONS);
      for(const invitation of invitations) {
        const {browserContextId}=await send('Target.createBrowserContext');
        try {
          const {targetId}=await send('Target.createTarget',{url:'about:blank',browserContextId});
          const {sessionId}=await send('Target.attachToTarget',{targetId,flatten:true});
          const call=(method,params)=>send(method,params,sessionId);
          const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error('Activation browser expression failed');return r.result.value;};
          const wait=async expression=>{for(let n=0;n<250;n++){if(await evaluate(expression))return;await sleep(100);}throw Error('Activation browser wait failed for '+invitation.username);};
          await call('Page.enable');await call('Runtime.enable');
          const width=invitation.username.includes('390')?390:1440;
          await call('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});
          await call('Page.navigate',{url:invitation.link});
          await wait(`document.readyState==='complete'&&!!document.querySelector('#activation-password')`);
          assert(await evaluate(`!location.hash&&document.documentElement.scrollWidth<=innerWidth+1`),`${invitation.username} private activation clears URL token and fits viewport`);
          await evaluate(`document.querySelector('#activation-password').value='BrowserPrivate!42';document.querySelector('#activation-confirmation').value='BrowserPrivate!42';document.querySelector('#activation-password').form.requestSubmit()`);
          await wait(`document.body.textContent.includes('Your account is active.')`);
          assert(true,invitation.username+' activates with private initial password');
          await call('Page.navigate',{url:base+'/auth/login.php'});
          await wait(`!!document.querySelector('[name="identifier"]')`);
          await evaluate(`document.querySelector('[name="identifier"]').value=${JSON.stringify(invitation.username)};document.querySelector('[name="password"]').value='BrowserPrivate!42';document.querySelector('[name="identifier"]').form.requestSubmit()`);
          await wait(`!location.pathname.endsWith('/auth/login.php')&&document.readyState==='complete'`);
          assert(await evaluate(`!location.pathname.includes('/admin/')&&!location.pathname.includes('/doctor/')`),invitation.username+' signs in without privileged role escalation');
          const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync(`scratch/final-clinical-20261028/browser/activated-${invitation.username}.png`,Buffer.from(shot.data,'base64'));
        } finally {await send('Target.disposeBrowserContext',{browserContextId});}
      }
    }
  } finally { socket.close(); }
})().catch(error => { console.error('FAIL ' + error.message); process.exitCode = 1; });
