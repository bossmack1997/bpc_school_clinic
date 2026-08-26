<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
$pageTitle = 'Scheduled Services';
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

      <div class="alert alert-light border d-flex align-items-center gap-2 mb-3" role="alert">
        <i class="bi bi-info-circle-fill text-primary"></i>
        <div class="small"><strong>Scheduled Services are optional.</strong> For normal clinic visits (feeling sick, needing care), you can simply walk in or scan your QR — no appointment needed. Use Scheduled Services for follow-ups, vaccinations, screenings, counseling, and other non-urgent services.</div>
      </div>

      <div class="row g-3">
        <div class="col-lg-4">
          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-calendar-plus text-success me-1"></i> Request a Scheduled Service</h6>
            <form id="bookForm">
              <div class="mb-3">
                <label class="form-label small fw-semibold">Date</label>
                <input type="date" class="form-control" name="appointment_date" min="<?= date('Y-m-d') ?>" required>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Time</label>
                <input type="time" class="form-control" name="appointment_time" required>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Reason for Visit</label>
                <textarea class="form-control" name="reason" rows="3" placeholder="e.g. Fever and headache" required></textarea>
              </div>
              <button class="btn btn-primary w-100" type="submit">Submit Request</button>
            </form>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card p-3">
            <h6 class="section-title mb-3">My Scheduled Services</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="apptTable" style="width:100%">
                <thead><tr><th>Date</th><th>Time</th><th>Reason</th><th>Nurse</th><th>Status</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
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

function loadAppointments() {
  $.get(APP_URL + '/api/appointments.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    if (table) table.destroy();
    const rows = res.data.map(a => [
      a.appointment_date,
      a.appointment_time?.slice(0,5),
      a.reason,
      a.first_name ? (a.first_name + ' ' + a.last_name) : '—',
      `<span class="badge status-${a.status}">${a.status.charAt(0).toUpperCase()+a.status.slice(1)}</span>`
    ]);
    $('#apptTable tbody').empty();
    table = $('#apptTable').DataTable({ data: rows, columns: [{},{},{},{},{}], destroy:true, pageLength: 6 });
  }, 'json');
}
loadAppointments();

$('#bookForm').on('submit', function (e) {
  e.preventDefault();
  const data = $(this).serialize() + '&action=book&csrf_token=' + csrfToken;
  $.post(APP_URL + '/api/appointments.php', data, function (res) {
    if (res.success) { toastSuccess(res.message); $('#bookForm')[0].reset(); loadAppointments(); }
    else toastError(res.message);
  }, 'json');
});
</script>
</body>
</html>
