<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$pageTitle = 'Appointments Overview';
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
        <h6 class="section-title mb-3">All Clinic Appointments</h6>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="apptTable" style="width:100%">
            <thead><tr><th>Student</th><th>No.</th><th>Date</th><th>Time</th><th>Reason</th><th>Status</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$.get(APP_URL + '/api/appointments.php', { action: 'list' }, function (res) {
  if (!res.success) return;
  const rows = res.data.map(a => [
    a.s_first + ' ' + a.s_last, a.student_number, a.appointment_date, a.appointment_time?.slice(0,5),
    a.reason, `<span class="badge status-${a.status}">${a.status.charAt(0).toUpperCase()+a.status.slice(1)}</span>`
  ]);
  $('#apptTable').DataTable({ data: rows, columns:[{},{},{},{},{},{}], pageLength: 10 });
}, 'json');
</script>
</body>
</html>
