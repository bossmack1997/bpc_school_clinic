<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Medicine Inventory';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area">
      <div class="card p-3">
        <h6 class="section-title mb-3">Medicine Inventory <small class="text-muted">(View Only)</small></h6>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="invTable" style="width:100%">
            <thead><tr><th>Name</th><th>Category</th><th>Qty</th><th>Unit</th><th>Reorder Lvl</th><th>Expiration</th><th>Batch</th><th>Status</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
function loadInventory() {
  $.get(APP_URL + '/api/inventory.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    const rows = res.data.map(m => {
      let status = '<span class="badge bg-success">OK</span>';
      if (m.is_expired == 1) status = '<span class="badge bg-danger">Expired</span>';
      else if (m.is_low == 1) status = '<span class="badge bg-warning text-dark">Low Stock</span>';
      return [m.medicine_name, m.category ?? '—', m.quantity, m.unit || '—', m.reorder_level, m.expiration_date ?? '—', m.batch_number ?? '—', status];
    });
    $('#invTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{},{}], pageLength: 10, destroy:true });
  }, 'json');
}
loadInventory();
</script>
</body>
</html>
