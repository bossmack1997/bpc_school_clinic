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
    <div class="content-area page-enter">
      <div class="row g-3">
        <!-- ==================== DISPENSE FORM ==================== -->
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
              <div class="mb-2"><label class="form-label small">Purpose / Reason <span class="text-danger">*</span></label><input class="form-control" name="purpose" id="dispensePurpose" required placeholder="e.g. Headache relief"></div>
              <div class="mb-2"><label class="form-label small">Remarks</label><textarea class="form-control" name="remarks" rows="2" placeholder="Optional notes"></textarea></div>
              <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle me-1"></i> Dispense</button>
            </form>
          </div>
        </div>

        <!-- ==================== DISPENSING HISTORY ==================== -->
        <div class="col-lg-7">
          <div class="card p-3 mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
              <h6 class="section-title mb-0">Dispensing History</h6>
              <button class="btn btn-sm btn-outline-secondary" id="printHistoryBtn"><i class="bi bi-printer me-1"></i> Print</button>
            </div>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="histTable" style="width:100%">
                <thead><tr><th>Date</th><th>Student</th><th>Medicine</th><th>Qty</th><th>Purpose</th><th>Nurse</th></tr></thead>
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
let histDt = null;

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
});

$('#medSearch').on('keyup', function () {
  const q = $(this).val();
  if (q.length < 2) { $('#medResults').empty(); return; }
  $.get(APP_URL + '/api/dispensing.php', { action: 'search_medicines', q }, function (res) {
    const $r = $('#medResults').empty();
    res.data.forEach(m => {
      const expInfo = m.expiration_date ? ' · Exp: ' + m.expiration_date : '';
      const lowInfo = +m.quantity <= +m.reorder_level ? ' <span class="badge bg-warning text-dark">Low</span>' : '';
      $r.append(`<button type="button" class="list-group-item list-group-item-action" data-id="${m.id}" data-name="${m.medicine_name} (Stock: ${m.quantity} ${m.unit||''})">${m.medicine_name}${m.brand ? ' (' + m.brand + ')' : ''} — Stock: ${m.quantity} ${m.unit||''}${expInfo}${lowInfo}</button>`);
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
    if (res.success) {
      toastSuccess(res.message);
      $('#dispenseForm')[0].reset();
      $('#dispenseForm').addClass('d-none');
      $('#selectedMed').addClass('d-none');
      selectedStudentId = null; selectedMedId = null;
      loadAllHistory();
    }
    else toastError(res.message);
  }, 'json');
});

// ============================================================
// Load full dispensing history (all students, searchable)
// ============================================================
function loadAllHistory() {
  $.get(APP_URL + '/api/dispensing.php', { action: 'all_history', limit: 200 }, function (res) {
    if (!res.success) return;
    const rows = res.data.map(d => {
      const stud = [d.stud_first, d.stud_last].filter(Boolean).join(' ') || '—';
      const nurse = [d.nurse_first, d.nurse_last].filter(Boolean).join(' ') || '—';
      return [
        d.performed_at,
        stud + (d.student_number ? '<br><span class="text-muted small">' + d.student_number + '</span>' : ''),
        d.medicine_name,
        d.quantity + ' ' + (d.unit || ''),
        d.reason || '—',
        nurse
      ];
    });
    if (histDt) histDt.destroy();
    histDt = $('#histTable').DataTable({ data: rows, columns: [null, null, null, null, null, null], pageLength: 10, order: [[0, 'desc']] });
  }, 'json');
}

$('#printHistoryBtn').on('click', function () {
  const w = window.open('', '_blank');
  w.document.write(`
    <html><head><title>Dispensing History</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',Arial,sans-serif;padding:30px}table{width:100%;border-collapse:collapse;font-size:13px}
    th,td{border:1px solid #ccc;padding:6px 8px;text-align:left}th{background:#f0f0f0}
    h2{color:#0B6B3A;margin:0 0 4px}.muted{color:#777;font-size:12px;margin-bottom:18px}</style>
    </head><body>
    <h2>BPC School Clinic — Medicine Dispensing History</h2>
    <div class="muted">Bulacan Polytechnic College · Generated on ${new Date().toLocaleString()}</div>
    <table><thead><tr><th>Date</th><th>Student</th><th>Medicine</th><th>Qty</th><th>Purpose</th><th>Nurse</th></tr></thead>
    <tbody>${$('#histTable tbody').html()}</tbody></table>
    </body></html>`);
  w.document.close();
  w.focus();
  setTimeout(function () { w.print(); }, 400);
});

loadAllHistory();
</script>
</body>
</html>
