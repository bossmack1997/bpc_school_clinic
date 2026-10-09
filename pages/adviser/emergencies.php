<?php
require_once __DIR__ . '/../../config/config.php';
require_role('teacher');
$db = getDB();
$u = current_user();
$section = get_adviser_current_section($db, $u['id']);

$pageTitle = 'Emergency Reports';
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
        <h6 class="section-title mb-0"><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i> Emergency Reports</h6>
        <a href="students.php" class="btn btn-danger btn-sm"><i class="bi bi-broadcast me-1"></i> Report an Emergency</a>
      </div>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="emgTable" style="width:100%">
              <thead><tr><th>Student</th><th>Type</th><th>Location</th><th>Status</th><th>Reported</th></tr></thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const emgStatusLabels = {
  new: 'New', active: 'New', acknowledged: 'Acknowledged', responding: 'Responding',
  responded: 'Responded', resolved: 'Resolved', cancelled: 'Cancelled', false_alarm: 'False Alarm'
};
const emgStatusColors = {
  new: 'danger', active: 'danger', acknowledged: 'warning text-dark', responding: 'info',
  responded: 'warning text-dark', resolved: 'success', cancelled: 'secondary', false_alarm: 'secondary'
};

function loadEmergencies() {
  $.get(APP_URL + '/api/emergencies.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    const rows = (res.data || []).map(e => [
      (e.first_name ? (e.first_name + ' ' + e.last_name) : 'Student #' + e.student_id),
      `<strong>${e.emergency_type || 'Emergency'}</strong>`,
      `<i class="bi bi-geo-alt-fill text-danger me-1"></i><strong>${e.location || '—'}</strong>`,
      `<span class="badge bg-${emgStatusColors[e.status] || 'secondary'}">${emgStatusLabels[e.status] || e.status}</span>`,
      e.created_at ? e.created_at.replace(' ', ' · ') : '—'
    ]);
    if (window.__emgDt) window.__emgDt.destroy();
    window.__emgDt = $('#emgTable').DataTable({ data: rows, columns: [{},{},{},{},{}], destroy: true, pageLength: 10, order: [] });
  }, 'json');
}
loadEmergencies();
setInterval(loadEmergencies, 10000);
</script>
</body>
</html>
