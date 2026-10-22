const fs=require('fs'),vm=require('vm'),assert=require('assert');
class El{constructor(attrs={}){this.attrs=attrs;this.textContent='';this.hidden=false;this.dataset={};this.listeners={};this.classList={toggle(){}};}getAttribute(k){return this.attrs[k];}setAttribute(k,v){this.attrs[k]=v;}addEventListener(k,fn){this.listeners[k]=fn;}getContext(){return {};}}
const buttons=['week','today','month'].map(r=>new El({'data-nurse-range':r}));
const metrics=['queue_waiting','queue_serving','queue_completed','emergencies','low_stock'].map(k=>new El({'data-metric':k}));
const snapshot={visits_today:0,queue_waiting:2,queue_serving:1,queue_completed:3,emergencies:4,low_stock:2,ranges:{week:{labels:['Mon'],values:[2],line:true},today:{labels:['Midnight'],values:[0],line:false},month:{labels:['Oct 1'],values:[5],line:true}}};
const ids={nurseDashboard:new El(),nurseDashboardData:new El(),nurseVisitsChart:new El(),nurseVisitsChartWrap:new El(),nurseVisitsChartEmpty:new El(),nurseDashboardStatus:new El(),nurseActivitySummary:new El()};
ids.nurseDashboardData.textContent=JSON.stringify(snapshot);ids.nurseDashboard.dataset.endpoint='/api/nurse_dashboard.php';
const title=new El(),desc=new El();ids.nurseDashboard.querySelector=s=>s.includes('title')?title:desc;ids.nurseDashboard.querySelectorAll=s=>s.includes('metric')?metrics:buttons;ids.nurseDashboard.contains=b=>buttons.includes(b);
const charts=[];function Chart(ctx,config){charts.push(config);this.destroy=()=>{};}
const document={getElementById:id=>ids[id],hidden:false,addEventListener(){}};let timer,fail=false;
const context={document,Chart,JSON,Array,Number,window:{addEventListener(){}},AbortController,setTimeout:()=>1,clearTimeout(){},setInterval:fn=>{timer=fn;return 1;},clearInterval(){},fetch:async()=>{if(fail)throw Error('Offline');return {ok:true,json:async()=>({success:true,data:{...snapshot,queue_serving:8,ranges:{...snapshot.ranges,month:{labels:['Oct 1'],values:[7],line:true}}}})};}};
(async()=>{vm.runInNewContext(fs.readFileSync('assets/js/nurse-dashboard.js','utf8'),context);
 assert.equal(charts.at(-1).data.datasets[0].data[0],2);assert.equal(metrics[1].textContent,'1');
 function click(n){ids.nurseDashboard.listeners.click({target:{closest:()=>buttons[n]}});}
 click(1);assert(!ids.nurseVisitsChartEmpty.hidden);assert(ids.nurseVisitsChartWrap.hidden);assert.equal(title.textContent,'No visits recorded');assert.equal(buttons[1].attrs['aria-pressed'],'true');
 click(2);assert.equal(charts.at(-1).data.datasets[0].data[0],5);assert(ids.nurseVisitsChartEmpty.hidden);
 await timer();assert.equal(charts.at(-1).data.datasets[0].data[0],7);assert.equal(metrics[1].textContent,'8');assert.equal(buttons[2].attrs['aria-pressed'],'true');
 fail=true;await timer();assert(!ids.nurseDashboardStatus.hidden);assert.equal(metrics[1].textContent,'8');
 console.log('PASS: working range filters, chart/empty-state switching, all metric updates, selected-range retention and network-failure stale-value handling (DOM/Chart fixtures).');
})().catch(e=>{console.error(e);process.exitCode=1;});
