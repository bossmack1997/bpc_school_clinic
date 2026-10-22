from pathlib import Path
p=Path('includes/inventory_ui.php');s=p.read_text();s=s.replace("$canManage = ($roleDir === 'nurse');", "$canManage = current_user()['role']==='nurse';")
s=s.replace('<div class="page-enter fade-in">','''<link rel="stylesheet" href="<?= asset_url('css/inventory-refinements.css') ?>?v=<?= filemtime(__DIR__.'/../assets/css/inventory-refinements.css') ?>">
<div class="page-enter fade-in inventory-workspace">''',1)
s=s.replace('  <!-- Dashboard Cards -->','''  <div id="inventoryFeedback" class="small mb-3" role="status" aria-live="polite"></div>
  <div class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-sm btn-outline-success" href="<?= APP_URL ?>/pages/medicine_requisition.php" target="_blank" rel="noopener">Prepare restock draft / PDF</a><span class="small text-muted align-self-center"><?= $canManage?'Nurse-managed stock and batch records':'Read-only stock oversight. Physical stock changes are restricted to nurses.' ?></span></div>
  <?php if($canManage): ?><details class="card p-3 mb-3"><summary class="fw-semibold small">Restock draft calculation rules</summary><form id="inventoryRuleForm" class="row g-2 mt-1">
  <?php foreach(['lookback_days'=>'Usage lookback (days)','coverage_days'=>'Coverage (days)','safety_days'=>'Safety allowance (days)','threshold_multiplier'=>'Minimum stock multiplier'] as $key=>$label): ?><div class="col-12 col-sm-6 col-lg-3"><label class="form-label small" for="rule_<?= $key ?>"><?= e($label) ?></label><input class="form-control form-control-sm" type="number" min="1" max="365" required name="<?= $key ?>" id="rule_<?= $key ?>"></div><?php endforeach; ?>
  <div class="col-12"><button type="submit" class="btn btn-sm btn-outline-success">Save draft rules</button></div></form><p class="small text-muted mb-0 mt-2">Suggestions use the higher of the configured minimum-stock target and actual dispensing demand. Every draft requires human review.</p></details><?php endif; ?>
  <!-- Dashboard Cards -->''',1)
s=s.replace('app-stats app-stats--5 mb-3','app-stats mb-3')
s=s.replace("      if (!res.success) return;\n      const s = res;", "      if (!res.success) {$('#inventoryFeedback').text(res.message||'Summary unavailable.');return;}\n      const s = res;",1)
s=s.replace("      $('#statCats').text(s.total_categories).attr('data-count', s.total_categories);\n    }, 'json');", "      $('#statCats').text(s.total_categories).attr('data-count', s.total_categories);\n    }, 'json').fail(function(xhr){$('#inventoryFeedback').text((xhr.responseJSON&&xhr.responseJSON.message)||'Summary unavailable. Refresh to retry.');});",1)
a=s.index('  function loadInventory()');b=s.index("  $('#invSearch').on",a)
old=s[a:b];start=old.index('      const rows = incomingRows.map');end=old.index('      if (dt)',start);render=old[start:end].replace('incomingRows.map','res.data.map')
render=render.replace('        if (canManage) row.push(actions);', '        if (!canManage) row.shift();\n        if (canManage) row.push(actions);').replace("const qty = m.quantity + ' ' + (m.unit || '');", "const qty = escapeHtml(String(m.quantity) + ' ' + (m.unit || ''));")
s=s[:a]+'''  let inventoryRequest=null;
  function loadInventory() {
    if(dt){dt.ajax.reload();return;}
    const cols=Array.from({length:canManage?13:11},()=>null);
    if(canManage){cols[0]={orderable:false,searchable:false};cols[12]={orderable:false,searchable:false};}
    dt=$('#invTable').DataTable({serverSide:true,processing:true,searching:false,ordering:false,columns:cols,pageLength:10,lengthMenu:[10,25,50],order:[],
      language:{emptyTable:'No medicines match the selected filters.',processing:'Loading medicine records…'},
      ajax:function(request,callback){
        if(inventoryRequest)inventoryRequest.abort();
        $('#inventoryFeedback').text('Loading inventory…');
        inventoryRequest=$.get(APP+'/api/inventory.php',{action:'list',search:$('#invSearch').val(),category:$('#invCategory').val(),expiry:$('#invExpiry').val(),status:$('#invStatus').val(),offset:request.start,limit:request.length},function(res){
          if(!res.success){$('#inventoryFeedback').text(res.message||'Unable to load inventory.');callback({draw:request.draw,data:[],recordsTotal:0,recordsFiltered:0});return;}
          $('#invCount').text(res.total+' medicine(s)');allMedRows=res.data;
'''+render+'''          callback({draw:request.draw,data:rows,recordsTotal:res.total,recordsFiltered:res.total});
          $('#inventoryFeedback').text(res.total?'Inventory loaded.':'No medicines match the selected filters.');
        },'json').fail(function(xhr,status){if(status==='abort')return;$('#inventoryFeedback').text((xhr.responseJSON&&xhr.responseJSON.message)||'Connection lost. Refresh to retry.');callback({draw:request.draw,data:[],recordsTotal:0,recordsFiltered:0});});
      }
    });
  }

'''+s[b:]
s=s.replace("loadInventory(); loadStats(); toastSuccess('Refreshed.');", "loadInventory(); loadStats();")
s=s.replace('x.id === id','Number(x.id) === Number(id)')
# Preserve the existing form; add a baseline for safe full-record edits.
s=s.replace('<input type="hidden" name="id" id="medId">','<input type="hidden" name="id" id="medId"><input type="hidden" name="original_quantity" id="originalMedicineQuantity">')
s=s.replace("    const m = allMedRows.find", "    const m = allMedRows.find",1)
s=s.replace("    if (!m) return;", "    if (!m) return;\n    $('#originalMedicineQuantity').val(m.quantity);",1)
# Initialize policy inputs from existing settings; save only through nurse gate.
pos=s.index("  // ============================================================\n  // Status badge")
s=s[:pos]+'''  if(canManage){
    $.get(APP+'/api/inventory.php',{action:'policy'},function(result){if(result.success)Object.keys(result.policy).forEach(function(key){$('#rule_'+key).val(result.policy[key]);});},'json');
    $('#inventoryRuleForm').on('submit',function(event){event.preventDefault();var button=$(this).find('[type="submit"]');button.prop('disabled',true);$.post(APP+'/api/inventory.php',$(this).serialize()+'&action=save_policy&csrf_token='+encodeURIComponent(csrf),function(result){$('#inventoryFeedback').text(result.message);},'json').fail(function(xhr){$('#inventoryFeedback').text((xhr.responseJSON&&xhr.responseJSON.message)||'Draft rules could not be saved.');}).always(function(){button.prop('disabled',false);});});
  }

'''+s[pos:]
p.write_text(s)
