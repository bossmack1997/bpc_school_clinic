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
   await navigate('/pages/doctor/nurse_verification.php?view='+patient);
   assert(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),width+'px staff verification has no viewport overflow');
   await evaluate(`document.querySelector('[data-bs-target="#authorizeModal"]').click()`);await sleep(400);
   assert(await evaluate(`(()=>{const m=document.querySelector('#authorizeModal .modal-content').getBoundingClientRect();return m.left>=0&&m.right<=innerWidth+1&&!!document.querySelector('#authorizeModal [name="review_password"]');})()`),width+'px review modal fits and requires reauthentication');
   assert(await evaluate(`Array.from(document.querySelectorAll('#authorizeModal .modal-footer .btn')).every(b=>b.getBoundingClientRect().height<=60)`),width+'px modal actions remain compact');
   const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/doctor-browser/staff-'+width+'.png',Buffer.from(shot.data,'base64'));
  }
  assert(errors.length===0,'Staff verification has no uncaught JavaScript exceptions');
 }finally{await send('Target.disposeBrowserContext',{browserContextId});socket.close();}
})().catch(error=>{console.error('FAIL '+error.message);process.exitCode=1;});
