/* Extends the existing MICA widget; inventory facts never pass through a language model. */
window.MICAInventory = (function () {
  'use strict';
  var busy = false;
  function role() { return (window.MICA_USER || {}).role || ''; }
  function allowed() { return role()==='nurse'||(window.MICA_USER||{}).inventoryAllowed===true; }
  function endpoint() { return APP_URL + '/api/mica_inventory.php'; }
  function csrf() { return document.querySelector('meta[name="csrf-token"]').content; }
  async function request(action) {
    var data = new FormData(); data.append('action',action); data.append('csrf_token',csrf());
    var response = await fetch(endpoint(),{method:'POST',body:data,credentials:'same-origin'});
    var body = await response.json();
    if (!response.ok || !body.success){var failure=new Error(body.message || 'Inventory could not be loaded.');failure.status=response.status;throw failure;}
    return body;
  }
  function node(tag,text,parent) { var el=document.createElement(tag); if(text!==undefined) el.textContent=text; if(parent) parent.appendChild(el); return el; }
  function openRequisition() { window.open(APP_URL + '/pages/medicine_requisition.php','_blank','noopener'); }
  async function show(container, all, automatic) {
    if(!allowed() || busy || !container) return;
    busy=true;
    try {
      var result=await request('check');
      var old=container.querySelector('.mica-inventory'); if(old) old.remove();
      var panel=node('section',undefined,container); panel.className='mica-inventory'; panel.setAttribute('aria-label','Medicine Inventory');
      node('h3','Medicine Inventory',panel);
      var total=result.low_count+result.out_count;
      node('p',result.low_count+' low stock / '+result.out_count+' out of stock',panel);
      if(automatic && total) node('p','Medicine Inventory Alert: '+total+' inventory records may require restocking. Review the affected medicines or generate a requisition.',panel);
      var actions=node('div',undefined,panel); actions.className='mica-inventory-actions';
      function button(label,fn) { var b=node('button',label,actions); b.type='button'; if (/Download|Print|Requisition/.test(label)) b.disabled = !result.items.some(function(m){return m.reorder_level !== null && m.usable_stock <= m.reorder_level;}); b.addEventListener('click',fn); }
      button('Check Inventory',function(){show(container,true,false);});
      button('View Low Stock',function(){show(container,false,false);request('read').catch(console.warn);});
      button('Generate Requisition',openRequisition);
      button('Download PDF',openRequisition);
      button('Download Word (.docx)',openRequisition);
      button('Print Requisition',openRequisition);
      var manage=node('a',role()==='nurse'?'Manage Medicine Stocks':'Inventory Oversight',actions); manage.href=APP_URL+'/pages/'+role()+'/inventory.php'; manage.target='_blank'; manage.rel='noopener';
      var items=result.items.filter(function(m){return all || ['LOW STOCK','OUT OF STOCK'].includes(m.stock_status);});
      if(!items.length) node('p','No medicines currently require replenishment.',panel);
      var list=node('div',undefined,panel); list.className='mica-inventory-list';
      items.forEach(function(m){
        var row=node('article',undefined,list);
        node('strong',m.medicine_name+' (ID '+m.id+')',row);
        node('div',m.usable_stock+' '+m.unit+' usable | Minimum: '+(m.reorder_level===null?'Not configured':m.reorder_level)+' | '+m.stock_status,row);
        node('small',m.remarks+'. '+(m.suggested_quantity===null?'Staff must enter requested quantity.':'Proposed replenishment: '+m.suggested_quantity+' '+m.unit),row);
      });
      node('small','Checks run when MICA opens and when the configured server scheduler runs. Thresholds are edited through the existing nurse inventory editor.',panel);
    } catch(error) {
      if(error.status===401||error.status===403){var oldPanel=container.querySelector('.mica-inventory');if(oldPanel)oldPanel.remove();}
      var existing=container.querySelector('.mica-inventory-error'); if(existing) existing.remove();
      var notice=node('p',error.message,container); notice.className='mica-inventory-error'; notice.setAttribute('role','alert');
    } finally { busy=false; }
  }
  function handles(text) { return /(?:medicine.*(?:stock|order|requisition)|(?:low[ -]?stock|out of stock|restock|requisition|inventory alert)|(?:printable.*medicine)|check.*inventory)/i.test(text); }
  function command(text,container) {
    if(!handles(text)) return false;
    if(!allowed()) return false;
    show(container,!/(low[ -]?stock|restock|requisition|order|alert)/i.test(text),false);
    if(/requisition|printable|medicine order/i.test(text)) openRequisition();
    return true;
  }
  return { show:show, command:command, allowed:allowed };
})();
