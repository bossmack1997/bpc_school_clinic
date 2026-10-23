document.querySelectorAll('[data-clinical-draft]').forEach(form=>{
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    const button=form.querySelector('button'),output=document.getElementById('clinicalDraft');
    button.disabled=true;output.hidden=false;output.textContent='Preparing authorized history draft…';
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
    try{
      const response=await fetch(form.getAttribute('action'),{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'},signal:controller.signal});
      const result=await response.json();
      if(!response.ok||!result.success)throw Error(result.message||'The draft source is unavailable. Please retry.');
      output.textContent=result.draft;
    }catch(error){output.textContent=error.name==='AbortError'?'Draft request timed out. No clinical record was changed. Please retry.':(error instanceof SyntaxError?'The server returned an unreadable response. Please sign in again or retry.':error.message);}
    finally{clearTimeout(timer);button.disabled=false;}
  });
});
