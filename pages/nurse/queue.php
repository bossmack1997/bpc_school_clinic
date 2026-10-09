<?php
/**
 * pages/nurse/queue.php
 * =====================================================================
 * Nurse Station — Live Queue Management & Patient Calling Console
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$db = getDB();
$u = current_user();
$pageTitle = 'Clinic Queue Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <!-- Header & Quick Actions -->
      <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
          <h4 class="fw-bold mb-1" style="color: var(--text-color, #1E293B);">
            <i class="bi bi-people-fill text-success me-2"></i> Clinic Live Queue Desk
          </h4>
          <p class="text-muted small mb-0">Call next patients, monitor waiting line, and record consultations in real-time.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <button type="button" class="btn btn-warning fw-bold text-dark shadow-sm" onclick="openQrScannerModal()">
            <i class="bi bi-qr-code-scan me-1"></i> Scan Patient QR
          </button>
          <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#newWalkinModal">
            <i class="bi bi-person-plus-fill me-1"></i> Add Walk-in
          </button>
          <a href="<?= APP_URL ?>/pages/queue_display.php" target="_blank" class="btn btn-success">
            <i class="bi bi-tv me-1"></i> Open Live TV Display
          </a>
        </div>
      </div>

      <!-- Quick Stats Row -->
      <div class="app-stats app-stats--4 mb-4">
        <div class="dbg-stat dbg-stat--green">
          <div class="dbg-stat__top">
            <div class="dbg-stat__icon"><i class="bi bi-ticket-perforated"></i></div>
          </div>
          <div class="dbg-stat__value" id="statTotalToday">0</div>
          <div class="dbg-stat__label">Today's Tickets</div>
        </div>
        <div class="dbg-stat dbg-stat--orange">
          <div class="dbg-stat__top">
            <div class="dbg-stat__icon"><i class="bi bi-clock-history"></i></div>
          </div>
          <div class="dbg-stat__value" id="statWaiting">0</div>
          <div class="dbg-stat__label">Currently Waiting</div>
        </div>
        <div class="dbg-stat dbg-stat--blue">
          <div class="dbg-stat__top">
            <div class="dbg-stat__icon"><i class="bi bi-person-check"></i></div>
          </div>
          <div class="dbg-stat__value" id="statServing">0</div>
          <div class="dbg-stat__label">Now Serving</div>
        </div>
        <div class="dbg-stat dbg-stat--teal">
          <div class="dbg-stat__top">
            <div class="dbg-stat__icon"><i class="bi bi-check2-circle"></i></div>
          </div>
          <div class="dbg-stat__value" id="statCompleted">0</div>
          <div class="dbg-stat__label">Completed Today</div>
        </div>
      </div>

      <!-- Main Layout -->
      <div class="row g-4">
        <!-- Left Column: Command Center / Now Serving -->
        <div class="col-xl-5">
          <!-- Call Next Button -->
          <div class="mb-3">
            <button type="button" class="app-btn-hero" id="callNextBtn" onclick="callNextPatient()">
              <i class="bi bi-megaphone-fill" aria-hidden="true"></i>
              <span class="text-start">
                <span class="d-block">Call Next Patient</span>
                <small id="nextPatientHint">Loading next...</small>
              </span>
            </button>
          </div>

          <!-- Active Ticket Card -->
          <div class="app-queue-hero app-queue-hero--start mb-4" id="activeServingCard">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="app-badge" style="background:rgba(255,255,255,0.14);color:#fff;">
                <i class="bi bi-broadcast me-1"></i> ACTIVE CONSOLE
              </span>
              <span class="app-status-pill" id="activeStatusPill">No patient</span>
            </div>

            <div class="d-flex align-items-baseline gap-3 my-2">
              <div class="app-queue-hero__number" id="activeQueueNumber">---</div>
              <div>
                <h5 class="fw-semibold mb-0" style="color:#fff;" id="activePatientName">No active patient called</h5>
                <small style="color:rgba(255,255,255,0.7);" id="activePatientSub">Click [Call Next Patient] to begin</small>
              </div>
            </div>

            <!-- Patient Info Details -->
            <div class="p-3 my-3 rounded" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12);" id="activeDetailsBox">
              <div class="row g-2" style="font-size:0.8125rem; color:rgba(255,255,255,0.72);">
                <div class="col-6">Priority: <span class="fw-semibold" style="color:#fff;" id="activePriority">—</span></div>
                <div class="col-6">Arrived: <span class="fw-semibold" style="color:#fff;" id="activeArrivedTime">—</span></div>
                <div class="col-12">Complaint / Note: <span class="fw-semibold" style="color:#fff;" id="activeComplaint">None provided</span></div>
              </div>
            </div>

            <!-- Actions Row -->
            <div class="d-flex flex-wrap gap-2 mt-3" id="activeActionsGroup">
              <button class="btn btn-outline-light btn-sm flex-fill" id="recallBtn" onclick="recallActivePatient()" disabled>
                <i class="bi bi-arrow-repeat me-1"></i> Recall Notice
              </button>
              <button class="btn btn-primary btn-sm flex-fill" id="startServingBtn" onclick="openConsultationWorkspace(activeTicket ? activeTicket.id : null)" disabled>
                <i class="bi bi-heart-pulse-fill me-1"></i> Start Consultation
              </button>
              <button class="btn btn-outline-warning btn-sm" id="skipBtn" onclick="skipActivePatient()" disabled>
                <i class="bi bi-skip-forward-fill me-1"></i> Skip
              </button>
              <button class="btn btn-success btn-sm flex-fill" id="completeBtn" onclick="openConsultationWorkspace(activeTicket ? activeTicket.id : null)" disabled>
                <i class="bi bi-check-lg me-1"></i> Complete Visit
              </button>
            </div>
          </div>

          <!-- Direct Walk-in / Triage Shortcuts -->
          <div class="card p-3 shadow-sm border-0">
            <h6 class="fw-bold mb-2 text-dark"><i class="bi bi-lightning-charge-fill text-warning me-1"></i> Station Tools</h6>
            <div class="d-flex flex-wrap gap-2">
              <a href="walkin.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person-walking me-1"></i> Walk-in Triage</a>
              <a href="medical_records.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-folder-check me-1"></i> Medical Records</a>
              <a href="inventory.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-capsule me-1"></i> Medicine Inventory</a>
              <a href="certificates.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-medical me-1"></i> Certificates</a>
            </div>
          </div>
        </div>

        <!-- Right Column: Live Queue List & Tabs -->
        <div class="col-xl-7">
          <div class="app-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
              <ul class="app-tabs border-0" id="queueTabs">
                <li class="nav-item">
                  <a class="nav-link active" href="#" onclick="switchTab('waiting'); return false;">
                    Waiting <span class="badge bg-warning text-dark rounded-pill ms-1" id="badgeWaitingCount">0</span>
                  </a>
                </li>
                <li class="nav-item">
                  <a class="nav-link" href="#" onclick="switchTab('all'); return false;">
                    All Today <span class="badge bg-secondary rounded-pill ms-1" id="badgeAllCount">0</span>
                  </a>
                </li>
                <li class="nav-item">
                  <a class="nav-link" href="#" onclick="switchTab('skipped'); return false;">
                    Skipped <span class="badge bg-light text-dark rounded-pill ms-1" id="badgeSkippedCount">0</span>
                  </a>
                </li>
                <li class="nav-item">
                  <a class="nav-link" href="#" onclick="switchTab('completed'); return false;">
                    Completed <span class="badge bg-success rounded-pill ms-1" id="badgeCompletedCount">0</span>
                  </a>
                </li>
              </ul>
              <div class="d-flex gap-2">
                <input type="text" id="queueSearch" class="form-control form-control-sm" placeholder="Search ticket / student..." style="width: 190px;" oninput="filterQueueTable()">
                <button class="btn btn-light btn-sm" onclick="loadQueueList()" title="Refresh"><i class="bi bi-arrow-clockwise"></i></button>
              </div>
            </div>

            <!-- Table -->
            <div class="table-responsive" style="min-height: 360px;">
              <table class="table table-hover align-middle mb-0" id="queueTable">
                <thead class="table-light">
                  <tr>
                    <th>Ticket</th>
                    <th>Patient Name</th>
                    <th>Type</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Wait</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody id="queueTableBody">
                  <tr>
                    <td colspan="7" class="text-center text-muted py-5">
                      <div class="spinner-border spinner-border-sm me-2 text-success"></div> Loading live queue...
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Unified Contextual Consultation Workspace -->
<!-- ===================================================================== -->
<div class="modal fade" id="consultationWorkspaceModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="consultationWorkspaceModalTitle">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
      <div class="modal-header bg-success text-white py-3">
        <div class="d-flex align-items-center gap-2">
          <div class="fs-4"><i class="bi bi-heart-pulse-fill"></i></div>
          <div>
            <h5 id="consultationWorkspaceModalTitle" class="modal-title fw-bold mb-0">Clinic Consultation Workspace</h5>
            <small class="opacity-90" id="consultModalSubtitle">Ticket --- · Patient Details</small>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body p-4 bg-light">
        <form id="unifiedConsultationForm" onsubmit="submitUnifiedConsultation(event)">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="complete_consultation_full">
          <input type="hidden" name="ticket_id" id="consultTicketId">

          <!-- Patient Summary & Medical History Banner -->
          <div class="card p-3 mb-3 border-0 shadow-sm" style="border-radius: 14px;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center fs-3 fw-bold" style="width:52px; height:52px;" id="patientAvatarLetter">
                  P
                </div>
                <div>
                  <h5 class="fw-bold text-dark mb-0" id="patientFullNameText">Loading...</h5>
                  <div class="text-muted small" id="patientIdentifiersText">—</div>
                </div>
              </div>
              <div class="d-flex gap-2 flex-wrap" id="patientMedicalBadges">
                <span class="badge bg-light text-dark border">Blood Type: <strong id="pBloodType">—</strong></span>
                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" id="pAllergiesBadge">Allergies: None</span>
              </div>
            </div>
          </div>

          <div class="row g-3">
            <!-- Left Sub-column: Clinical Notes -->
            <div class="col-lg-7">
              <div class="card p-3 border-0 shadow-sm h-100" style="border-radius: 14px;">
                <h6 class="fw-bold text-success mb-3"><i class="bi bi-journal-medical me-1"></i> Clinical Evaluation</h6>

                <div class="mb-3">
                  <label class="form-label fw-semibold small">Chief Complaint <span class="text-danger">*</span></label>
                  <input type="text" class="form-control" name="chief_complaint" id="consultComplaint" required placeholder="e.g. Headache, Fever, Dizziness, Stomach ache...">
                </div>

                <div class="row g-2 mb-3">
                  <div class="col-md-6">
                    <label class="form-label fw-semibold small">Symptoms Observed</label>
                    <textarea class="form-control" name="symptoms" rows="2" placeholder="Onset, severity, duration..."></textarea>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-semibold small">Clinical Assessment</label>
                    <textarea class="form-control" name="assessment" rows="2" placeholder="Physical examination findings..."></textarea>
                  </div>
                </div>

                <div class="row g-2 mb-3">
                  <div class="col-md-6">
                    <label class="form-label fw-semibold small">Diagnosis</label>
                    <input type="text" class="form-control" name="diagnosis" id="consultDiagnosis" placeholder="e.g. Tension Headache, Upper Respiratory Tract Infection">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-semibold small">Treatment Given</label>
                    <input type="text" class="form-control" name="treatment" placeholder="e.g. Bed rest, hydration, ice pack, oral medication">
                  </div>
                </div>

                <div class="mb-3">
                  <label class="form-label fw-semibold small">Nurse Advice &amp; Recommendation</label>
                  <textarea class="form-control" name="recommendation" rows="2" placeholder="Rest instructions, return if symptoms persist, hydration..."></textarea>
                </div>

                <div class="row g-2 align-items-center">
                  <div class="col-auto">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="need_follow_up" id="needFollowUpCheck" value="1" onchange="toggleFollowUpInput()">
                      <label class="form-check-label small fw-semibold" for="needFollowUpCheck">Needs Follow-up Visit</label>
                    </div>
                  </div>
                  <div class="col" id="followUpDateCol" style="display: none;">
                    <input type="date" class="form-control form-control-sm" name="follow_up_date" min="<?= date('Y-m-d') ?>">
                  </div>
                </div>
              </div>
            </div>

            <!-- Right Sub-column: Vitals & Medicine Dispensing -->
            <div class="col-lg-5">
              <!-- Vital Signs Card -->
              <div class="card p-3 border-0 shadow-sm mb-3" style="border-radius: 14px;">
                <h6 class="fw-bold text-danger mb-3"><i class="bi bi-heart-pulse me-1"></i> Vital Signs</h6>
                <div class="row g-2 small">
                  <div class="col-6">
                    <label class="form-label text-muted mb-1">Temp (°C)</label>
                    <input type="number" step="0.1" class="form-control form-control-sm" name="temperature" id="vTemp" placeholder="36.5">
                  </div>
                  <div class="col-6">
                    <label class="form-label text-muted mb-1">O2 Saturation (%)</label>
                    <input type="number" step="0.1" class="form-control form-control-sm" name="oxygen_saturation" id="vO2" placeholder="98">
                  </div>
                  <div class="col-6">
                    <label class="form-label text-muted mb-1">BP (Systolic / Diastolic)</label>
                    <div class="d-flex gap-1">
                      <input type="number" class="form-control form-control-sm" name="blood_pressure_systolic" id="vBpSys" placeholder="120">
                      <span class="align-self-center text-muted">/</span>
                      <input type="number" class="form-control form-control-sm" name="blood_pressure_diastolic" id="vBpDia" placeholder="80">
                    </div>
                  </div>
                  <div class="col-6">
                    <label class="form-label text-muted mb-1">Heart Rate (bpm)</label>
                    <input type="number" class="form-control form-control-sm" name="heart_rate" id="vHr" placeholder="75">
                  </div>
                  <div class="col-6">
                    <label class="form-label text-muted mb-1">Height (cm)</label>
                    <input type="number" step="0.1" class="form-control form-control-sm" name="height_cm" id="vHeight" placeholder="165" oninput="calculateLiveBmi()">
                  </div>
                  <div class="col-6">
                    <label class="form-label text-muted mb-1">Weight (kg)</label>
                    <input type="number" step="0.1" class="form-control form-control-sm" name="weight_kg" id="vWeight" placeholder="58" oninput="calculateLiveBmi()">
                  </div>
                  <div class="col-12 mt-2">
                    <div class="p-2 rounded bg-light border d-flex justify-content-between align-items-center">
                      <span class="text-muted small">Auto BMI:</span>
                      <span class="fw-bold" id="liveBmiText">— (Normal)</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Medicine Dispensing Card -->
              <div class="card p-3 border-0 shadow-sm mb-3" style="border-radius: 14px;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h6 class="fw-bold text-primary mb-0"><i class="bi bi-capsule me-1"></i> Dispense Medicine</h6>
                  <button type="button" class="btn btn-sm btn-outline-primary" onclick="addMedicineRow()">
                    <i class="bi bi-plus-lg me-1"></i> Add
                  </button>
                </div>
                <div id="medicinesContainer">
                  <!-- Dynamic Medicine Rows -->
                </div>
              </div>

              <!-- Certificate Issuance Option -->
              <div class="card p-3 border-0 shadow-sm" style="border-radius: 14px;">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="issue_certificate" id="issueCertCheck" value="1" onchange="toggleCertificateInputs()">
                  <label class="form-check-label small fw-bold text-dark" for="issueCertCheck">
                    <i class="bi bi-file-medical text-success me-1"></i> Issue Official Certificate
                  </label>
                </div>
                <div id="certFieldsDiv" style="display: none;" class="mt-2 pt-2 border-top">
                  <div class="mb-2">
                    <label class="form-label small text-muted mb-1">Certificate Type</label>
                    <select class="form-select form-select-sm" name="certificate_type">
                      <option value="Clinic Visit Pass">Clinic Visit Clearance / Pass</option>
                      <option value="Medical Certificate">Medical Certificate / Fit to Class</option>
                    </select>
                  </div>
                  <div>
                    <label class="form-label small text-muted mb-1">Purpose / Reason</label>
                    <input type="text" class="form-control form-control-sm" name="certificate_purpose" placeholder="e.g. Excused from PE class today">
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer border-0 px-0 pb-0 mt-3 d-flex justify-content-between">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success btn-lg px-4 fw-bold" id="submitConsultationBtn">
              <i class="bi bi-check2-circle me-1"></i> Complete Consultation &amp; Update Records
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ===================================================================== -->
<!-- MODAL: Quick Add Walk-in to Queue -->
<!-- ===================================================================== -->
<div class="modal fade" id="newWalkinModal" tabindex="-1" aria-labelledby="newWalkinModalTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-success text-white">
        <h5 id="newWalkinModalTitle" class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i> Issue Queue Ticket</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form id="quickCheckinForm" onsubmit="submitQuickCheckin(event)">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label fw-semibold">Patient Category <span class="text-danger">*</span></label>
            <select class="form-select" id="checkinPatientType" name="patient_type" onchange="togglePatientSelect()">
              <option value="student">Student</option>
              <option value="teacher">Faculty / Staff</option>
              <option value="guest">Guest / Walk-in Visitor</option>
            </select>
          </div>

          <!-- Student Selector / Search -->
          <div class="mb-3" id="studentSearchGroup">
            <label class="form-label fw-semibold">Search Student</label>
            <input type="text" class="form-control" id="studentSearchInput" placeholder="Type student name or student #..." oninput="searchStudentLive(this.value)">
            <input type="hidden" name="student_id" id="selectedStudentId">
            <div id="studentSearchResults" class="list-group mt-1 shadow-sm" style="max-height: 180px; overflow-y: auto; display: none;"></div>
            <div id="selectedStudentBadge" class="mt-2 text-success fw-semibold small" style="display: none;"></div>
          </div>

          <!-- Guest Name (if guest) -->
          <div class="mb-3" id="guestNameGroup" style="display: none;">
            <label class="form-label fw-semibold">Visitor Full Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="full_name" id="guestFullName" placeholder="e.g. Juan dela Cruz">
          </div>

          <!-- Priority -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Triage Priority <span class="text-danger">*</span></label>
            <div class="d-flex gap-2">
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="priority" id="prioNormal" value="normal" checked>
                <label class="form-check-label text-success fw-bold" for="prioNormal">Normal</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="priority" id="prioUrgent" value="urgent">
                <label class="form-check-label text-warning fw-bold" for="prioUrgent">Urgent</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="priority" id="prioEmergency" value="emergency">
                <label class="form-check-label text-danger fw-bold" for="prioEmergency"> Emergency</label>
              </div>
            </div>
          </div>

          <!-- Chief Complaint -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Chief Complaint / Purpose</label>
            <textarea class="form-control" name="chief_complaint" rows="2" placeholder="e.g. Headache, Fever, Wound Dressing..."></textarea>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success fw-bold" id="submitCheckinBtn">
            <i class="bi bi-ticket-perforated-fill me-1"></i> Issue Ticket
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/patient_workspace_modal.php'; ?>
<?php include __DIR__ . '/../../includes/qr_scanner_modal.php'; ?>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
  let activeTicket = null;
  let currentTab = 'waiting';
  let queueData = [];
  let availableMedicines = [];
  let isQueueLoading = false;
  let queuePollTimer = null;
  const csrfToken = '<?= csrf_token() ?>';

  async function loadQueueList() {
    if (isQueueLoading) return;
    isQueueLoading = true;
    try {
      const res = await fetch(`<?= APP_URL ?>/api/queue.php?action=queue_list&status=${currentTab === 'all' ? '' : currentTab}`);
      const json = await res.json();
      if (!json.success) return;

      queueData = json.data.items || [];
      const counts = json.data.counts || {};

      document.getElementById('statTotalToday').innerText = counts.total || 0;
      document.getElementById('statWaiting').innerText = counts.waiting || 0;
      document.getElementById('statServing').innerText = counts.serving || 0;
      document.getElementById('statCompleted').innerText = counts.completed || 0;

      document.getElementById('badgeWaitingCount').innerText = counts.waiting || 0;
      document.getElementById('badgeAllCount').innerText = counts.total || 0;
      document.getElementById('badgeSkippedCount').innerText = counts.skipped || 0;
      document.getElementById('badgeCompletedCount').innerText = counts.completed || 0;

      renderQueueTable(queueData);

      // Find if there is an active called or serving ticket
      const active = queueData.find(i => i.status === 'called' || i.status === 'serving');
      updateActiveCard(active || null);

      // Find next in line
      const nextWaiting = queueData.find(i => i.status === 'waiting');
      if (nextWaiting) {
        document.getElementById('nextPatientHint').innerText = `Next up: ${nextWaiting.queue_number} (${nextWaiting.patient_name})`;
      } else {
        document.getElementById('nextPatientHint').innerText = 'No patients currently waiting';
      }
    } catch (err) {
      console.error('Failed to load queue:', err);
    } finally {
      isQueueLoading = false;
    }
  }

  function renderQueueTable(items) {
    const tbody = document.getElementById('queueTableBody');
    if (!items || items.length === 0) {
      tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>No patients in this queue tab.</td></tr>`;
      return;
    }

    let html = '';
    items.forEach(item => {
      const prioClass = item.priority === 'emergency' ? 'badge bg-danger' : (item.priority === 'urgent' ? 'badge bg-warning text-dark' : 'badge bg-success bg-opacity-75');
      const statusClass = 'app-status-pill app-status-pill--' + item.status;
      const isCurrent = activeTicket && activeTicket.id == item.id;
      const rowHighlight = isCurrent ? 'table-success' : '';

      html += `
        <tr class="${rowHighlight}">
          <td class="fw-bold fs-6 text-success">${item.queue_number}</td>
          <td>
            <div class="fw-semibold text-dark">${escapeHtml(item.patient_name)}</div>
            <small class="text-muted">${item.student_number || item.employee_id || item.patient_type}</small>
          </td>
          <td><span class="badge bg-light text-dark border">${item.patient_type}</span></td>
          <td><span class="${prioClass}">${item.priority.toUpperCase()}</span></td>
          <td><span class="${statusClass}">${item.status.toUpperCase()}</span></td>
          <td><small class="text-muted">${item.wait_minutes || 0} mins</small></td>
          <td class="text-end">
            ${item.status === 'waiting' ? `
              <button class="btn btn-sm btn-primary py-1 px-2" onclick="callSpecificPatient(${item.id})">
                <i class="bi bi-megaphone-fill me-1"></i> Call
              </button>
            ` : ''}
            ${item.status === 'called' ? `
              <button class="btn btn-sm btn-success py-1 px-2" onclick="openConsultationWorkspace(${item.id})">
                <i class="bi bi-heart-pulse-fill me-1"></i> Consult
              </button>
            ` : ''}
            ${item.status === 'skipped' ? `
              <button class="btn btn-sm btn-outline-primary py-1 px-2" onclick="callSpecificPatient(${item.id})">
                <i class="bi bi-arrow-repeat me-1"></i> Recall
              </button>
            ` : ''}
            ${item.status === 'serving' ? `
              <button class="btn btn-sm btn-success py-1 px-2" onclick="openConsultationWorkspace(${item.id})">
                <i class="bi bi-check-lg me-1"></i> Complete
              </button>
            ` : ''}
          </td>
        </tr>
      `;
    });
    tbody.innerHTML = html;
  }

  function updateActiveCard(ticket) {
    activeTicket = ticket;
    const numEl = document.getElementById('activeQueueNumber');
    const nameEl = document.getElementById('activePatientName');
    const subEl = document.getElementById('activePatientSub');
    const pillEl = document.getElementById('activeStatusPill');
    const prioEl = document.getElementById('activePriority');
    const arrEl = document.getElementById('activeArrivedTime');
    const compEl = document.getElementById('activeComplaint');

    const recallBtn = document.getElementById('recallBtn');
    const startServingBtn = document.getElementById('startServingBtn');
    const skipBtn = document.getElementById('skipBtn');
    const completeBtn = document.getElementById('completeBtn');

    if (!ticket) {
      numEl.innerText = '---';
      nameEl.innerText = 'No active patient called';
      subEl.innerText = 'Click [CALL NEXT PATIENT] to call the next person in line';
      pillEl.className = 'app-status-pill app-status-pill--skipped';
      pillEl.innerText = 'STANDBY';
      prioEl.innerText = '—';
      arrEl.innerText = '—';
      compEl.innerText = 'None provided';

      recallBtn.disabled = true;
      startServingBtn.disabled = true;
      skipBtn.disabled = true;
      completeBtn.disabled = true;
      return;
    }

    numEl.innerText = ticket.queue_number;
    nameEl.innerText = ticket.patient_name;
    subEl.innerText = `${ticket.patient_type.toUpperCase()} · ${ticket.student_number || ticket.employee_id || ''}`;
    pillEl.className = 'app-status-pill app-status-pill--' + ticket.status;
    pillEl.innerText = ticket.status.toUpperCase();
    prioEl.innerText = ticket.priority.toUpperCase();
    arrEl.innerText = `${ticket.wait_minutes || 0} mins ago`;
    compEl.innerText = ticket.chief_complaint || 'General consultation';

    recallBtn.disabled = false;
    skipBtn.disabled = false;
    completeBtn.disabled = false;
    startServingBtn.disabled = false;
  }

  function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('#queueTabs .nav-link').forEach(el => el.classList.remove('active'));
    event.target.classList.add('active');
    loadQueueList();
  }

  // Queue actions
  async function callNextPatient() {
    try {
      const form = new FormData();
      form.append('csrf_token', csrfToken);
      form.append('action', 'call_next');

      const res = await fetch('<?= APP_URL ?>/api/queue.php', { method: 'POST', body: form });
      const json = await res.json();
      if (json.success) {
        if (typeof showToast === 'function') showToast(json.message, 'success');
        loadQueueList();
      } else {
        if (typeof showToast === 'function') showToast(json.message, 'warning');
      }
    } catch (err) {
      console.error(err);
    }
  }

  async function callSpecificPatient(ticketId) {
    try {
      const form = new FormData();
      form.append('csrf_token', csrfToken);
      form.append('action', 'call_next');
      form.append('ticket_id', ticketId);

      const res = await fetch('<?= APP_URL ?>/api/queue.php', { method: 'POST', body: form });
      const json = await res.json();
      if (json.success) {
        if (typeof showToast === 'function') showToast(json.message, 'success');
        loadQueueList();
      } else {
        if (typeof showToast === 'function') showToast(json.message, 'danger');
      }
    } catch (err) {
      console.error(err);
    }
  }

  async function recallActivePatient() {
    if (!activeTicket) return;
    try {
      const form = new FormData();
      form.append('csrf_token', csrfToken);
      form.append('action', 'recall');
      form.append('ticket_id', activeTicket.id);

      const res = await fetch('<?= APP_URL ?>/api/queue.php', { method: 'POST', body: form });
      const json = await res.json();
      if (json.success) {
        if (typeof showToast === 'function') showToast(json.message, 'info');
        loadQueueList();
      }
    } catch (err) {
      console.error(err);
    }
  }

  async function skipActivePatient() {
    if (!activeTicket) return;
    if (!confirm(`Are you sure you want to skip ticket ${activeTicket.queue_number}?`)) return;
    try {
      const form = new FormData();
      form.append('csrf_token', csrfToken);
      form.append('action', 'skip_ticket');
      form.append('ticket_id', activeTicket.id);

      const res = await fetch('<?= APP_URL ?>/api/queue.php', { method: 'POST', body: form });
      const json = await res.json();
      if (json.success) {
        if (typeof showToast === 'function') showToast(json.message, 'warning');
        loadQueueList();
      }
    } catch (err) {
      console.error(err);
    }
  }

  // =====================================================================
  // CONSULTATION WORKSPACE MODAL
  // =====================================================================
  async function openConsultationWorkspace(ticketId) {
    if (!ticketId) return;

    try {
      // Mark as serving in background
      const serveForm = new FormData();
      serveForm.append('csrf_token', csrfToken);
      serveForm.append('action', 'start_serving');
      serveForm.append('ticket_id', ticketId);
      fetch('<?= APP_URL ?>/api/queue.php', { method: 'POST', body: serveForm });

      // Fetch patient medical summary and medicines list
      const res = await fetch(`<?= APP_URL ?>/api/queue.php?action=get_patient_medical_summary&ticket_id=${ticketId}`);
      const json = await res.json();
      if (!json.success) {
        alert(json.message || 'Failed to fetch patient data.');
        return;
      }

      const p = json.data.patient;
      const m = json.data.medical_record;
      const v = json.data.latest_vitals;
      availableMedicines = json.data.medicines || [];

      document.getElementById('consultTicketId').value = ticketId;
      document.getElementById('consultModalSubtitle').innerText = `Ticket ${p.queue_number} · ${p.patient_type.toUpperCase()}`;
      document.getElementById('patientFullNameText').innerText = p.patient_name;
      document.getElementById('patientAvatarLetter').innerText = p.patient_name.charAt(0).toUpperCase();

      const sub = p.student_number ? `${p.student_number} · ${p.course || ''} ${p.year_level || ''} ${p.section || ''}` : (p.employee_id ? `${p.employee_id} · ${p.department || 'Staff'}` : 'Guest Patient');
      document.getElementById('patientIdentifiersText').innerText = sub;

      document.getElementById('pBloodType').innerText = m ? (m.blood_type || 'Unknown') : '—';
      const allergies = m && m.allergies ? m.allergies : 'None recorded';
      document.getElementById('pAllergiesBadge').innerText = `Allergies: ${allergies}`;
      document.getElementById('pAllergiesBadge').className = (m && m.allergies && m.allergies !== 'None' && m.allergies !== 'N/A') ? 'badge bg-danger bg-opacity-25 text-danger border border-danger' : 'badge bg-success bg-opacity-10 text-success border border-success border-opacity-25';

      document.getElementById('consultComplaint').value = p.initial_complaint || p.chief_complaint || '';

      // Prefill vitals if available
      if (v) {
        document.getElementById('vTemp').value = v.temperature || '';
        document.getElementById('vO2').value = v.oxygen_saturation || '';
        document.getElementById('vHr').value = v.heart_rate || '';
        document.getElementById('vHeight').value = v.height_cm || '';
        document.getElementById('vWeight').value = v.weight_kg || '';
        calculateLiveBmi();
      } else {
        document.getElementById('vTemp').value = '';
        document.getElementById('vO2').value = '';
        document.getElementById('vHr').value = '';
        document.getElementById('vHeight').value = '';
        document.getElementById('vWeight').value = '';
        document.getElementById('liveBmiText').innerText = '—';
      }

      // Clear medicines container
      document.getElementById('medicinesContainer').innerHTML = '<div class="text-muted small py-2" id="noMedsHint">No medicines selected yet. Click [+ Add] above to dispense.</div>';

      const modal = new bootstrap.Modal(document.getElementById('consultationWorkspaceModal'));
      modal.show();
    } catch (e) {
      console.error('Error loading consultation workspace:', e);
    }
  }

  function calculateLiveBmi() {
    const h = parseFloat(document.getElementById('vHeight').value);
    const w = parseFloat(document.getElementById('vWeight').value);
    const textEl = document.getElementById('liveBmiText');

    if (h > 0 && w > 0) {
      const hM = h / 100;
      const bmi = (w / (hM * hM)).toFixed(2);
      let status = 'Normal';
      let colorClass = 'text-success';
      if (bmi < 18.5) { status = 'Underweight'; colorClass = 'text-warning'; }
      else if (bmi >= 25 && bmi < 30) { status = 'Overweight'; colorClass = 'text-warning'; }
      else if (bmi >= 30) { status = 'Obese'; colorClass = 'text-danger'; }

      textEl.innerHTML = `<span class="${colorClass}">${bmi} (${status})</span>`;
    } else {
      textEl.innerText = '—';
    }
  }

  function addMedicineRow() {
    const hint = document.getElementById('noMedsHint');
    if (hint) hint.remove();

    const container = document.getElementById('medicinesContainer');
    const rowId = 'med_row_' + Date.now();

    let options = '<option value="">Select Medicine...</option>';
    availableMedicines.forEach(m => {
      options += `<option value="${m.id}">${escapeHtml(m.medicine_name)} (${m.quantity} ${m.unit || 'pcs'} left)</option>`;
    });

    const div = document.createElement('div');
    div.className = 'border rounded p-2 mb-2 bg-light med-dispense-row';
    div.id = rowId;
    div.innerHTML = `
      <div class="row g-2 align-items-center">
        <div class="col-6">
          <select class="form-select form-select-sm med-select" required>
            ${options}
          </select>
        </div>
        <div class="col-3">
          <input type="number" class="form-control form-control-sm med-qty" min="1" max="50" value="1" placeholder="Qty" required>
        </div>
        <div class="col-3 text-end">
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="document.getElementById('${rowId}').remove()"><i class="bi bi-trash"></i></button>
        </div>
        <div class="col-12 mt-1">
          <input type="text" class="form-control form-control-sm med-dosage" placeholder="Dosage instructions (e.g. 1 tab every 6 hrs after meals)">
        </div>
      </div>
    `;
    container.appendChild(div);
  }

  function toggleFollowUpInput() {
    const chk = document.getElementById('needFollowUpCheck').checked;
    document.getElementById('followUpDateCol').style.display = chk ? 'block' : 'none';
  }

  function toggleCertificateInputs() {
    const chk = document.getElementById('issueCertCheck').checked;
    document.getElementById('certFieldsDiv').style.display = chk ? 'block' : 'none';
  }

  async function submitUnifiedConsultation(e) {
    e.preventDefault();
    const btn = document.getElementById('submitConsultationBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving consultation & updating records...';

    try {
      const form = document.getElementById('unifiedConsultationForm');
      const formData = new FormData(form);

      // Collect medicines array
      const medRows = document.querySelectorAll('.med-dispense-row');
      const medsArray = [];
      medRows.forEach(row => {
        const medId = row.querySelector('.med-select').value;
        const qty = row.querySelector('.med-qty').value;
        const dosage = row.querySelector('.med-dosage').value;
        if (medId && qty > 0) {
          medsArray.push({
            medicine_id: parseInt(medId),
            quantity: parseInt(qty),
            dosage_instructions: dosage
          });
        }
      });
      formData.append('medicines', JSON.stringify(medsArray));

      const res = await fetch('<?= APP_URL ?>/api/queue.php', { method: 'POST', body: formData });
      const json = await res.json();

      if (json.success) {
        if (typeof showToast === 'function') {
          showToast(json.message, 'success');
        } else {
          alert(json.message);
        }

        if (json.data && json.data.low_stock_alerts && json.data.low_stock_alerts.length > 0) {
          json.data.low_stock_alerts.forEach(warn => {
            if (typeof showToast === 'function') showToast(warn, 'warning');
          });
        }

        bootstrap.Modal.getInstance(document.getElementById('consultationWorkspaceModal')).hide();
        form.reset();
        loadQueueList();
      } else {
        if (typeof showToast === 'function') {
          showToast(json.message, 'danger');
        } else {
          alert(json.message);
        }
      }
    } catch (err) {
      console.error(err);
      alert('An error occurred while completing the consultation.');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Complete Consultation &amp; Update Records';
    }
  }

  // Quick check-in modal logic
  function togglePatientSelect() {
    const type = document.getElementById('checkinPatientType').value;
    const studentGroup = document.getElementById('studentSearchGroup');
    const guestGroup = document.getElementById('guestNameGroup');
    if (type === 'guest') {
      studentGroup.style.display = 'none';
      guestGroup.style.display = 'block';
    } else {
      studentGroup.style.display = 'block';
      guestGroup.style.display = 'none';
    }
  }

  let searchTimeout = null;
  function searchStudentLive(val) {
    clearTimeout(searchTimeout);
    if (!val || val.length < 2) {
      document.getElementById('studentSearchResults').style.display = 'none';
      return;
    }
    searchTimeout = setTimeout(async () => {
      try {
        const res = await fetch(`<?= APP_URL ?>/api/walkin.php?action=search_students&q=${encodeURIComponent(val)}`);
        const json = await res.json();
        const results = json.data || [];
        const box = document.getElementById('studentSearchResults');
        if (results.length === 0) {
          box.innerHTML = '<div class="list-group-item text-muted small">No students found</div>';
        } else {
          box.innerHTML = results.map(s => `
            <a href="#" class="list-group-item list-group-item-action py-2" onclick="selectStudent(${s.id}, '${escapeHtml(s.first_name + ' ' + s.last_name)}', '${s.student_number}'); return false;">
              <div class="fw-bold">${escapeHtml(s.first_name + ' ' + s.last_name)}</div>
              <small class="text-muted">${s.student_number} · ${s.course || ''} ${s.year_level || ''}</small>
            </a>
          `).join('');
        }
        box.style.display = 'block';
      } catch (e) {
        console.error(e);
      }
    }, 250);
  }

  function selectStudent(id, name, num) {
    document.getElementById('selectedStudentId').value = id;
    document.getElementById('studentSearchInput').value = `${name} (${num})`;
    document.getElementById('studentSearchResults').style.display = 'none';
    const badge = document.getElementById('selectedStudentBadge');
    badge.innerText = `Selected: ${name} (${num})`;
    badge.style.display = 'block';
  }

  async function submitQuickCheckin(e) {
    e.preventDefault();
    const form = document.getElementById('quickCheckinForm');
    const formData = new FormData(form);
    formData.append('action', 'checkin');

    try {
      const res = await fetch('<?= APP_URL ?>/api/queue.php', { method: 'POST', body: formData });
      const json = await res.json();
      if (json.success) {
        if (typeof showToast === 'function') showToast(json.message, 'success');
        bootstrap.Modal.getInstance(document.getElementById('newWalkinModal')).hide();
        form.reset();
        document.getElementById('selectedStudentBadge').style.display = 'none';
        loadQueueList();
      } else {
        if (typeof showToast === 'function') showToast(json.message, 'danger');
      }
    } catch (err) {
      console.error(err);
    }
  }

  function filterQueueTable() {
    const q = document.getElementById('queueSearch').value.toLowerCase();
    const filtered = queueData.filter(i => 
      (i.queue_number && i.queue_number.toLowerCase().includes(q)) ||
      (i.patient_name && i.patient_name.toLowerCase().includes(q)) ||
      (i.student_number && i.student_number.toLowerCase().includes(q))
    );
    renderQueueTable(filtered);
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // Keyboard shortcut: Space or Alt+N to call next
  document.addEventListener('keydown', (e) => {
    if ((e.code === 'Space' && e.target === document.body) || (e.altKey && e.code === 'KeyN')) {
      e.preventDefault();
      callNextPatient();
    }
  });

  // Auto-polling every 4 seconds with background throttling and tab visibility resume
  function startPolling() {
    if (queuePollTimer) clearInterval(queuePollTimer);
    loadQueueList();
    queuePollTimer = setInterval(() => {
      if (!document.hidden) loadQueueList();
    }, 4000);
  }

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) loadQueueList();
  });

  window.addEventListener('beforeunload', () => {
    if (queuePollTimer) clearInterval(queuePollTimer);
  });

  startPolling();
</script>
</body>
</html>
