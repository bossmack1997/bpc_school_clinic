/* Native details/summary works without JavaScript; keep older browsers exclusive too. */
(function () {
  'use strict';
  var menu = document.getElementById('settingsMenu');
  if (!menu) return;
  var sections = Array.from(menu.querySelectorAll('details.settings-menu__item'));
  sections.forEach(function (section) {
    section.addEventListener('toggle', function () {
      if (!section.open) return;
      sections.forEach(function (other) { if (other !== section) other.open = false; });
    });
  });
  menu.addEventListener('invalid', function (event) {
    var section = event.target.closest('details.settings-menu__item');
    if (section) section.open = true;
  }, true);
})();
/* Keep the existing permissions modal; load from DB rather than stale inline arguments. */
(function () {
  'use strict';
  var form=document.getElementById('adminPermissionsForm');
  if(!form)return;
  var checks=Array.from(form.querySelectorAll('.perm-chk'));
  var save=form.querySelector('button[type="submit"]');
  var status=document.getElementById('permissionFormStatus');
  var loading=false,saving=false,sequence=0;
  function message(text,kind){status.textContent=text;status.className='alert alert-'+kind+' small';}
  function disabled(value){checks.forEach(function(check){check.disabled=value;});save.disabled=value;}
  async function request(url,options){
    var control=new AbortController(),timeout=setTimeout(function(){control.abort();},20000);
    try {
      var response;
      try {response=await fetch(url,Object.assign({credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'},signal:control.signal},options||{}));}
      catch(error){throw new Error(error.name==='AbortError'?'The request timed out. Reopen the form to check saved permissions.':'Connection lost. Reopen the form to check saved permissions before retrying.');}
      var result;
      try {result=await response.json();}catch(error){throw new Error('The server returned an unreadable response (HTTP '+response.status+').');}
      if(!response.ok||!result.success)throw new Error(result.message||'Permission request failed (HTTP '+response.status+').');
      return result;
    }finally{clearTimeout(timeout);}
  }
  function apply(result){
    document.getElementById('permTargetUserId').value=result.target_user_id;
    document.getElementById('permTargetUserName').textContent=result.target_name;
    document.getElementById('permRevision').value=result.revision;
    checks.forEach(function(check){check.checked=result.permissions.includes(check.value);});
  }
  window.openPermissionsModal=async function(id,name){
    if(saving)return;
    var current=++sequence;loading=true;disabled(true);
    checks.forEach(function(check){check.checked=false;});
    document.getElementById('permTargetUserId').value=id;
    document.getElementById('permTargetUserName').textContent=name;
    document.getElementById('permRevision').value='';
    message('Loading saved permissions…','info');BPCModal.show('permissionsModal');
    try {
      var result=await request(form.dataset.endpoint+'?'+new URLSearchParams({action:'get_admin_permissions',target_user_id:id}));
      if(current!==sequence)return;apply(result);message('Saved permissions loaded.','info');disabled(false);
    }catch(error){if(current===sequence)message(error.message,'danger');}
    finally{if(current===sequence)loading=false;}
  };
  form.addEventListener('submit',async function(event){
    event.preventDefault();if(loading||saving||save.disabled)return;
    saving=true;
    // Capture selected values before disabling inputs (disabled inputs are not submitted).
    var payload=new URLSearchParams(new FormData(form));
    var original=save.innerHTML;disabled(true);save.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span> Saving…';
    message('Saving permissions…','info');
    try {
      var result=await request(form.dataset.endpoint,{method:'POST',body:payload});
      apply(result);message(result.message,'success');
      if(typeof toastSuccess==='function')toastSuccess(result.message);
      // Update the existing summary, without replacing the modal or table design.
      var cell=document.querySelector('[data-permissions-for="'+result.target_user_id+'"]');
      if(cell){cell.replaceChildren();result.permissions.forEach(function(key){var badge=document.createElement('span');badge.className='app-badge app-badge--primary me-1';badge.textContent=key.replace(/_/g,' ');cell.appendChild(badge);});if(!result.permissions.length)cell.textContent='Standard Overview';}
    }catch(error){message(error.message,'danger');}
    finally{saving=false;save.innerHTML=original;disabled(false);}
  });
})();
