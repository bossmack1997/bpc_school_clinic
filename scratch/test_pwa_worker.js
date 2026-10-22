const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const events={},stores=new Map(),deleted=[];
const caches={async open(name){if(!stores.has(name))stores.set(name,new Map());const map=stores.get(name);return {async add(url){map.set(String(url),new Response('public offline'));},async put(request,response){map.set(request.url||String(request),response);},async match(request){return map.get(request.url||String(request));}};},async keys(){return [...stores.keys()];},async delete(name){deleted.push(name);return stores.delete(name);}};
const ctx=vm.createContext({URL,Response,Promise,encodeURIComponent,caches,self:{location:{href:'https://clinic.test/app/service-worker.js'},addEventListener:(name,fn)=>events[name]=fn,skipWaiting:async()=>{},clients:{claim:async()=>{}}},fetch:async()=>new Response('public stylesheet')});
vm.runInContext(fs.readFileSync('service-worker.js','utf8'),ctx);
async function lifecycle(name){let pending;events[name]({waitUntil:promise=>pending=promise});await pending;}
async function fetchEvent(path,options={}){let pending;events.fetch({request:{url:'https://clinic.test/app/'+path,method:'GET',mode:'cors',...options},respondWith:promise=>pending=promise});return pending;}
(async()=>{
 stores.set('unrelated-app',new Map());stores.set('bpc-clinic-static-v1',new Map());await lifecycle('install');await lifecycle('activate');
 assert(stores.has('unrelated-app'));assert(deleted.includes('bpc-clinic-static-v1'));
 for(const path of ['api/messages.php','pages/student/medical_record.php','assets/images/profiles/patient.jpg','assets/images/student_ids/id.png','uploads/private.pdf'])assert.equal(await fetchEvent(path),undefined);
 assert.equal(await fetchEvent('assets/css/style.css',{method:'POST'}),undefined);
 await fetchEvent('assets/css/style.css');
 ctx.fetch=async()=>{throw new Error('offline');};
 const fallback=await fetchEvent('pages/student/dashboard.php',{mode:'navigate'});assert.equal(await fallback.text(),'public offline');
 for(const map of stores.values())for(const key of map.keys())assert(!/profiles|student_ids|medical_record|api\/|dashboard\.php/.test(key));
 console.log('PASS: scoped caches, legacy cleanup, private upload/API/page exclusions, public assets and offline navigation fallback.');
})().catch(error=>{console.error(error);process.exitCode=1;});
