<?php
/** Thin administration view for the existing sections API. */
require_once __DIR__.'/../../config/config.php';
require_admin_permission('manage_sections');
$pageTitle='Sections & Classes';
?>
<!doctype html><html lang="en"><head><?php include __DIR__.'/../../includes/head.php'; ?></head><body>
<div class="app-wrapper"><?php include __DIR__.'/../../components/sidebar.php'; ?><div class="main-content"><?php include __DIR__.'/../../components/navbar.php'; ?>
<main class="content-area"><div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h5 mb-0">Sections &amp; Classes</h1><button type="button" class="btn btn-success btn-sm" id="newSection">Add section</button></div>
<div id="sectionsStatus" class="small mb-3" role="status">Loading sections…</div>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Section</th><th>Course / Year</th><th>School year / Semester</th><th>Adviser</th><th>Status</th><th>Actions</th></tr></thead><tbody id="sectionRows"></tbody></table></div></div></main></div></div>
<div class="modal fade" id="sectionModal" tabindex="-1" aria-labelledby="sectionTitle" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><form id="sectionForm"><?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="id"><div class="modal-header"><h2 class="modal-title fs-5" id="sectionTitle">Section details</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body">
<?php foreach(['name'=>'Section name','course'=>'Course','year_level'=>'Year level','school_year'=>'School year','semester'=>'Semester'] as $key=>$label): ?>
<div class="mb-3"><label class="form-label" for="section_<?= $key ?>"><?= e($label) ?></label><input class="form-control" id="section_<?= $key ?>" name="<?= $key ?>" maxlength="100" <?= $key==='name'?'required':'' ?>></div>
<?php endforeach; ?>
<div class="mb-3"><label class="form-label" for="sectionAdviser">Adviser</label><select class="form-select" id="sectionAdviser" name="adviser_id"><option value="0">Unassigned</option></select></div><div><label for="sectionStatus" class="form-label">Status</label><select id="sectionStatus" name="status" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div><p class="text-danger small mt-3" id="sectionFormError" role="alert"></p>
</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Save section</button></div></form></div></div></div>
<?php include __DIR__.'/../../components/footer.php'; ?>
<script>
(function(){
 'use strict';var rows=[],busy=false;var form=document.getElementById('sectionForm');var status=document.getElementById('sectionsStatus');var modal=bootstrap.Modal.getOrCreateInstance(document.getElementById('sectionModal'));
 function esc(value){return String(value==null?'':value).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
 async function request(params,post){var response=await fetch(APP_URL+'/api/sections.php'+(post?'':'?'+new URLSearchParams(params)),{method:post?'POST':'GET',credentials:'same-origin',cache:'no-store',body:post?params:undefined,headers:{Accept:'application/json'}});var body;try{body=await response.json();}catch(e){throw Error('Unreadable server response (HTTP '+response.status+').');}if(!response.ok||!body.success)throw Error(body.message||'Request failed (HTTP '+response.status+').');return body;}
 async function load(){try{var data=await request({action:'list'});rows=data.sections;document.getElementById('sectionRows').innerHTML=rows.map(function(row){return '<tr><td>'+esc(row.name)+'</td><td>'+esc(row.course||'—')+' / '+esc(row.year_level||'—')+'</td><td>'+esc(row.school_year||'—')+' / '+esc(row.semester||'—')+'</td><td>'+esc(row.adviser_name||'Unassigned')+'</td><td>'+esc(row.status)+'</td><td><button class="btn btn-sm btn-outline-secondary" type="button" data-edit-section="'+Number(row.id)+'">Edit</button></td></tr>';}).join('');document.getElementById('sectionAdviser').innerHTML='<option value="0">Unassigned</option>'+data.advisers.map(function(a){return '<option value="'+Number(a.id)+'">'+esc(a.first_name+' '+a.last_name)+'</option>';}).join('');status.textContent=rows.length+' sections';}catch(error){status.textContent=error.message||'Connection lost. Refresh to retry.';}}
 function open(row){form.reset();document.getElementById('sectionFormError').textContent='';form.elements.action.value=row?'update':'create';form.elements.id.value=row?row.id:'';if(row){['name','course','year_level','school_year','semester','status'].forEach(function(key){form.elements[key].value=row[key]||'';});form.elements.adviser_id.value=row.teacher_id||0;}modal.show();}
 document.getElementById('newSection').addEventListener('click',function(){open(null);});document.getElementById('sectionRows').addEventListener('click',function(event){var button=event.target.closest('[data-edit-section]');if(button)open(rows.find(function(row){return Number(row.id)===Number(button.dataset.editSection);}));});
 form.addEventListener('submit',async function(event){event.preventDefault();if(busy)return;busy=true;var button=form.querySelector('[type="submit"]');button.disabled=true;try{var result=await request(new FormData(form),true);modal.hide();toastSuccess(result.message);await load();}catch(error){document.getElementById('sectionFormError').textContent=error.message||'Connection lost. Check the sections list before retrying.';}finally{busy=false;button.disabled=false;}});load();
})();
</script></body></html>
