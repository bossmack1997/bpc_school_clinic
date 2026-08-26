<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Appointments';
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
        <h6 class="section-title mb-3">All Appointments</h6>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="apptTable" style="width:100%">
            <thead><tr><th>Student</th><th>No.</th><th>Date</th><th>Time</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');
let table;

function loadAppointments() {
  $.get(APP_URL + '/api/appointments.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    if (table) table.destroy();
    const rows = res.data.map(a => {
      const actions = (a.status === 'pending')
        ? `<button class="btn btn-sm btn-success" onclick="updateStatus(${a.id},'approved')">Approve</button>
           <button class="btn btn-sm btn-danger" onclick="updateStatus(${a.id},'rejected')">Reject</button>`
        : (a.status === 'approved'
            ? `<button class="btn btn-sm btn-primary" onclick="updateStatus(${a.id},'completed')">Mark Completed</button>
               <button class="btn btn-sm btn-outline-secondary" onclick="updateStatus(${a.id},'cancelled')">Cancel</button>`
            : '—');
      return [
        a.s_first + ' ' + a.s_last, a.student_number, a.appointment_date, a.appointment_time?.slice(0,5),
        a.reason, `<span class="badge status-${a.status}">${a.status.charAt(0).toUpperCase()+a.status.slice(1)}</span>`, actions
      ];
    });
    table = $('#apptTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{}], destroy:true, pageLength: 10 });
  }, 'json');
}
loadAppointments();

function updateStatus(id, status) {
  confirmAction('This appointment will be marked as ' + status + '.', function () {
    $.post(APP_URL + '/api/appointments.php', { action:'update_status', id, status, csrf_token: csrfToken }, function (res) {
      if (res.success) { toastSuccess(res.message); loadAppointments(); } else toastError(res.message);
    }, 'json');
  });
}
</script>
</body>
</html>
