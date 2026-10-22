<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Patient Clinic History';
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
      <!-- Header & Quick Actions -->
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
          <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history text-success me-2"></i> Patient Clinic History</h5>
          <small class="text-muted">Explore patient consultations, vitals, vaccinations, and comprehensive medical timeline.</small>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-warning fw-bold text-dark shadow-sm" onclick="openQrScannerModal()">
            <i class="bi bi-qr-code-scan me-1"></i> Scan Patient QR
          </button>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-4">
          <div class="card p-3 shadow-sm border-0">
            <h6 class="section-title mb-3"><i class="bi bi-person-badge text-success me-1"></i> Search Patient</h6>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Search Student</label>
              <input type="text" id="studentSearch" class="form-control" placeholder="Name or student number...">
              <div id="searchResults" class="list-group mt-1 shadow-sm" style="max-height: 200px; overflow-y: auto;"></div>
            </div>
            <div id="patientInfo" class="d-none">
              <hr class="my-2">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="small fw-bold text-dark mb-0">Active Patient Profile</h6>
                <button type="button" class="btn btn-sm btn-success fw-bold" id="openWorkspaceBtn">
                  <i class="bi bi-person-workspace me-1"></i> Open Workspace
                </button>
              </div>
              <div class="p-2 bg-light rounded border">
                <p class="mb-1 small fw-bold text-dark" id="pName"></p>
                <p class="mb-1 small text-muted" id="pNumber"></p>
                <p class="mb-0 small text-muted" id="pCourse"></p>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card p-3 mb-3 shadow-sm border-0" id="consultCard" style="display:none">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="section-title mb-0"><i class="bi bi-journal-medical text-success me-1"></i> Consultation History</h6>
            </div>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="consultTable" style="width:100%">
                <thead><tr><th>Date</th><th>Type</th><th>Complaint</th><th>Diagnosis</th><th>Treatment</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
          <div class="card p-3 mb-3 shadow-sm border-0" id="healthCard" style="display:none">
            <h6 class="section-title mb-3"><i class="bi bi-heart-pulse-fill text-danger me-1"></i> Health Monitoring &amp; Vitals</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="healthTable" style="width:100%">
                 <thead><tr><th>Date</th><th>BMI</th><th>Status</th><th>Record Type</th><th>BP</th><th>Heart Rate</th><th>Temp</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
          <div class="card p-3 shadow-sm border-0" id="vacCard" style="display:none">
            <h6 class="section-title mb-3"><i class="bi bi-shield-check text-primary me-1"></i> Vaccination Record</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="vacTable" style="width:100%">
                <thead><tr><th>Vaccine</th><th>Dose</th><th>Date Administered</th><th>Next Dose</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
          <div id="noHistoryPlaceholder" class="card p-5 text-center text-muted border-0 shadow-sm">
            <i class="bi bi-search fs-1 mb-2 text-secondary"></i>
            <h6>No Patient Selected</h6>
            <p class="small text-muted mb-0">Search a student above or click [Scan Patient QR] to view complete medical timeline.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/patient_workspace_modal.php'; ?>
<?php include __DIR__ . '/../../includes/qr_scanner_modal.php'; ?>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
let currentPatientId = null;

$('#studentSearch').on('keyup', function () {
  const q = $(this).val();
  if (q.length < 2) { $('#searchResults').empty(); return; }
  $.get(APP_URL + '/api/students_api.php', { action: 'search', q }, function (res) {
    const $r = $('#searchResults').empty();
    (res.data || []).forEach(s => {
      $r.append(`<button type="button" class="list-group-item list-group-item-action py-2" data-id="${s.id}" data-name="${escapeHtml(s.first_name + ' ' + s.last_name)}" data-course="${escapeHtml(s.course||'')}" data-number="${escapeHtml(s.student_number||'')}">${escapeHtml(s.first_name + ' ' + s.last_name)} — ${s.student_number}</button>`);
    });
  }, 'json');
});

$(document).on('click', '#searchResults button', function () {
  const id = $(this).data('id');
  $('#pName').text($(this).data('name'));
  $('#pNumber').text('Student ID: ' + $(this).data('number'));
  $('#pCourse').text('Course: ' + $(this).data('course'));
  $('#patientInfo').removeClass('d-none');
  $('#searchResults').empty();
  $('#studentSearch').val('');
  $('#openWorkspaceBtn').off('click').on('click', function() {
    openPatientWorkspace(id);
  });
  loadPatientHistory(id);
});

function loadPatientHistory(id) {
  currentPatientId = id;
  $('#noHistoryPlaceholder').hide();

  $.get(APP_URL + '/api/students_api.php', { action: 'patient_history', student_id: id }, function (res) {
    if (!res.success) return;

    if (res.consultations && res.consultations.length) {
      $('#consultCard').show();
      const rows = res.consultations.map(c => [
        escapeHtml(c.consultation_date || '—'),
        escapeHtml(c.visit_type || '—'),
        escapeHtml(c.chief_complaint || '—'),
        escapeHtml(c.diagnosis || '—'),
        escapeHtml(c.treatment || '—')
      ]);
      $('#consultTable').DataTable({ data: rows, columns:[{},{},{},{},{}], destroy:true, pageLength:5 });
    } else { $('#consultCard').hide(); }

    if (res.health_monitoring && res.health_monitoring.length) {
      $('#healthCard').show();
      const rows = res.health_monitoring.map(h => {
        const typeLabel = h.record_type === 'nurse_assessment' ? 'Nurse Assessment' : 'Self-Recorded BMI';
        return [
          escapeHtml(h.recorded_at || '—'),
          escapeHtml(h.bmi || '—'),
          escapeHtml(h.bmi_status || '—'),
          typeLabel,
          escapeHtml(h.blood_pressure || '—'),
          escapeHtml(h.heart_rate || '—'),
          escapeHtml(h.temperature || '—')
        ];
      });
      $('#healthTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{}], destroy:true, pageLength:5 });
    } else { $('#healthCard').hide(); }

    if (res.vaccinations && res.vaccinations.length) {
      $('#vacCard').show();
      const rows = res.vaccinations.map(v => [
        escapeHtml(v.vaccine_name || '—'),
        escapeHtml(v.dose_number || '—'),
        escapeHtml(v.date_administered || '—'),
        escapeHtml(v.next_dose_date || '—')
      ]);
      $('#vacTable').DataTable({ data: rows, columns:[{},{},{},{}], destroy:true, pageLength:5 });
    } else { $('#vacCard').hide(); }
  }, 'json');
}

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

$(function() {
  const urlParams = new URLSearchParams(window.location.search);
  const initId = parseInt(urlParams.get('student_id') || urlParams.get('id') || '0', 10);
  if (initId > 0) {
    $.get(APP_URL + '/api/students_api.php', { action: 'get', id: initId }, function(res) {
      if (res && res.success && res.data) {
        const s = res.data;
        $('#pName').text((s.first_name || '') + ' ' + (s.last_name || ''));
        $('#pNumber').text('Student ID: ' + (s.student_number || '—'));
        $('#pCourse').text('Course: ' + (s.course || '—'));
        $('#patientInfo').removeClass('d-none');
        $('#openWorkspaceBtn').off('click').on('click', function() {
          openPatientWorkspace(initId);
        });
      }
    }, 'json');
    loadPatientHistory(initId);
  }
});
</script>
</body>
</html>
