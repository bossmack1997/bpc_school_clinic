const fs=require('fs'),vm=require('vm'),assert=require('assert');
const html=fs.readFileSync('includes/inventory_ui.php','utf8');let source=html.slice(html.indexOf('window.__initInventoryUI ='),html.lastIndexOf('</script>'));
function run(manage){
 const values={'#invSearch':'','#invCategory':'','#invExpiry':'','#invStatus':''};let options,draw,calls=[],failure=false,handlers={};
 const row={id:1,medicine_name:'Actual <Medicine>',generic_name:'Generic',brand:'Brand',category:'Tablet',quantity:5,unit:'pcs',reorder_level:10,expiration_date:null,supplier:'Supplier',batch_number:'Batch',storage_location:'Shelf',status:'Low Stock'};
 const chain={fail(fn){if(failure)fn({status:403,responseJSON:{message:'Permission revoked'}},'error');return this;},always(fn){fn();return this;},abort(){}};
 const table={ajax:{reload(){options.ajax({start:0,length:10,draw:2},res=>draw=res);}}};
 function $(selector){return {length:1,on(event,...args){handlers[String(selector)+':'+event]=args.at(-1);return this;},val(value){if(value!==undefined){values[selector]=value;return this;}return values[selector]||'';},attr(){return 'csrf';},text(value){if(value!==undefined)values[String(selector)+':text']=value;return this;},append(){return this;},prop(){return false;},find(){return $(selector);},html(){return this;},hide(){return this;},show(){return this;},DataTable(config){options=config;config.ajax({start:0,length:10,draw:1},res=>draw=res);return table;}};}
 $.get=(url,params,cb)=>{calls.push(params);if(failure)return chain;const body=params.action==='list'?{success:true,total:301,data:[row]}:params.action==='stats'?{success:true,total_medicines:301,low_stock:1}:params.action==='policy'?{success:true,policy:{lookback_days:30}}:{success:true,data:['Tablet']};cb(body);return chain;};
 const context={window:{location:{pathname:'/clinic/pages/nurse/inventory.php',origin:'http://local',search:''}},document:{getElementById(){return null;}},APP_URL:'/clinic',$,URLSearchParams,Array,Number,String,Date,escapeHtml:v=>String(v).replace(/</g,'&lt;').replace(/>/g,'&gt;'),setTimeout,clearTimeout,console};
 const code=source.replace("<?= $canManage ? 'true' : 'false' ?>",manage?'true':'false');vm.runInNewContext(code,context);context.window.__initInventoryUI();
 assert(options.serverSide&&options.processing&&!options.searching);assert.equal(options.columns.length,manage?13:11);assert.equal(draw.data[0].length,manage?13:11);assert(draw.data[0][manage?1:0].includes('Actual &lt;Medicine&gt;'));assert(draw.data[0][manage?11:10].includes('Low Stock'));
 values['#invSearch']='supplier';values['#invStatus']='low_stock';options.ajax({start:225,length:25,draw:3},res=>draw=res);assert.equal(calls.at(-1).offset,225);assert.equal(calls.at(-1).limit,25);assert.equal(calls.at(-1).search,'supplier');assert.equal(calls.at(-1).status,'low_stock');assert.equal(draw.recordsFiltered,301);
 failure=true;options.ajax({start:0,length:10,draw:4},res=>draw=res);assert.equal(draw.data.length,0);assert(values['#inventoryFeedback:text'].includes('Permission revoked'));
}
run(true);run(false);console.log('PASS inventory UI fixtures: nurse/co-admin column mapping, escaped data, one search, server paging beyond 200, combined filter parameters, and denied-request empty/error recovery.');

