/* Real Chrome/CDP against disposable loopback fixture. Never print invitation URLs. */
const fs = require('node:fs');
const base = process.env.MIS_TEST_BASE;
const tokens = JSON.parse(process.env.MIS_INVITATION_TOKENS || '{}');
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base || '')) throw Error('Loopback fixture required');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
  const info = await (await fetch('http://127.0.0.1:9337/json/version')).json();
  const socket = new WebSocket(info.webSocketDebuggerUrl);
  await new Promise(resolve => socket.addEventListener('open', resolve, {once:true}));
  let serial = 0;
  const pending = new Map();
  socket.addEventListener('message', event => {
    const message = JSON.parse(event.data), task = pending.get(message.id);
    if (!task) return;
    pending.delete(message.id);
    message.error ? task.reject(Error('CDP request failed')) : task.resolve(message.result);
  });
  const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
    const id = ++serial; pending.set(id, {resolve,reject});
    socket.send(JSON.stringify({id,method,params,...(sessionId ? {sessionId} : {})}));
  });
  const assert = (ok, label) => {if (!ok) throw Error(label); console.log('PASS '+label);};
  fs.mkdirSync('scratch/invitation-browser', {recursive:true});
  try {
    for (const [device,width] of [['mobile',390],['desktop',1366]]) {
      if (!/^[a-f0-9]{64}$/.test(tokens[device] || '')) throw Error('Synthetic token required');
      const {browserContextId} = await send('Target.createBrowserContext');
      try {
        const {targetId} = await send('Target.createTarget', {url:'about:blank',browserContextId});
        const {sessionId} = await send('Target.attachToTarget', {targetId,flatten:true});
        const call = (method,params) => send(method,params,sessionId);
        const evaluate = async expression => {
          const result = await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
          if (result.exceptionDetails) throw Error('Browser evaluation failed');
          return result.result.value;
        };
        async function ready(selector) {
          for (let attempt=0;attempt<150;attempt++) {
            if (await evaluate(`document.readyState==='complete'&&!!document.querySelector(${JSON.stringify(selector)})`)) return;
            await sleep(100);
          }
          throw Error(device+' page did not load');
        }
        await call('Page.enable'); await call('Network.enable');
        let secretInUrl = false;
        const watch = event => {
          const message = JSON.parse(event.data);
          if (message.sessionId===sessionId && message.method==='Network.requestWillBeSent') {
            const url=message.params.request.url.split('#')[0];
            if (url.includes(tokens[device])) secretInUrl=true;
          }
        };
        socket.addEventListener('message',watch);
        await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:device==='mobile'});
        if (device==='mobile') await call('Network.setUserAgentOverride',{userAgent:'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36'});
        await call('Page.navigate',{url:base+'/auth/setup_account.php#token='+tokens[device]});
        await ready('#setupAccountForm');
        assert(await evaluate(`location.hash===''&&location.search===''`),device+' fresh browser verifies fragment without an administrator session');
        for (const viewport of device==='mobile'?[320,360,390,430]:[1024,1366]) {
          await call('Emulation.setDeviceMetricsOverride',{width:viewport,height:900,deviceScaleFactor:1,mobile:device==='mobile'});
          assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),device+' '+viewport+'px has no horizontal overflow');
        }
        // Screenshots exclude the hidden token and contain synthetic identity only.
        const screen=await call('Page.captureScreenshot',{format:'png'});
        fs.writeFileSync('scratch/invitation-browser/'+device+'.png',Buffer.from(screen.data,'base64'));
        await evaluate(`document.getElementById('password').value='BrowserPass!42';document.getElementById('confirm_password').value='wrong';document.getElementById('setupAccountForm').requestSubmit();`);
        assert(await evaluate(`document.getElementById('passwordError').textContent.includes('do not match')`),device+' displays password mismatch');
        await evaluate(`document.getElementById('togglePassword').click()`);
        assert(await evaluate(`document.getElementById('password').type==='text'`),device+' password visibility works');
        await evaluate(`document.getElementById('confirm_password').value='BrowserPass!42';document.getElementById('setupAccountForm').requestSubmit()`);
        await ready('.alert-success');
        assert(await evaluate(`document.body.textContent.includes('Your account is active.')`),device+' activates with a private password');
        await call('Page.navigate',{url:base+'/auth/setup_account.php#token='+tokens[device]});
        // The initial shell has an invalid-link alert; wait for the fragment POST.
        for(let attempt=0;attempt<150;attempt++) {
          if(await evaluate(`location.hash===''&&document.body&&document.body.textContent.includes('already been used')`))break;
          await sleep(100);
        }
        assert(await evaluate(`document.body.textContent.includes('already been used')&&!document.querySelector('#setupAccountForm')`),device+' replay displays a friendly used-link message');
        for(const [token,message] of [[tokens.expired,'has expired'],['not-a-valid-token','invitation is invalid']]) {
          await call('Page.navigate',{url:base+'/auth/setup_account.php#token='+token});
          for(let attempt=0;attempt<150;attempt++) {
            if(await evaluate(`location.hash===''&&document.body&&document.body.textContent.includes(${JSON.stringify(message)})`))break;
            await sleep(100);
          }
          assert(await evaluate(`document.body.textContent.includes(${JSON.stringify(message)})&&!document.querySelector('#setupAccountForm')`),device+' displays '+message+' without an activation form');
        }
        assert(!secretInUrl,device+' token never appears in HTTP request URLs');
        socket.removeEventListener('message',watch);
      } finally {await send('Target.disposeBrowserContext',{browserContextId});}
    }
  } finally {socket.close();}
})().catch(error => {console.error('FAIL '+String(error.message).replace(/[a-f0-9]{64}/g,'[redacted]'));process.exitCode=1;});
