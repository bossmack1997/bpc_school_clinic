/* Isolated loopback registration rendering, real Chrome/CDP. */
const fs=require('node:fs'),base=process.env.REG_TEST_BASE;
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base||''))throw Error('Local fixture required');
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 const info=await(await fetch('http://127.0.0.1:9337/json/version')).json(),ws=new WebSocket(info.webSocketDebuggerUrl);
 await new Promise(r=>ws.addEventListener('open',r,{once:true}));let id=0;const pending=new Map();
 ws.addEventListener('message',e=>{const m=JSON.parse(e.data),p=pending.get(m.id);if(p){pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}});
 const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const n=++id;pending.set(n,{resolve,reject});ws.send(JSON.stringify({id:n,method,params,...(sessionId?{sessionId}:{})}));});
 const {targetId}=await send('Target.createTarget',{url:'about:blank'}),{sessionId}=await send('Target.attachToTarget',{targetId,flatten:true});
 const call=(m,p)=>send(m,p,sessionId),ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error('Browser evaluation failed');return r.result.value;};
 const check=(ok,label)=>{if(!ok)throw Error(label);console.log('PASS '+label);};
 try{
  await call('Page.enable');await call('Network.clearBrowserCookies');
  for(const width of[320,360,375,390,430,768,1024,1440]){
   await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<768});await call('Page.navigate',{url:base+'/auth/register.php'});
   let ready=false;for(let n=0;n<300;n++){await sleep(100);if(await ev(`document.readyState==='complete'&&!!document.querySelector('#regPwToggle')`)){ready=true;break;}}check(ready,width+'px registration renders');await sleep(800);
   check(await ev(`!document.querySelector('.pwa-install-banner')||getComputedStyle(document.querySelector('.pwa-install-banner')).display==='none'`),width+'px install banner does not obstruct registration');
   const controls=await ev(`(()=>{return ['regPw','regConfirmPw'].map(id=>{const i=document.getElementById(id),b=document.getElementById(id+'Toggle'),r=b.getBoundingClientRect();return {width:r.width,height:r.height,icon:parseFloat(getComputedStyle(b.querySelector('i')).fontSize),input:i.getBoundingClientRect().width}})})()`);
   check(controls.every(c=>c.width>=43.9&&c.width<=44.1&&c.height>=43.9&&c.height<=44.1&&c.icon>=18&&c.icon<=20&&c.input>80),width+'px compact aligned 44px visibility controls');
   check(await ev(`document.documentElement.scrollWidth<=innerWidth+1&&!document.getElementById('validIdInput').required&&!!document.querySelector('#profilePicInput, [name="profile_picture"]')`),width+'px no overflow and optional upload fields preserved');
   for(const input of['regPw','regConfirmPw']){
    check(await ev(`(()=>{const i=document.getElementById('${input}'),b=document.getElementById('${input}Toggle');i.value='FixturePass!42';b.click();const shown=i.type==='text'&&b.getAttribute('aria-pressed')==='true'&&b.getAttribute('aria-label').startsWith('Hide');b.click();return shown&&i.type==='password'&&i.value==='FixturePass!42'&&document.activeElement===i&&b.getAttribute('aria-label')==='Show '+(i.name==='confirm_password'?'confirm password':'password')})()`),width+'px '+input+' toggles twice preserving value, focus and labels');
   }
   if(width===390){await ev(`document.getElementById('regPw').scrollIntoView({block:'center'})`);await sleep(200);fs.mkdirSync('scratch/registration-browser',{recursive:true});const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/registration-browser/password-390.png',Buffer.from(shot.data,'base64'));await call('Emulation.setDeviceMetricsOverride',{width,height:460,deviceScaleFactor:1,mobile:true});await ev(`document.getElementById('certify').scrollIntoView({block:'center'})`);await sleep(600);check(await ev(`(()=>{const r=document.getElementById('certify').getBoundingClientRect();return r.top>=0&&r.bottom<=innerHeight})()`),'Reduced-height viewport can scroll to final certification');}
  }

  await call('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
  await ev(`(()=>{const f=document.querySelector('#unifiedRegForm');for(const [name,value] of Object.entries({first_name:'Alex',last_name:'Fixture',username:'http_student',email:'browserduplicate@example.invalid',password:'FixturePass!42',confirm_password:'FixturePass!42',student_number:'BROWSER-ST001'})){f.elements[name].value=value}f.elements.certify.checked=true;f.submit()})()`);
  for(let n=0;n<150;n++){await sleep(100);if(await ev(`document.readyState==='complete'&&!!document.querySelector('#usernameInputError')`))break;}
  check(await ev(`(()=>{const i=document.querySelector('#usernameInput'),e=document.querySelector('#usernameInputError'),g=i.closest('.input-group');return e&&!g.contains(e)&&e.getBoundingClientRect().top>=g.getBoundingClientRect().bottom-1&&i.value==='http_student'&&e.textContent.includes('already registered')})()`),'Server-confirmed duplicate appears below editable username with value preserved');
  check(await ev(`(()=>{const i=document.querySelector('#usernameInput');i.value='New_User123';i.dispatchEvent(new Event('input',{bubbles:true}));const cleared=!document.querySelector('#usernameInputError');i.value='';return cleared&&i.placeholder==='Choose a username'&&i.getBoundingClientRect().width>80})()`),'Editing username clears stale server error and preserves empty placeholder');
 }finally{await send('Target.closeTarget',{targetId});ws.close();}
})().catch(e=>{console.error('FAIL '+e.message);process.exit(1);});
