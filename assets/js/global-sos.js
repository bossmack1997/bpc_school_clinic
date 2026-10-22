/* Global responder alerts: no MICA, AI, jQuery or Bootstrap dependencies. */
(function () {
  'use strict';
  var root=document.getElementById('globalSos');
  if(!root || root.dataset.initialized) return;
  root.dataset.initialized='true';
  var dialog=document.getElementById('globalSosDialog');
  var list=document.getElementById('globalSosList');
  var indicator=document.getElementById('globalSosIndicator');
  var count=document.getElementById('globalSosCount');
  var summary=document.getElementById('globalSosSummary');
  var message=document.getElementById('globalSosMessage');
  var status=document.getElementById('globalSosStatus');
  var connection=document.getElementById('globalSosConnection');
  var acknowledge=document.getElementById('globalSosAcknowledge');
  var closeButton=document.getElementById('globalSosClose');
  var entries=new Map(), emergencies=[], pending=[], inFlight=false, mutating=false, stopped=false, epoch=0, lastFocus=null;
  var csrf=document.querySelector('meta[name="csrf-token"]');
  var headerBadge=document.getElementById('emergencyBadge');
  function node(tag,text,parent){var el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(parent)parent.appendChild(el);return el;}
  function button(label,fn,parent){var el=node('button',label,parent);el.type='button';el.addEventListener('click',fn);return el;}
  async function request(url,data){
    var controller=new AbortController();var timeout=setTimeout(function(){controller.abort();},9000);
    try {
      var response=await fetch(url,{method:data?'POST':'GET',body:data||undefined,credentials:'same-origin',cache:'no-store',signal:controller.signal});
      if(response.status===401 || response.status===403){stopped=true;emergencies=[];pending=[];entries.clear();list.replaceChildren();close();count.textContent='Sign in';throw new Error('Session expired or access denied. Sign in to resume emergency alerts.');}
      var body=await response.json();
      if(!response.ok || !body.success)throw new Error(body.message||'Emergency request failed.');
      return body;
    } finally{clearTimeout(timeout);}
  }
  function open(details){
    var wasClosed=!dialog.open;
    if(wasClosed){lastFocus=document.activeElement;if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');}
    if(details)list.hidden=false;
    if(wasClosed){
      var target=details?list.querySelector('summary'):document.getElementById('globalSosView');
      if(target)target.focus();
    }
  }
  function close(){if(dialog.open){if(typeof dialog.close==='function')dialog.close();else dialog.removeAttribute('open');}if(lastFocus && lastFocus.isConnected)lastFocus.focus();}
  function fields(entry,item){
    var facts=[['Emergency Reference Number',item.reference],['Reported By',item.reported_by],['User Role',item.reporter_role],['Emergency Type',item.emergency_type],['Emergency Description',item.description],['Reported Location',(item.location||'') + (item.location_accuracy ? ' (GPS accuracy: '+item.location_accuracy+' meters; '+(parseFloat(item.location_accuracy)>100?'poor accuracy, confirm location':'approximate estimate')+')' : '')],['Date and Time',item.created_at],['Current Emergency Status',item.status],['Contact Information',item.phone]];
    facts.forEach(function(fact){entry.fields[fact[0]].textContent=fact[1]===null || fact[1]===undefined || fact[1]===''?'Not provided':String(fact[1]);});
    var lat=Number(item.latitude),lng=Number(item.longitude);
    var gps=item.latitude!==null && item.longitude!==null && item.latitude!=='' && item.longitude!=='' && Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat)<=90 && Math.abs(lng)<=180;
    entry.location.hidden=!gps;
    if(gps)entry.location.href='https://www.google.com/maps?q='+encodeURIComponent(lat+','+lng);else entry.location.removeAttribute('href');
    entry.heading.textContent=item.reference+' - '+(item.emergency_type||'Emergency')+' - '+item.status;
    entry.ack.hidden=root.dataset.readOnly==='1'||!['new','active'].includes(item.status);
    entry.respond.hidden=root.dataset.readOnly==='1'||!['new','active','acknowledged','responded'].includes(item.status);
    if(root.dataset.readOnly==='1'){entry.resolve.hidden=true;entry.notesLabel.hidden=true;}
  }
  function build(item){
    var details=node('details',undefined,list);details.className='global-sos-item';
    var heading=node('summary','',details);var facts=node('dl',undefined,details);facts.className='global-sos_fields';
    var entry={element:details,heading:heading,fields:{}};
    ['Emergency Reference Number','Reported By','User Role','Emergency Type','Emergency Description','Reported Location','Date and Time','Current Emergency Status','Contact Information'].forEach(function(label){var group=node('div',undefined,facts);node('dt',label,group);entry.fields[label]=node('dd','',group);});
    var actions=node('div',undefined,details);actions.className='global-sos-actions';
    entry.location=node('a','View Location',actions);entry.location.target='_blank';entry.location.rel='noopener noreferrer';
    entry.ack=button('Acknowledge Emergency',function(){respond(item.id,'acknowledge');},actions);
    entry.respond=button('Respond to Emergency',function(){respond(item.id,'respond');},actions);
    var label=node('label','Resolution notes',details);entry.notesLabel=label;var notes=node('textarea',undefined,label);notes.maxLength=2000;
    entry.resolve=button('Mark Emergency Resolved',function(){respond(item.id,'resolve',notes.value);},details);
    return entry;
  }
  function apply(body){
    emergencies=body.emergencies||[];pending=body.pending_ids||[];
    count.textContent=body.active_count+' active';indicator.setAttribute('aria-label','Emergency SOS: '+body.active_count+' active. Open emergency details.');
    // Update the header shield badge (dynamic count, hidden at zero)
    if(headerBadge){headerBadge.textContent=body.active_count>99?'99+':String(body.active_count);headerBadge.classList.toggle('d-none',body.active_count===0);}
    summary.textContent=body.active_count+' active emergency report'+(body.active_count===1?'':'s')+' / '+pending.length+' unacknowledged screen notification'+(pending.length===1?'':'s');
    message.textContent=body.active_count?'An emergency has been reported. Immediate attention may be required.':'There are no active emergencies. Emergency Management remains available.';
    acknowledge.hidden=pending.length===0;closeButton.hidden=pending.length>0;
    var activeIds=new Set(emergencies.map(function(e){return e.id;}));
    entries.forEach(function(entry,id){if(!activeIds.has(id)){entry.element.remove();entries.delete(id);}});
    emergencies.forEach(function(item){var entry=entries.get(item.id);if(!entry){entry=build(item);entries.set(item.id,entry);}fields(entry,item);});
    if(pending.length){open(false);}else if(!emergencies.length && dialog.open){close();}
    connection.hidden=true;
  }
  async function poll(){
    if(stopped || inFlight || mutating || document.visibilityState==='hidden')return;
    inFlight=true;var version=epoch;
    try{var body=await request(root.dataset.endpoint);if(version===epoch)apply(body);}
    catch(error){connection.textContent=error.message+' Retrying automatically while this page is open.';connection.hidden=false;}
    finally{inFlight=false;}
  }
  async function acknowledgeScreen(){
    if(mutating || !pending.length)return !pending.length;
    var shown=pending.slice(0,2000);mutating=true;epoch++;acknowledge.disabled=true;
    var data=new FormData();data.append('action','acknowledge_notification');shown.forEach(function(id){data.append('ids[]',id);});data.append('csrf_token',root.dataset.csrf || (csrf?csrf.content:''));
    try{var body=await request(root.dataset.endpoint,data);apply(body);root.dispatchEvent(new Event('sos-receipt-saved'));status.textContent='Screen notification acknowledged. Emergency response status was not changed.';if(!pending.length)close();return true;}
    catch(error){status.textContent=error.message;return false;}
    finally{mutating=false;acknowledge.disabled=false;}
  }
  async function respond(id,operation,notes){
    if(mutating)return;
    if(operation==='resolve' && !window.confirm('Mark emergency SOS-'+id+' resolved? This updates the emergency report, not just the screen notification.'))return;
    if(operation==='resolve' && !notes){notes=window.prompt('Resolution notes:');if(!notes || !notes.trim())return;}
    mutating=true;epoch++;
    var data=new FormData();data.append('action','update_status');data.append('emergency_id',id);data.append('op',operation);data.append('csrf_token',root.dataset.csrf || (csrf?csrf.content:''));if(notes)data.append('resolution_notes',notes);
    var buttons=list.querySelectorAll('button');buttons.forEach(function(b){b.disabled=true;});
    try{var result=await request(root.dataset.responseEndpoint,data);status.textContent=result.message||'Emergency updated.';}
    catch(error){status.textContent=error.message;}
    finally{mutating=false;buttons.forEach(function(b){b.disabled=false;});poll();}
  }
  indicator.addEventListener('click',function(){open(true);var first=list.querySelector('details');if(first)first.open=true;});
  document.getElementById('globalSosView').addEventListener('click',function(){list.hidden=false;var first=list.querySelector('details');if(first){first.open=true;first.querySelector('summary').focus();}});
  acknowledge.addEventListener('click',acknowledgeScreen);
  closeButton.addEventListener('click',function(){if(!pending.length)close();});
  dialog.addEventListener('cancel',function(event){if(pending.length){event.preventDefault();status.textContent='Acknowledge the screen notification to continue. Emergency response actions remain available in View Emergency Details.';}});
  document.getElementById('globalSosManagement').addEventListener('click',async function(event){
    if(!pending.length)return;
    event.preventDefault();
    await acknowledgeScreen();
    window.location.assign(root.dataset.management);
  });
  window.addEventListener('online',poll);
  window.addEventListener('bpc-sos-updated',poll);
  document.addEventListener('visibilitychange',function(){if(document.visibilityState!=='hidden')poll();});
  if(typeof BroadcastChannel==='function'){
    var channel=new BroadcastChannel('bpc-global-sos');
    channel.addEventListener('message',function(){epoch++;poll();});
    root.addEventListener('sos-receipt-saved',function(){channel.postMessage('refresh');});
  }
  poll();var timer=setInterval(poll,12000);
  window.addEventListener('pagehide',function(){clearInterval(timer);});
  window.addEventListener('pageshow',function(event){if(event.persisted){poll();clearInterval(timer);timer=setInterval(poll,12000);}});
})();
