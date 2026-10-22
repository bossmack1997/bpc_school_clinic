const fs=require('fs'), vm=require('vm'), assert=require('assert');
const ids={};let document;
class El {
 constructor(tag,id){this.tagName=tag;this.id=id;this.children=[];this.listeners={};this.dataset={};this.hidden=false;this.open=false;this.isConnected=true;this.textContent='';this.attributes={};}
 appendChild(el){el.parent=this;this.children.push(el);return el;}
 addEventListener(type,fn){(this.listeners[type]||=[]).push(fn);}
 async trigger(type,event={}){for(const fn of this.listeners[type]||[])await fn({preventDefault(){},...event});}
 dispatchEvent(event){this.trigger(event.type);}
 setAttribute(k,v){this.attributes[k]=v;if(k==='open')this.open=true;}
 removeAttribute(k){delete this.attributes[k];}
 querySelectorAll(selector){let out=[];for(const c of this.children){if(c.tagName===selector)out.push(c);out.push(...c.querySelectorAll(selector));}return out;}
 querySelector(s){return this.querySelectorAll(s)[0]||null;}
 remove(){if(this.parent)this.parent.children=this.parent.children.filter(e=>e!==this);}
 replaceChildren(){this.children=[];}
 focus(){document.activeElement=this;}
 showModal(){this.open=true;}
 close(){this.open=false;}
}
for(const id of ['globalSos','globalSosDialog','globalSosList','globalSosIndicator','globalSosCount','globalSosSummary','globalSosMessage','globalSosStatus','globalSosConnection','globalSosAcknowledge','globalSosClose','globalSosView','globalSosManagement'])ids[id]=new El(id==='globalSosDialog'?'dialog':'div',id);
ids.globalSos.dataset={endpoint:'/global',responseEndpoint:'/response',management:'/management'};
const csrf={content:'fixture-csrf'};
document={visibilityState:'visible',activeElement:ids.globalSosIndicator,getElementById:id=>ids[id],createElement:tag=>new El(tag),querySelector:()=>csrf,addEventListener(){}};
let rows=[{id:1,reference:'SOS-1',emergency_type:'Test',status:'active',latitude:0,longitude:0},{id:2,reference:'SOS-2',emergency_type:'Test2',status:'new',latitude:null,longitude:null}];
let seen=new Set(),offline=false;const timers=[];let posts=[];
const snapshot=()=>({success:true,emergencies:rows,active_count:rows.length,pending_ids:rows.filter(e=>!seen.has(e.id)).map(e=>e.id)});
const context={document,window:{confirm:()=>true,location:{assign:p=>posts.push(p)},addEventListener(){}},Map,Set,Array,Number,String,Event:class{constructor(type){this.type=type;}},FormData,AbortController,console,setTimeout:()=>1,clearTimeout(){},setInterval:fn=>{timers.push(fn);return 1;},clearInterval(){},fetch:async(url,options)=>{
 if(offline)throw Error('Offline');
 let body=options.body;
 if(body){assert.equal(body.get('csrf_token'),'fixture-csrf');posts.push(body.get('action'));
  if(body.get('action')==='acknowledge_notification')for(const id of body.getAll('ids[]'))seen.add(Number(id));
  if(body.get('op')==='resolve')rows=rows.filter(e=>e.id!==Number(body.get('emergency_id')));
 }
 return {status:200,ok:true,json:async()=>snapshot()};
}};
async function flush(){for(let i=0;i<20;i++)await Promise.resolve();}
(async()=>{
 vm.runInNewContext(fs.readFileSync('assets/js/global-sos.js','utf8'),context);await flush();
 assert(ids.globalSosDialog.open);assert.equal(ids.globalSosCount.textContent,'2 active');assert.equal(ids.globalSosList.children.length,2);
 await ids.globalSosView.trigger('click');assert.equal(ids.globalSosList.hidden,false);
 const notes=ids.globalSosList.querySelector('textarea');notes.value='Preserve unsaved notes';notes.focus();timers[0]();await flush();
 assert.equal(document.activeElement,notes,'Poll must not steal input focus');assert.equal(notes.value,'Preserve unsaved notes');
 await ids.globalSosAcknowledge.trigger('click');await flush();assert(!ids.globalSosDialog.open);assert(rows.every(r=>['active','new'].includes(r.status)),'Screen dismissal must not resolve');
 timers[0]();await flush();assert(!ids.globalSosDialog.open,'Acknowledged reports must not reopen');
 rows.push({id:3,reference:'SOS-3',emergency_type:'New report',status:'active'});timers[0]();await flush();assert(ids.globalSosDialog.open,'New report must reopen');
 rows=rows.filter(r=>r.id!==3);timers[0]();await flush();assert.equal(ids.globalSosCount.textContent,'2 active');
 offline=true;timers[0]();await flush();assert(!ids.globalSosConnection.hidden,'Network failure status');
 offline=false;timers[0]();await flush();assert(ids.globalSosConnection.hidden,'Recovery clears failure status');
 console.log('PASS: JS polling, grouped overlays/details, screen-only acknowledgment, no repeat interruption, new report, resolution refresh, offline recovery and preserved notes/focus (DOM fixtures).');
})().catch(e=>{console.error(e);process.exitCode=1;});
