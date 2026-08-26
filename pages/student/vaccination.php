<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
$pageTitle = 'Vaccination Record';
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
      <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-shield-check text-success me-1"></i> My Vaccination Record</h6>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="vacTable" style="width:100%">
            <thead><tr><th>Vaccine</th><th>Dose No.</th><th>Date Administered</th><th>Administered By</th><th>Next Dose</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$.get(APP_URL + '/api/vaccinations.php', { action: 'list' }, function (res) {
  if (!res.success) return;
  const rows = res.data.map(v => [
    v.vaccine_name,
    v.dose_number || '—',
    v.date_administered || '—',
    v.administered_by || '—',
    v.next_dose_date || '—'
  ]);
  $('#vacTable').DataTable({ data: rows, columns:[{},{},{},{},{}], pageLength: 10 });
}, 'json');
</script>
</body>
</html>
