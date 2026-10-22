/* Existing emergency endpoint, server pagination and one shared desktop/mobile state. */
(function () {
  'use strict';
  var root=document.getElementById('emergencyOversight');
  if(!root)return;
  var endpoint=root.dataset.endpoint;
  var labels={new:'New',active:'Active',acknowledged:'Acknowledged',responding:'Responding',responded:'Responded',resolved:'Resolved',cancelled:'Cancelled',false_alarm:'False alarm'};
  var groups={active:['new','active','acknowledged'],responding:['responding','responded'],history:['resolved','cancelled','false_alarm']};
  var roles={student:'Student',teacher:'Teacher',nurse:'Nurse',admin:'Administration'};
  var actionLabels={accept:'Accept SOS',respond:'Respond',resolve:'Resolve SOS',cancel:'Cancel SOS'};
  var state={tab:'active',page:1,per_page:10,search:'',status:'',reporter_role:'',date_from:'',date_to:''};
  var pagination={page:1,pages:1,total:0,per_page:10},reports=[],controller=null,version=0,detailVersion=0,detailId=null,mutating=false,loading=false,searchTimer;
  var modalElement=document.getElementById('eoDetailModal');
  var modal=bootstrap.Modal.getOrCreateInstance(modalElement);
  var byId=function(id){return document.getElementById(id);};
  function esc(value){return String(value==null?'':value).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function nameRole(role){return roles[role]||'Unknown role';}
  function dateLabel(value){
    if(!value)return 'Not recorded';
    var date=new Date(String(value).replace(' ','T'));
    return Number.isNaN(date.getTime())?'Not recorded':date.toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});
  }
  function tone(status){return ['new','active'].includes(status)?'danger':groups.history.includes(status)?(status==='resolved'?'success':'neutral'):'warning';}
  function badge(report){return '<span class="eo-badge eo-badge--'+tone(report.status)+'">'+esc(labels[report.status]||report.status)+'</span>';}
  function errorMessage(error){return error.name==='AbortError'?'The request timed out. Refresh to check the current emergency status.':error.message;}
  async function request(params,signal,mutation){
    var ownedController=!signal?new AbortController():null;
    var ownedTimeout=ownedController?setTimeout(function(){ownedController.abort();},20000):null;
    if(ownedController)signal=ownedController.signal;
    try {
    var response;
    try{
      response=await fetch(endpoint+(mutation?'':'?'+new URLSearchParams(params)),{method:mutation?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:signal,
        headers:mutation?{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'}:{'Accept':'application/json'},body:mutation?new URLSearchParams(params):undefined});
    }catch(error){if(error.name==='AbortError')throw error;throw new Error('Connection lost. Reconnect and refresh to check the current emergency status.');}
    var body;
    try{body=await response.json();}catch(error){throw new Error('The server returned an unreadable response (HTTP '+response.status+'). Refresh to check the current status.');}
    if(!response.ok||!body.success){var failure=new Error(body.message||'The emergency request failed (HTTP '+response.status+').');failure.status=response.status;throw failure;}
    return body;
    } finally { if(ownedTimeout)clearTimeout(ownedTimeout); }
  }
  function showError(message){byId('eoError').textContent=message;byId('eoError').classList.remove('d-none');}
  function clearError(){byId('eoError').classList.add('d-none');byId('eoError').textContent='';}
  function hasGps(report){return bpcSosHasGps(report);}
  function mapLink(report){return hasGps(report)?'<a class="eo-map" href="https://www.google.com/maps?q='+encodeURIComponent(report.latitude+','+report.longitude)+'" target="_blank" rel="noopener noreferrer"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>View Map</a>':'';}
  function location(report){return report.location||(hasGps(report)?'GPS: '+Number(report.latitude).toFixed(5)+', '+Number(report.longitude).toFixed(5):'Location not provided');}
  function person(report){
    var initials=report.patient_name.split(/\s+/).slice(0,2).map(function(n){return n.charAt(0);}).join('');
    var reporter=report.reporter_name&&report.reporter_name!==report.patient_name?'<span class="eo-meta">Reported by '+esc(report.reporter_name)+' · '+esc(nameRole(report.actual_reporter_role))+'</span>':'';
    return '<div class="eo-person"><span class="eo-avatar" aria-hidden="true">'+esc(initials)+'</span><div><strong>'+esc(report.patient_name)+'</strong><span class="eo-meta">'+esc(nameRole(report.patient_role))+(report.patient_identifier?' · '+esc(report.patient_identifier):'')+'</span>'+reporter+'</div></div>';
  }
  function statusMeta(report){return groups.responding.includes(report.status)?'<span class="eo-meta">'+esc(report.responder_name||'Responder not recorded')+'</span><span class="eo-meta">'+esc(dateLabel(report.responded_at||report.acknowledged_at))+'</span>':'';}
  function actionButton(id,operation){return '<button type="button" class="btn btn-sm '+(operation==='resolve'?'btn-success':operation==='cancel'?'btn-outline-danger':'btn-outline-success')+'" data-action="'+operation+'" data-id="'+id+'">'+actionLabels[operation]+'</button>';}
  function rowActions(report){
    var html='<button type="button" class="btn btn-outline-secondary btn-sm" data-details="'+report.id+'" aria-label="View details for SOS '+report.id+'">View Details</button>';
    var next=(report.allowed_actions||[]).find(function(a){return a==='accept'||a==='respond';});
    if(next)html+=actionButton(report.id,next);
    return '<div class="eo-row-actions">'+html+'</div>';
  }
  function updateSummary(summary){
    root.querySelectorAll('[data-stat]').forEach(function(el){el.textContent=Number(summary[el.dataset.stat]||0).toLocaleString();});
    root.querySelectorAll('[data-count]').forEach(function(el){el.textContent=Number(summary[el.dataset.count]||0).toLocaleString();});
  }
  function render(body){
    reports=body.reports;pagination=body.pagination;state.page=pagination.page;
    updateSummary(body.summary);
    byId('eoRows').innerHTML=reports.map(function(report){return '<tr class="'+(['new','active'].includes(report.status)?'eo-needs-attention':'')+'"><td>'+person(report)+'</td><td><strong class="eo-report-type">'+esc(report.emergency_type)+'</strong><span class="eo-meta">SOS-'+report.id+'</span></td><td><span class="eo-location" title="'+esc(location(report))+'">'+esc(location(report))+'</span>'+mapLink(report)+(hasGps(report)&&parseFloat(report.location_accuracy)>100?'<span class="eo-meta">Approximate GPS · confirm location</span>':'')+'</td><td>'+badge(report)+statusMeta(report)+'</td><td>'+esc(dateLabel(report.created_at))+'</td><td>'+rowActions(report)+'</td></tr>';}).join('');
    byId('eoMobileCards').innerHTML=reports.map(function(report){return '<article class="eo-mobile-card '+(['new','active'].includes(report.status)?'eo-needs-attention':'')+'"><header>'+person(report)+badge(report)+'</header><strong class="eo-report-type">'+esc(report.emergency_type)+' <span class="eo-meta">SOS-'+report.id+'</span></strong><p><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>'+esc(location(report))+' '+mapLink(report)+'</p><span class="eo-meta">Reported '+esc(dateLabel(report.created_at))+'</span>'+statusMeta(report)+rowActions(report)+'</article>';}).join('');
    var filtered=state.search||state.status||state.reporter_role||state.date_from||state.date_to;
    byId('eoEmpty').hidden=reports.length>0;
    byId('eoEmptyTitle').textContent=filtered?'No reports match your filters':state.tab==='history'?'No emergency history':state.tab==='responding'?'No emergencies currently responding':'No active emergencies';
    byId('eoEmptyText').textContent=filtered?'Try another search or clear the filters.':state.tab==='history'?'Resolved, cancelled, and false-alarm reports will appear here.':'New reports and status changes appear automatically.';
    byId('eoResultsLabel').textContent=pagination.total.toLocaleString()+' '+(state.tab==='active'?'active':state.tab==='responding'?'responding':'historical')+' report'+(pagination.total===1?'':'s')+(filtered?' matching filters':'');
    var start=pagination.total?(pagination.page-1)*pagination.per_page+1:0;
    byId('eoRange').textContent='Showing '+start+'–'+Math.min(pagination.page*pagination.per_page,pagination.total)+' of '+pagination.total.toLocaleString()+' reports';
    byId('eoPageLabel').textContent='Page '+pagination.page+' of '+pagination.pages;
    byId('eoPrevious').disabled=pagination.page<=1;
    byId('eoNext').disabled=pagination.page>=pagination.pages;
    byId('eoUpdated').textContent='Updated '+new Date().toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit',second:'2-digit'});
  }
  async function load(){
    var current=++version;if(controller)controller.abort();controller=new AbortController();var currentController=controller;
    var timeout=setTimeout(function(){currentController.abort();},20000);
    loading=true;
    byId('eoResults').setAttribute('aria-busy','true');byId('eoRefresh').disabled=true;
    try{
      var body=await request(Object.assign({action:'oversight'},state),currentController.signal);
      if(current!==version)return;
      render(body);clearError();
      if(detailId&&modalElement.classList.contains('show')&&!mutating)showDetails(detailId,false);
    }catch(error){if(current===version){if(error.status===401||error.status===403){reports=[];byId('eoRows').innerHTML='';byId('eoMobileCards').innerHTML='';byId('eoDetailBody').textContent='';byId('eoDetailActions').replaceChildren();modal.hide();byId('eoRange').textContent='Access unavailable';root.querySelectorAll('[data-stat], [data-count]').forEach(function(el){el.textContent='�';});byId('eoPrevious').disabled=true;byId('eoNext').disabled=true;}showError(errorMessage(error));byId('eoUpdated').textContent='Update unavailable · displayed records may be stale';}}
    finally{clearTimeout(timeout);if(current===version){loading=false;byId('eoResults').setAttribute('aria-busy','false');byId('eoRefresh').disabled=false;}}
  }
  function fact(label,value,full){return '<div'+(full?' class="eo-full"':'')+'><dt>'+esc(label)+'</dt><dd>'+esc(value||'Not recorded')+'</dd></div>';}
  function detailHtml(report){
    var accuracy=hasGps(report)?bpcSosAccuracyLabel(report.location_accuracy):'GPS unavailable. Use the reported location or contact the reporter.';
    var events=[['Emergency reported',report.created_at],['Accepted / acknowledged',report.acknowledged_at],['Responder dispatched',report.responded_at],['Emergency resolved',report.resolved_at],['Emergency cancelled',report.cancelled_at]].filter(function(event){return !!event[1];});
    return '<section class="eo-detail-section"><h3>Patient & reporter</h3><dl class="eo-facts">'+fact('Patient',report.patient_name)+fact('Patient role',nameRole(report.patient_role))+fact('Student / employee ID',report.patient_identifier)+fact('Contact number',report.phone)+fact('Reporting user',report.reporter_name)+fact('Reporter role',nameRole(report.actual_reporter_role))+'</dl></section>'+
      '<section class="eo-detail-section"><h3>Emergency information</h3><dl class="eo-facts">'+fact('Emergency type',report.emergency_type)+'<div><dt>Current status</dt><dd>'+badge(report)+'</dd></div>'+fact('Reported at',dateLabel(report.created_at))+fact('Assigned responder',report.responder_name||'Not assigned')+'<div class="eo-full"><dt>Description</dt><dd class="eo-description">'+esc(report.description||'No description provided.')+'</dd></div>'+(report.resolution_notes?fact('Resolution notes',report.resolution_notes,true):'')+(report.status==='cancelled'?fact('Cancellation reason',report.notes,true):'')+'</dl></section>'+
      '<section class="eo-detail-section"><h3>Location</h3><dl class="eo-facts">'+fact('Reported location',report.location||'Not provided',true)+(hasGps(report)?fact('GPS estimate',Number(report.latitude).toFixed(6)+', '+Number(report.longitude).toFixed(6)): '')+fact('GPS accuracy',accuracy)+fact('Last location update',dateLabel(report.location_updated_at))+'</dl>'+mapLink(report)+'</section>'+
      '<section class="eo-detail-section"><h3>Response timeline</h3><ol class="eo-timeline">'+events.map(function(event){return '<li>'+esc(event[0])+'<time>'+esc(dateLabel(event[1]))+'</time></li>';}).join('')+'</ol></section>';
  }
  async function showDetails(id,open){
    var current=++detailVersion;detailId=Number(id);
    if(open!==false){byId('eoDetailBody').textContent='Loading emergency details…';byId('eoDetailActions').replaceChildren();modal.show();}
    try{
      var body=await request({action:'oversight_detail',emergency_id:id});
      if(current!==detailVersion)return;
      byId('eoReference').textContent='SOS-'+body.report.id;
      byId('eoDetailBody').innerHTML=detailHtml(body.report);
      byId('eoDetailActions').innerHTML=body.report.allowed_actions.map(function(action){return actionButton(body.report.id,action);}).join('');
    }catch(error){if(current===detailVersion){byId('eoDetailBody').textContent=errorMessage(error);byId('eoDetailActions').replaceChildren();}}
  }
  async function act(id,operation){
    if(mutating)return;mutating=true;
    var reopen=modalElement.classList.contains('show');
    if(reopen)await new Promise(function(resolve){modalElement.addEventListener('hidden.bs.modal',resolve,{once:true});modal.hide();});
    root.querySelectorAll('[data-action]').forEach(function(button){button.disabled=true;});
    try{
      var reason='';
      if(operation==='cancel'){
        var result=await Swal.fire({title:'Cancel SOS-'+id+'?',input:'textarea',inputLabel:'Reason for cancellation',showCancelButton:true,confirmButtonText:'Cancel emergency',confirmButtonColor:'#b52b39',inputValidator:function(value){if(!value||!value.trim())return 'Enter a cancellation reason.';}});
        if(!result.isConfirmed)return;
        reason=result.value.trim();
        await request({action:'update_status',emergency_id:id,op:operation,reason:reason,csrf_token:document.querySelector('meta[name="csrf-token"]').content},null,true);
        if(typeof loadNotifications==='function')loadNotifications();window.dispatchEvent(new Event('bpc-sos-updated'));
        Swal.fire({icon:'success',title:'Emergency cancelled',timer:1800,showConfirmButton:false});
      }else{
        await bpcSosAction(id,operation,'#emergencyOversight [data-action], #eoDetailActions [data-action]');
      }
    }catch(error){Swal.fire({icon:'error',title:'Could not update emergency',text:errorMessage(error)});}
    finally{
      mutating=false;
      root.querySelectorAll('[data-action]').forEach(function(button){button.disabled=false;});
      await load();
      if(reopen){await showDetails(id,false);modal.show();}
    }
  }
  function statusOptions(){byId('eoStatus').innerHTML='<option value="">All statuses</option>'+groups[state.tab].map(function(status){return '<option value="'+status+'">'+labels[status]+'</option>';}).join('');byId('eoStatus').value=state.status;}
  function changeTab(button){
    state.tab=button.dataset.tab;state.page=1;state.status='';statusOptions();
    root.querySelectorAll('[data-tab]').forEach(function(tab){var selected=tab===button;tab.classList.toggle('active',selected);tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;});
    byId('eoResults').setAttribute('aria-labelledby',button.id);load();
  }
  root.querySelectorAll('[data-tab]').forEach(function(button){button.addEventListener('click',function(){changeTab(button);});button.addEventListener('keydown',function(event){var tabs=Array.from(root.querySelectorAll('[data-tab]'));var index=tabs.indexOf(button);if(['ArrowLeft','ArrowRight','Home','End'].includes(event.key)){event.preventDefault();var next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:tabs.length-1))%tabs.length;tabs[next].focus();changeTab(tabs[next]);}});});
  function applyFilters(event){
    if(event)event.preventDefault();clearTimeout(searchTimer);
    if(byId('eoFrom').value&&byId('eoTo').value&&byId('eoFrom').value>byId('eoTo').value){showError('The start date must be before the end date.');return;}
    state.search=byId('eoSearch').value.trim();state.status=byId('eoStatus').value;state.reporter_role=byId('eoRole').value;state.date_from=byId('eoFrom').value;state.date_to=byId('eoTo').value;state.page=1;load();
  }
  byId('eoFilters').addEventListener('submit',applyFilters);
  byId('eoSearch').addEventListener('input',function(){clearTimeout(searchTimer);searchTimer=setTimeout(applyFilters,350);});
  ['eoStatus','eoRole','eoFrom','eoTo'].forEach(function(id){byId(id).addEventListener('change',applyFilters);});
  byId('eoClear').addEventListener('click',function(){byId('eoFilters').reset();applyFilters();});
  byId('eoPageSize').addEventListener('change',function(){state.per_page=Number(this.value);state.page=1;load();});
  byId('eoPrevious').addEventListener('click',function(){if(state.page>1){state.page--;load();}});
  byId('eoNext').addEventListener('click',function(){if(state.page<pagination.pages){state.page++;load();}});
  byId('eoRefresh').addEventListener('click',load);
  function delegate(event){var details=event.target.closest('[data-details]');var action=event.target.closest('[data-action]');if(details)showDetails(details.dataset.details,true);else if(action)act(action.dataset.id,action.dataset.action);}
  root.addEventListener('click',delegate);byId('eoDetailActions').addEventListener('click',delegate);
  modalElement.addEventListener('hidden.bs.modal',function(){if(!mutating){detailId=null;detailVersion++;}});
  window.addEventListener('online',load);
  document.addEventListener('visibilitychange',function(){if(!document.hidden&&!mutating&&!loading)load();});
  var timer=setInterval(function(){if(!document.hidden&&!mutating&&!loading)load();},15000);
  window.addEventListener('pagehide',function(){clearInterval(timer);if(controller)controller.abort();});
  window.addEventListener('pageshow',function(event){if(event.persisted){load();clearInterval(timer);timer=setInterval(function(){if(!document.hidden&&!mutating&&!loading)load();},15000);}});
  statusOptions();load();
})();
