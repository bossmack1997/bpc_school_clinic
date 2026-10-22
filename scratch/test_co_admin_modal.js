const fs=require('fs'),vm=require('vm'),assert=require('assert');
class El{constructor(){this.listeners={};this.value='';this.textContent='';this.innerHTML='Save Permissions';this.className='';this.disabled=false;this.dataset={};this.children=[];}addEventListener(type,fn){this.listeners[type]=fn;}replaceChildren(){this.children=[];}appendChild(el){this.children.push(el);}async trigger(type){if(this.listeners[type])await this.listeners[type]({preventDefault(){}});}}
const keys=['manage_users','manage_sections','view_reports','manage_security','manage_settings','view_audit_logs','view_emergencies','approve_users'];const checks=keys.map(value=>Object.assign(new El(),{value,checked:false}));
const form=new El(),save=new El(),status=new El(),cell=new El();form.dataset.endpoint='/api/settings.php';form.querySelectorAll=()=>checks;form.querySelector=()=>save;
const ids={adminPermissionsForm:form,permissionFormStatus:status,permTargetUserId:new El(),permTargetUserName:new El(),permRevision:new El()};
const document={getElementById:id=>ids[id]||null,querySelector:()=>cell,createElement:()=>new El()};
class FormDataFixture{constructor(){this.entries=[['action','update_admin_permissions'],['csrf_token','csrf-test'],['target_user_id',String(ids.permTargetUserId.value)],['permissions_revision',ids.permRevision.value]];for(const check of checks)if(check.checked&&!check.disabled)this.entries.push(['permissions[]',check.value]);}*[Symbol.iterator](){yield* this.entries;}}
let saved=['manage_users'],revision='a'.repeat(64),offline=false,denied=false,payloads=[],pendingSave=null,showCount=0;
const fetch=async(url,options)=>{
 if(offline)throw Error('offline');if(denied)return {ok:false,status:403,json:async()=>({success:false,message:'Only the Primary Administrator can manage permissions.'})};
 if(options.method==='POST'){payloads.push(options.body);if(pendingSave)await pendingSave.promise;saved=options.body.getAll('permissions[]');revision='b'.repeat(64);}
 return {ok:true,status:200,json:async()=>({success:true,target_user_id:2,target_name:'Saved Co Admin',permissions:saved.slice(),revision,message:'Co-admin permissions saved.'})};
};
const context={document,window:{},BPCModal:{show(){showCount++;}},toastSuccess(){},fetch,URLSearchParams,FormData:FormDataFixture,AbortController,setTimeout,clearTimeout,Array,console};
async function flush(){for(let i=0;i<30;i++)await Promise.resolve();}
(async()=>{
 vm.runInNewContext(fs.readFileSync('assets/js/admin-settings.js','utf8'),context);
 await context.window.openPermissionsModal(2,'Old name',['manage_settings']);assert(checks[0].checked);assert(!checks[4].checked);assert.equal(ids.permTargetUserName.textContent,'Saved Co Admin');assert.equal(ids.permRevision.value,'a'.repeat(64));assert.equal(save.disabled,false);
 checks[2].checked=true;let release;pendingSave={promise:new Promise(resolve=>release=resolve)};
 const first=form.trigger('submit');await flush();assert.equal(save.disabled,true);await form.trigger('submit');assert.equal(payloads.length,1);assert.deepEqual(payloads[0].getAll('permissions[]'),['manage_users','view_reports']);assert.equal(payloads[0].get('csrf_token'),'csrf-test');
 release();await first;pendingSave=null;assert.equal(save.disabled,false);assert.equal(status.className,'alert alert-success small');assert.equal(ids.permRevision.value,'b'.repeat(64));
 await context.window.openPermissionsModal(2,'Old name',[]);assert(checks[0].checked&&checks[2].checked,'Reopen uses saved server permissions');
 checks.forEach(check=>check.checked=false);await form.trigger('submit');assert.deepEqual(saved,[],'All managed permissions may be revoked');
 offline=true;await form.trigger('submit');assert(status.textContent.includes('Connection lost'));assert.equal(save.disabled,false);
 offline=false;denied=true;await context.window.openPermissionsModal(2,'Co',[]);assert.equal(save.disabled,true);assert(status.textContent.includes('Primary Administrator'));const count=payloads.length;await form.trigger('submit');assert.equal(payloads.length,count);
 console.log('PASS existing permission modal: fresh load, correct checks/revision, CSRF/payload before disabling, double-click suppression, confirmed save, reopening, empty selection, offline errors and authority denial.');
})().catch(error=>{console.error(error);process.exitCode=1;});
