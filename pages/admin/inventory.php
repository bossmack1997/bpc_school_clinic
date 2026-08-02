<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
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
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="section-title mb-0">Medicine Inventory</h6>
        <span class="badge bg-secondary">View Only</span>
      </div>
      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="invTable" style="width:100%">
            <thead><tr><th>Name</th><th>Category</th><th>Qty</th><th>Reorder Lvl</th><th>Expiration</th><th>Batch</th><th>Status</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    <!-- Add Medicine Modal -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content border-0 rounded-4">
      <div class="modal-header border-0 bg-success text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Medicine</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="addForm" novalidate>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Medicine Name</label>
            <input type="text" class="form-control" name="medicine_name" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Category</label>
            <input type="text" class="form-control" name="category" placeholder="e.g. Analgesic">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label fw-semibold">Quantity</label>
              <input type="number" class="form-control" name="quantity" min="0" value="0" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Unit</label>
              <input type="text" class="form-control" name="unit" value="pcs">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Reorder Level</label>
              <input type="number" class="form-control" name="reorder_level" min="0" value="20">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Expiration Date</label>
              <input type="date" class="form-control" name="expiration_date">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Batch Number</label>
              <input type="text" class="form-control" name="batch_number">
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 justify-content-between">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i> Save Medicine</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Stock Update Modal -->
<div class="modal fade" id="stockModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content border-0 rounded-4">
      <div class="modal-header border-0 bg-primary text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-box-arrow-in-up me-2"></i>Stock Adjustment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="stockForm" novalidate>
        <input type="hidden" name="id" id="stockId">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Action</label>
            <select class="form-select" name="action_type" id="stockActionType">
              <option value="stock_in">Stock In (Add)</option>
              <option value="stock_out">Stock Out (Remove)</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Quantity</label>
            <input type="number" class="form-control" name="quantity" min="1" value="1" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Reason</label>
            <input type="text" class="form-control" name="reason" placeholder="Optional">
          </div>
        </div>
        <div class="modal-footer border-0 justify-content-between">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Apply</button>
        </div>
      </form>
    </div>
  </div>
</div>
</div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');
let table;

function loadInventory() {
  $.get(APP_URL + '/api/inventory.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    if (table) table.destroy();
    const rows = res.data.map(m => {
      let status = '<span class="badge bg-success">OK</span>';
      if (m.is_expired == 1) status = '<span class="badge bg-danger">Expired</span>';
      else if (m.is_low == 1) status = '<span class="badge bg-warning text-dark">Low Stock</span>';
      return [
        m.medicine_name, m.category ?? '—', m.quantity + ' ' + (m.unit||''), m.reorder_level,
        m.expiration_date ?? '—', m.batch_number ?? '—', status
      ];
    });
    table = $('#invTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{}], destroy:true, pageLength: 10 });
  }, 'json');
}
loadInventory();

$('#addForm').on('submit', function (e) {
  e.preventDefault();
  $.post(APP_URL + '/api/inventory.php', $(this).serialize() + '&action=add&csrf_token=' + csrfToken, function (res) {
    if (res.success) { toastSuccess(res.message); $('#addModal').modal('hide'); this.reset?.(); loadInventory(); }
    else toastError(res.message);
  }.bind(this), 'json');
});

function openStock(id) {
  $('#stockId').val(id);
  new bootstrap.Modal(document.getElementById('stockModal')).show();
}
$('#stockForm').on('submit', function (e) {
  e.preventDefault();
  $.post(APP_URL + '/api/inventory.php', $(this).serialize() + '&action=stock_update&csrf_token=' + csrfToken, function (res) {
    if (res.success) { toastSuccess(res.message); $('#stockModal').modal('hide'); loadInventory(); }
    else toastError(res.message);
  }, 'json');
});
</script>
</body>
</html>
