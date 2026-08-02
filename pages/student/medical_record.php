<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
$pageTitle = 'My Medical Record';
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
        <h6 class="section-title mb-3"><i class="bi bi-file-waveform text-success me-1"></i> My Medical Records</h6>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="recordsTable" style="width:100%">
            <thead><tr><th>Date</th><th>Complaint</th><th>Diagnosis</th><th>Treatment</th><th>File</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$.get(APP_URL + '/api/medical_records.php', { action: 'list' }, function (res) {
  if (!res.success) return;
  const rows = res.data.map(r => [
    r.consultation_date?.split(' ')[0] || '—',
    r.chief_complaint || '—',
    r.diagnosis || '—',
    r.treatment || '—',
    r.file_path ? `<a href="${APP_URL}/${r.file_path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark"></i> View</a>` : '—'
  ]);
  $('#recordsTable').DataTable({ data: rows, columns:[{},{},{},{},{}], pageLength: 10 });
}, 'json');
</script>
</body>
</html>
