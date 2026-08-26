<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
$pageTitle = 'Medical Certificates';
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
        <h6 class="section-title mb-3"><i class="bi bi-file-medical text-success me-1"></i> My Certificates</h6>
        <div class="table-responsive">
              <table class="table table-hover align-middle" id="certTable" style="width:100%">
                <thead><tr><th>Cert No.</th><th>Type</th><th>Purpose</th><th>Date Issued</th><th>Issued By</th><th>File</th></tr></thead>
                <tbody></tbody>
              </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$.get(APP_URL + '/api/certificates.php', { action: 'list' }, function (res) {
  if (!res.success) return;
  const rows = res.data.map(c => [
    c.certificate_number || '—',
    c.certificate_type,
    c.purpose || '—',
    c.date_issued || '—',
    (c.first_name || '') + ' ' + (c.last_name || ''),
    c.file_path ? `<a href="${APP_URL}/${c.file_path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark"></i> View</a>` : '—'
  ]);
  $('#certTable').DataTable({ data: rows, columns:[{},{},{},{},{},{}], pageLength: 10 });
}, 'json');
</script>
</body>
</html>
