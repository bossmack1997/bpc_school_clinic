/* Real Chrome/CDP against the disposable MIS audit fixture only. */
const fs=require('node:fs'),base=process.env.MIS_AUDIT_BASE;
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base||''))throw Error('Disposable loopback fixture required');
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 const info=await(await fetch('http://127.0.0.1:9337/json/version')).json(),ws=new WebSocket(info.webSocketDebuggerUrl);
 await new Promise(r=>ws.addEventListener('open',r,{once:true}));let id=0;const pending=new Map(),errors=[];
 ws.addEventListener('message',e=>{const m=JSON.parse(e.data),p=pending.get(m.id);if(p){pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);});
 const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const n=++id;pending.set(n,{resolve,reject});ws.send(JSON.stringify({id:n,method,params,...(sessionId?{sessionId}:{})}));});
 const {browserContextId}=await send('Target.createBrowserContext'),{targetId}=await send('Target.createTarget',{url:'about:blank',browserContextId}),{sessionId}=await send('Target.attachToTarget',{targetId,flatten:true});
 const call=(m,p)=>send(m,p,sessionId),ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const check=(ok,label)=>{if(!ok)throw Error(label);console.log('PASS '+label);};
 async function wait(expression){for(let n=0;n<200;n++){if(await ev(expression))return;await sleep(100);}throw Error('Browser condition timed out: '+expression);}
 async function nav(path,selector){await call('Page.navigate',{url:base+path});await wait(`document.readyState==='complete'&&!!document.querySelector(${JSON.stringify(selector)})`);await sleep(500);}
 try{
  await call('Page.enable');await call('Runtime.enable');
  await nav('/auth/login.php','#loginForm');await ev(`document.querySelector('[name="identifier"]').value='audit_primary';document.querySelector('[name="password"]').value='FixturePass!42';document.getElementById('loginForm').requestSubmit()`);await sleep(800);
  fs.mkdirSync('scratch/mis-complete-browser',{recursive:true});
  for(const path of ['dashboard.php','users.php','admin_accounts.php','role_profiles.php','system_monitoring.php','security.php','activity_logs.php','backups.php','settings.php']){
   await nav('/pages/admin/'+path,'.sidebar');
   for(const width of [320,375,390,430,768,1024,1440,1920]){
    await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<768});await sleep(120);
    const overflow=await ev(`document.documentElement.scrollWidth>innerWidth+1`);
    if(overflow)console.log(JSON.stringify(await ev(`({width:innerWidth,scroll:document.documentElement.scrollWidth,bad:[...document.querySelectorAll('main *')].filter(e=>e.getBoundingClientRect().right>innerWidth+1).slice(0,6).map(e=>({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right}))})`)));
    check(!overflow,path+' '+width+'px contains its layout');
    check(await ev(`document.querySelectorAll('.mica-fab').length===1&&!document.querySelector('.sidebar').textContent.includes('MICA')`),path+' '+width+'px has one floating assistant');
   }
   if(path==='role_profiles.php')check(await ev(`document.querySelector('#profile-target').tagName==='SELECT'&&!!document.querySelector('.account-filter')&&!document.querySelector('input[name="target"][type="number"]')`),'Profile form uses searchable account selectors');
   if(path==='backups.php')await wait(`!document.getElementById('backupRows').textContent.includes('Loading')`);
   if(path==='system_monitoring.php'){await ev(`document.getElementById('refreshMonitoring').click()`);await wait(`document.getElementById('monitoringStatus').textContent.includes('Checks completed')`);check(await ev(`document.getElementById('healthApi').textContent.includes('Responded in')`),'Monitoring refresh probes the real API');}
   if(['security.php','backups.php','role_profiles.php'].includes(path)){await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await sleep(200);const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/mis-complete-browser/'+path+'.png',Buffer.from(shot.data,'base64'));}
  }
  await nav('/pages/admin/role_profiles.php','#profile-target');
  check(await ev(`(()=>{const s=document.querySelector('#profile-search');s.value='doctor';s.dispatchEvent(new Event('input'));return [...document.querySelector('#profile-target').options].filter(o=>o.value&&!o.hidden).every(o=>o.textContent.includes('doctor'))})()`),'Account search filters eligible administrator options');
  // The fixture does not log actual passwords or submit any live records.
  await ev(`(()=>{const f=document.getElementById('profile-target').form;f.elements.target.value=[...f.elements.target.options].find(o=>o.textContent.includes('doctor')).value;f.elements.profile.value='doctor';f.elements.license.value='TEST-PRC-2026';f.elements.reference.value='BROWSER-APPOINTMENT-2026';f.elements.current_password.value='FixturePass!42';f.elements.confirm_action.checked=true;f.requestSubmit()})()`);await sleep(700);await wait(`document.readyState==='complete'&&!!document.getElementById('profile-target')`);
  check(await ev(`document.body.textContent.includes('BROWSER-APPOINTMENT-2026')`),'Verified profile form persists appointment and audit history');
  await nav('/pages/admin/backups.php','#createBackup');await wait(`!document.getElementById('backupRows').textContent.includes('Loading')`);
  await ev(`window.confirm=()=>true;document.getElementById('createBackup').click()`);await wait(`!document.getElementById('createBackup').disabled`);
  check(await ev(`document.getElementById('backupAlert').classList.contains('alert-success')&&!!document.querySelector('.verify-backup')`),'Backup create button writes and refreshes real history');
  await ev(`document.querySelector('.verify-backup').click()`);await wait(`document.getElementById('backupAlert').textContent.includes('integrity verified')`);check(true,'Backup verify button succeeds');
  await ev(`(()=>{const f=document.getElementById('recoveryForm');f.elements.id.value=document.querySelector('.verify-backup').dataset.id;f.elements.target_database.value=${JSON.stringify(process.env.MIS_RECOVERY_DB)};f.elements.confirmation.value=${JSON.stringify(process.env.MIS_RECOVERY_DB)};f.elements.current_password.value='FixturePass!42';f.requestSubmit()})()`);await wait(`document.getElementById('backupAlert').textContent.includes('Recovery completed')`);check(await ev(`document.getElementById('recovery-password').value===''`),'Recovery form restores isolated target and clears reauthentication field');
  await ev(`window.fixtureFetch=window.fetch;window.fetch=async()=>({ok:false,text:async()=>'<html>upstream failed</html>'});document.getElementById('refreshBackups').click()`);await wait(`document.getElementById('backupAlert').textContent.includes('invalid response')`);check(await ev(`!document.getElementById('backupRows').textContent.includes('Loading')`),'Invalid backup response leaves a readable error without a stuck loader');
  check(errors.length===0,'No uncaught MIS browser exceptions');
 }finally{await send('Target.closeTarget',{targetId});await send('Target.disposeBrowserContext',{browserContextId});ws.close();}
})().catch(e=>{console.error('FAIL '+e.message);process.exit(1);});
