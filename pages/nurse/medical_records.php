<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Medical Records';
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
            <h6 class="section-title mb-3"><i class="bi bi-file-earmark-waveform text-success me-1"></i> Upload Record</h6>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Search Student</label>
              <input type="text" id="studentSearch" class="form-control" placeholder="Name or student number...">
              <div id="searchResults" class="list-group mt-1"></div>
            </div>
            <form id="uploadForm" class="d-none">
              <input type="hidden" name="student_id" id="student_id">
              <input type="hidden" name="consultation_id" id="consultation_id">
              <div class="alert alert-success py-2 small" id="selectedStudent"></div>
              <div class="mb-2">
                <label class="form-label small">Select Consultation</label>
                <select class="form-select" id="consultationSelect" name="consultation_id" required>
                  <option value="">— Select —</option>
                </select>
              </div>
              <div class="mb-2"><label class="form-label small">File</label><input type="file" class="form-control" name="file" required></div>
              <div class="mb-2"><label class="form-label small">Description</label><input class="form-control" name="description"></div>
              <button class="btn btn-primary w-100" type="submit">Upload</button>
            </form>
          </div>
        </div>
        <div class="col-lg-7">
          <div class="card p-3">
            <h6 class="section-title mb-3">All Medical Records</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="recordsTable" style="width:100%">
                <thead><tr><th>Student</th><th>No.</th><th>Date</th><th>Complaint</th><th>Diagnosis</th><th>File</th></tr></thead>
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
  $('#uploadForm').removeClass('d-none');
  $('#searchResults').empty();
  $('#studentSearch').val('');

  $.get(APP_URL + '/api/medical_records.php', { action: 'consultations', student_id: selectedId }, function (res) {
    const $sel = $('#consultationSelect').empty().append('<option value="">— Select —</option>');
    res.data.forEach(c => {
      $sel.append(`<option value="${c.id}">${c.consultation_date?.split(' ')[0] || '—'} — ${c.chief_complaint || '—'}</option>`);
    });
  }, 'json');
});

$('#uploadForm').on('submit', function (e) {
  e.preventDefault();
  const fd = new FormData(this);
  fd.append('action', 'upload');
  fd.append('csrf_token', csrfToken);
  $.ajax({ url: APP_URL + '/api/medical_records.php', type:'POST', data: fd, processData:false, contentType:false,
    success: function (res) { if (res.success) { toastSuccess(res.message); $('#uploadForm')[0].reset(); $('#uploadForm').addClass('d-none'); loadRecords(); } else toastError(res.message); }
  });
});

function loadRecords() {
  $.get(APP_URL + '/api/medical_records.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    const rows = res.data.map(r => [
      (r.first_name || '') + ' ' + (r.last_name || ''),
      r.student_number || '—',
      r.consultation_date?.split(' ')[0] || '—',
      r.chief_complaint || '—',
      r.diagnosis || '—',
      r.file_path ? `<a href="${APP_URL}/${r.file_path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark"></i></a>` : '—'
    ]);
    $('#recordsTable').DataTable({ data: rows, columns:[{},{},{},{},{},{}], pageLength: 10, destroy:true });
  }, 'json');
}
loadRecords();
</script>
</body>
</html>
