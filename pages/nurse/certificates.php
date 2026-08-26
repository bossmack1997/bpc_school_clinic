<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
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
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-file-medical text-success me-1"></i> Issue Certificate</h6>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Search Student</label>
              <input type="text" id="studentSearch" class="form-control" placeholder="Name or student number...">
              <div id="searchResults" class="list-group mt-1"></div>
            </div>
            <form id="certForm" class="d-none">
              <input type="hidden" name="student_id" id="student_id">
              <div class="alert alert-success py-2 small" id="selectedStudent"></div>
              <div class="mb-2"><label class="form-label small">Certificate Type</label>
                <select class="form-select" name="certificate_type">
                  <option>Medical Certificate</option>
                  <option>Health Clearance</option>
                  <option>Fit to Return</option>
                  <option>Other</option>
                </select>
              </div>
              <div class="mb-2"><label class="form-label small">Purpose</label><input class="form-control" name="purpose"></div>
              <div class="mb-2"><label class="form-label small">Date Issued</label><input type="date" class="form-control" name="date_issued" value="<?= date('Y-m-d') ?>"></div>
              <div class="mb-2"><label class="form-label small">Remarks</label><textarea class="form-control" name="remarks" rows="2"></textarea></div>
              <div class="mb-2"><label class="form-label small">Upload File (optional)</label><input type="file" class="form-control" name="file"></div>
              <button class="btn btn-primary w-100" type="submit">Issue Certificate</button>
            </form>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="card p-3">
            <h6 class="section-title mb-3">All Certificates</h6>
            <div class="table-responsive">
               <table class="table table-hover align-middle" id="certTable" style="width:100%">
                 <thead><tr><th>Cert No.</th><th>Student</th><th>Student ID</th><th>Type</th><th>Purpose</th><th>Date Issued</th><th>File</th><th>Actions</th></tr></thead>
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
let selectedId = null;

$('#studentSearch').on('keyup', function () {
  const q = $(this).val();
  if (q.length < 2) { $('#searchResults').empty(); return; }
  $.get(APP_URL + '/api/students_api.php', { action: 'search', q }, function (res) {
    const $r = $('#searchResults').empty();
    res.data.forEach(s => {
      $r.append(`<button type="button" class="list-group-item list-group-item-action" data-id="${s.id}" data-name="${s.first_name} ${s.last_name} (${s.student_number})">${s.first_name} ${s.last_name} — ${s.student_number}</button>`);
    });
  }, 'json');
});

$(document).on('click', '#searchResults button', function () {
  selectedId = $(this).data('id');
  $('#student_id').val(selectedId);
  $('#selectedStudent').text('Selected: ' + $(this).data('name'));
  $('#certForm').removeClass('d-none');
  $('#searchResults').empty();
  $('#studentSearch').val('');
});

$('#certForm').on('submit', function (e) {
  e.preventDefault();
  const fd = new FormData(this);
  fd.append('action', 'issue');
  fd.append('csrf_token', csrfToken);
  $.ajax({ url: APP_URL + '/api/certificates.php', type:'POST', data: fd, processData:false, contentType:false,
    success: function (res) { if (res.success) { toastSuccess(res.message); $('#certForm')[0].reset(); $('#certForm').addClass('d-none'); loadCerts(); } else toastError(res.message); }
  });
});

function loadCerts() {
  $.get(APP_URL + '/api/certificates.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    const rows = res.data.map(c => [
      c.certificate_number || '—',
      (c.s_first || '') + ' ' + (c.s_last || ''),
      c.student_number || '—',
      c.certificate_type,
      c.purpose || '—',
      c.date_issued || '—',
      c.file_path ? `<a href="${APP_URL}/${c.file_path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark"></i></a>` : '—',
      `<div class="d-flex gap-1">
        <button class="btn btn-sm btn-outline-success print-cert" data-id="${c.id}" data-number="${c.certificate_number || ''}" title="Print"><i class="bi bi-printer"></i></button>
        <button class="btn btn-sm btn-outline-danger delete-btn" data-id="${c.id}" title="Delete"><i class="bi bi-trash3"></i></button>
      </div>`
    ]);
    $('#certTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{},{}], pageLength: 10, destroy:true });
  }, 'json');
}
loadCerts();

$(document).on('click', '.print-cert', function () {
  const certId = $(this).data('id');
  const certNumber = $(this).data('number');
  if (!certId) return;
  const w = window.open(APP_URL + '/pages/nurse/certificate_print.php?id=' + certId, '_blank');
  if (w) w.focus();
});

$(document).on('click', '.delete-btn', function () {
  const id = $(this).data('id');
  confirmAction('Delete this certificate?', function () {
    $.post(APP_URL + '/api/certificates.php', { action: 'delete', id, csrf_token: csrfToken }, function (res) {
      if (res.success) { toastSuccess(res.message); loadCerts(); }
      else toastError(res.message);
    }, 'json');
  });
});
</script>
</body>
</html>
