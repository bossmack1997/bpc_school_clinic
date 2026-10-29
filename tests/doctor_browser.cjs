/* Chrome/CDP against the schema-only local HTTP fixture. */
const fs=require('node:fs'),base=process.env.DOCTOR_TEST_BASE,patient=Number(process.env.DOCTOR_TEST_PATIENT);
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base||'')||!patient)throw Error('Isolated Doctor fixture required');
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
   for(let i=0;i<200;i++){if(await evaluate(`document.readyState==='complete'&&!!document.querySelector(${JSON.stringify(selector)})`)){for(let n=0;n<45;n++){if(await evaluate(`!document.querySelector('.page-loader')||getComputedStyle(document.querySelector('.page-loader')).visibility==='hidden'||getComputedStyle(document.querySelector('.page-loader')).display==='none'`))return;await sleep(100);}return;}await sleep(100);}throw Error('Page did not load: '+path);
  }
  await navigate('/auth/login.php','[name="identifier"]');
  await evaluate(`document.querySelector('[name="identifier"]').value='accept_http';document.querySelector('[name="password"]').value='FixturePass!42';document.querySelector('form').submit()`);
  await sleep(800);fs.mkdirSync('scratch/doctor-browser',{recursive:true});
  for(const width of [320,360,375,390,430,768,1024,1440]){
   await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
   for(const path of ['/pages/doctor/dashboard.php','/pages/doctor/queue.php','/pages/doctor/patients.php?patient='+patient+'&tab=consultations','/pages/doctor/inventory.php','/pages/doctor/emergencies.php','/pages/doctor/reports.php','/pages/doctor/profile.php','/pages/doctor/settings.php','/pages/doctor_messages.php']){
    await navigate(path);
    assert(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),width+'px no horizontal overflow '+path.split('?')[0]);
    assert(await evaluate(`!document.querySelector('.page-loader')||getComputedStyle(document.querySelector('.page-loader')).visibility==='hidden'`),width+'px content is not blocked by loader');
    if(path.includes('dashboard')){
     assert(await evaluate(`document.body.textContent.includes('Doctor Dashboard')&&document.querySelector('.role-badge').textContent.includes('Doctor')`),width+'px clinical identity and command center');
     assert(await evaluate(`!Array.from(document.querySelectorAll('.sidebar a')).some(a=>/MICA|ai_assistant/.test(a.textContent+' '+a.getAttribute('href')))`),width+'px no MICA sidebar item');
     assert(await evaluate(`!!document.querySelector('#mica-assistant')`),width+'px floating MICA retained');
     assert(await evaluate(`window.MICA_USER.profile==='doctor'`),width+'px MICA uses verified Doctor profile');
     if(width===320||width===1440){
      await evaluate(`window.MICA.open()`);await sleep(250);
      assert(await evaluate(`document.querySelector('#micaChips')?.textContent.includes('pending nurse') || document.body.textContent.includes('Nurse Approvals')`),width+'px MICA shows Nurse review suggestions');
      assert(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),width+'px open MICA fits viewport');
      assert(await evaluate(`(()=>{const r=document.getElementById('micaSendBtn').getBoundingClientRect();return r.left>=0&&r.right<=innerWidth;})()`),width+'px MICA send button remains visible');
      const micaShot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/doctor-browser/mica-'+width+'.png',Buffer.from(micaShot.data,'base64'));
      await evaluate(`window.MICA.close()`);
     }
     if(width<1200){
      await evaluate(`document.getElementById('sidebarToggle').click()`);
      assert(await evaluate(`document.querySelector('.sidebar').classList.contains('show')&&document.getElementById('sidebarToggle').getAttribute('aria-expanded')==='true'`),width+'px sidebar opens with accessible state');
      await evaluate(`document.querySelector('.sidebar-close').click()`);
      assert(await evaluate(`!document.querySelector('.sidebar').classList.contains('show')&&document.getElementById('sidebarToggle').getAttribute('aria-expanded')==='false'`),width+'px sidebar closes with accessible state');
     }
     if(width<768)assert(await evaluate(`(()=>{const f=document.querySelector('.mica-fab');if(!f)return false;const a=f.getBoundingClientRect();return Array.from(document.querySelectorAll('nav[aria-label="Clinical quick actions"] .btn')).every(b=>{const r=b.getBoundingClientRect();return r.right<=a.left||r.left>=a.right||r.bottom<=a.top||r.top>=a.bottom;});})()`),width+'px MICA launcher does not cover clinical quick actions');
     const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/doctor-browser/dashboard-'+width+'.png',Buffer.from(shot.data,'base64'));
    }
   }
   await navigate('/pages/doctor/patients.php?patient='+patient+'&tab=notes');
   assert(await evaluate(`!!document.querySelector('[name="chief_complaint"]')&&document.querySelector('#selectedPatient').dataset.patientId==='${patient}'`),width+'px patient-specific clinical notes form');
   await evaluate(`document.querySelector('[data-clinical-draft] button').click()`);
   for(let n=0;n<100;n++){if(await evaluate(`document.getElementById('clinicalDraft').textContent.includes('MICA DRAFT')`))break;await sleep(100);}
   assert(await evaluate(`document.getElementById('clinicalDraft').textContent.includes('MICA DRAFT')`),width+'px patient history draft button works');
   const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/doctor-browser/patient-'+width+'.png',Buffer.from(shot.data,'base64'));
  }
  assert(errors.length===0,'Doctor pages have no uncaught browser JavaScript exceptions');
 }finally{await send('Target.disposeBrowserContext',{browserContextId});socket.close();}
})().catch(error=>{console.error('FAIL '+error.message);process.exitCode=1;});
