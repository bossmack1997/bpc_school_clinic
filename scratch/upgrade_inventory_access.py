from pathlib import Path
p=Path('includes/auth.php');s=p.read_text();pos=s.index('function login_user(');s=s[:pos]+'''/** Inventory delegation is deliberately independent of Primary Admin's general bypass. */
function can_view_inventory(?array $user=null, ?PDO $db=null): bool {
    $user=$user??current_user();
    return $user && ($user['role']==='nurse'||($user['role']==='admin'&&!is_primary_admin($user)&&has_admin_permission('inventory_oversight',$user,$db)));
}
function require_inventory_access(bool $manage=false): void {
    require_role(['nurse','admin']);
    $user=current_user();
    if($manage ? $user['role']!=='nurse' : !can_view_inventory($user))admin_access_denied($manage?'Only nurses can manage medicine stock.':'Inventory data requires authorized clinic or delegated oversight access.');
}

'''+s[pos:];p.write_text(s)
p=Path('includes/admin_permission_service.php');s=p.read_text().replace("'view_emergencies','approve_users'];", "'view_emergencies','approve_users','inventory_oversight'];");p.write_text(s)
p=Path('pages/admin/settings.php');s=p.read_text();pos=s.index('        <div class="modal-footer',s.index('id="permissionsModal"'));a=s.rfind('        </div>',0,pos);s=s[:a]+'''          <div class="form-check mb-3"><input class="form-check-input perm-chk" type="checkbox" name="permissions[]" value="inventory_oversight" id="chk_inventory_oversight"><label class="form-check-label small" for="chk_inventory_oversight"><strong>Inventory Oversight</strong> - Monitor stock and alerts; prepare restock drafts. Physical stock remains nurse-managed.</label></div>
'''+s[a:];p.write_text(s)
p=Path('components/sidebar.php');s=p.read_text().replace("$sbMenus['sections']['Management'][]=['file-text','Reports','reports.php'];", "$sbMenus['sections']['Management'][]=['file-text','Reports','reports.php'];\n    $sbMenus['sections']['Management'][]=['pill',is_primary_admin($sbUser)?'Inventory Service Health':'Inventory Oversight','inventory.php'];");s=s.replace("'reports.php'=>has_admin_permission('view_reports',$sbUser),", "'reports.php'=>has_admin_permission('view_reports',$sbUser),\n        'inventory.php'=>is_primary_admin($sbUser)||can_view_inventory($sbUser),")
s=s.replace("$sbMenus = $sbNav[$sbRole] ?? $sbNav['student'];", "$sbMenus = $sbNav[$sbRole] ?? $sbNav['student'];\nif($sbRole==='nurse')$sbMenus['sections']['Clinic Operations'][]=['pill','Medicine Inventory','inventory.php'];")
p.write_text(s)
for f in ['api/mica_inventory.php','api/medicine_requisition_download.php']:
 p=Path(f);s=p.read_text().replace("require_role(['admin','nurse']);\nif(current_user()['role']==='admin'&&!is_primary_admin())admin_access_denied('This administration tool is restricted to the Primary Administrator.');",'require_inventory_access();',1);p.write_text(s)
p=Path('pages/medicine_requisition.php');s=p.read_text().replace("require_role(['admin','nurse']);",'require_inventory_access();',1);s=s.replace("if (!$final): ?>\n<p class=", "if (!$final): ?>\n<p class=");p.write_text(s)
p=Path('api/inventory.php');s=p.read_text().replace("if(current_user()['role']==='admin'&&!is_primary_admin())admin_access_denied('This administration tool is restricted to the Primary Administrator.');",'')
pos=s.index('// ================================================================');s=s[:pos]+'''if($action==='health'){
    require_primary_admin();
    $ok=(int)$db->query('SELECT 1')->fetchColumn()===1;
    json_response(true,['database'=>$ok?'available':'unavailable','inventory_api'=>'available','document_renderer'=>class_exists('Dompdf\\\\Dompdf')||is_file(__DIR__.'/../vendor/dompdf/dompdf/src/Dompdf.php'),'checked_at'=>date(DATE_ATOM)]);
}
$readActions=['list','stats','categories','policy'];
require_inventory_access(!in_array($action,$readActions,true));
if(!in_array($action,$readActions,true)&&$_SERVER['REQUEST_METHOD']!=='POST'&&$action!=='template'){http_response_code(405);header('Allow: POST');json_response(false,[],'Use POST.');}
require_once __DIR__.'/../includes/medicine_inventory_service.php';
set_exception_handler(function(Throwable $e){if(getDB()->inTransaction())getDB()->rollBack();error_log('Inventory API: '.$e->getMessage());http_response_code(500);json_response(false,[],'The inventory request could not be completed. Check current stock before retrying.');});

'''+s[pos:];p.write_text(s)
p=Path('pages/admin/inventory.php');s=p.read_text().replace('require_primary_admin();', "require_role('admin');\n$technicalOnly=is_primary_admin();\nif(!$technicalOnly)require_inventory_access();")
s=s.replace("<?php include __DIR__ . '/../../includes/inventory_ui.php'; ?>",'''<?php if($technicalOnly): ?>
      <div class="card p-4"><h1 class="h5">Inventory Service Health</h1><p class="text-muted">Technical availability only. Medicine records and physical stock are managed by authorized clinic personnel.</p><p id="inventoryHealth" role="status">Checking service…</p><button class="btn btn-outline-secondary btn-sm" id="checkInventoryHealth">Check again</button></div>
      <?php else: include __DIR__ . '/../../includes/inventory_ui.php'; endif; ?>''')
s=s.replace('</body>', '''<?php if($technicalOnly): ?><script>
(function(){async function check(){var el=document.getElementById('inventoryHealth');el.textContent='Checking service…';try{var response=await fetch(APP_URL+'/api/inventory.php?action=health',{credentials:'same-origin',cache:'no-store'});var result=await response.json();if(!response.ok||!result.success)throw Error(result.message||'Health check failed.');el.textContent='Database: '+result.database+' · Inventory API: '+result.inventory_api+' · PDF renderer: '+(result.document_renderer?'available':'unavailable');}catch(error){el.textContent='Service check unavailable. '+error.message;}}document.getElementById('checkInventoryHealth').addEventListener('click',check);check();})();
</script><?php endif; ?></body>''');p.write_text(s)
