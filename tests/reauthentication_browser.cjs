/* Chrome/CDP against the schema-only local HTTP fixture. */
const fs=require('node:fs'),base=process.env.REAUTH_TEST_BASE;
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base||''))throw Error('Isolated reauthentication fixture required');
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{
 const info=await(await fetch('http://127.0.0.1:9337/json/version')).json(),socket=new WebSocket(info.webSocketDebuggerUrl);
 await new Promise(resolve=>socket.addEventListener('open',resolve,{once:true}));let id=0;const pending=new Map();
 socket.addEventListener('message',event=>{const m=JSON.parse(event.data),task=pending.get(m.id);if(task){pending.delete(m.id);m.error?task.reject(Error('CDP failed')):task.resolve(m.result);}});
 const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const n=++id;pending.set(n,{resolve,reject});socket.send(JSON.stringify({id:n,method,params,...(sessionId?{sessionId}:{})}));});
 const {browserContextId}=await send('Target.createBrowserContext');
 try{
  const {targetId}=await send('Target.createTarget',{url:'about:blank',browserContextId}),{sessionId}=await send('Target.attachToTarget',{targetId,flatten:true});
  const call=(method,params)=>send(method,params,sessionId),evaluate=async expression=>{const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw Error('Browser expression failed');return result.result.value;};
  const assert=(ok,label)=>{if(!ok)throw Error(label);console.log('PASS '+label);};
  const errors=[];socket.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.sessionId===sessionId&&m.method==='Runtime.exceptionThrown')errors.push('runtime exception');});
  await call('Page.enable');await call('Runtime.enable');
  async function navigate(path,selector='main,.content-area'){
   await call('Page.navigate',{url:base+path});
   for(let i=0;i<200;i++){if(await evaluate(`location.pathname===${JSON.stringify(path.split('?')[0])}&&document.readyState==='complete'&&!!document.querySelector(${JSON.stringify(selector)})`)){for(let n=0;n<45;n++){if(await evaluate(`!document.querySelector('.page-loader')||getComputedStyle(document.querySelector('.page-loader')).visibility==='hidden'||getComputedStyle(document.querySelector('.page-loader')).display==='none'`))return;await sleep(100);}return;}await sleep(100);}throw Error('Page did not load: '+path);
  }
  let checks=0,secretInQuery=false;const check=(ok,label)=>{assert(ok,label);checks++;};
  await call('Network.enable');socket.addEventListener('message',event=>{const m=JSON.parse(event.data);if(m.sessionId!==sessionId||m.method!=='Network.requestWillBeSent')return;try{for(const key of new URL(m.params.request.url).searchParams.keys())if(/password|csrf|token|session|phpsessid/i.test(key))secretInQuery=true;}catch{}});
  async function waitFor(expression){for(let n=0;n<150;n++){if(await evaluate(expression))return;await sleep(100);}throw Error('Browser condition timed out');}
  async function login(username='mis_fixture'){await navigate('/auth/login.php','[name="identifier"]');await evaluate(`document.querySelector('[name="identifier"]').value=${JSON.stringify(username)};document.querySelector('[name="password"]').value='FixturePass!42';document.querySelector('form').submit()`);await waitFor(`!location.pathname.includes('/auth/login.php')&&document.readyState==='complete'`);}
  async function cookie(){const result=await call('Network.getCookies',{urls:[base]});const item=result.cookies.find(item=>item.name==='PHPSESSID');if(!item||!/^[a-zA-Z0-9,-]+$/.test(item.value))throw Error('Synthetic session cookie unavailable');return item;}
  async function age(key,seconds){const owned=await cookie(),path='scratch/sess_'+owned.value,pattern=new RegExp(key+'\\|i:[0-9]+;');for(let attempt=0;attempt<120;attempt++){try{const source=fs.readFileSync(path,'utf8');if(!pattern.test(source))throw Error('Synthetic session field unavailable');fs.writeFileSync(path,source.replace(pattern,key+'|i:'+(Math.floor(Date.now()/1000)-seconds)+';'));return;}catch(error){if(!['EBUSY','EACCES','EPERM'].includes(error.code))throw error;await sleep(50);}}throw Error('Synthetic session remained busy');}
  fs.mkdirSync('scratch/reauthentication-browser',{recursive:true});
  for(const width of [320,390,768,1440]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
    await call('Network.clearBrowserCookies');await login();await navigate('/pages/admin/admin_accounts.php');
    const originalCookie=await cookie();check(originalCookie.httpOnly&&originalCookie.sameSite==='Lax'&&!originalCookie.secure,width+'px local session cookie has HttpOnly and SameSite=Lax');
    await age('password_confirmed_at',600);
    await evaluate(`fetch(APP_URL+'/pages/admin/admin_accounts.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest',Accept:'application/json','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'invite_doctor',csrf_token:document.querySelector('meta[name=csrf-token]').content})}).then(r=>window.__confirmationStatus=r.status)`);
    await waitFor(`document.querySelector('#authenticationRequiredModal.show')`);
    check(await evaluate(`window.__confirmationStatus===428&&document.getElementById('authenticationRequiredTitle').textContent==='Confirm your identity'`),width+'px sensitive fetch shows password-confirmation dialog');
    check(await evaluate(`(()=>{const r=document.querySelector('#authenticationRequiredModal .modal-content').getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1;})()`),width+'px confirmation modal fits viewport');
    await evaluate(`document.getElementById('authenticationRequiredContinue').click()`);await waitFor(`location.pathname.endsWith('/auth/confirm_password.php')&&!!document.querySelector('[name=password]')&&document.readyState==='complete'`);
    const csrfBefore=await evaluate(`document.querySelector('[name=csrf_token]').value`);
    check(await evaluate(`document.querySelector('form').method==='post'&&document.querySelector('[name=password]').type==='password'`),width+'px secure confirmation uses masked POST password field');
    await evaluate(`document.querySelector('[name=password]').value='wrong';document.querySelector('form').requestSubmit()`);await waitFor(`!!document.querySelector('[role=alert]')&&document.readyState==='complete'`);
    check(await evaluate(`document.querySelector('[role=alert]').textContent.includes('confirmation failed')&&document.querySelector('[name=password]').value===''`),width+'px wrong confirmation leaves password field empty and action blocked');
    await evaluate(`document.querySelector('[name=password]').value='FixturePass!42';document.querySelector('form').requestSubmit()`);await waitFor(`location.pathname.endsWith('/pages/admin/admin_accounts.php')&&!!document.querySelector('[name=request_nonce]')&&document.readyState==='complete'`);
    check((await cookie()).value!==originalCookie.value&&await evaluate(`document.querySelector('meta[name=csrf-token]').content!==${JSON.stringify(csrfBefore)}`),width+'px confirmation rotates session ID and CSRF');
    await age('last_activity',7200);
    await evaluate(`void jQuery.get(APP_URL+'/api/notifications.php?action=list').fail(()=>{})`);await waitFor(`document.querySelector('#authenticationRequiredModal.show')`);
    check(await evaluate(`document.getElementById('authenticationRequiredTitle').textContent==='Sign in again'&&document.getElementById('authenticationRequiredContinue').href.includes('/auth/login.php?timeout=1')`),width+'px expired jQuery action opens sign-in dialog');
    await evaluate(`fetch(APP_URL+'/api/notifications.php?action=list').then(r=>window.__expiredStatus=r.status)`);await sleep(200);
    check(await evaluate(`window.__expiredStatus===401&&document.querySelectorAll('#authenticationRequiredModal').length===1`),width+'px repeated expired requests retain one dialog and HTTP 401');
    const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/reauthentication-browser/session-'+width+'.png',Buffer.from(shot.data,'base64'));
    await evaluate(`document.getElementById('authenticationRequiredContinue').click()`);await waitFor(`location.pathname.endsWith('/auth/login.php')&&document.readyState==='complete'&&!!document.querySelector('[name=identifier]')`);
    check(await evaluate(`document.body.textContent.includes('Your session expired')&&!document.body.textContent.trim().startsWith('{')`),width+'px expired session reaches actual login interface');
    await login();await age('last_activity',7200);await call('Page.navigate',{url:base+'/api/notifications.php?action=list'});await waitFor(`location.pathname.endsWith('/auth/login.php')&&document.readyState==='complete'&&!!document.querySelector('[name=identifier]')`);
    check(true,width+'px direct expired API navigation redirects to login HTML');
  }
  await call('Network.clearBrowserCookies');await login('accept_http');await navigate('/pages/doctor/dashboard.php');
  await evaluate(`fetch(APP_URL+'/api/users.php?action=list').then(r=>window.__forbiddenStatus=r.status)`);await waitFor(`window.__forbiddenStatus===403`);check(await evaluate(`!document.getElementById('authenticationRequiredModal')`),'Authorization denial remains 403 and does not trigger sign-in escalation');
  check(!secretInQuery,'Passwords CSRF and session credentials never enter query parameters');check(errors.length===0,'Reauthentication browser flows have no uncaught JavaScript exceptions');console.log(checks+' reauthentication browser checks passed.');
 }finally{await send('Target.disposeBrowserContext',{browserContextId});socket.close();}
})().catch(error=>{console.error('FAIL '+error.message);process.exitCode=1;});
