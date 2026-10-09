<?php
require_once __DIR__ . '/../../config/config.php';
require_role('teacher');
$u = current_user();
$pageTitle = 'My Consultations';
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
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h5 class="section-title mb-0"><i class="bi bi-file-earmark-medical text-success me-1"></i> My Consultations</h5>
        <a href="my_qr.php" class="btn btn-sm btn-outline-success"><i class="bi bi-qr-code-scan me-1"></i> My Clinic QR</a>
      </div>

      <div class="alert alert-light border d-flex align-items-center gap-2 mb-3" role="alert">
        <i class="bi bi-info-circle-fill text-primary"></i>
        <div class="small">As a clinic patient, you may receive consultations from the School Nurse. Only your own records are shown here.</div>
      </div>

      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="consTable" style="width:100%">
            <thead><tr><th>Date</th><th>Type</th><th>Chief Complaint</th><th>Treatment</th><th>Nurse</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div id="consEmpty" class="text-center text-muted py-4" style="display:none;">No consultations recorded yet.</div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$(function () {
  $.get(APP_URL + '/api/students_api.php', { action: 'my_consultations' }, function (res) {
    if (!res.success) { toastError(res.message); return; }
    const list = res.consultations || [];
    if (!list.length) { $('#consEmpty').show(); return; }
    const rows = list.map(function (c) {
      return [
        c.consultation_date ? c.consultation_date.replace(' ', ' · ') : '—',
        '<span class="badge bg-secondary text-capitalize">' + (c.visit_type || 'consultation') + '</span>',
        $('<div>').text(c.chief_complaint || '—').html(),
        $('<div>').text(c.treatment || '—').html(),
        c.license_number || '—'
      ];
    });
    $('#consTable').DataTable({ data: rows, columns: [null,null,null,null,null], pageLength: 10, destroy: true });
  }, 'json');
});
</script>
</body>
</html>
