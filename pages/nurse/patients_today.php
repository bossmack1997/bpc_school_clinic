<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = "Today's Patients";
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
        <h6 class="section-title mb-3"><i class="bi bi-hospital text-success me-1"></i> Today's Patients</h6>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="todayTable" style="width:100%">
            <thead><tr><th>Time</th><th>Student</th><th>Student No.</th><th>Course</th><th>Year</th><th>Complaint</th><th>Diagnosis</th><th>Treatment</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$.get(APP_URL + '/api/students_api.php', { action: 'today_patients' }, function (res) {
  if (!res.success) return;
  const rows = res.data.map(c => [
    c.consultation_date?.split(' ')[1]?.slice(0,5) || '—',
    (c.first_name || '') + ' ' + (c.last_name || ''),
    c.student_number || '—',
    c.course || '—',
    c.year_level || '—',
    c.chief_complaint || '—',
    c.diagnosis || '—',
    c.treatment || '—'
  ]);
  $('#todayTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{},{}], pageLength: 10 });
}, 'json');
</script>
</body>
</html>
