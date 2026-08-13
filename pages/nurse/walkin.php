<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Walk-in Patient Management';
$u = current_user();
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

      <!-- Quick Stats -->
      <div class="row g-3 mb-4" id="statsRow">
        <div class="col-6 col-md-3">
          <div class="stat-card"><div class="stat-icon"><i class="bi bi-people"></i></div>
            <div><div class="stat-value counter-animate" data-count="0" id="statToday">0</div><div class="stat-label">Today's Walk-ins</div></div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card warning"><div class="stat-icon"><i class="bi bi-clock-history"></i></div>
            <div><div class="stat-value counter-animate" data-count="0" id="statWaiting">0</div><div class="stat-label">Waiting</div></div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card info"><div class="stat-icon"><i class="bi bi-person-check"></i></div>
            <div><div class="stat-value counter-animate" data-count="0" id="statServing">0</div><div class="stat-label">Serving</div></div></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="stat-card success"><div class="stat-icon"><i class="bi bi-check-circle"></i></div>
            <div><div class="stat-value counter-animate" data-count="0" id="statCompleted">0</div><div class="stat-label">Completed</div></div></div>
        </div>
      </div>

      <!-- Main Row -->
      <div class="row g-3">
        <!-- Left: Queue / New Patient -->
        <div class="col-xl-5">
          <!-- Live Queue -->
          <div class="card p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h6 class="section-title mb-0"><i class="bi bi-list-ol text-success me-1"></i> Live Queue</h6>
              <button class="btn btn-primary btn-sm" id="newPatientBtn"><i class="bi bi-plus-lg me-1"></i> New Walk-in</button>
            </div>
            <div id="queueContainer" style="max-height:400px;overflow-y:auto;">
              <div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>Loading queue...</div>
            </div>
          </div>

          <!-- Quick Actions -->
          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-lightning-charge text-warning me-1"></i> Quick Actions</h6>
            <div class="d-flex flex-wrap gap-2">
              <button class="btn btn-outline-primary btn-sm" onclick="if(typeof MICA!=='undefined'&&MICA.send)MICA.send('Show today patients');" style="font-size:.8rem"><i class="bi bi-people me-1"></i>Today's Patients</button>
              <button class="btn btn-outline-success btn-sm" onclick="if(typeof MICA!=='undefined'&&MICA.send)MICA.send('Low stock medicines');" style="font-size:.8rem"><i class="bi bi-exclamation-triangle me-1"></i>Low Stock</button>
              <button class="btn btn-outline-info btn-sm" onclick="window.open('dispensing.php','_blank')" style="font-size:.8rem"><i class="bi bi-capsule me-1"></i>Dispensing</button>
              <button class="btn btn-outline-secondary btn-sm" onclick="if(typeof MICA!=='undefined'&&MICA.send)MICA.send('Recent patient records');" style="font-size:.8rem"><i class="bi bi-journal me-1"></i>Records</button>
            </div>
          </div>
        </div>

        <!-- Right: Visit List -->
        <div class="col-xl-7">
          <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
              <h6 class="section-title mb-0"><i class="bi bi-journal-text text-success me-1"></i> Today's Visits</h6>
              <div class="d-flex gap-2 flex-wrap">
                <select id="filterStatus" class="form-select form-select-sm" style="width:auto;">
                  <option value="">All Status</option>
                  <option value="waiting">Waiting</option>
                  <option value="in_consultation">In Consultation</option>
                  <option value="completed">Completed</option>
                  <option value="cancelled">Cancelled</option>
                </select>
                <input type="text" id="searchVisit" class="form-control form-control-sm" placeholder="Search..." style="width:180px;">
              </div>
            </div>
            <div class="table-responsive">
              <table class="table table-hover align-middle" id="visitsTable" style="width:100%">
                <thead><tr><th>Queue</th><th>Patient</th><th>Complaint</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody id="visitsBody"><tr><td colspan="6" class="text-center text-muted py-4">Loading...</td></tr></tbody>
              </table>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-2">
              <small class="text-muted" id="visitCount">0 visits today</small>
              <button class="btn btn-sm btn-outline-primary" id="loadMoreBtn" style="display:none">Load More</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================ -->
<!-- NEW WALK-IN MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="walkinModal" tabindex="-1" data-bs-backdrop="static">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content border-0 rounded-4">
<div class="modal-header border-0" style="background:var(--primary);color:#fff;border-radius:12px 12px 0 0;">
  <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>New Walk-in Patient</h5>
  <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form id="walkinForm">
  <input type="hidden" name="student_id" id="selectedStudentId">
  
  <!-- Step 1: Student Search -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Search Student</label>
    <div class="input-group">
      <input type="text" id="studentSearch" class="form-control" placeholder="Search by name or student ID..." autocomplete="off">
      <button class="btn btn-outline-secondary" type="button" id="qrScanBtn" title="QR Code Scan"><i class="bi bi-qr-code-scan"></i></button>
    </div>
    <div id="searchResults" class="list-group mt-1" style="max-height:200px;overflow-y:auto;"></div>
  </div>
  
  <!-- Student Info Card (appears when student selected) -->
  <div id="studentInfoCard" class="card bg-light mb-3 d-none">
    <div class="card-body py-3">
      <div class="d-flex align-items-center gap-3">
        <img id="selStudentPic" src="https://via.placeholder.com/60" class="rounded-circle" width="60" height="60" style="object-fit:cover;">
        <div>
          <h6 class="mb-1 fw-semibold" id="selStudentName">—</h6>
          <small class="text-muted" id="selStudentDetails">—</small>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger ms-auto" id="clearStudentBtn"><i class="bi bi-x"></i></button>
      </div>
    </div>
  </div>
  
  <!-- Temp Visitor Form -->
  <div id="tempVisitorForm" class="d-none">
    <hr><h6 class="fw-semibold mb-3"><i class="bi bi-person me-1"></i> Temporary Visitor Details</h6>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label small">Full Name</label><input class="form-control" name="temp_full_name"></div>
      <div class="col-md-6"><label class="form-label small">Contact Number</label><input class="form-control" name="temp_contact"></div>
      <div class="col-md-6"><label class="form-label small">Course</label><input class="form-control" name="temp_course"></div>
      <div class="col-md-6"><label class="form-label small">Year Level</label><select class="form-select" name="temp_year_level"><option value="">Select</option><option>1st Year</option><option>2nd Year</option><option>3rd Year</option><option>4th Year</option></select></div>
    </div>
  </div>
  
  <hr>
  <h6 class="fw-semibold mb-3"><i class="bi bi-clipboard-pulse me-1"></i> Visit Information</h6>
  <div class="row g-2 mb-3">
    <div class="col-md-6">
      <label class="form-label small fw-semibold">Priority Level</label>
      <select class="form-select" name="priority_level" id="priorityLevel" required>
        <option value="normal">Normal</option>
        <option value="urgent">Urgent</option>
        <option value="emergency" class="text-danger">Emergency</option>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label small fw-semibold">Chief Complaint</label>
      <input class="form-control" name="chief_complaint" id="chiefComplaint" required placeholder="e.g. Headache, Fever">
    </div>
    <div class="col-12">
      <label class="form-label small">Symptoms <small class="text-muted">(optional)</small></label>
      <textarea class="form-control" name="symptoms" rows="2" placeholder="Describe symptoms..."></textarea>
    </div>
  </div>
  
  <!-- Vitals -->
  <h6 class="fw-semibold mb-3"><i class="bi bi-heart-pulse me-1 text-danger"></i> Vital Signs</h6>
  <div class="row g-2">
    <div class="col-4 col-md-3"><label class="form-label small">Temp (°C)</label><input type="number" step="0.1" class="form-control" name="temperature" placeholder="36.5"></div>
    <div class="col-4 col-md-3"><label class="form-label small">BP (sys/dia)</label><input class="form-control" name="blood_pressure" placeholder="120/80"></div>
    <div class="col-4 col-md-2"><label class="form-label small">Heart Rate</label><input type="number" class="form-control" name="heart_rate" placeholder="72"></div>
    <div class="col-4 col-md-2"><label class="form-label small">Resp Rate</label><input type="number" class="form-control" name="respiratory_rate" placeholder="16"></div>
    <div class="col-4 col-md-2"><label class="form-label small">O2 Sat (%)</label><input type="number" step="0.1" class="form-control" name="oxygen_saturation" placeholder="98"></div>
    <div class="col-4 col-md-3"><label class="form-label small">Height (cm)</label><input type="number" step="0.1" class="form-control" name="height_cm" id="heightCm" placeholder="170"></div>
    <div class="col-4 col-md-3"><label class="form-label small">Weight (kg)</label><input type="number" step="0.1" class="form-control" name="weight_kg" id="weightKg" placeholder="65"></div>
    <div class="col-4 col-md-3">
      <label class="form-label small">BMI</label>
      <div class="form-control-plaintext py-1" id="bmiDisplay">
        <span class="text-muted">Auto-calculate</span>
      </div>
    </div>
  </div>
  
  <!-- Consultation -->
  <hr>
  <h6 class="fw-semibold mb-3"><i class="bi bi-heart-pulse me-1"></i> Initial Consultation</h6>
  <div class="row g-2">
    <div class="col-md-6"><label class="form-label small">Assessment</label><textarea class="form-control" name="assessment" rows="2"></textarea></div>
    <div class="col-md-6"><label class="form-label small">Diagnosis</label><input class="form-control" name="diagnosis" placeholder="Initial diagnosis"></div>
    <div class="col-md-6"><label class="form-label small">Treatment</label><textarea class="form-control" name="treatment" rows="2"></textarea></div>
    <div class="col-md-6"><label class="form-label small">Recommendation</label><textarea class="form-control" name="recommendation" rows="2" placeholder="Rest, hydration..."></textarea></div>
    <div class="col-md-6">
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" name="need_follow_up" id="needFollowUp" value="1">
        <label class="form-check-label small" for="needFollowUp">Needs Follow-up</label>
      </div>
    </div>
    <div class="col-md-6 d-none" id="followUpDateDiv">
      <label class="form-label small">Follow-up Date</label>
      <input type="date" class="form-control" name="follow_up_date">
    </div>
  </div>
</form>
</div>
<div class="modal-footer border-0 justify-content-between">
  <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
  <button class="btn btn-primary" id="saveWalkinBtn"><i class="bi bi-save me-1"></i> Save &amp; Add to Queue</button>
</div>
</div></div></div>

<!-- ============================================================ -->
<!-- CONSULTATION MODAL (Edit/Complete) -->
<!-- ============================================================ -->
<div class="modal fade" id="consultModal" tabindex="-1" data-bs-backdrop="static">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content border-0 rounded-4">
<div class="modal-header border-0" style="background:var(--info);color:#fff;border-radius:12px 12px 0 0;">
  <h5 class="modal-title"><i class="bi bi-heart-pulse me-2"></i>Consultation</h5>
  <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form id="consultForm">
  <input type="hidden" name="id" id="consultId">
  
  <!-- Patient Info -->
  <div class="card bg-light mb-3">
    <div class="card-body py-3">
      <div class="d-flex align-items-center gap-3">
        <div class="d-flex align-items-center justify-content-center rounded-circle bg-primary text-white" style="width:48px;height:48px;font-weight:700;font-size:1.2rem;" id="consultAvatar">?</div>
        <div>
          <h6 class="mb-1 fw-semibold" id="consultPatientName">—</h6>
          <small class="text-muted" id="consultPatientDetails">—</small>
          <br><span class="badge bg-primary" id="consultQueueBadge">—</span>
        </div>
        <div class="ms-auto text-end">
          <small class="text-muted">Visit #<span id="consultVisitNo">—</span></small>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Vitals Display -->
  <div class="row g-2 mb-3">
    <div class="col-3"><div class="border rounded p-2 text-center"><small class="text-muted">Temp</small><div class="fw-bold" id="vTemp">—</div></div></div>
    <div class="col-3"><div class="border rounded p-2 text-center"><small class="text-muted">BP</small><div class="fw-bold" id="vBP">—</div></div></div>
    <div class="col-3"><div class="border rounded p-2 text-center"><small class="text-muted">HR</small><div class="fw-bold" id="vHR">—</div></div></div>
    <div class="col-3"><div class="border rounded p-2 text-center"><small class="text-muted">BMI</small><div class="fw-bold" id="vBMI">—</div></div></div>
  </div>
  
  <!-- Consultation Fields -->
  <div class="row g-2">
    <div class="col-12"><label class="form-label small fw-semibold">Chief Complaint</label><input class="form-control" id="consultComplaint" readonly></div>
    <div class="col-md-6"><label class="form-label small">Symptoms</label><textarea class="form-control" name="symptoms" rows="3" id="consultSymptoms"></textarea></div>
    <div class="col-md-6"><label class="form-label small">Assessment</label><textarea class="form-control" name="assessment" rows="3" id="consultAssessment"></textarea></div>
    <div class="col-md-6"><label class="form-label small">Diagnosis</label><input class="form-control" name="diagnosis" id="consultDiagnosis"></div>
    <div class="col-md-6"><label class="form-label small">Treatment</label><textarea class="form-control" name="treatment" rows="2" id="consultTreatment"></textarea></div>
    <div class="col-12"><label class="form-label small">Recommendation</label><textarea class="form-control" name="recommendation" rows="2" id="consultRecommendation"></textarea></div>
    <div class="col-md-4">
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" name="need_follow_up" id="consultFollowUp" value="1">
        <label class="form-check-label small" for="consultFollowUp">Needs Follow-up</label>
      </div>
    </div>
    <div class="col-md-4 d-none" id="consultFollowUpDateDiv">
      <label class="form-label small">Follow-up Date</label>
      <input type="date" class="form-control" name="follow_up_date" id="consultFollowUpDate">
    </div>
    <div class="col-md-4">
      <label class="form-label small">Digital Signature</label>
      <input class="form-control" name="digital_signature" id="consultSignature" placeholder="Type nurse name">
    </div>
  </div>
  
  <!-- AI Summary -->
  <hr>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="fw-semibold mb-0"><i class="bi bi-robot me-1 text-primary"></i> AI Consultation Summary</h6>
    <button class="btn btn-sm btn-outline-primary" id="genAISummaryBtn" type="button"><i class="bi bi-magic me-1"></i> Generate</button>
  </div>
  <textarea class="form-control" name="ai_summary" id="aiSummary" rows="4" placeholder="Click 'Generate' to create AI consultation summary..."></textarea>
  <div id="aiSummaryLoading" class="d-none text-center py-2"><div class="spinner-border spinner-border-sm text-primary me-2"></div>Generating AI summary...</div>
</form>
</div>
<div class="modal-footer border-0 justify-content-between">
  <div>
    <button class="btn btn-outline-danger btn-sm" id="cancelVisitBtn"><i class="bi bi-x-circle me-1"></i> Cancel Visit</button>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
    <button class="btn btn-primary" id="saveConsultBtn"><i class="bi bi-save me-1"></i> Save Consultation</button>
    <button class="btn btn-success" id="completeVisitBtn"><i class="bi bi-check-circle me-1"></i> Complete Visit</button>
  </div>
</div>
</div></div></div>

<!-- ============================================================ -->
<!-- DISPENSE MEDICINE MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="dispenseModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content border-0 rounded-4">
<div class="modal-header border-0" style="background:var(--success);color:#fff;">
  <h6 class="modal-title"><i class="bi bi-capsule me-2"></i>Dispense Medicine</h6>
  <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form id="dispenseForm">
  <input type="hidden" name="walk_in_id" id="dispenseWalkinId">
  <div class="mb-3">
    <label class="form-label fw-semibold">Medicine</label>
    <select class="form-select" name="medicine_id" id="dispenseMedicine" required>
      <option value="">Select medicine...</option>
    </select>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Quantity</label>
    <input type="number" class="form-control" name="quantity" id="dispenseQty" min="1" value="1" required>
    <small class="text-muted">Available: <span id="dispenseStock">0</span></small>
  </div>
  <div class="mb-3">
    <label class="form-label fw-semibold">Dosage Instructions</label>
    <input class="form-control" name="dosage_instructions" placeholder="e.g. 1 tablet every 6 hours">
  </div>
</form>
</div>
<div class="modal-footer border-0 justify-content-between">
  <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
  <button class="btn btn-success" id="dispenseSubmitBtn"><i class="bi bi-capsule me-1"></i> Dispense</button>
</div>
</div></div></div>

<?php include __DIR__ . '/../../components/footer.php'; ?>

<style>
#queueContainer .queue-item {
  transition: all 0.2s ease;
  cursor: pointer;
}
#queueContainer .queue-item:hover { background: var(--primary-50); }
.priority-badge { font-size:0.65rem; padding:2px 8px; }
.estimated-wait { font-size:0.75rem; color:var(--text-muted); }
</style>

<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');
let currentVisitId = null;
let queueRefreshInterval = null;

// ================================================================
// INIT
// ================================================================
$(function () {
  loadQueue();
  loadVisits();
  loadStats();
  
  // Refresh queue every 15 seconds
  queueRefreshInterval = setInterval(function () {
    loadQueue();
    loadStats();
  }, 15000);
  
  // Clean up interval on modal hide
  $(document).on('hidden.bs.modal', function () {
    $('.modal-backdrop').remove();
  });
});

// ================================================================
// LOAD STATS
// ================================================================
function loadStats() {
  $.get(APP_URL + '/api/walkin.php', { action: 'dashboard_stats' }, function (res) {
    if (!res.success) return;
    $('#statToday').text(res.today_walkins).data('count', res.today_walkins);
    $('#statWaiting').text(res.waiting).data('count', res.waiting);
    $('#statServing').text(res.serving).data('count', res.serving);
    $('#statCompleted').text(res.completed).data('count', res.completed);
  }, 'json');
}

// ================================================================
// LOAD QUEUE
// ================================================================
function loadQueue() {
  $.get(APP_URL + '/api/walkin.php', { action: 'queue_list' }, function (res) {
    if (!res.success) return;
    const $c = $('#queueContainer');
    if (res.data.length === 0) {
      $c.html('<div class="text-center text-muted py-4"><i class="bi bi-inbox" style="font-size:2rem;"></i><p class="mt-2 mb-0">No patients in queue today</p></div>');
      return;
    }
    let html = '';
    res.data.forEach(function (q) {
      const priorityColor = q.priority === 'emergency' ? 'danger' : q.priority === 'urgent' ? 'warning' : 'success';
      const statusIcon = q.status === 'waiting' ? 'clock' : q.status === 'serving' ? 'person-check' : 'check-circle';
      html += `
        <div class="d-flex align-items-center justify-content-between p-2 border-bottom queue-item" data-queue="${q.queue_number}">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-${priorityColor} priority-badge">${q.priority.toUpperCase()}</span>
            <div>
              <div class="fw-semibold small">${escapeHtml(q.display_name || 'Unknown')}</div>
              <small class="estimated-wait">${q.student_number || ''} ${q.course || ''}</small>
            </div>
          </div>
          <div class="text-end">
            <div class="fw-bold" style="font-size:0.85rem;color:var(--primary);">${q.queue_number}</div>
            <small class="text-muted"><i class="bi bi-${statusIcon} me-1"></i>${q.status}</small>
            ${q.estimated_wait_minutes ? `<br><small class="text-muted">~${q.estimated_wait_minutes} min</small>` : ''}
          </div>
        </div>
      `;
    });
    $c.html(html);
  }, 'json');
}

// ================================================================
// LOAD VISITS
// ================================================================
function loadVisits() {
  const status = $('#filterStatus').val();
  const search = $('#searchVisit').val();
  
  $.get(APP_URL + '/api/walkin.php', { action: 'list_walkins', status, search, limit: 20 }, function (res) {
    if (!res.success) return;
    const $b = $('#visitsBody');
    if (res.data.length === 0) {
      $b.html('<tr><td colspan="6" class="text-center text-muted py-4">No visits today</td></tr>');
      $('#visitCount').text('0 visits today');
      return;
    }
    $('#visitCount').text(res.total + ' visit(s) today');
    let html = '';
    res.data.forEach(function (v) {
      const priorityColor = v.priority_level === 'emergency' ? 'danger' : v.priority_level === 'urgent' ? 'warning' : 'success';
      const statusColor = v.status === 'completed' ? 'success' : v.status === 'cancelled' ? 'danger' : v.status === 'in_consultation' ? 'info' : 'warning';
      html += `
        <tr class="visit-row" data-id="${v.id}">
          <td><span class="badge bg-primary" style="font-size:0.7rem;">${v.queue_number || '—'}</span></td>
          <td><div class="fw-semibold small">${escapeHtml(v.patient_name || 'Unknown')}</div><small class="text-muted">${v.student_number || v.temp_contact || ''}</small></td>
          <td><span class="small">${escapeHtml(v.chief_complaint || '')}</span></td>
          <td><span class="badge bg-${priorityColor} priority-badge">${v.priority_level}</span></td>
          <td><span class="badge bg-${statusColor}">${v.status.replace('_',' ')}</span></td>
          <td>
            <button class="btn btn-sm btn-outline-primary view-visit" data-id="${v.id}" title="View/Edit"><i class="bi bi-eye"></i></button>
            ${v.status === 'waiting' || v.status === 'in_consultation' ? `
              <button class="btn btn-sm btn-outline-success dispense-btn" data-id="${v.id}" title="Dispense Medicine"><i class="bi bi-capsule"></i></button>
            ` : ''}
          </td>
        </tr>
      `;
    });
    $b.html(html);
  }, 'json');
}

// ================================================================
// STUDENT SEARCH
// ================================================================
let searchTimeout = null;
$('#studentSearch').on('keyup', function () {
  clearTimeout(searchTimeout);
  const q = $(this).val().trim();
  if (q.length < 1) { $('#searchResults').empty().addClass('d-none'); return; }
  
  searchTimeout = setTimeout(function () {
    $.get(APP_URL + '/api/walkin.php', { action: 'search_students', q }, function (res) {
      const $r = $('#searchResults').empty().removeClass('d-none');
      if (res.data.length === 0) {
        $r.append('<button type="button" class="list-group-item list-group-item-action text-muted small temp-register">Not found — Register as visitor</button>');
        return;
      }
      res.data.forEach(function (s) {
        $r.append(`<button type="button" class="list-group-item list-group-item-action" data-id="${s.id}" data-name="${s.first_name} ${s.last_name}" data-number="${s.student_number}" data-course="${s.course||''}" data-year="${s.year_level||''}" data-section="${s.section||''}" data-phone="${s.phone||''}" data-pic="${s.profile_picture||''}">
          <div class="d-flex align-items-center gap-2">
            <img src="${APP_URL + '/' + (s.profile_picture || 'assets/images/default-avatar.png')}" onerror="this.src='https://via.placeholder.com/32'" width="32" height="32" class="rounded-circle" style="object-fit:cover;">
            <div>
              <div class="fw-semibold small">${s.first_name} ${s.last_name}</div>
              <small class="text-muted">${s.student_number} — ${s.course||''} ${s.year_level||''}</small>
            </div>
          </div>
        </button>`);
      });
    }, 'json');
  }, 300);
});

// Select student from search
$(document).on('click', '#searchResults button[data-id]', function () {
  const $btn = $(this);
  $('#selectedStudentId').val($btn.data('id'));
  $('#selStudentName').text($btn.data('name'));
  $('#selStudentDetails').text($btn.data('number') + ' — ' + $btn.data('course') + ' ' + $btn.data('year') + ' ' + $btn.data('section'));
  $('#selStudentPic').attr('src', APP_URL + '/' + ($btn.data('pic') || 'assets/images/default-avatar.png'));
  $('#studentInfoCard').removeClass('d-none');
  $('#tempVisitorForm').addClass('d-none');
  $('#studentSearch').val('').prop('readonly', true);
  $('#searchResults').empty().addClass('d-none');
});

// Temp visitor registration
$(document).on('click', '.temp-register', function () {
  $('#selectedStudentId').val('');
  $('#studentInfoCard').addClass('d-none');
  $('#tempVisitorForm').removeClass('d-none');
  $('#studentSearch').val('Registering as visitor...').prop('readonly', true);
  $('#searchResults').empty().addClass('d-none');
});

// Clear student selection
$(document).on('click', '#clearStudentBtn', function () {
  $('#selectedStudentId').val('');
  $('#studentInfoCard').addClass('d-none');
  $('#tempVisitorForm').addClass('d-none');
  $('#studentSearch').val('').prop('readonly', false).focus();
});

// ================================================================
// BMI AUTO-CALCULATE
// ================================================================
$('#heightCm, #weightKg').on('input', function () {
  const h = parseFloat($('#heightCm').val());
  const w = parseFloat($('#weightKg').val());
  if (h > 0 && w > 0) {
    $.post(APP_URL + '/api/walkin.php', { action: 'calculate_bmi', height_cm: h, weight_kg: w, csrf_token: csrfToken }, function (res) {
      if (res.success) $('#bmiDisplay').html(`<span class="fw-bold">${res.bmi}</span> <span class="badge bg-info">${res.status}</span>`);
    }, 'json');
  } else {
    $('#bmiDisplay').html('<span class="text-muted">Auto-calculate</span>');
  }
});

// ================================================================
// Follow-up toggle
// ================================================================
$('#needFollowUp, #consultFollowUp').on('change', function () {
  const divider = $(this).attr('id') === 'needFollowUp' ? '#followUpDateDiv' : '#consultFollowUpDateDiv';
  if ($(this).is(':checked')) $(divider).removeClass('d-none');
  else $(divider).addClass('d-none');
});

// ================================================================
// NEW PATIENT MODAL
// ================================================================
$('#newPatientBtn').on('click', function () {
  $('#walkinForm')[0].reset();
  $('#selectedStudentId').val('');
  $('#studentInfoCard').addClass('d-none');
  $('#tempVisitorForm').addClass('d-none');
  $('#bmiDisplay').html('<span class="text-muted">Auto-calculate</span>');
  $('#followUpDateDiv').addClass('d-none');
  $('#studentSearch').val('').prop('readonly', false);
  new bootstrap.Modal(document.getElementById('walkinModal')).show();
});

// SAVE WALK-IN
$('#saveWalkinBtn').on('click', function () {
  const data = $('#walkinForm').serialize() + '&action=save_visit&csrf_token=' + csrfToken;
  
  Swal.fire({
    title: 'Save Walk-in Visit?',
    text: 'This patient will be added to the queue.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#0B6B3A',
    confirmButtonText: 'Yes, Save',
    cancelButtonText: 'Cancel'
  }).then(function (result) {
    if (!result.isConfirmed) return;
    
    $('#saveWalkinBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    
    $.post(APP_URL + '/api/walkin.php', data, function (res) {
      if (res.success) {
        $('#walkinModal').modal('hide');
        Swal.fire({ icon: 'success', title: res.message, timer: 2000, showConfirmButton: false });
        loadQueue();
        loadVisits();
        loadStats();
      } else {
        Swal.fire({ icon: 'error', title: 'Error', text: res.message });
      }
    }).fail(function () {
      Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Please try again.' });
    }).always(function () {
      $('#saveWalkinBtn').prop('disabled', false).html('<i class="bi bi-save me-1"></i> Save & Add to Queue');
    });
  });
});

// ================================================================
// VIEW / EDIT VISIT
// ================================================================
$(document).on('click', '.view-visit', function () {
  const id = $(this).data('id');
  currentVisitId = id;
  
  $.get(APP_URL + '/api/walkin.php', { action: 'get_visit', id }, function (res) {
    if (!res.success) return toastError(res.message);
    const v = res.data;
    
    $('#consultId').val(v.id);
    $('#consultVisitNo').text(v.visit_number);
    $('#consultQueueBadge').text(v.queue_number || '—');
    $('#consultPatientName').text(v.patient_name || 'Unknown');
    $('#consultPatientDetails').text((v.student_number || 'Visitor') + ' — ' + (v.course || '') + ' ' + (v.year_level || ''));
    $('#consultAvatar').text(v.patient_name ? v.patient_name.charAt(0).toUpperCase() : '?');
    $('#consultComplaint').val(v.chief_complaint);
    
    // Vitals
    $('#vTemp').text(v.temperature ? v.temperature + '°C' : '—');
    $('#vBP').text(v.blood_pressure_systolic ? v.blood_pressure_systolic + '/' + v.blood_pressure_diastolic : '—');
    $('#vHR').text(v.heart_rate ? v.heart_rate : '—');
    $('#vBMI').text(v.bmi ? v.bmi + ' (' + v.bmi_status + ')' : '—');
    
    // Consultation fields
    $('#consultSymptoms').val(v.symptoms || '');
    $('#consultAssessment').val(v.assessment || '');
    $('#consultDiagnosis').val(v.diagnosis || '');
    $('#consultTreatment').val(v.treatment || '');
    $('#consultRecommendation').val(v.recommendation || '');
    $('#aiSummary').val(v.ai_summary_edited || v.ai_summary || '');
    
    if (v.need_follow_up) {
      $('#consultFollowUp').prop('checked', true);
      $('#consultFollowUpDateDiv').removeClass('d-none');
      $('#consultFollowUpDate').val(v.follow_up_date || '');
    } else {
      $('#consultFollowUp').prop('checked', false);
      $('#consultFollowUpDateDiv').addClass('d-none');
    }
    
    $('#consultSignature').val(v.digital_signature || '');
    
    // Enable/disable buttons based on status
    if (v.status === 'completed' || v.status === 'cancelled') {
      $('#saveConsultBtn').prop('disabled', true);
      $('#completeVisitBtn').prop('disabled', true);
    } else {
      $('#saveConsultBtn').prop('disabled', false);
      $('#completeVisitBtn').prop('disabled', false);
    }
    
    new bootstrap.Modal(document.getElementById('consultModal')).show();
  }, 'json');
});

// SAVE CONSULTATION
$('#saveConsultBtn').on('click', function () {
  const data = $('#consultForm').serialize() + '&action=update_consultation&csrf_token=' + csrfToken;
  
  $.post(APP_URL + '/api/walkin.php', data, function (res) {
    if (res.success) {
      toastSuccess('Consultation saved.');
      loadVisits();
      loadQueue();
    } else toastError(res.message);
  }, 'json');
});

// COMPLETE VISIT
$('#completeVisitBtn').on('click', function () {
  const id = $('#consultId').val();
  
  Swal.fire({
    title: 'Complete Visit?',
    text: 'This will mark the visit as completed and update medical records.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#16A34A',
    confirmButtonText: 'Yes, Complete',
    cancelButtonText: 'Cancel'
  }).then(function (result) {
    if (!result.isConfirmed) return;
    
    $.post(APP_URL + '/api/walkin.php', { action: 'complete_visit', id, csrf_token: csrfToken }, function (res) {
      if (res.success) {
        $('#consultModal').modal('hide');
        Swal.fire({ icon: 'success', title: 'Visit completed!', timer: 1500, showConfirmButton: false });
        loadVisits();
        loadQueue();
        loadStats();
      } else toastError(res.message);
    }, 'json');
  });
});

// CANCEL VISIT
$('#cancelVisitBtn').on('click', function () {
  const id = $('#consultId').val();
  confirmAction('Cancel this visit?', function () {
    $.post(APP_URL + '/api/walkin.php', { action: 'cancel_visit', id, csrf_token: csrfToken }, function () {
      toastSuccess('Visit cancelled.');
      $('#consultModal').modal('hide');
      loadVisits();
      loadQueue();
    }, 'json');
  });
});

// ================================================================
// DISPENSE MEDICINE
// ================================================================
$(document).on('click', '.dispense-btn', function () {
  const id = $(this).data('id');
  $('#dispenseWalkinId').val(id);
  
  // Load inventory
  $.get(APP_URL + '/api/walkin.php', { action: 'inventory_list' }, function (res) {
    const $sel = $('#dispenseMedicine').empty().append('<option value="">Select medicine...</option>');
    res.data.forEach(function (m) {
      if (m.is_expired || m.is_out_of_stock) return;
      $sel.append(`<option value="${m.id}" data-stock="${m.quantity}">${m.medicine_name} (${m.quantity} ${m.unit||'pcs'})</option>`);
    });
  }, 'json');
  
  new bootstrap.Modal(document.getElementById('dispenseModal')).show();
});

$('#dispenseMedicine').on('change', function () {
  const stock = $(this).find('option:selected').data('stock') || 0;
  $('#dispenseStock').text(stock);
  $('#dispenseQty').attr('max', stock);
});

$('#dispenseSubmitBtn').on('click', function () {
  const data = $('#dispenseForm').serialize() + '&action=dispense_medicine&csrf_token=' + csrfToken;
  
  $.post(APP_URL + '/api/walkin.php', data, function (res) {
    if (res.success) {
      $('#dispenseModal').modal('hide');
      Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false });
      loadVisits();
    } else toastError(res.message);
  }, 'json');
});

// ================================================================
// AI SUMMARY GENERATION
// ================================================================
$('#genAISummaryBtn').on('click', function () {
  const id = $('#consultId').val();
  if (!id) return;
  
  $('#aiSummaryLoading').removeClass('d-none');
  $('#genAISummaryBtn').prop('disabled', true);
  
  $.post(APP_URL + '/api/walkin.php', { action: 'generate_ai_summary', id, csrf_token: csrfToken }, function (res) {
    if (res.success) {
      $('#aiSummary').val(res.summary);
      toastSuccess('AI summary generated!');
    } else toastError(res.message);
  }).always(function () {
    $('#aiSummaryLoading').addClass('d-none');
    $('#genAISummaryBtn').prop('disabled', false);
  });
});

// ================================================================
// FILTERS
// ================================================================
$('#filterStatus, #searchVisit').on('change keyup', function () {
  loadVisits();
});

// ================================================================
// HELPER: Escape HTML
// ================================================================
function escapeHtml(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}
</script>
</body>
</html>

