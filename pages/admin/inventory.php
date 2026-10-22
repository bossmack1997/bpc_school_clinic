<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$technicalOnly=is_primary_admin();
if(!$technicalOnly)require_inventory_access();
$pageTitle = 'Medicine Inventory';
$roleDir = 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">
      <?php if($technicalOnly): ?>
      <div class="card p-4"><h1 class="h5">Inventory Service Health</h1><p class="text-muted">Technical availability only. Medicine records and physical stock are managed by authorized clinic personnel.</p><p id="inventoryHealth" role="status">Checking service…</p><button class="btn btn-outline-secondary btn-sm" id="checkInventoryHealth">Check again</button></div>
      <?php else: include __DIR__ . '/../../includes/inventory_ui.php'; endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<?php if($technicalOnly): ?><script>
(function(){async function check(){var el=document.getElementById('inventoryHealth');el.textContent='Checking service…';try{var response=await fetch(APP_URL+'/api/inventory.php?action=health',{credentials:'same-origin',cache:'no-store'});var result=await response.json();if(!response.ok||!result.success)throw Error(result.message||'Health check failed.');el.textContent='Database: '+result.database+' · Inventory API: '+result.inventory_api+' · PDF renderer: '+(result.document_renderer?'available':'unavailable');}catch(error){el.textContent='Service check unavailable. '+error.message;}}document.getElementById('checkInventoryHealth').addEventListener('click',check);check();})();
</script><?php endif; ?></body>
</html>
