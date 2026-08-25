<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Patient History';
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
        <div class="col-lg-4">
          <div class="card p-3">
<h6 class="section-title mb-3"><i class="bi bi-clock-history text-success me-1"></i> Search Patient</h6>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Search Student</label>
              <input type="text" id="studentSearch" class="form-control" placeholder="Name or student number...">
              <div id="searchResults" class="list-group mt-1"></div>
            </div>
            <div id="patientInfo" class="d-none">
              <hr>
              <h6 class="small fw-semibold">Patient Info</h6>
              <p class="mb-1" id="pName"></p>
              <p class="mb-1" id="pNumber"></p>
              <p class="mb-1" id="pCourse"></p>
            </div>
            <div id="wellbeingCard" class="card border-info mt-3 d-none">
              <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                  <i class="bi bi-emoji-smile-heart-eyes text-info fs-4"></i>
                  <div class="small">
                    <div class="fw-semibold">Well-being Information</div>
                    <div class="text-muted">Student has voluntarily provided additional well-being information.</div>
                  </div>
                </div>
                <button class="btn btn-sm btn-info w-100 mt-2" id="viewWellbeingBtn"><i class="bi bi-eye me-1"></i> View Relevant Information</button>
              </div>
            </div>
            <button class="btn btn-success w-100 mt-3" id="createReferralBtn" style="display:none;"><i class="bi bi-send me-1"></i> Create Support Referral</button>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card p-3 mb-3" id="consultCard" style="display:none">
            <h6 class="section-title mb-3">Consultation History</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="consultTable" style="width:100%">
                <thead><tr><th>Date</th><th>Type</th><th>Complaint</th><th>Diagnosis</th><th>Treatment</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
          <div class="card p-3 mb-3" id="healthCard" style="display:none">
            <h6 class="section-title mb-3">Health Monitoring</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="healthTable" style="width:100%">
                 <thead><tr><th>Date</th><th>BMI</th><th>Status</th><th>Record Type</th><th>BP</th><th>Heart Rate</th><th>Temp</th></tr></thead>
                <tbody></tbody>
              </table>
            </div>
          </div>
          <div class="card p-3" id="vacCard" style="display:none">
            <h6 class="section-title mb-3">Vaccination Record</h6>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="vacTable" style="width:100%">
                <thead><tr><th>Vaccine</th><th>Dose</th><th>Date Administered</th><th>Next Dose</th></tr></thead>
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
$('#studentSearch').on('keyup', function () {
  const q = $(this).val();
  if (q.length < 2) { $('#searchResults').empty(); return; }
  $.get(APP_URL + '/api/students_api.php', { action: 'search', q }, function (res) {
    const $r = $('#searchResults').empty();
    res.data.forEach(s => {
      $r.append(`<button type="button" class="list-group-item list-group-item-action" data-id="${s.id}" data-name="${s.first_name} ${s.last_name} (${s.student_number})" data-course="${s.course||''}" data-number="${s.student_number||''}">${s.first_name} ${s.last_name} — ${s.student_number}</button>`);
    });
  }, 'json');
});

$(document).on('click', '#searchResults button', function () {
  const id = $(this).data('id');
  $('#pName').text('Name: ' + $(this).data('name'));
  $('#pNumber').text('Student No: ' + $(this).data('number'));
  $('#pCourse').text('Course: ' + $(this).data('course'));
  $('#patientInfo').removeClass('d-none');
  $('#searchResults').empty();
  $('#studentSearch').val('');
  loadPatientHistory(id);
});

let currentPatientId = null;
function loadPatientHistory(id) {
  currentPatientId = id;
  $('#createReferralBtn').show();
  // Check for voluntarily-provided well-being information (gated, nurse-only)
  $.get(APP_URL + '/api/wellbeing.php', { action: 'get', student_id: id }, function (wb) {
    if (wb.success && wb.exists) {
      $('#wellbeingCard').removeClass('d-none');
    } else {
      $('#wellbeingCard').addClass('d-none');
    }
  }, 'json');

  $.get(APP_URL + '/api/students_api.php', { action: 'patient_history', student_id: id }, function (res) {
    if (!res.success) return;

    if (res.consultations.length) {
      $('#consultCard').show();
      const rows = res.consultations.map(c => [c.consultation_date, c.visit_type, c.chief_complaint, c.diagnosis || '—', c.treatment || '—']);
      $('#consultTable').DataTable({ data: rows, columns:[{},{},{},{},{}], destroy:true, pageLength:5 });
    } else { $('#consultCard').hide(); }

    if (res.health_monitoring.length) {
      $('#healthCard').show();
      const rows = res.health_monitoring.map(h => {
        const typeLabel = h.record_type === 'nurse_assessment' ? 'Nurse Assessment' : 'Self-Recorded BMI';
        return [h.recorded_at, h.bmi || '—', h.bmi_status || '—', typeLabel, h.blood_pressure || '—', h.heart_rate || '—', h.temperature || '—'];
      });
      $('#healthTable').DataTable({ data: rows, columns:[{},{},{},{},{},{},{}], destroy:true, pageLength:5 });
    } else { $('#healthCard').hide(); }

    if (res.vaccinations.length) {
      $('#vacCard').show();
      const rows = res.vaccinations.map(v => [v.vaccine_name, v.dose_number || '—', v.date_administered, v.next_dose_date || '—']);
      $('#vacTable').DataTable({ data: rows, columns:[{},{},{},{}], destroy:true, pageLength:5 });
    } else { $('#vacCard').hide(); }
  }, 'json');
}

// -------- Well-being (sensitive, nurse view) --------
$('#viewWellbeingBtn').on('click', function () {
  if (!currentPatientId) return;
  $.get(APP_URL + '/api/wellbeing.php', { action: 'get', student_id: currentPatientId }, function (res) {
    if (!res.success || !res.profile) { toastError('Unable to load well-being information.'); return; }
    const p = res.profile;
    const field = (label, val) => `<div class="mb-2"><div class="text-muted small">${label}</div><div class="fw-semibold">${val ? $('<div>').text(val).html() : '<span class="text-muted">Not provided</span>'}</div></div>`;
    let prefs = '';
    try { const arr = JSON.parse(p.support_preferences || '[]'); prefs = arr.length ? arr.join(', ') : '—'; } catch (e) { prefs = '—'; }
    const html =
      `<div class="alert alert-info small"><i class="bi bi-shield-lock-fill me-1"></i> Sensitive information — use only to support the student appropriately.</div>` +
      `<h6 class="small fw-bold">Medical Information</h6>` +
      field('Known Medical Conditions', p.known_medical_conditions) +
      field('Allergies', p.allergies) +
      field('Previous Serious Illnesses', p.previous_illnesses) +
      field('Current Medications', p.current_medications) +
      field('Other Important Health Information', p.other_medical_info) +
      `<h6 class="small fw-bold mt-3">Mental &amp; Emotional Well-being</h6>` +
      field('Concerns', p.concerns) +
      field('Previous Professional Support', p.previous_support) +
      field('Previous Diagnosis / Condition (disclosed)', p.diagnosis_disclosed) +
      field('Current Support / Treatment', p.current_support) +
      field('Other Relevant Information', p.other_wellbeing) +
      `<h6 class="small fw-bold mt-3">Support Preferences</h6>` +
      field('Preferred approach', prefs) +
      field('Additional notes', p.student_notes) +
      `<div class="text-muted small mt-2">Consent acknowledged: ${p.consent_at || '—'}</div>`;
    $('#wellbeingBody').html(html);
    new bootstrap.Modal(document.getElementById('wellbeingModal')).show();
  }, 'json');
});

// -------- Support Referral from patient view --------
$('#createReferralBtn').on('click', function () {
  if (!currentPatientId) return;
  $('#refStudentId2').val(currentPatientId);
  $('#refStudentChosen2').removeClass('d-none').text('Student ID: ' + currentPatientId);
  new bootstrap.Modal(document.getElementById('referralModal2')).show();
});

$('#referralForm2').on('submit', function (e) {
  e.preventDefault();
  if (!$('#refStudentId2').val()) { toastError('No student selected.'); return; }
  const data = $(this).serialize() + '&action=create&csrf_token=' + $('meta[name="csrf-token"]').attr('content');
  $.post(APP_URL + '/api/referrals.php', data, function (res) {
    if (res.success) { toastSuccess(res.message); $('#referralForm2')[0].reset(); bootstrap.Modal.getInstance(document.getElementById('referralModal2')).hide(); }
    else toastError(res.message);
  }, 'json');
});
</script>

<!-- Well-being Detail Modal -->
<div class="modal fade" id="wellbeingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header border-0 bg-info text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-emoji-smile-heart-eyes me-2"></i> Student Well-being Information</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="wellbeingBody"></div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Referral Modal (from patient view) -->
<div class="modal fade" id="referralModal2" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header border-0 bg-success text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-send-fill me-2"></i> New Support Referral</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="referralForm2">
          <input type="hidden" name="student_id" id="refStudentId2">
          <div id="refStudentChosen2" class="small text-success mb-2 d-none"></div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Referral Destination</label>
            <input type="text" class="form-control" name="referred_to" value="Guidance Office" required>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Reason</label>
            <textarea class="form-control" name="reason" rows="2" required></textarea>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small fw-semibold">Priority</label>
              <select class="form-select" name="priority">
                <option value="routine">Routine</option>
                <option value="soon">Soon</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-6 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="student_informed" id="si2" value="1">
                <label class="form-check-label small" for="si2">Student informed</label>
              </div>
            </div>
          </div>
          <div class="mb-2 mt-2">
            <label class="form-label small fw-semibold">Notes</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
          </div>
          <button class="btn btn-success w-100" type="submit"><i class="bi bi-send me-1"></i> Create Referral</button>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
