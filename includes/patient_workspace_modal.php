<?php
/**
 * includes/patient_workspace_modal.php
 * =====================================================================
 * Reusable Centralized Patient Workspace Modal
 * Provides a single unified view for:
 *   - Patient Demographics & Identification
 *   - Medical Health Alerts (Blood Type, Allergies, Chronic Conditions)
 *   - Emergency Contact Access
 *   - Live Clinic Status & Ticket Controls
 *   - Complete Historical Clinic Visits & Dispensed Medicines
 *   - Integrated Consultation Form (Vitals, Notes, In-line Dispensing)
 *   - 1-Click Medical Certificate Generation
 * =====================================================================
 */
?>
<!-- Centralized Patient Workspace Modal -->
<div class="modal fade" id="centralPatientWorkspaceModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true" aria-labelledby="centralPatientWorkspaceModalTitle">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
      
      <!-- Workspace Header -->
      <div class="modal-header bg-success text-white py-3 px-4 d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-circle bg-white text-success d-flex align-items-center justify-content-center fw-bold fs-4" style="width: 46px; height: 46px;" id="pwAvatarIcon">
            <i class="bi bi-person-fill"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0" id="centralPatientWorkspaceModalTitle">Loading Patient Profile...</h5>
            <small class="opacity-90" id="pwPatientSubtitle">Patient Identifier: —</small>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="badge bg-light text-dark px-3 py-2 rounded-pill fw-semibold" id="pwClinicStatusBadge">Status: —</span>
          <button type="button" class="btn-close btn-close-white ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
      </div>

      <!-- Workspace Body -->
      <div class="modal-body p-4 bg-light">
        <div id="pwLoadingSpinner" class="text-center py-5">
          <div class="spinner-border text-success mb-2" role="status"></div>
          <div class="text-muted small">Loading complete medical profile and clinic history...</div>
        </div>

        <div id="pwWorkspaceContent" style="display: none;">
          
          <!-- 1. Patient Demographics, Medical Alerts & Emergency Contact Card -->
          <div class="card border-0 shadow-sm mb-3 rounded-4 p-3 bg-white">
            <div class="row g-3 align-items-center">
              <div class="col-md-5 border-end-md">
                <div class="d-flex align-items-center gap-3">
                  <img src="<?= asset_url('images/default-avatar.svg') ?>" id="pwProfilePic" class="rounded-circle border" style="width:68px; height:68px; object-fit:cover;" alt="Patient Photo">
                  <div>
                    <h6 class="fw-bold text-dark mb-1" id="pwHeaderName">—</h6>
                    <div class="text-muted small mb-1" id="pwHeaderAcademic">—</div>
                    <div class="d-flex gap-2 small">
                      <span class="text-secondary"><i class="bi bi-gender-ambiguous me-1"></i><span id="pwHeaderGender">—</span></span>
                      <span class="text-secondary">·</span>
                      <span class="text-secondary"><i class="bi bi-telephone me-1"></i><span id="pwHeaderContact">—</span></span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Medical Alerts -->
              <div class="col-md-4 border-end-md">
                <div class="small fw-bold text-uppercase text-secondary mb-2"><i class="bi bi-shield-plus text-danger me-1"></i> Health Information</div>
                <div class="d-flex gap-2 flex-wrap align-items-center mb-1">
                  <span class="badge bg-light text-dark border">Blood Type: <strong id="pwBloodType">—</strong></span>
                  <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" id="pwAllergiesBadge">Allergies: None</span>
                </div>
                <div class="text-muted small" style="font-size:0.78rem;">
                  Conditions: <span id="pwConditionsText" class="fw-medium text-dark">None Recorded</span>
                </div>
              </div>

              <!-- Emergency Contact -->
              <div class="col-md-3">
                <div class="small fw-bold text-uppercase text-secondary mb-1"><i class="bi bi-telephone-outbound-fill text-warning me-1"></i> Emergency Contact</div>
                <div class="fw-bold text-dark small" id="pwEmergName">—</div>
                <div class="text-muted small" id="pwEmergNumber">—</div>
              </div>
            </div>
          </div>

          <!-- 2. Contextual Clinic Action Bar -->
          <div class="card border-0 shadow-sm mb-3 rounded-4 p-3 bg-white">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div class="d-flex align-items-center gap-2">
                <div class="fs-4 text-success"><i class="bi bi-activity"></i></div>
                <div>
                  <div class="fw-bold text-dark small" id="pwTicketStatusText">No Active Clinic Visit Today</div>
                  <small class="text-muted" id="pwTicketSubText">Scan QR or start visit to generate queue ticket.</small>
                </div>
              </div>
              <div class="d-flex gap-2 flex-wrap" id="pwActionButtonsGroup">
                <button type="button" class="btn btn-primary btn-sm fw-bold px-3 rounded-pill" id="pwStartVisitBtn" onclick="pwStartClinicVisit()">
                  <i class="bi bi-ticket-perforated-fill me-1"></i> Start Clinic Visit
                </button>
                <button type="button" class="btn btn-warning btn-sm fw-bold px-3 rounded-pill text-dark" id="pwCallNextBtn" style="display:none;" onclick="pwCallCurrentPatient()">
                  <i class="bi bi-megaphone-fill me-1"></i> Call Patient
                </button>
                <button type="button" class="btn btn-success btn-sm fw-bold px-3 rounded-pill" id="pwConsultBtn" onclick="pwOpenConsultationForm()">
                  <i class="bi bi-heart-pulse-fill me-1"></i> Consultation &amp; Vitals
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm fw-bold px-3 rounded-pill" id="pwEmergencyBtn" onclick="pwTriggerEmergency()">
                  <i class="bi bi-exclamation-triangle-fill me-1"></i> Emergency
                </button>
              </div>
            </div>
          </div>

          <!-- 3. Integrated Consultation Workspace Form (Expandable / Toggleable) -->
          <div class="card border-0 shadow-sm mb-3 rounded-4 p-3 bg-white" id="pwConsultationFormContainer" style="display: none;">
            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
              <h6 class="fw-bold text-success mb-0"><i class="bi bi-pencil-square me-1"></i> Record Clinical Consultation &amp; Dispensing</h6>
              <button type="button" class="btn btn-sm btn-outline-secondary" onclick="pwCloseConsultationForm()">Close Form</button>
            </div>

            <form id="pwConsultationForm" onsubmit="pwSubmitConsultation(event)">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="complete_consultation_full">
              <input type="hidden" name="ticket_id" id="pwFormTicketId">
              <input type="hidden" name="student_id" id="pwFormStudentId">

              <div class="row g-3">
                <!-- Left Column: Vitals -->
                <div class="col-lg-5 border-end-lg">
                  <div class="fw-bold text-dark small mb-2"><i class="bi bi-heart-pulse text-danger me-1"></i> Vital Signs</div>
                  <div class="row g-2 small">
                    <div class="col-6">
                      <label class="form-label text-muted mb-1">Temperature (°C)</label>
                      <input type="number" step="0.1" class="form-control form-control-sm" name="temperature" id="pwVTemp" placeholder="36.5">
                    </div>
                    <div class="col-6">
                      <label class="form-label text-muted mb-1">O2 Saturation (%)</label>
                      <input type="number" step="0.1" class="form-control form-control-sm" name="oxygen_saturation" id="pwVO2" placeholder="98">
                    </div>
                    <div class="col-6">
                      <label class="form-label text-muted mb-1">BP (Sys / Dia)</label>
                      <div class="d-flex gap-1">
                        <input type="number" class="form-control form-control-sm" name="blood_pressure_systolic" id="pwVBpSys" placeholder="120">
                        <span class="align-self-center text-muted">/</span>
                        <input type="number" class="form-control form-control-sm" name="blood_pressure_diastolic" id="pwVBpDia" placeholder="80">
                      </div>
                    </div>
                    <div class="col-6">
                      <label class="form-label text-muted mb-1">Heart Rate (bpm)</label>
                      <input type="number" class="form-control form-control-sm" name="heart_rate" id="pwVHr" placeholder="75">
                    </div>
                    <div class="col-6">
                      <label class="form-label text-muted mb-1">Height (cm)</label>
                      <input type="number" step="0.1" class="form-control form-control-sm" name="height_cm" id="pwVHeight" placeholder="165" oninput="pwCalcBmi()">
                    </div>
                    <div class="col-6">
                      <label class="form-label text-muted mb-1">Weight (kg)</label>
                      <input type="number" step="0.1" class="form-control form-control-sm" name="weight_kg" id="pwVWeight" placeholder="58" oninput="pwCalcBmi()">
                    </div>
                    <div class="col-12 mt-2">
                      <div class="p-2 rounded bg-light border d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Auto BMI:</span>
                        <span class="fw-bold" id="pwLiveBmiText">— (Normal)</span>
                      </div>
                    </div>
                  </div>

                  <!-- Medicine Dispensing -->
                  <div class="mt-3 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <div class="fw-bold text-primary small"><i class="bi bi-capsule me-1"></i> Dispense Medication</div>
                      <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2" onclick="pwAddMedRow()">
                        <i class="bi bi-plus-lg me-1"></i> Add
                      </button>
                    </div>
                    <div id="pwMedRowsContainer"></div>
                  </div>
                </div>

                <!-- Right Column: Clinical Evaluation Notes & Certificate -->
                <div class="col-lg-7">
                  <div class="mb-2">
                    <label class="form-label fw-semibold small">Chief Complaint <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" name="chief_complaint" id="pwComplaintInput" required placeholder="e.g. Headache, Fever, Dizziness, Abdominal Pain">
                  </div>
                  <div class="row g-2 mb-2">
                    <div class="col-md-6">
                      <label class="form-label fw-semibold small">Symptoms Observed</label>
                      <textarea class="form-control form-control-sm" name="symptoms" rows="2" placeholder="Onset, severity, duration..."></textarea>
                    </div>
                    <div class="col-md-6">
                      <label class="form-label fw-semibold small">Clinical Assessment</label>
                      <textarea class="form-control form-control-sm" name="assessment" rows="2" placeholder="Physical examination findings..."></textarea>
                    </div>
                  </div>
                  <div class="row g-2 mb-2">
                    <div class="col-md-6">
                      <label class="form-label fw-semibold small">Diagnosis</label>
                      <input type="text" class="form-control form-control-sm" name="diagnosis" placeholder="e.g. Tension Headache, Upper Respiratory Infection">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label fw-semibold small">Treatment Given</label>
                      <input type="text" class="form-control form-control-sm" name="treatment" placeholder="e.g. Rest, oral medication, hydration">
                    </div>
                  </div>
                  <div class="mb-2">
                    <label class="form-label fw-semibold small">Nurse Advice &amp; Recommendations</label>
                    <textarea class="form-control form-control-sm" name="recommendation" rows="2" placeholder="Rest instructions, return if symptoms persist..."></textarea>
                  </div>

                  <!-- Follow-up & Certificate issuance -->
                  <div class="row g-2 align-items-center mt-2 pt-2 border-top">
                    <div class="col-md-6">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="issue_certificate" id="pwIssueCertCheck" value="1" onchange="pwToggleCertFields()">
                        <label class="form-check-label small fw-bold text-success" for="pwIssueCertCheck">
                          <i class="bi bi-file-medical me-1"></i> Issue Official Certificate
                        </label>
                      </div>
                      <div id="pwCertFieldsDiv" style="display: none;" class="mt-2">
                        <select class="form-select form-select-sm mb-1" name="certificate_type">
                          <option value="Clinic Visit Pass">Clinic Visit Clearance Pass</option>
                          <option value="Medical Certificate">Medical Certificate / Fit to Class</option>
                        </select>
                        <input type="text" class="form-control form-control-sm" name="certificate_purpose" placeholder="Purpose (e.g. Excused from PE class)">
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="need_follow_up" id="pwFollowUpCheck" value="1" onchange="pwToggleFollowUp()">
                        <label class="form-check-label small fw-semibold" for="pwFollowUpCheck">Needs Follow-up Visit</label>
                      </div>
                      <div id="pwFollowUpDiv" style="display: none;" class="mt-2">
                        <input type="date" class="form-control form-control-sm" name="follow_up_date" min="<?= date('Y-m-d') ?>">
                      </div>
                    </div>
                  </div>

                  <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="pwCloseConsultationForm()">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold px-4" id="pwSubmitConsultBtn">
                      <i class="bi bi-check2-circle me-1"></i> Complete Consultation &amp; Save Record
                    </button>
                  </div>
                </div>
              </div>
            </form>
          </div>

          <!-- 4. Complete Clinic Visit History & Issued Certificates -->
          <div class="card border-0 shadow-sm rounded-4 p-3 bg-white">
            <ul class="nav nav-tabs nav-tabs-bordered mb-3" role="tablist">
              <li class="nav-item">
                <button class="nav-link active fw-semibold small" data-bs-toggle="tab" data-bs-target="#pwTabVisits" type="button">
                  <i class="bi bi-clock-history me-1"></i> Previous Visits (<span id="pwVisitCountBadge">0</span>)
                </button>
              </li>
              <li class="nav-item">
                <button class="nav-link fw-semibold small" data-bs-toggle="tab" data-bs-target="#pwTabCerts" type="button">
                  <i class="bi bi-file-medical-fill me-1"></i> Issued Certificates (<span id="pwCertCountBadge">0</span>)
                </button>
              </li>
            </ul>

            <div class="tab-content">
              <!-- Visits Tab -->
              <div class="tab-pane fade show active" id="pwTabVisits">
                <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                  <table class="table table-hover align-middle mb-0 small" id="pwVisitsTable">
                    <thead class="table-light">
                      <tr>
                        <th>Date &amp; Time</th>
                        <th>Type</th>
                        <th>Chief Complaint</th>
                        <th>Diagnosis</th>
                        <th>Treatment / Medicines</th>
                        <th>Attending Staff</th>
                      </tr>
                    </thead>
                    <tbody id="pwVisitsTableBody">
                      <tr><td colspan="6" class="text-center text-muted py-3">No previous clinic visits recorded.</td></tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- Certificates Tab -->
              <div class="tab-pane fade" id="pwTabCerts">
                <div class="table-responsive" style="max-height: 280px; overflow-y: auto;">
                  <table class="table table-hover align-middle mb-0 small" id="pwCertsTable">
                    <thead class="table-light">
                      <tr>
                        <th>Certificate #</th>
                        <th>Type</th>
                        <th>Purpose</th>
                        <th>Date Issued</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody id="pwCertsTableBody">
                      <tr><td colspan="5" class="text-center text-muted py-3">No certificates issued yet.</td></tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

      <div class="modal-footer bg-light border-0 py-2 px-4 justify-content-between">
        <span class="text-muted small" id="pwFooterNotes"><i class="bi bi-shield-check text-success me-1"></i> Confidential Medical Record · BPC School Clinic</span>
        <button type="button" class="btn btn-outline-secondary btn-sm px-3 rounded-pill" data-bs-dismiss="modal">Close Workspace</button>
      </div>

    </div>
  </div>
</div>

<!-- Available Medicines Cache for In-Line Dispensing -->
<script>
// Use the current page's host/protocol with the configured app subdirectory.
// This keeps QR lookups working when XAMPP is opened via localhost, 127.0.0.1,
// a LAN address, or HTTPS, where an absolute APP_URL can point to another host.
const patientServicePath = <?= json_encode(rtrim((string)parse_url(APP_URL, PHP_URL_PATH), '/') . '/api/patients.php') ?>;
function patientServiceUrl(params = {}) {
  const url = new URL(patientServicePath, window.location.origin);
  Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
  return url.toString();
}

window.PW_MEDICINES_CACHE = [];
(async function() {
  try {
    const res = await fetch('<?= APP_URL ?>/api/dispensing.php?action=search_medicines&q=');
    const json = await res.json();
    if (json.success) window.PW_MEDICINES_CACHE = json.data || [];
  } catch(e) { console.error('Failed to load medicines cache:', e); }
})();

let currentPwPatient = null;

/**
 * Open Central Patient Workspace by Student ID or QR Token
 */
async function openPatientWorkspace(identifier, type = 'student_id') {
  const modalEl = document.getElementById('centralPatientWorkspaceModal');
  if (!modalEl) return;
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  modal.show();

  document.getElementById('pwLoadingSpinner').style.display = 'block';
  document.getElementById('pwWorkspaceContent').style.display = 'none';
  document.getElementById('pwConsultationFormContainer').style.display = 'none';

  const params = { action: 'get_workspace' };
  if (type === 'qr_token') {
    params.qr_token = identifier;
  } else if (type === 'ticket_id') {
    params.ticket_id = identifier;
  } else {
    params.student_id = identifier;
  }

  try {
    const res = await fetch(patientServiceUrl(params), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const raw = await res.text();
    let json = null;
    try {
      json = JSON.parse(raw);
    } catch(e) {
      console.error('Non-JSON response from patient service:', raw);
    }

    document.getElementById('pwLoadingSpinner').style.display = 'none';

    if (!res.ok || !json || !json.success) {
      const errMsg = (json && json.message) ? json.message : (!res.ok
        ? `Patient service returned HTTP ${res.status}. Please try scanning again.`
        : 'Patient record not found.');
      if (typeof showToast === 'function') showToast(errMsg, 'danger');
      else alert(errMsg);
      modal.hide();
      return;
    }

    // Support both data-wrapped and flat payload responses
    const patientData = (json.data && json.data.patient) ? json.data : (json.patient ? json : null);
    if (!patientData || !patientData.patient) {
      const errMsg = json.message || 'Patient record data is unavailable.';
      if (typeof showToast === 'function') showToast(errMsg, 'danger');
      else alert(errMsg);
      modal.hide();
      return;
    }

    currentPwPatient = patientData;
    renderPatientWorkspaceData(currentPwPatient);
    document.getElementById('pwWorkspaceContent').style.display = 'block';
  } catch(err) {
    console.error('Error loading patient workspace:', err);
    document.getElementById('pwLoadingSpinner').style.display = 'none';
    const message = 'Could not reach the patient service. Check the clinic connection and try scanning again.';
    if (typeof showToast === 'function') showToast(message, 'danger');
    else alert(message);
    modal.hide();
  }
}

function pwSetText(id, value) {
  const el = document.getElementById(id);
  if (!el) { console.warn('Missing element:', id); return; }
  el.textContent = value;
}

function renderPatientWorkspaceData(data) {
  if (!data || !data.patient) {
    console.warn("renderPatientWorkspaceData called with invalid or empty data:", data);
    return;
  }
  const p = data.patient || {};
  const h = data.health_profile || {};
  const e = data.emergency_contact || {};
  const q = (data.current_status && data.current_status.queue) ? data.current_status.queue : null;
  const history = Array.isArray(data.clinic_history) ? data.clinic_history : [];
  const certs = Array.isArray(data.certificates) ? data.certificates : [];

  const patientIdNum = p.student_number || p.patient_code || '—';
  const academicLine = [p.course, p.year_level, p.section].filter(Boolean).join(' ') || '—';

  // Demographics
document.getElementById('centralPatientWorkspaceModalTitle').textContent = p.name || '—';
  document.getElementById('pwPatientSubtitle').textContent = `Patient ID: ${patientIdNum} · Course: ${academicLine}`;
  document.getElementById('pwHeaderName').textContent = p.name || '—';
  document.getElementById('pwHeaderAcademic').textContent = `${patientIdNum} · ${academicLine}`;
  document.getElementById('pwHeaderGender').textContent = p.gender || '—';
  document.getElementById('pwHeaderContact').textContent = p.phone || '—';
  if (p.profile_picture) document.getElementById('pwProfilePic').src = p.profile_picture;

  // Health Profile & Allergies
  document.getElementById('pwBloodType').textContent = h.blood_type || '—';
  const aBadge = document.getElementById('pwAllergiesBadge');
  if (h.has_allergies && h.allergies && h.allergies !== 'None Recorded') {
    aBadge.className = 'badge bg-danger text-white border-0 fw-bold';
    aBadge.textContent = 'Allergies: ' + h.allergies;
  } else {
    aBadge.className = 'badge bg-light text-secondary border';
    aBadge.textContent = 'Allergies: None Recorded';
  }
  document.getElementById('pwConditionsText').textContent = h.medical_conditions || 'None Recorded';

  // Emergency
  document.getElementById('pwEmergName').textContent = e.name || e.guardian_name || '—';
  document.getElementById('pwEmergNumber').textContent = e.number || e.guardian_contact || '—';

  // Current Queue Status & Action Buttons
  const startBtn = document.getElementById('pwStartVisitBtn');
  const callBtn = document.getElementById('pwCallNextBtn');
  const consultBtn = document.getElementById('pwConsultBtn');

  if (q && (q.status === 'waiting' || q.status === 'called' || q.status === 'serving')) {
    const qStatusUpper = String(q.status || '').toUpperCase();
    document.getElementById('pwClinicStatusBadge').className = 'badge bg-success text-white px-3 py-2 rounded-pill fw-semibold';
    document.getElementById('pwClinicStatusBadge').textContent = `Ticket #${q.queue_number || '—'} (${qStatusUpper})`;
    document.getElementById('pwTicketStatusText').textContent = `Active Ticket #${q.queue_number || '—'} — Status: ${qStatusUpper}`;
    document.getElementById('pwTicketSubText').textContent = `Checked in ${q.created_at || 'Today'} · Visit Type: ${q.visit_type || 'Walk-in'}`;

    startBtn.style.display = 'none';
    callBtn.style.display = (q.status === 'waiting') ? 'inline-block' : 'none';
    consultBtn.style.display = 'inline-block';
    document.getElementById('pwFormTicketId').value = q.ticket_id || '';
  } else {
    document.getElementById('pwClinicStatusBadge').className = 'badge bg-light text-dark px-3 py-2 rounded-pill fw-semibold';
    document.getElementById('pwClinicStatusBadge').textContent = 'No Active Visit';
    document.getElementById('pwTicketStatusText').textContent = 'No Active Clinic Visit Today';
    document.getElementById('pwTicketSubText').textContent = 'Click Start Visit to issue a ticket and begin triage.';

    startBtn.style.display = 'inline-block';
    callBtn.style.display = 'none';
    consultBtn.style.display = 'none';
    document.getElementById('pwFormTicketId').value = '';
  }

  document.getElementById('pwFormStudentId').value = p.id || '';

  // History Tab
  document.getElementById('pwVisitCountBadge').textContent = history.length;
  const tbody = document.getElementById('pwVisitsTableBody');
  if (history.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No previous clinic visits recorded.</td></tr>';
  } else {
    tbody.innerHTML = history.map(v => {
      let medsList = (v.medicines_dispensed && v.medicines_dispensed.length > 0)
        ? v.medicines_dispensed.map(m => `<span class="badge bg-light text-dark border me-1">${escapeHtml(m.medicine_name || '')} (${m.quantity || 0} ${escapeHtml(m.unit || '')})</span>`).join('')
        : '<span class="text-muted">None</span>';

      return `<tr>
        <td class="fw-semibold">${escapeHtml(v.formatted_date || v.consultation_date || '')}</td>
        <td><span class="badge bg-secondary-subtle text-secondary">${escapeHtml(v.visit_type || 'Walk-in')}</span></td>
        <td class="text-dark fw-medium">${escapeHtml(v.chief_complaint || '—')}</td>
        <td>${escapeHtml(v.diagnosis || '—')}</td>
        <td><div>${escapeHtml(v.treatment || '—')}</div><div class="mt-1">${medsList}</div></td>
        <td class="text-muted">${escapeHtml(v.nurse_name || 'Clinic Staff')}</td>
      </tr>`;
    }).join('');
  }

  // Certificates Tab
  document.getElementById('pwCertCountBadge').textContent = certs.length;
  const cbody = document.getElementById('pwCertsTableBody');
  if (certs.length === 0) {
    cbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No certificates issued yet.</td></tr>';
  } else {
    cbody.innerHTML = certs.map(c => `<tr>
      <td class="fw-bold text-success">${escapeHtml(c.certificate_number || '')}</td>
      <td>${escapeHtml(c.certificate_type || '')}</td>
      <td>${escapeHtml(c.purpose || '—')}</td>
      <td>${escapeHtml(c.date_issued || '')}</td>
      <td><a href="<?= APP_URL ?>/pages/nurse/certificates.php" class="btn btn-sm btn-outline-success py-0 px-2"><i class="bi bi-eye"></i> View</a></td>
    </tr>`).join('');
  }
}

async function pwStartClinicVisit() {
  if (!currentPwPatient || !currentPwPatient.patient) return;
  const studentId = currentPwPatient.patient.id;
  const csrf = $('meta[name="csrf-token"]').attr('content') || '<?= csrf_token() ?>';

  try {
    const fd = new FormData();
    fd.append('action', 'start_visit');
    fd.append('student_id', studentId);
    fd.append('csrf_token', csrf);

    const res = await fetch(patientServiceUrl(), { method: 'POST', body: fd, credentials: 'same-origin' });
    const json = await res.json();
    if (json.success) {
      if (typeof showToast === 'function') showToast(json.message, 'success');
      else alert(json.message);
      // Reload workspace with new ticket
      openPatientWorkspace(studentId);
      if (typeof loadQueueList === 'function') loadQueueList();
    } else {
      if (typeof showToast === 'function') showToast(json.message, 'danger');
      else alert(json.message);
    }
  } catch(e) {
    console.error(e);
  }
}

async function pwCallCurrentPatient() {
  if (!currentPwPatient || !currentPwPatient.current_status || !currentPwPatient.current_status.queue) return;
  const ticketId = currentPwPatient.current_status.queue.ticket_id;
  const csrf = $('meta[name="csrf-token"]').attr('content') || '<?= csrf_token() ?>';

  try {
    const fd = new FormData();
    fd.append('action', 'call_ticket');
    fd.append('ticket_id', ticketId);
    fd.append('csrf_token', csrf);

    const res = await fetch('<?= APP_URL ?>/api/queue.php', { method: 'POST', body: fd });
    const json = await res.json();
    if (json.success) {
      if (typeof showToast === 'function') showToast(json.message, 'success');
      openPatientWorkspace(currentPwPatient.patient.id);
      if (typeof loadQueueList === 'function') loadQueueList();
    }
  } catch(e) { console.error(e); }
}

function pwOpenConsultationForm() {
  document.getElementById('pwConsultationFormContainer').style.display = 'block';
  document.getElementById('pwComplaintInput').focus();
}

function pwCloseConsultationForm() {
  document.getElementById('pwConsultationFormContainer').style.display = 'none';
}

function pwToggleCertFields() {
  const cb = document.getElementById('pwIssueCertCheck');
  document.getElementById('pwCertFieldsDiv').style.display = cb.checked ? 'block' : 'none';
}

function pwToggleFollowUp() {
  const cb = document.getElementById('pwFollowUpCheck');
  document.getElementById('pwFollowUpDiv').style.display = cb.checked ? 'block' : 'none';
}

function pwCalcBmi() {
  const h = parseFloat(document.getElementById('pwVHeight').value);
  const w = parseFloat(document.getElementById('pwVWeight').value);
  const el = document.getElementById('pwLiveBmiText');
  if (h > 0 && w > 0) {
    const hm = h / 100;
    const bmi = (w / (hm * hm)).toFixed(1);
    let cat = 'Normal';
    if (bmi < 18.5) cat = 'Underweight';
    else if (bmi >= 25 && bmi < 30) cat = 'Overweight';
    else if (bmi >= 30) cat = 'Obese';
    el.textContent = `${bmi} (${cat})`;
    el.className = 'fw-bold ' + (cat === 'Normal' ? 'text-success' : 'text-danger');
  } else {
    el.textContent = '— (Normal)';
    el.className = 'fw-bold text-dark';
  }
}

function pwAddMedRow() {
  const container = document.getElementById('pwMedRowsContainer');
  const div = document.createElement('div');
  div.className = 'd-flex gap-1 align-items-center mb-1 pw-med-row';
  div.innerHTML = `
    <select class="form-select form-select-sm pw-med-select" style="max-width: 140px;">
      <option value="">Select Medicine</option>
      ${window.PW_MEDICINES_CACHE.map(m => `<option value="${m.id}" data-max="${m.quantity}">${escapeHtml(m.medicine_name)} (${m.quantity} ${escapeHtml(m.unit || '')})</option>`).join('')}
    </select>
    <input type="number" class="form-control form-control-sm pw-med-qty" style="width: 60px;" min="1" value="1" placeholder="Qty">
    <input type="text" class="form-control form-control-sm pw-med-dosage" placeholder="Dosage (e.g. 1 tab after meal)">
    <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" onclick="this.closest('.pw-med-row').remove()">&times;</button>
  `;
  container.appendChild(div);
}

async function pwSubmitConsultation(e) {
  e.preventDefault();
  const form = document.getElementById('pwConsultationForm');
  const btn = document.getElementById('pwSubmitConsultBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

  const formData = new FormData(form);

  // Collect medicines array
  const medRows = document.querySelectorAll('.pw-med-row');
  const medsArray = [];
  medRows.forEach(row => {
    const medId = row.querySelector('.pw-med-select').value;
    const qty = row.querySelector('.pw-med-qty').value;
    const dosage = row.querySelector('.pw-med-dosage').value;
    if (medId && qty > 0) {
      medsArray.push({
        medicine_id: parseInt(medId),
        quantity: parseInt(qty),
        dosage_instructions: dosage
      });
    }
  });
  formData.append('medicines', JSON.stringify(medsArray));

  try {
    const res = await fetch('<?= APP_URL ?>/api/queue.php', { method: 'POST', body: formData });
    const json = await res.json();
    if (json.success) {
      if (typeof showToast === 'function') showToast(json.message, 'success');
      else alert(json.message);
      form.reset();
      document.getElementById('pwMedRowsContainer').innerHTML = '';
      pwCloseConsultationForm();
      openPatientWorkspace(currentPwPatient.patient.id);
      if (typeof loadQueueList === 'function') loadQueueList();
    } else {
      if (typeof showToast === 'function') showToast(json.message, 'danger');
      else alert(json.message);
    }
  } catch(err) {
    console.error(err);
    alert('Failed to complete consultation.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Complete Consultation &amp; Save Record';
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

