<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Medicine Dispensing';
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
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-capsule text-success me-1"></i> Dispense Medicine</h6>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Search Student</label>
              <input type="text" id="studentSearch" class="form-control" placeholder="Name or student number...">
              <div id="studentResults" class="list-group mt-1"></div>
            </div>
            <form id="dispenseForm" class="d-none">
              <input type="hidden" name="student_id" id="student_id">
              <div class="alert alert-success py-2 small" id="selectedStudent"></div>
              <div class="mb-2">
                <label class="form-label small">Search Medicine</label>
                <input type="text" id="medSearch" class="form-control" placeholder="Type medicine name...">
                <div id="medResults" class="list-group mt-1"></div>
              </div>
              <input type="hidden" name="medicine_id" id="medicine_id">
              <div class="alert alert-info py-2 small d-none" id="selectedMed"></div>
              <div class="mb-2"><label class="form-label small">Quantity</label><input type="number" class="form-control" name="quantity" min="1" required></div>
              <div class="mb-2"><label class="form-label small">Reason</label><input class="form-control" name="reason" value="Dispensing"></div>
              <button class="btn btn-primary w-100" type="submit">Dispense</button>
            </form>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="card p-3">
            <h6 class="section-title mb-3">Dispensing History</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="histTable" style="width:100%">
                <thead><tr><th>Date</th><th>Student</th><th>Medicine</th><th>Qty</th><th>Reason</th></tr></thead>
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
let selectedStudentId = null, selectedMedId = null;

$('#studentSearch').on('keyup', function () {
  const q = $(this).val();
  if (q.length < 2) { $('#studentResults').empty(); return; }
  $.get(APP_URL + '/api/dispensing.php', { action: 'search_students', q }, function (res) {
    const $r = $('#studentResults').empty();
    res.data.forEach(s => {
      $r.append(`<button type="button" class="list-group-item list-group-item-action" data-id="${s.id}" data-name="${s.first_name} ${s.last_name} (${s.student_number})">${s.first_name} ${s.last_name} — ${s.student_number}</button>`);
    });
  }, 'json');
});

$(document).on('click', '#studentResults button', function () {
  selectedStudentId = $(this).data('id');
  $('#student_id').val(selectedStudentId);
  $('#selectedStudent').text('Selected: ' + $(this).data('name'));
  $('#dispenseForm').removeClass('d-none');
  $('#studentResults').empty();
  $('#studentSearch').val('');
  loadHistory();
});

$('#medSearch').on('keyup', function () {
  const q = $(this).val();
  if (q.length < 2) { $('#medResults').empty(); return; }
  $.get(APP_URL + '/api/dispensing.php', { action: 'search_medicines', q }, function (res) {
    const $r = $('#medResults').empty();
    res.data.forEach(m => {
      $r.append(`<button type="button" class="list-group-item list-group-item-action" data-id="${m.id}" data-name="${m.medicine_name} (Stock: ${m.quantity} ${m.unit||''})">${m.medicine_name} — Stock: ${m.quantity} ${m.unit||''}</button>`);
    });
  }, 'json');
});

$(document).on('click', '#medResults button', function () {
  selectedMedId = $(this).data('id');
  $('#medicine_id').val(selectedMedId);
  $('#selectedMed').text('Selected: ' + $(this).data('name')).removeClass('d-none');
  $('#medResults').empty();
  $('#medSearch').val('');
});

$('#dispenseForm').on('submit', function (e) {
  e.preventDefault();
  const data = $(this).serialize() + '&action=dispense&csrf_token=' + csrfToken;
  $.post(APP_URL + '/api/dispensing.php', data, function (res) {
    if (res.success) { toastSuccess(res.message); $('#dispenseForm')[0].reset(); $('#dispenseForm').addClass('d-none'); $('#selectedMed').addClass('d-none'); selectedStudentId = null; selectedMedId = null; }
    else toastError(res.message);
  }, 'json');
});

function loadHistory() {
  if (!selectedStudentId) return;
  $.get(APP_URL + '/api/dispensing.php', { action: 'history', student_id: selectedStudentId }, function (res) {
    if (!res.success) return;
    const rows = res.data.map(d => [d.performed_at, '', d.medicine_name, d.quantity + ' ' + (d.unit||''), d.reason || '—']);
    $('#histTable').DataTable({ data: rows, columns:[{},{},{},{},{}], pageLength: 5, destroy:true });
  }, 'json');
}
</script>
</body>
</html>
