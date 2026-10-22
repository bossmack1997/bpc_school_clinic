(() => {
  'use strict';
  const token=document.querySelector('meta[name="csrf-token"]')?.content || '';
  for(const [id,action] of [['accountSettingsForm','save_account_settings'],['passwordForm','change_password']]) {
    const form=document.getElementById(id); if(!form)continue;
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(form.dataset.saving)return;form.dataset.saving='true';
      const button=form.querySelector('[type="submit"]');button.disabled=true;
      try {
        const data=new FormData(form);data.append('action',action);data.append('csrf_token',token);
        const response=await fetch(APP_URL+'/api/profile.php',{method:'POST',credentials:'same-origin',body:data});
        const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Unable to save changes.');
        toastSuccess(result.message);
        if(id==='passwordForm')form.reset();else setTimeout(()=>location.reload(),700);
      }catch(error){toastError(error.message||'Connection interrupted. Please try again.');}
      finally{delete form.dataset.saving;button.disabled=false;}
    });
  }
})();
