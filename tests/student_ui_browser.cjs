/* Chrome/CDP against the schema-only local HTTP fixture. */
const fs=require('node:fs'),base=process.env.STUDENT_UI_BASE,chat=Number(process.env.STUDENT_UI_CHAT),nurse=Number(process.env.STUDENT_UI_NURSE);
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base||'')||!chat||!nurse)throw Error('Isolated student UI fixture required');
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const widths=process.env.STUDENT_UI_WIDTHS?process.env.STUDENT_UI_WIDTHS.split(',').map(Number):[320,360,375,390,430,768,1024,1440];
if(widths.some(w=>![320,360,375,390,430,768,1024,1440].includes(w)))throw Error('Unsupported viewport');
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
  let checks=0;const check=(ok,label)=>{assert(ok,label);checks++;};
  async function waitFor(expression){for(let n=0;n<120;n++){if(await evaluate(expression))return;await sleep(100);}throw Error('Condition timed out: '+expression);}
  async function login(name){await navigate('/auth/login.php','[name="identifier"]');await evaluate(`document.querySelector('[name="identifier"]').value=${JSON.stringify(name)};document.querySelector('[name="password"]').value='FixturePass!42';document.querySelector('form').submit()`);await waitFor(`!location.pathname.includes('/auth/login.php')&&document.readyState==='complete'`);}
  async function logout(){await call('Page.navigate',{url:base+'/auth/logout.php'});await sleep(500);}
  fs.mkdirSync('scratch/student-ui-browser',{recursive:true});await login('ui_student');
  for(const width of widths){
   await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
   for(const path of ['/pages/student/dashboard.php','/pages/student/profile.php','/pages/student/my_qr.php','/pages/student/messages.php?id='+chat]){
    await navigate(path);
    if(path.includes('my_qr'))await waitFor(`document.getElementById('studentAllergies').textContent.includes('RecordedAllergy')&&!!document.querySelector('#qrCanvas canvas,#qrCanvas img')`);
    if(path.includes('messages'))await waitFor(`document.querySelectorAll('.messenger-avatar,.staff-avatar').length>1`);
    check(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),width+'px no overflow '+path);
    check(await evaluate(`(()=>{const bar=document.querySelector('.topbar'),r=bar.getBoundingClientRect();return r.width<=innerWidth+1&&document.getElementById('sidebarToggle').getBoundingClientRect().width<=44&&Array.from(bar.querySelectorAll('.topbar-right .topbar-btn,.profile-trigger')).every(el=>{const b=el.getBoundingClientRect();return b.right<=innerWidth+1;});})()`),width+'px compact aligned shared header '+path);
    if(path.includes('my_qr')){
     check(await evaluate(`['#qrStudentInfo .hp-name','#studentName','#studentCourse','#qrStatusBadge'].every(selector=>{const el=document.querySelector(selector),r=el.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&el.scrollWidth<=el.clientWidth+1;})`),width+'px long QR identity and status fit');
     check(await evaluate(`['studentEmail','studentAllergies','studentEmergency','studentGender'].every(id=>{const el=document.getElementById(id),r=el.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&el.scrollWidth<=el.clientWidth+1&&el.textContent.trim()!=='—';})`),width+'px complete long patient fields fit');
     check(await evaluate(`(()=>{const image=Array.from(document.querySelectorAll('#qrCanvas canvas,#qrCanvas img')).find(i=>i.getBoundingClientRect().width>0),r=image?.getBoundingClientRect();return !!r&&r.width>0&&Math.abs(r.width-r.height)<2&&r.right<=innerWidth;})()`),width+'px QR remains square');
     await evaluate(`window.__download=null;HTMLAnchorElement.prototype.click=function(){window.__download={href:this.href,name:this.download};};document.getElementById('downloadQrBtn').click();`);
     check(await evaluate(`!!window.__download&&window.__download.href.startsWith('data:image/png')&&window.__download.name.includes('UI-STUDENT-01')`),width+'px actual QR download handler produces PNG');
     await evaluate(`window.__printHTML='';window.__printCalled=false;window.open=()=>({document:{write(html){window.__printHTML=html;},close(){}},focus(){},print(){window.__printCalled=true;}});document.getElementById('printQrBtn').click();`);await sleep(500);
     check(await evaluate(`window.__printCalled&&window.__printHTML.includes('UI-STUDENT-01')&&window.__printHTML.includes('data:image/png')`),width+'px actual QR print handler prepares patient pass');
    }
    if(path.includes('profile.php')&&width===1440){
     const before=await evaluate(`document.getElementById('profileAvatar').src`);
     await evaluate(`(()=>{const bytes=Uint8Array.from(atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aE1sAAAAASUVORK5CYII='),c=>c.charCodeAt(0));const transfer=new DataTransfer();transfer.items.add(new File([bytes],'updated.png',{type:'image/png'}));const input=document.getElementById('pictureInput');input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));})()`);
     await waitFor(`document.getElementById('profileAvatar').src!==${JSON.stringify(before)}&&document.querySelector('.topbar .avatar').src===document.getElementById('profileAvatar').src`);
     check(true,'Actual profile upload updates both profile and shared header avatar');await sleep(1500);
    }
    if(path.includes('messages')){await sleep(500);check(await evaluate(`Array.from(document.querySelectorAll('.messenger-avatar,.staff-avatar,.message-avatar')).every(img=>img.complete&&img.naturalWidth>0)`),width+'px messaging avatars load');}
    if(path.includes('dashboard')){
     await waitFor(`document.querySelectorAll('[data-announcement-slide]').length===2`);
     check(await evaluate(`!!document.querySelector('.dbg-wb-hours')&&!document.body.textContent.includes('Hidden fixture')`),width+'px published announcements and operating hours');
     if(width===320){
      const initial=await evaluate(`Array.from(document.querySelectorAll('[data-announcement-slide]')).findIndex(s=>!s.hidden)`);await sleep(6400);
      check(await evaluate(`Array.from(document.querySelectorAll('[data-announcement-slide]')).findIndex(s=>!s.hidden)!==${initial}`),'Carousel rotates after six seconds');
      await evaluate(`document.querySelector('[data-carousel-next]').click()`);const pausedIndex=await evaluate(`Array.from(document.querySelectorAll('[data-announcement-slide]')).findIndex(s=>!s.hidden)`);await sleep(6400);
      check(await evaluate(`document.querySelector('[data-carousel-play]').textContent==='Play'&&Array.from(document.querySelectorAll('[data-announcement-slide]')).findIndex(s=>!s.hidden)===${pausedIndex}`),'Manual interaction pauses autoplay');
      await evaluate(`document.querySelector('[data-announcement-carousel]').dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowLeft',bubbles:true}))`);
      check(await evaluate(`Array.from(document.querySelectorAll('[data-announcement-slide]')).findIndex(s=>!s.hidden)!==${pausedIndex}`),'Carousel supports keyboard navigation');
      const beforeSwipe=await evaluate(`Array.from(document.querySelectorAll('[data-announcement-slide]')).findIndex(s=>!s.hidden)`);
      await evaluate(`(()=>{const c=document.querySelector('[data-announcement-carousel]');c.dispatchEvent(new PointerEvent('pointerdown',{clientX:220,bubbles:true,pointerType:'touch'}));c.dispatchEvent(new PointerEvent('pointerup',{clientX:80,bubbles:true,pointerType:'touch'}));})()`);
      check(await evaluate(`Array.from(document.querySelectorAll('[data-announcement-slide]')).findIndex(s=>!s.hidden)!==${beforeSwipe}`),'Carousel supports touch swipe');
     }
     if(width<1200){await evaluate(`document.getElementById('sidebarToggle').click()`);check(await evaluate(`document.querySelector('.sidebar').classList.contains('show')`),width+'px sidebar opens');await evaluate(`document.querySelector('.sidebar-close').click()`);check(await evaluate(`!document.querySelector('.sidebar').classList.contains('show')`),width+'px sidebar closes');}
    }
    if(width===320&&path.includes('my_qr')){await evaluate(`document.querySelector('.hp-details-card').scrollIntoView({block:'start',behavior:'instant'})`);await sleep(300);const detailShot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/student-ui-browser/medical-details-320.png',Buffer.from(detailShot.data,'base64'));await evaluate(`document.querySelector('.main-content').scrollTo({top:0,behavior:'instant'});window.scrollTo({top:0,behavior:'instant'})`);}
    if((width===320||width===1440)&&!path.includes('messages')){const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/student-ui-browser/'+path.split('/').pop().replace('.php','')+'-'+width+'.png',Buffer.from(shot.data,'base64'));}
   }
  }
  await call('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});await navigate('/pages/student/dashboard.php');const reducedIndex=await evaluate(`Array.from(document.querySelectorAll('[data-announcement-slide]')).findIndex(s=>!s.hidden)`);await sleep(6400);check(await evaluate(`document.querySelector('[data-carousel-play]').textContent==='Play'&&Array.from(document.querySelectorAll('[data-announcement-slide]')).findIndex(s=>!s.hidden)===${reducedIndex}`),'Reduced motion disables automatic rotation');await call('Emulation.setEmulatedMedia',{features:[]});
  await navigate('/pages/student/messages.php?id='+chat);await waitFor(`!!document.querySelector('.staff-avatar')`);await evaluate(`(()=>{const image=document.createElement('img');image.className='message-avatar';image.id='avatarFallbackProbe';document.body.append(image);image.src=APP_URL+'/assets/images/profiles/missing-fixture.png';})()`);await waitFor(`document.getElementById('avatarFallbackProbe').naturalWidth>0&&document.getElementById('avatarFallbackProbe').src.includes('default-avatar.svg')`);check(true,'Dynamic broken avatar uses safe loaded fallback');await evaluate(`document.getElementById('avatarFallbackProbe').remove()`);
  await logout();await login('ui_teacher');
  for(const width of widths){await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});await navigate('/pages/adviser/dashboard.php');check(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),width+'px shared Teacher dashboard remains responsive');}
  await logout();await login('ui_nurse');
  for(const width of widths){await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});for(const path of ['/pages/nurse/announcements.php','/pages/nurse/messages.php?id='+chat,'/pages/doctor_messages.php']){await navigate(path);await sleep(300);check(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1`),width+'px Nurse publishing/messaging fits '+path);}}
  await navigate('/pages/nurse/announcements.php');await waitFor(`!document.getElementById('announcementFields').disabled`);
  await evaluate(`(()=>{const f=document.getElementById('announcementForm');f.reset();f.elements.title.value='Browser verified announcement';f.elements.content.value='Synthetic publish workflow';f.elements.category.value='general';f.elements.publication_status.value='published';f.elements.audience.value='all';f.requestSubmit();})()`);
  await waitFor(`document.getElementById('announcementsList').textContent.includes('Browser verified announcement')`);check(true,'Nurse publishing form saves a real announcement');
  await logout();await login('ui_student');await navigate('/pages/student/dashboard.php');check(await evaluate(`document.querySelector('[data-announcement-carousel]').textContent.includes('Browser verified announcement')`),'Published Nurse update appears on Student dashboard');
  check(errors.length===0,'Patient, Teacher and Nurse pages have no uncaught JavaScript exceptions');console.log(checks+' student UI browser checks passed.');
 }finally{await send('Target.disposeBrowserContext',{browserContextId});socket.close();}
})().catch(error=>{console.error('FAIL '+error.message);process.exitCode=1;});
