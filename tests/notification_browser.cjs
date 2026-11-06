const fs=require('node:fs');
const base=process.env.NOTIFICATION_TEST_BASE,ids=JSON.parse(process.env.NOTIFICATION_TEST_IDS||'{}');
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base||''))throw Error('Loopback fixture required');
const sleep=ms=>new Promise(r=>setTimeout(r,ms));let checks=0;
(async()=>{
 const info=await(await fetch('http://127.0.0.1:9337/json/version')).json(),ws=new WebSocket(info.webSocketDebuggerUrl);await new Promise(r=>ws.addEventListener('open',r,{once:true}));let seq=0;const pending=new Map();
 ws.addEventListener('message',event=>{const m=JSON.parse(event.data),p=pending.get(m.id);if(p){pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}});
 const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const id=++seq;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
 const {browserContextId}=await send('Target.createBrowserContext');
 try{
 const {targetId}=await send('Target.createTarget',{url:'about:blank',browserContextId}),{sessionId}=await send('Target.attachToTarget',{targetId,flatten:true});const call=(m,p)=>send(m,p,sessionId);
 const ev=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
 const check=(ok,label)=>{if(!ok)throw Error(label);checks++;console.log('PASS '+label);};
 const wait=async(expression)=>{for(let i=0;i<140;i++){if(await ev(expression))return;await sleep(70);}throw Error('Timed out: '+expression);};
 const nav=async path=>{await call('Page.navigate',{url:base+path});await wait(`document.readyState==='complete'&&!!window.BPCModal`);await sleep(450);};
 const click=async selector=>{const p=await ev(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});if(!e)throw Error('Missing click target');e.scrollIntoView({block:'center'});const r=e.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2,hit:document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)?.closest(${JSON.stringify(selector)})===e,top:document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)?.outerHTML.slice(0,300),url:location.href};})()`);if(!p.hit){console.log('BLOCKED '+JSON.stringify(p));const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/notification-browser/blocked.png',Buffer.from(shot.data,'base64'));}check(p.hit,'Click target is reachable: '+selector);await call('Input.dispatchMouseEvent',{type:'mousePressed',x:p.x,y:p.y,button:'left',clickCount:1});await call('Input.dispatchMouseEvent',{type:'mouseReleased',x:p.x,y:p.y,button:'left',clickCount:1});};
 const clean=async()=>{await wait(`!document.querySelector('.modal.show,.modal-backdrop,.offcanvas.show,.offcanvas-backdrop')&&!document.body.classList.contains('modal-open')&&document.body.style.overflow!=='hidden'`);check(await ev(`(()=>{const b=document.getElementById('notificationBell'),r=b.getBoundingClientRect();return b.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2));})()`),'Page interactive without an invisible overlay');};
 const errors=[];ws.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.sessionId===sessionId&&m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);});await call('Page.enable');await call('Runtime.enable');
 fs.mkdirSync('scratch/notification-browser',{recursive:true});
 for(const role of ['mis','doctor','nurse','student','teacher']){
  await call('Network.clearBrowserCookies');await call('Page.navigate',{url:base+'/auth/login.php'});await wait(`document.readyState==='complete'&&!!document.querySelector('[name="identifier"]')`);
  await ev(`document.querySelector('[name="identifier"]').value='notif_${role}';document.querySelector('[name="password"]').value='FixturePass!42';document.querySelector('form').submit()`);await wait(`!!document.getElementById('notificationBell')&&!!window.BPCModal`);
  const dir=(role==='mis'||role==='doctor')?'admin':role==='teacher'?'adviser':role;
  for(const width of [320,1440]){
   await call('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<768});
   await nav('/pages/'+dir+'/notifications.php?id='+ids[role]);await wait(`!!document.querySelector('#notifDetailModal.show')`);await sleep(350);
   const state=await ev(`(()=>{const m=document.getElementById('notifDetailModal'),b=m.querySelector('.btn-close'),r=b.getBoundingClientRect();return {parent:m.parentElement.className,hit:document.elementFromPoint(r.x+r.width/2,r.y+r.height/2)?.className,backdrops:document.querySelectorAll('.modal-backdrop').length};})()`);
   console.log('MODAL STATE '+role+' '+width+' '+JSON.stringify(state));
   if(process.env.NOTIFICATION_BASELINE==='1'){const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/notification-browser/baseline.png',Buffer.from(shot.data,'base64'));}
   await click('#notifDetailModal .btn-close');await clean();
   check(await ev('document.documentElement.scrollWidth<=innerWidth+1'),role+' '+width+' notification layout fits');
   await click('.view-notif[data-id="'+ids[role]+'"]');await wait(`!!document.querySelector('#notifDetailModal.show')`);await sleep(350);
   await call('Input.dispatchMouseEvent',{type:'mousePressed',x:2,y:2,button:'left',clickCount:1});await call('Input.dispatchMouseEvent',{type:'mouseReleased',x:2,y:2,button:'left',clickCount:1});await clean();
   for(let n=0;n<3;n++){await click('.view-notif[data-id="'+ids[role]+'"]');await wait(`!!document.querySelector('#notifDetailModal.show')`);await sleep(350);await click('#notifDetailModal .modal-footer [data-bs-dismiss]');await clean();}
   // Out-of-order requests and rapid repeated opens must still own one modal.
   await ev(`for(let i=0;i<5;i++)document.querySelector('.view-notif[data-id="${ids[role]}"]').click()`);await wait(`!!document.querySelector('#notifDetailModal.show')`);await sleep(500);check(await ev(`document.querySelectorAll('.modal-backdrop').length===1`),'Rapid clicks create one backdrop');await click('#notifDetailModal .btn-close');await clean();
   // Close during Bootstrap's fade-in, then reopen during fade-out.
   await ev(`BPCModal.show('notifDetailModal');BPCModal.hide('notifDetailModal')`);await clean();
   await ev(`BPCModal.show('notifDetailModal')`);await sleep(350);await ev(`BPCModal.hide('notifDetailModal');BPCModal.show('notifDetailModal')`);await sleep(750);check(await ev(`!!document.querySelector('#notifDetailModal.show')&&document.querySelectorAll('.modal-backdrop').length===1`),'Reopen during hide finishes with one visible modal');await click('#notifDetailModal .btn-close');await clean();
   // Switching between dialogs must use hidden/shown events, not stacked modals.
   await ev(`BPCModal.show('notifDetailModal');BPCModal.show('sosDetailsModal')`);await wait(`!!document.querySelector('#sosDetailsModal.show')&&!document.querySelector('#notifDetailModal.show')`);await sleep(350);check(await ev(`document.querySelectorAll('.modal-backdrop').length===1`),'Dialog handoff owns one backdrop');await click('#sosDetailsModal .btn-close');await clean();
   await ev(`(()=>{const panel=document.createElement('div');panel.id='notificationTestPanel';panel.className='offcanvas offcanvas-start';panel.tabIndex=-1;document.body.appendChild(panel);bootstrap.Offcanvas.getOrCreateInstance(panel).show();BPCModal.show('notifDetailModal');})()`);
   await wait(`!!document.querySelector('#notifDetailModal.show')&&!document.querySelector('.offcanvas.show,.offcanvas.showing,.offcanvas.hiding')`);await sleep(350);check(await ev(`document.querySelectorAll('.modal-backdrop').length===1&&!document.querySelector('.offcanvas-backdrop')`),'Offcanvas finishes hiding before notification opens');
   await ev(`document.getElementById('sosDetailsModal').dispatchEvent(new Event('hidden.bs.modal',{bubbles:true}))`);await sleep(100);check(await ev(`document.querySelectorAll('.modal-backdrop').length===1&&document.body.style.overflow==='hidden'`),'Unrelated hidden event preserves legitimate modal backdrop and scroll lock');await click('#notifDetailModal .btn-close');await clean();
   // Ownership is enforced by the real endpoint, regardless of UI selectors.
   if(role==='doctor'||role==='nurse'){
    const emergency=await ev(`[...document.querySelectorAll('.view-notif')].find(e=>e.dataset.id!=='${ids[role]}')?.dataset.id`);check(!!emergency,'Authorized SOS notification is available');
    await click('.view-notif[data-id="'+emergency+'"]');await wait(`!!document.querySelector('#notifSosModal.show')`);await sleep(350);await click('#notifSosModal .btn-close');await clean();
    await click('#notificationBell');await wait(`!!document.querySelector('#notifList .sos-notif-item')`);await click('#notifList .sos-notif-item');await wait(`!!document.querySelector('#sosDetailsModal.show')`);await sleep(350);check(await ev(`!document.getElementById('notificationDropdown').classList.contains('show')`),'SOS dropdown closes before opening the dialog');await click('#sosDetailsModal .btn-close');await clean();
   }
   const foreign=ids[role==='mis'?'student':'mis'];check(await ev(`fetch('${base}/api/notifications.php',{method:'POST',body:new URLSearchParams({action:'details',id:${foreign},csrf_token:document.querySelector('meta[name="csrf-token"]').content})}).then(r=>r.json()).then(r=>!r.success)`),role+' cannot read another user notification');
   // An injected transport failure must produce an actionable state and no modal.
   await call('Fetch.enable',{patterns:[{urlPattern:'*/api/notifications.php',requestStage:'Request'}]});const handler=async event=>{const m=JSON.parse(event.data);if(m.sessionId===sessionId&&m.method==='Fetch.requestPaused')await call('Fetch.failRequest',{requestId:m.params.requestId,errorReason:'Failed'});};ws.addEventListener('message',handler);
   await click('.view-notif[data-id="'+ids[role]+'"]');await sleep(600);check(await ev(`!!document.querySelector('#notificationCenterStatus')?.textContent.trim()`),'Failed details request shows a clear error');await clean();await call('Fetch.disable');ws.removeEventListener('message',handler);
   await click('.view-notif[data-id="'+ids[role]+'"]');await wait(`!!document.querySelector('#notifDetailModal.show')`);await sleep(350);
   const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/notification-browser/'+role+'-'+width+'.png',Buffer.from(shot.data,'base64'));await click('#notifDetailModal .btn-close');await clean();
   // Real dropdown navigation and browser Back must not revive a backdrop.
   await nav('/pages/'+dir+'/notifications.php');await wait(`!!document.querySelector('#notifList a[data-id="${ids[role]}"]')`);
   const history=await call('Page.getNavigationHistory');const backId=history.entries[history.currentIndex].id;
   await click('#notificationBell');await click('#notifList a[data-id="'+ids[role]+'"]');await wait(`!!document.querySelector('#notifDetailModal.show')`);await sleep(350);await click('#notifDetailModal .btn-close');await clean();
   await call('Page.navigateToHistoryEntry',{entryId:backId});await wait(`document.readyState==='complete'&&!location.search&&!!document.getElementById('notificationBell')`);await sleep(350);await clean();
   if(role==='mis'){await nav('/pages/admin/users.php');check(await ev(`JSON.stringify([...document.querySelectorAll('.people-table th')].map(e=>e.textContent.trim()))===JSON.stringify(['Name','Role','ID Number','Email Status','QR Code','Status','Date Added','Actions'])`),'MIS table uses requested labels');check(await ev(`document.documentElement.scrollWidth<=innerWidth+1&&!!document.querySelector('.table-responsive')`),'MIS table contains mobile scrolling without page overflow');}
   console.log('PASS ROLE '+role+' WIDTH '+width);
  }
 }
 check(errors.length===0,'No uncaught JavaScript exceptions');console.log(checks+' notification browser checks passed.');
 }finally{await send('Target.disposeBrowserContext',{browserContextId});ws.close();}
})().catch(e=>{console.error('FAIL '+e.message);process.exitCode=1;});
