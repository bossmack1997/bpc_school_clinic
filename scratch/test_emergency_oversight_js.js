const fs=require('fs'),vm=require('vm'),assert=require('assert');
class Element {
 constructor(id,dataset={}){this.id=id;this.dataset=dataset;this.listeners={};this.attributes={};this.value='';this.textContent='';this.innerHTML='';this.hidden=false;this.disabled=false;this.classes=new Set();this.classList={add:c=>this.classes.add(c),remove:c=>this.classes.delete(c),contains:c=>this.classes.has(c),toggle:(c,on)=>on?this.classes.add(c):this.classes.delete(c)};}
 addEventListener(name,fn,options){(this.listeners[name]||=[]).push({fn,once:options&&options.once});}
 async trigger(name,event={}){for(const entry of [...(this.listeners[name]||[])]){await entry.fn.call(this,{preventDefault(){},target:this,...event});if(entry.once)this.listeners[name]=this.listeners[name].filter(e=>e!==entry);}}
 setAttribute(name,value){this.attributes[name]=value;}
 querySelectorAll(selector){if(selector==='[data-tab]')return tabs;if(selector==='[data-stat]')return stats;if(selector==='[data-count]')return counts;if(selector==='[data-stat], [data-count]')return stats.concat(counts);return [];}
 replaceChildren(){this.innerHTML='';this.textContent='';}
 focus(){this.focused=true;}
 reset(){for(const id of ['eoSearch','eoStatus','eoRole','eoFrom','eoTo'])ids[id].value='';}
}
const source=fs.readFileSync('assets/js/emergency-oversight.js','utf8');
const ids={};for(const match of source.matchAll(/byId\('([^']+)'\)/g))ids[match[1]]=new Element(match[1]);
for(const id of ['emergencyOversight','eoDetailModal','eoStatus','eoRole','eoFrom','eoTo'])ids[id]??=new Element(id);
const root=ids.emergencyOversight;root.dataset.endpoint='/api/emergencies.php';
const tabs=['active','responding','history'].map(tab=>new Element('eoTab'+tab[0].toUpperCase()+tab.slice(1),{tab}));
const stats=['active','responding','resolved','total'].map(stat=>new Element('stat-'+stat,{stat}));const counts=['active','responding','history'].map(count=>new Element('count-'+count,{count}));
const document={hidden:false,getElementById:id=>ids[id],querySelector:()=>({content:'test-csrf'}),addEventListener(){}};
const modal={show(){ids.eoDetailModal.classList.add('show');},hide(){ids.eoDetailModal.classList.remove('show');ids.eoDetailModal.trigger('hidden.bs.modal');}};
const groups={active:['new','active','acknowledged'],responding:['responding','responded'],history:['resolved','cancelled','false_alarm']};
let rows=Array.from({length:14},(_,i)=>({id:i+1,status:i===0?'acknowledged':'new',patient_name:i===0?'Alex <script>bad()</script>':'Patient '+(i+1),patient_role:i===1?'teacher':'student',patient_identifier:'ID-'+i,actual_reporter_role:i===1?'teacher':'student',emergency_type:'Emergency '+(i+1),location:'Room '+(i+1),latitude:null,longitude:null,created_at:'2026-10-22 09:00:00',reporter_name:'Patient '+(i+1),allowed_actions:['accept','respond','resolve','cancel']}));
rows.push({...rows[0],id:90,status:'responding',patient_name:'Assigned patient',responder_name:'Robin Nurse',responded_at:'2026-10-22 09:02:00',allowed_actions:[]});
rows.push({...rows[0],id:91,status:'resolved',patient_name:'Closed patient',resolved_at:'2026-10-22 09:10:00',allowed_actions:[]});
let calls=[],offline=false,malformed=false,forbidden=false,actionCalls=[],intervals=[];
async function fetch(url,options){
 if(forbidden)return {ok:false,status:403,json:async()=>({success:false,message:'Permission revoked'})};
 if(offline)throw Error('offline');if(malformed)return {ok:false,status:500,json:async()=>{throw Error('HTML')}};
 const params=Object.fromEntries(new URL(url,'http://test').searchParams);calls.push(params);
 let body;
 if(params.action==='oversight_detail'){body={report:rows.find(r=>r.id===Number(params.emergency_id))};}
 else {let filtered=rows.filter(r=>groups[params.tab].includes(r.status));if(params.search)filtered=filtered.filter(r=>r.patient_name.includes(params.search)||r.emergency_type.includes(params.search));if(params.status)filtered=filtered.filter(r=>r.status===params.status);if(params.reporter_role)filtered=filtered.filter(r=>r.actual_reporter_role===params.reporter_role);
 const total=filtered.length,size=Number(params.per_page),pages=Math.max(1,Math.ceil(total/size)),page=Math.min(Number(params.page),pages);
 body={reports:filtered.slice((page-1)*size,page*size),pagination:{page,pages,total,per_page:size},summary:{active:14,responding:1,resolved:1,history:1,total:16}};}
 return {ok:true,status:200,json:async()=>({success:true,...body})};
}
const context={document,window:{addEventListener(){},dispatchEvent(){}},bootstrap:{Modal:{getOrCreateInstance:()=>modal}},bpcSosHasGps:()=>false,bpcSosAccuracyLabel:()=>'',bpcSosAction:async(id,op)=>{actionCalls.push({id,op});return {success:true};},Swal:{fire:async()=>({isConfirmed:false})},fetch,AbortController,URLSearchParams,Date,Event:class{},Number,String,Array,console,setTimeout,clearTimeout,setInterval:fn=>{intervals.push(fn);return 1;},clearInterval(){}};
async function flush(){for(let i=0;i<40;i++)await Promise.resolve();}
function clickTarget(dataset){return {dataset,closest(selector){return selector==='[data-details]'&&dataset.details?this:selector==='[data-action]'&&dataset.action?this:null;}};}
(async()=>{
 vm.runInNewContext(source,context);await flush();
 assert.equal(stats[0].textContent,'14');assert.equal(stats[3].textContent,'16');
 assert(ids.eoRows.innerHTML.indexOf('Alex &lt;script&gt;')<ids.eoRows.innerHTML.indexOf('Emergency 1'),'Patient precedes emergency type');assert(!ids.eoRows.innerHTML.includes('<script>bad()'));
 assert(ids.eoRows.innerHTML.includes('data-details="1"'));assert(ids.eoMobileCards.innerHTML.includes('Patient 2'));assert.equal(ids.eoPageLabel.textContent,'Page 1 of 2');
 await ids.eoNext.trigger('click');await flush();assert.equal(calls.at(-1).page,'2');assert.equal(ids.eoPageLabel.textContent,'Page 2 of 2');
 ids.eoPageSize.value='25';await ids.eoPageSize.trigger('change');await flush();assert.equal(calls.at(-1).per_page,'25');assert.equal(calls.at(-1).page,'1');
 await tabs[1].trigger('click');await flush();assert.equal(calls.at(-1).tab,'responding');assert(ids.eoRows.innerHTML.includes('Robin Nurse'));assert(!ids.eoRows.innerHTML.includes('data-action='));assert.equal(ids.eoResults.attributes['aria-labelledby'],'eoTabResponding');
 await tabs[1].trigger('keydown',{key:'ArrowRight'});await flush();assert.equal(calls.at(-1).tab,'history');assert(ids.eoStatus.innerHTML.includes('cancelled'));assert(ids.eoRows.innerHTML.includes('Resolved'));assert(!ids.eoRows.innerHTML.includes('data-action='));
 ids.eoStatus.value='cancelled';await ids.eoStatus.trigger('change');await flush();assert(ids.eoEmpty.hidden===false);assert.equal(ids.eoEmptyTitle.textContent,'No reports match your filters');
 await ids.eoClear.trigger('click');await flush();assert.equal(calls.at(-1).status,'');
 ids.eoFrom.value='2026-10-23';ids.eoTo.value='2026-10-20';const before=calls.length;await ids.eoFilters.trigger('submit');await flush();assert.equal(calls.length,before);assert(ids.eoError.textContent.includes('start date'));
 await ids.eoClear.trigger('click');await flush();
 await root.trigger('click',{target:clickTarget({details:'91'})});await flush();assert(ids.eoDetailBody.innerHTML.includes('Response timeline'));assert(ids.eoDetailBody.innerHTML.includes('Emergency resolved'));assert.equal(ids.eoDetailActions.innerHTML,'');
 modal.hide();await flush();await tabs[0].trigger('click');await flush();
 await root.trigger('click',{target:clickTarget({action:'accept',id:'2'})});await flush();assert.equal(actionCalls[0].op,'accept');assert.equal(actionCalls[0].id,'2');
 offline=true;await ids.eoRefresh.trigger('click');await flush();assert(ids.eoError.textContent.includes('Connection lost'));assert.equal(ids.eoRefresh.disabled,false);assert.equal(ids.eoResults.attributes['aria-busy'],'false');
 offline=false;malformed=true;await ids.eoRefresh.trigger('click');await flush();assert(ids.eoError.textContent.includes('HTTP 500'));
 malformed=false;await ids.eoRefresh.trigger('click');await flush();assert(ids.eoError.classList.contains('d-none'));
 forbidden=true;await ids.eoRefresh.trigger('click');await flush();assert.equal(ids.eoRows.innerHTML,'');assert.equal(ids.eoMobileCards.innerHTML,'');assert.equal(stats[0].textContent,'—');assert(ids.eoError.textContent.includes('Permission revoked'));
 console.log('PASS oversight DOM fixtures: summary/mapping, escaping, shared mobile records, tabs/keyboard navigation, filters, 10/25 pagination, details/timeline, closed/assigned permissions, action routing and HTTP/offline recovery.');
})().catch(error=>{console.error(error);process.exitCode=1;});

