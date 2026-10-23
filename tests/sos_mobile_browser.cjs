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
    await call('Page.enable');await call('Runtime.enable');
    socket.addEventListener('message',e=>{const m=JSON.parse(e.data);if(m.method==='Runtime.exceptionThrown')console.log(JSON.stringify(m.params.exceptionDetails));});
    await call('Network.clearBrowserCookies');
    await navigate('/auth/login.php','[name="identifier"]');
    await evaluate(`document.querySelector('[name="identifier"]').value='mis_fixture';document.querySelector('[name="password"]').value='FixturePass!42';document.querySelector('form').submit();`);
    await sleep(1000);

    fs.mkdirSync('scratch/sos-browser',{recursive:true});
    await navigate('/pages/admin/dashboard.php','#sosModal');
    await evaluate(`window.sosPosts=[];window.gpsRequests=0;window.__testGps='denied';
      Object.defineProperty(navigator,'geolocation',{configurable:true,value:{getCurrentPosition(ok,bad){window.gpsRequests++;setTimeout(()=>window.__testGps==='ok'?ok({coords:{latitude:0,longitude:121,accuracy:15}}):bad({code:window.__testGps==='timeout'?3:1}),10)},watchPosition(){return 1},clearWatch(){}}});
      window.originalPost=$.post;$.post=function(url,data,callback){if(data&&data.action==='sos'){window.sosPosts.push(data);const d=$.Deferred();setTimeout(()=>{if(window.__testFail){d.reject({status:503,responseJSON:{message:'Synthetic delivery failure'}});return;}const response={success:true,emergency_id:999,tracking_active:false,latitude:data.latitude,longitude:data.longitude,message:'Synthetic emergency accepted'};callback(response);d.resolve(response)},30);return d.promise();}return window.originalPost.apply(this,arguments)};`);
    for(const width of [320,360,375,390,430,768,1024,1440]){
      await call('Emulation.setDeviceMetricsOverride',{width,height:844,deviceScaleFactor:1,mobile:width<768});
      await evaluate(`bootstrap.Modal.getOrCreateInstance(document.querySelector('#sosModal')).show()`);await sleep(650);
      assert(await evaluate(`(()=>{const m=document.querySelector('#sosModal'),b=m.querySelector('.modal-body'),f=m.querySelector('.modal-footer'),r=f.getBoundingClientRect();return window.sosPosts.length===0&&window.gpsRequests===0&&document.documentElement.scrollWidth<=innerWidth+1&&r.bottom<=innerHeight+1&&b.getBoundingClientRect().bottom<=r.top+1&&[...f.querySelectorAll('button')].every(e=>{const r=e.getBoundingClientRect();return r.height>=43.9&&r.height<=52.1&&r.left>=0&&r.right<=innerWidth})})()`),width+'px SOS buttons bounded, body scrolls, opening does not request GPS or send');
      if(width===390){const shot=await call('Page.captureScreenshot',{format:'png'});fs.writeFileSync('scratch/sos-browser/sos-390.png',Buffer.from(shot.data,'base64'));await call('Emulation.setDeviceMetricsOverride',{width,height:400,deviceScaleFactor:1,mobile:true});await sleep(200);await evaluate(`document.querySelector('#sosModal .modal-body').scrollTop=10000`);assert(await evaluate(`(()=>{const r=document.querySelector('#sosManualLocation').getBoundingClientRect(),b=document.querySelector('#sosModal .modal-body').getBoundingClientRect();return r.top>=b.top&&r.bottom<=b.bottom&&document.querySelector('#sosModal .modal-footer').getBoundingClientRect().bottom<=innerHeight+1})()`),'Reduced-height SOS viewport keeps final location field and actions reachable');}
      await evaluate(`bootstrap.Modal.getOrCreateInstance(document.querySelector('#sosModal')).hide()`);await sleep(650);
      assert(await evaluate(`window.sosPosts.length===0&&window.gpsRequests===0`),width+'px cancelling SOS sends nothing');
    }

    await evaluate(`bootstrap.Modal.getOrCreateInstance(document.querySelector('#sosModal')).show();document.querySelector('#emergencyType').value='Other';`);await sleep(650);
    await evaluate(`document.querySelector('#sosForm').dispatchEvent(new Event('submit',{cancelable:true}));document.querySelector('#sosModal .modal-footer [data-bs-dismiss]').click()`);await sleep(650);
    assert(await evaluate(`window.sosPosts.length===0&&!document.querySelector('#sosSubmitBtn').disabled`),'Cancelling while GPS is pending prevents subsequent SOS delivery');
    await evaluate(`bootstrap.Modal.getOrCreateInstance(document.querySelector('#sosModal')).show();document.querySelector('#emergencyType').value='Other';document.querySelector('#sosManualLocation').value='Synthetic room';`);await sleep(650);
    await evaluate(`const form=document.querySelector('#sosForm');form.dispatchEvent(new Event('submit',{cancelable:true}));form.dispatchEvent(new Event('submit',{cancelable:true}));`);await sleep(650);
    assert(await evaluate(`window.sosPosts.length===1&&window.sosPosts[0].latitude===null&&document.querySelector('.swal2-html-container').textContent.includes('without GPS')&&!document.querySelector('#sosSubmitBtn').disabled`),'Explicit activation after GPS denial sends once and truthfully reports missing GPS');
    await evaluate(`Swal.close();window.__MICA_SOS_LOCATION__=null;window.__testGps='ok';bootstrap.Modal.getOrCreateInstance(document.querySelector('#sosModal')).show()`);await sleep(650);
    await evaluate(`document.querySelector('#sosForm').dispatchEvent(new Event('submit',{cancelable:true}))`);await sleep(650);
    assert(await evaluate(`window.sosPosts.length===2&&window.sosPosts[1].latitude===0&&document.querySelector('.swal2-html-container').textContent.includes('GPS coordinates were included')`),'Latitude zero is valid and confirmation reports included GPS');
    await evaluate(`Swal.close();window.__testFail=true;bootstrap.Modal.getOrCreateInstance(document.querySelector('#sosModal')).show()`);await sleep(650);
    await evaluate(`document.querySelector('#sosForm').dispatchEvent(new Event('submit',{cancelable:true}))`);await sleep(650);
    assert(await evaluate(`document.querySelector('.swal2-html-container').textContent.includes('Synthetic delivery failure')&&!document.querySelector('#sosSubmitBtn').disabled`),'SOS transport failure is displayed and submit recovers');
    await evaluate(`Swal.close();window.__testFail=false;window.__MICA_SOS_LOCATION__=null;window.__testGps='timeout';document.querySelector('#sosForm').dispatchEvent(new Event('submit',{cancelable:true}))`);await sleep(650);
    assert(await evaluate(`window.sosPosts.length===4&&window.sosPosts[3].latitude===null&&document.querySelector('.swal2-html-container').textContent.includes('without GPS')`),'GPS timeout fallback still sends a location-honest emergency');

    await evaluate(`Swal.close();window.__MICA_SOS_LOCATION__=null;window.savedGpsCount=window.gpsRequests;Object.defineProperty(navigator,'geolocation',{configurable:true,value:undefined});bootstrap.Modal.getOrCreateInstance(document.querySelector('#sosModal')).show()`);await sleep(650);
    await evaluate(`document.querySelector('#sosForm').dispatchEvent(new Event('submit',{cancelable:true}))`);await sleep(650);
    assert(await evaluate(`window.sosPosts.length===5&&window.sosPosts[4].latitude===null&&window.gpsRequests===window.savedGpsCount&&document.querySelector('.swal2-html-container').textContent.includes('without GPS')`),'Unavailable geolocation sends manual details without claiming GPS');
  } finally {await send('Target.closeTarget',{targetId});socket.close();}
})().catch(e=>{console.error('FAIL '+e.message);process.exit(1);});
