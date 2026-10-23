/* Actual Chrome/CDP rendering against the disposable HTTP fixture. No dependencies. */
const fs = require('node:fs');
const base = process.env.MIS_TEST_BASE;
if (!base || !/^http:\/\/127\.0\.0\.1:\d+$/.test(base)) throw Error('Synthetic loopback fixture required');
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const info = await (await fetch('http://127.0.0.1:9337/json/version')).json();
  const socket = new WebSocket(info.webSocketDebuggerUrl);
  await new Promise(r => socket.addEventListener('open', r, {once:true}));
  let id=0; const pending=new Map();
  socket.addEventListener('message', e => {const m=JSON.parse(e.data); if(pending.has(m.id)){const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}});
  const send=(method,params={},sessionId)=>new Promise((resolve,reject)=>{const n=++id;pending.set(n,{resolve,reject});socket.send(JSON.stringify({id:n,method,params,...(sessionId?{sessionId}:{})}));});
  const {targetId}=await send('Target.createTarget',{url:'about:blank'});
  const {sessionId}=await send('Target.attachToTarget',{targetId,flatten:true});
  const call=(m,p)=>send(m,p,sessionId);
  const evaluate=async expression=>{const r=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw Error('Browser evaluation failed');return r.result.value;};
  const assert=(v,s)=>{if(!v)throw Error(s);console.log('PASS '+s);};
  async function navigate(path,selector){await call('Page.navigate',{url:base+path});for(let n=0;n<300;n++){await sleep(100);if(await evaluate(`document.readyState==='complete' && !!document.querySelector(${JSON.stringify(selector)}) && !document.querySelector('.page-loader')`))return;}throw Error('Page did not become ready: '+path);}
  try {
    await call('Page.enable');
    await call('Network.clearBrowserCookies');
    await navigate('/auth/login.php','[name="identifier"]');
    await evaluate(`document.querySelector('[name="identifier"]').value='mis_fixture';document.querySelector('[name="password"]').value='FixturePass!42';document.querySelector('form').submit();`);
    await sleep(1000);
    fs.mkdirSync('scratch/mis-browser',{recursive:true});
    for(const width of [320,360,375,390,430,768,1024,1440]){
      const height=width<768?844:900;
      await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<768});
      await navigate('/pages/admin/dashboard.php','.mis-audit-summary');
      const audit=await evaluate(`(()=>{const s=document.querySelector('.mis-audit-summary'),r=s.querySelectorAll('tbody tr'),meta=s.querySelector('.audit-mobile-meta');return {count:r.length,overflow:document.documentElement.scrollWidth>innerWidth+1,mobile:meta&&getComputedStyle(meta).display!=='none',thead:getComputedStyle(s.querySelector('thead')).display};})()`);
      assert(audit.count>0&&audit.count<=10&&!audit.overflow&&audit.mobile===(width<768)&&((width<768)===(audit.thead==='none')),width+'px audit summary compact/table layout and bounded rows');
      if(width===390||width===1440){const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/mis-browser/audit-'+width+'.png',Buffer.from(shot.data,'base64'));}
      await navigate('/pages/admin/users.php?search=accept_http&role=admin','[aria-label="People directory"]');
      if(await evaluate(`document.documentElement.scrollWidth>innerWidth+1`))console.log(JSON.stringify(await evaluate(`({width:innerWidth,scroll:document.documentElement.scrollWidth,bad:[...document.body.querySelectorAll('*')].filter(e=>{const r=e.getBoundingClientRect();return r.right>innerWidth+1&&getComputedStyle(e).position!=='fixed'}).slice(0,10).map(e=>({tag:e.tagName,cls:e.className,right:e.getBoundingClientRect().right,width:e.getBoundingClientRect().width}))})`)));
      assert(await evaluate(`document.documentElement.scrollWidth<=innerWidth+1&&document.body.textContent.includes('@accept_http')&&document.querySelector('[aria-label="People directory"]').scrollWidth>=document.querySelector('[aria-label="People directory"]').clientWidth`),width+'px People Management search and contained table');
      assert(await evaluate(`!document.querySelector('.mica-fab') || ['static','relative'].includes(getComputedStyle(document.querySelector('.mica-fab')).position)`),width+'px assistant launcher stays in page flow without covering directory controls');
      assert(await evaluate(`(()=>{const t=document.querySelector('.people-table'),row=t.querySelector('tbody tr'),extra=row.querySelector('.people-extra');return getComputedStyle(row).display===(innerWidth<768?'grid':'table-row')&&!!extra&&(getComputedStyle(extra).display!=='none')===(innerWidth<768)})()`),width+'px directory uses cards on mobile and full table on desktop');
      if(width===390){await evaluate(`document.querySelector('.people-table').scrollIntoView({block:'start'})`);const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/mis-browser/people-390.png',Buffer.from(shot.data,'base64'));}
      await navigate('/pages/admin/admin_accounts.php','#addAdminModal');
      await evaluate(`bootstrap.Modal.getOrCreateInstance(document.getElementById('addAdminModal')).show()`);await sleep(400);
      const measure=()=>evaluate(`(()=>{const m=document.querySelector('#addAdminModal'),b=m.querySelector('.modal-body'),f=m.querySelector('.modal-footer'),h=m.querySelector('.modal-header'),buttons=[...f.querySelectorAll('.btn')],rect=e=>{const r=e.getBoundingClientRect();return {top:r.top,bottom:r.bottom,left:r.left,right:r.right,height:r.height}};return {body:rect(b),header:rect(h),footer:rect(f),buttons:buttons.map(rect),overflow:document.documentElement.scrollWidth>innerWidth+1,scroll:b.scrollHeight>b.clientHeight,viewport:innerHeight};})()`);
      let m=await measure();
      if(m.overflow||m.header.top<0||m.footer.bottom>height+1||m.buttons.some(b=>b.height<43.9||b.height>49))console.log(JSON.stringify({width,...m}));
      assert(!m.overflow&&m.header.top>=0&&m.footer.bottom<=height+1&&m.body.bottom<=m.footer.top+1&&m.buttons.every(b=>b.height>=43.9&&b.height<=49),width+'px modal footer accessible with 44–48px buttons');
      await evaluate(`document.querySelector('#addAdminModal .modal-body').scrollTop=10000`);
      assert(await evaluate(`(()=>{const e=document.querySelector('[name="verification_status"]'),b=document.querySelector('#addAdminModal .modal-body'),r=e.getBoundingClientRect(),p=b.getBoundingClientRect();return r.top>=p.top&&r.bottom<=p.bottom})()`),width+'px final invitation field reachable by scrolling');
      if(width===390||width===1440){const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/mis-browser/modal-'+width+'.png',Buffer.from(shot.data,'base64'));}
      if(width===390){await call('Emulation.setDeviceMetricsOverride',{width,height:460,deviceScaleFactor:1,mobile:true});await sleep(200);m=await measure();assert(m.footer.bottom<=461&&m.body.height>0&&m.scroll,'Reduced-height viewport keeps scrolling body and footer accessible');await call('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:true});}
    }
    // Execute the real submit listener with an HTTP validation failure and rapid duplicate events.
    await evaluate(`(()=>{const f=document.querySelector('#addAdminForm');for(const [name,value] of Object.entries({first_name:'Browser',last_name:'Fixture',username:'browser_fixture',email:'browser@example.invalid',official_designation:'School Physician',appointment_reference:'SYNTHETIC',verification_status:'verified'}))f.elements[name].value=value;f.elements.csrf_token.value='invalid';window.fixtureRequests=0;const original=window.fetch;window.fetch=(...args)=>{window.fixtureRequests++;return original(...args)};f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));})()`);
    await sleep(800);
    assert(await evaluate(`window.fixtureRequests===1&&!document.querySelector('#addAdminForm button[type="submit"]').disabled&&!document.querySelector('#doctorInvitationFeedback').hidden`),'Browser AJAX failure shown inline, duplicate prevented, button restored');
  } finally {await send('Target.closeTarget',{targetId});socket.close();}
})().catch(e=>{console.error('FAIL '+e.message);process.exit(1);});
