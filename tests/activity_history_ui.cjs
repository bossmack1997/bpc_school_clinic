// Run the page script against a small DOM adapter; no server or school data.
const vm = require('node:vm'), fs = require('node:fs'), assert = require('node:assert/strict');
const nodes = new Map(), calls = [];
function $(selector) {
  if (!nodes.has(selector)) nodes.set(selector, {value:'', content:'', handlers:{}, disabled:false});
  const n=nodes.get(selector);
  return {
    val(v){if(v===undefined)return n.value;n.value=v;return this;},
    text(v){n.content=String(v);return this;},
    html(){return n.content.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');},
    empty(){n.content='';return this;}, append(v){n.content+=v;return this;},
    prop(k,v){n[k]=v;return this;}, on(k,fn){n.handlers[k]=fn;return this;}
  };
}
$.get=(url,query,callback)=>{const call={url,query,callback};calls.push(call);return {abort(){},fail(fn){call.fail=fn;return this;}};};
vm.runInNewContext(fs.readFileSync('assets/js/admin-activity-logs.js','utf8'),{
  $,APP_URL:'',window:{location:{search:'?search=Updated%20profile&page=2'}},
  URLSearchParams,document:{getElementById(){return null;}}
});
assert.equal(calls[0].query.search,'Updated profile');
assert.equal(calls[0].query.page,2);
assert.equal('module' in calls[0].query,false);
calls[0].callback({success:true,page:2,pages:3,total:65,data:[{
 first_name:'<script>',last_name:'Test',role:'nurse',activity:'Updated profile',created_at:'2026-11-04 08:00:00',details:'PRIVATE',module:'profile'
}]});
const markup=nodes.get('#logsTable tbody').content;
assert.equal((markup.match(/<td /g)||[]).length,4);
assert.ok(markup.includes('&lt;script&gt;'));
assert.ok(markup.includes('data-label="Activity"'));
assert.ok(!markup.includes('PRIVATE'));
nodes.get('#searchLogsButton').handlers.click();
assert.equal(calls[1].query.page,1);
assert.equal(nodes.get('#logsTable tbody').content,'');
calls[1].callback({success:true,page:1,pages:1,total:0,data:[]});
assert.ok(nodes.get('#logsTable tbody').content.includes('colspan="4"'));
nodes.get('#searchLogsButton').handlers.click();calls[2].fail({},'error');
assert.ok(nodes.get('#logInfo').content.includes('retry'));
console.log('PASS Activity History UI: four escaped cells, mobile labels, initial search, pagination, empty and failed requests');
