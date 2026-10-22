const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const queries=new Map(),ready=[],requests=[];
let empty=false,fail=false;
function $(selector){
 if(typeof selector==='function'){ready.push(selector);return;}
 if(!queries.has(selector))queries.set(selector,{markup:'',value:'',properties:{},events:{},html(value){this.markup=value;return this;},text(value){this.value=value;return this;},prop(key,value){this.properties[key]=value;return this;},on(name,callback){this.events[name]=callback;return this;}});
 return queries.get(selector);
}
$.get=(url,args)=>{
 assert.equal(url,'/bpc_clinic_system/api/qr_codes.php');requests.push(args);
 const timeline=args.action==='patient_visit_timeline',total=empty?0:timeline?12:7;
 const rows=Array.from({length:Math.min(args.limit,Math.max(0,total-args.offset))},(_,i)=>timeline?{event_key:'event-'+(args.offset+i),title:'Visit Completed',timestamp:'2026-10-19 10:30:00',type:'visit_completed',status:'completed'}:{purpose:'<script>test</script>',scan_time:'2026-10-19 10:30:00',status:'success',scan_location:'Clinic',scanner_name:'Nurse',queue_number:'Q1'});
 const result={success:true,total,has_more:args.offset+rows.length<total,[timeline?'timeline':'data']:rows};
 const xhr={abort(){},done(callback){if(!fail)callback(result);return xhr;},fail(callback){if(fail)callback({responseJSON:{message:'Offline'}},'error');return xhr;},always(callback){callback();return xhr;}};
 return xhr;
};
const window={};const context=vm.createContext({$,window,APP_URL:'/bpc_clinic_system',document:{querySelector:()=>({dataset:{autoLoad:'true'}})}});
vm.runInContext(fs.readFileSync('assets/js/patient-activity.js','utf8'),context);
ready.forEach(callback=>callback());
assert.equal(requests[0].limit,10);assert.equal(requests[1].limit,5);
assert.equal($('#visitTimelineCount').value,12);
assert.equal(($('#qrTimeline').markup.match(/hp-timeline-item/g)||[]).length,10);
assert.equal(($('#qrScanHistoryRows').markup.match(/pa-scan-row/g)||[]).length,5);
assert(!$('#qrScanHistoryRows').markup.includes('<script>'));
$('#visitTimelineMore').events.click();$('#qrScanHistoryMore').events.click();
assert.equal(($('#qrTimeline').markup.match(/hp-timeline-item/g)||[]).length,12);
assert.equal(($('#qrScanHistoryRows').markup.match(/pa-scan-row/g)||[]).length,7);
assert.equal($('#visitTimelineMore').properties.hidden,true);assert.equal($('#qrScanHistoryMore').properties.hidden,true);
empty=true;window.BPCPatientActivity.refresh();
assert.match($('#qrScanHistoryRows').markup,/No QR scan history available yet\./);
assert.match($('#qrTimeline').markup,/No visit timeline events/);
fail=true;window.BPCPatientActivity.refresh();
assert.match($('#qrScanHistoryRows').markup,/Offline/);assert.equal($('#qrScanHistoryMore').value,'Retry');
const template=fs.readFileSync('includes/patient_activity_sections.php','utf8');
assert.match(template,/aria-expanded="false"/);assert.match(template,/class="collapse" id="visitTimelinePanel"/);
assert(template.indexOf('visitTimelinePanel')<template.indexOf('qrScanHistoryHeading'));
console.log('PASS: API subdirectory URL, initial 10/5 limits, totals, Show/View More, escaping, empty/error/retry states, collapsed accordion markup and section order.');
