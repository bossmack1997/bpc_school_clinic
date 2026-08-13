<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
$pageTitle = 'My Medical Record';
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
        <h6 class="section-title mb-3"><i class="bi bi-file-earmark-medical text-success me-1"></i> My Medical Records</h6>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="recordsTable" style="width:100%">
            <thead><tr><th>Date</th><th>Visit Type</th><th>Complaint</th><th>Diagnosis</th><th>Treatment</th><th>File</th><th>Actions</th></tr></thead>
            <tbody></tbody>
          </table>
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
$.get(APP_URL + '/api/medical_records.php', { action: 'list' }, function (res) {
  if (!res.success) return;
  const rows = res.data.map(r => [
    r.consultation_date?.split(' ')[0] || '—',
    r.visit_type || '—',
    r.chief_complaint || '—',
    r.diagnosis || '—',
    r.treatment || '—',
    r.file_path ? `<a href="${APP_URL}/${r.file_path}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark"></i> View</a>` : '—',
    `<button class="btn btn-sm btn-outline-info view-record" data-id="${r.consultation_id || r.id}" title="View Details"><i class="bi bi-eye"></i></button>`
  ]);
  $('#recordsTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{}], pageLength: 10 });
});

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
    html += '<p class="small mb-1"><strong>Prescription:</strong> ' + escapeHtml(d.prescription || '—') + '</p>';
    html += '<p class="small mb-1"><strong>Remarks:</strong> ' + escapeHtml(d.remarks || '—') + '</p>';
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

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
</script>
</body>
</html>
