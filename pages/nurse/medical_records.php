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
            <h6 class="section-title mb-3"><i class="bi bi-file-earmark-medical text-success me-1"></i> Upload Record</h6>
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
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
              <h6 class="section-title mb-0">Medical Records</h6>
              <div class="input-group input-group-sm" style="max-width:260px">
                <span class="input-group-text bg-transparent"><i class="bi bi-search"></i></span>
                <input type="text" id="recordSearch" class="form-control" placeholder="Search student, complaint...">
              </div>
            </div>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="recordsTable" style="width:100%">
                <thead><tr><th>Student</th><th>ID</th><th>Date</th><th>Visit Type</th><th>Complaint</th><th>Diagnosis</th><th>File</th><th>Actions</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal fade" id="recordDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow-lg">
      <div class="modal-header border-0 bg-success text-white rounded-top-4">
        <h6 class="modal-title"><i class="bi bi-file-earmark-medical me-2"></i> Medical Record Details</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="recordDetailBody"></div>
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
  const q = $('#recordSearch').val();
  $.get(APP_URL + '/api/medical_records.php', { action: 'list', search: q }, function (res) {
    if (!res.success) return;
    const rows = res.data.map(r => [
      (r.first_name || '') + ' ' + (r.last_name || ''),
      r.student_number || '—',
      r.consultation_date?.split(' ')[0] || '—',
      r.visit_type || '—',
      r.chief_complaint || '—',
      r.diagnosis || '—',
      r.file_path ? `<a href="${APP_URL}/${r.file_path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark"></i></a>` : '—',
      `<button class="btn btn-sm btn-outline-info view-record" data-id="${r.consultation_id || r.id}" title="View Details"><i class="bi bi-eye"></i></button>`
    ]);
    $('#recordsTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{},{}], pageLength: 10, destroy:true });
  }, 'json');
}

$(document).on('click', '.view-record', function () {
  const visitId = $(this).data('id');
  if (!visitId) return;
  $.get(APP_URL + '/api/medical_records.php', { action: 'detail', visit_id: visitId }, function (res) {
    if (!res.success) { toastError(res.message); return; }
    const d = res.data;
    let html = '';
    html += '<div class="row g-3">';
    html += '<div class="col-md-6"><h6 class="fw-semibold text-success mb-2">Student Information</h6>';
    html += '<p class="small mb-1"><strong>Name:</strong> ' + escapeHtml(d.student_name || '—') + '</p>';
    html += '<p class="small mb-1"><strong>Student ID:</strong> ' + escapeHtml(d.student_number || '—') + '</p>';
    html += '<p class="small mb-1"><strong>Course:</strong> ' + escapeHtml(d.course || '—') + '</p>';
    html += '<p class="small mb-1"><strong>Year Level:</strong> ' + escapeHtml(d.year_level || '—') + '</p>';
    html += '</div>';
    html += '<div class="col-md-6"><h6 class="fw-semibold text-success mb-2">Visit Information</h6>';
    html += '<p class="small mb-1"><strong>Date:</strong> ' + escapeHtml(d.consultation_date || '—') + '</p>';
    html += '<p class="small mb-1"><strong>Visit Type:</strong> ' + escapeHtml(d.visit_type || '—') + '</p>';
    html += '<p class="small mb-1"><strong>Chief Complaint:</strong> ' + escapeHtml(d.chief_complaint || '—') + '</p>';
    html += '<p class="small mb-1"><strong>Diagnosis:</strong> ' + escapeHtml(d.diagnosis || '—') + '</p>';
    html += '<p class="small mb-1"><strong>Treatment:</strong> ' + escapeHtml(d.treatment || '—') + '</p>';
    html += '</div>';
    if (d.vitals) {
      html += '<div class="col-12"><h6 class="fw-semibold text-success mb-2">Vital Signs</h6><div class="row g-2">';
      if (d.vitals.temperature) html += '<div class="col-6 col-md-3"><div class="card p-2 text-center"><div class="small text-muted">Temperature</div><div class="fw-bold">' + escapeHtml(d.vitals.temperature) + '°C</div></div></div>';
      if (d.vitals.blood_pressure) html += '<div class="col-6 col-md-3"><div class="card p-2 text-center"><div class="small text-muted">Blood Pressure</div><div class="fw-bold">' + escapeHtml(d.vitals.blood_pressure) + '</div></div></div>';
      if (d.vitals.heart_rate) html += '<div class="col-6 col-md-3"><div class="card p-2 text-center"><div class="small text-muted">Heart Rate</div><div class="fw-bold">' + escapeHtml(d.vitals.heart_rate) + ' bpm</div></div></div>';
      if (d.vitals.respiratory_rate) html += '<div class="col-6 col-md-3"><div class="card p-2 text-center"><div class="small text-muted">Respiratory Rate</div><div class="fw-bold">' + escapeHtml(d.vitals.respiratory_rate) + ' rpm</div></div></div>';
      if (d.vitals.oxygen_saturation) html += '<div class="col-6 col-md-3"><div class="card p-2 text-center"><div class="small text-muted">Oxygen Sat.</div><div class="fw-bold">' + escapeHtml(d.vitals.oxygen_saturation) + '%</div></div></div>';
      if (d.vitals.weight_kg) html += '<div class="col-6 col-md-3"><div class="card p-2 text-center"><div class="small text-muted">Weight</div><div class="fw-bold">' + escapeHtml(d.vitals.weight_kg) + ' kg</div></div></div>';
      if (d.vitals.height_cm) html += '<div class="col-6 col-md-3"><div class="card p-2 text-center"><div class="small text-muted">Height</div><div class="fw-bold">' + escapeHtml(d.vitals.height_cm) + ' cm</div></div></div>';
      if (d.vitals.bmi) html += '<div class="col-6 col-md-3"><div class="card p-2 text-center"><div class="small text-muted">BMI</div><div class="fw-bold">' + escapeHtml(d.vitals.bmi) + '</div></div></div>';
      html += '</div></div>';
    }
    if (d.medicines && d.medicines.length) {
      html += '<div class="col-12"><h6 class="fw-semibold text-success mb-2">Medicines Dispensed</h6><ul class="list-group list-group-flush">';
      d.medicines.forEach(m => {
        html += '<li class="list-group-item small"><strong>' + escapeHtml(m.medicine_name || '—') + '</strong> — Qty: ' + (m.quantity || '—') + ' ' + escapeHtml(m.unit || '') + '</li>';
      });
      html += '</ul></div>';
    }
    html += '</div>';
    $('#recordDetailBody').html(html);
    new bootstrap.Modal(document.getElementById('recordDetailModal')).show();
  }, 'json');
});

$('#recordSearch').on('keyup', function () { clearTimeout(window.__recT); window.__recT = setTimeout(loadRecords, 300); });
loadRecords();

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
</script>
</body>
</html>
