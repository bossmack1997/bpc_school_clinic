(() => {
  'use strict';
  let showing=false;
  const local=url=>{try{const u=new URL(url,location.href),base=new URL(APP_URL,location.href);return u.origin===base.origin&&u.pathname.startsWith(base.pathname.replace(/\/$/,'')+'/');}catch{return false;}};
  async function present(status){
    if(showing||location.pathname.includes('/auth/'))return;showing=true;
    const confirmation=status===428;
    const link=APP_URL+(confirmation?'/auth/confirm_password.php':'/auth/login.php?timeout=1');
    document.querySelectorAll('input[type=password]').forEach(input=>input.value='');
    await Promise.all([...document.querySelectorAll('.modal.show')].map(modal=>new Promise(resolve=>{modal.addEventListener('hidden.bs.modal',resolve,{once:true});setTimeout(resolve,500);window.bootstrap?.Modal.getInstance(modal)?.hide();})));
    const root=document.createElement('div');root.className='modal fade';root.id='authenticationRequiredModal';root.tabIndex=-1;root.setAttribute('aria-labelledby','authenticationRequiredTitle');root.setAttribute('data-bs-backdrop','static');root.setAttribute('data-bs-keyboard','false');
    root.innerHTML='<div class="modal-dialog modal-dialog-centered"><section class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="authenticationRequiredTitle"></h2></div><div class="modal-body"><p id="authenticationRequiredMessage" class="mb-0"></p></div><div class="modal-footer"><a class="btn btn-success" id="authenticationRequiredContinue">Continue</a></div></section></div>';
    root.querySelector('h2').textContent=confirmation?'Confirm your identity':'Sign in again';root.querySelector('p').textContent=confirmation?'Confirm your current password, then review and submit the sensitive action.':'Your session expired. Sign in again, then review the current record before submitting your action.';
    const next=root.querySelector('a');next.href=link;next.textContent=confirmation?'Confirm password':'Sign in again';document.body.append(root);
    if(window.bootstrap){bootstrap.Modal.getOrCreateInstance(root).show();root.addEventListener('shown.bs.modal',()=>next.focus(),{once:true});}else{root.classList.add('show');root.style.display='block';root.setAttribute('role','dialog');root.setAttribute('aria-modal','true');next.focus();}
    window.dispatchEvent(new CustomEvent('bpc:authentication-required',{detail:{status}}));
  }
  if(window.fetch){const original=window.fetch;window.fetch=async function(...args){const response=await original.apply(this,args);if([401,428].includes(response.status)&&local(response.url))present(response.status);return response;};}
  if(window.jQuery)jQuery(document).ajaxError((_event,response,settings)=>{if([401,428].includes(response.status)&&local(settings.url))present(response.status);});
})();
