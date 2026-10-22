const fs = require('fs'), vm = require('vm'), assert = require('assert');
class Element {
  constructor(id) { this.id=id; this.value=''; this.checked=false; this.disabled=false; this.hidden=false; this.style={}; this.listeners={}; this.textContent=''; this.innerHTML=''; this.className=''; this.classList={add(){},remove(){},toggle(){}}; }
  set value(value) { this._value=String(value); } get value() { return this._value; }
  addEventListener(name, fn) { this.listeners[name]=fn; }
  before(el) { ids[el.id]=el; } append() {} replaceChildren() {} scrollIntoView() {}
}
const html=fs.readFileSync('pages/nurse/queue.php','utf8')+fs.readFileSync('includes/queue_walkin_ui.php','utf8');
const ids=Object.fromEntries([...html.matchAll(/id="([^"]+)"/g)].map(m=>[m[1],new Element(m[1])]));
const names=[...html.slice(html.indexOf('<form id="unifiedConsultationForm"'),html.indexOf('</form>',html.indexOf('<form id="unifiedConsultationForm"'))).matchAll(/<(?:input|select|textarea)\b([^>]+)/g)].map(m=>{const name=m[1].match(/name="([^"]+)"/),id=m[1].match(/id="([^"]+)"/);if(!name)return null;const el=id?ids[id[1]]:new Element(name[1]);el.name=name[1];el.checkbox=/type="checkbox"/.test(m[1]);return el;}).filter(Boolean);
const form=ids.unifiedConsultationForm; form.elements=Object.fromEntries(names.map(el=>[el.name,el]));
form.reset=()=>names.forEach(el=>{el.value='';el.checked=false;el.disabled=false;}); form.reportValidity=()=>true;
form.querySelectorAll=()=>names;
ids.triageWalkinForm.elements=Object.fromEntries(['height_cm','weight_kg','student_id','temp_full_name'].map(n=>[n,new Element(n)]));
class FormDataMock {
  constructor(form) { this.values=new Map(); if(form) for(const el of Object.values(form.elements))if(!el.disabled&&(!el.checkbox||el.checked))this.set(el.name,el.value||''); }
  set(k,v){this.values.set(k,String(v));} append(k,v){this.set(k,v);} entries(){return this.values.entries();} get(k){return this.values.get(k);}
}
const document={hidden:false,getElementById:id=>{assert(ids[id],'Missing element '+id);return ids[id];},createElement:()=>new Element(),addEventListener(){},querySelectorAll:()=>[],querySelector:()=>({classList:{add(){}}})};
let patient={id:11,queue_number:'Q-11',patient_name:'Test Patient',student_number:'S-11',course:'BSIT',age:20,gender:'Female',status:'waiting',patient_type:'student'};
let visit=null,revision='';let failSave=false,confirmAnswer=true;const requests=[],alerts=[];
const context={document,window:{location:{search:''},innerWidth:1440,addEventListener(){}},FormData:FormDataMock,URLSearchParams,APP_URL:'http://test',console,setInterval:()=>1,clearInterval(){},setTimeout,clearTimeout,alert:m=>alerts.push(m),confirm:()=>confirmAnswer,showToast(){},BPCModal:{show(){},hide(){}},fetch:async(url,options)=>{
  const action=options?.body.get('action')||new URL(url).searchParams.get('action');requests.push({action,data:options?.body});
  let data;
  if(action==='get_patient_medical_summary')data={patient:{...patient},visit:visit&&{...visit},medical_record:{blood_type:'O+',allergies:'Penicillin'},draft_version:revision,recent_visits:[],dispensing:[],medicines:[],draft_options:{}};
  if(action==='start_serving'){patient.status='serving';visit={id:22,visit_number:'V-22',status:'in_consultation',chief_complaint:'Headache'};revision='v1';data={walk_in_id:22,draft_version:revision};}
  if(action==='save_consultation_draft'){
    if(failSave)return {json:async()=>({success:false,message:'Concurrent edit detected'})};
    Object.assign(visit,Object.fromEntries(options.body.entries()));revision='v2';data={walk_in_id:22,draft_version:revision};
  }
  if(action==='queue_list')data={items:[{...patient,priority:'normal',created_at:'2026-10-17 08:00:00'}],counts:{total:1,waiting:patient.status==='waiting'?1:0,serving:patient.status==='serving'?1:0,completed:0}};
  return {json:async()=>({success:true,data})};
}};
vm.createContext(context);
const page=fs.readFileSync('pages/nurse/queue.php','utf8');
const inline=page.slice(page.indexOf('  let currentTab'),page.indexOf('</script>',page.indexOf('  let currentTab'))).replace(/<\?= APP_URL \?>/g,'http://test').replace(/<\?= csrf_token\(\) \?>/g,'csrf').replace('  startPolling();','');
assert(inline.startsWith('  let currentTab'),'Queue script extracted');
vm.runInContext(inline,context);
vm.runInContext(fs.readFileSync('assets/js/queue-consultation.js','utf8'),context);
(async()=>{
  await context.openIntegratedConsultation(11,false);
  assert.equal(ids.patientFullNameText.textContent,'Test Patient');assert(ids.patientIdentifiersText.textContent.includes('Age 20'));
  assert(!ids.consultationSelected.hidden);assert(ids.submitConsultationBtn.disabled);
  assert(!requests.some(r=>r.action==='start_serving'),'Selecting alone does not start consultation');
  await context.startWorkspaceConsultation();assert.equal(patient.status,'serving');assert(!ids.saveConsultationDraftBtn.disabled);
  form.elements.chief_complaint.value='Headache';form.elements.assessment.value='Unsaved assessment';
  form.listeners.input();
  confirmAnswer=false;await context.openIntegratedConsultation(99,false);
  assert.equal(ids.consultTicketId.value,11,'Patient switch protects unsaved data');
  await context.loadQueueList();assert.equal(form.elements.assessment.value,'Unsaved assessment','Polling retains entries');
  const saved=await context.saveConsultationDraft();assert(saved);assert.equal(visit.assessment,'Unsaved assessment');assert.equal(ids.consultDraftVersion.value,'v2');
  form.elements.assessment.value='New retained edit';form.listeners.input();failSave=true;
  assert.equal(await context.saveConsultationDraft(),false);assert.equal(form.elements.assessment.value,'New retained edit');assert.equal(ids.consultationSaveStatus.textContent,'Concurrent edit detected');
  confirmAnswer=true;context.closeConsultationWorkspace();failSave=false;
  await context.openIntegratedConsultation(11,false);assert.equal(form.elements.assessment.value,'Unsaved assessment','Saved draft resumes');
  patient.status='completed';await context.loadQueueList();assert(ids.submitConsultationBtn.disabled);assert(ids.saveConsultationDraftBtn.disabled);
  assert.equal(form.elements.assessment.value,'Unsaved assessment','Closed status retains displayed data');
  console.log('PASS: patient selection/start separation, demographics, draft payload and resume, unsaved patient-switch guard, polling retention, save-failure retention, closed-visit controls (DOM/API fixtures).');
})().catch(error=>{console.error(error);process.exitCode=1;});
