<?php /* Original Consultation Desk walk-in form, shared with Clinic Queue. */ ?>
<div class="modal fade" id="walkinModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="triageWalkinModalTitle">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content border-0 rounded-4">
<div class="modal-header border-0" style="background:var(--primary);color:#fff;border-radius:12px 12px 0 0;">
  <h5 id="triageWalkinModalTitle" class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>New Walk-in Patient</h5>
  <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<form id="triageWalkinForm">
  <input type="hidden" name="student_id" id="triageSelectedStudentId">
  
  <!-- Step 1: Student Search -->
  <div class="mb-3">
    <label class="form-label fw-semibold">Search Student</label>
    <div class="input-group">
      <input type="text" id="triageStudentSearch" class="form-control" placeholder="Search by name or student ID..." autocomplete="off">
      <button class="btn btn-outline-secondary" type="button" id="triageQrScanBtn" title="QR Code Scan"><i class="bi bi-qr-code-scan"></i></button>
    </div>
    <div id="triageSearchResults" class="list-group mt-1" style="max-height:200px;overflow-y:auto;"></div>
  </div>
  
  <!-- Student Info Card (appears when student selected) -->
  <div id="triageStudentInfoCard" class="card bg-light mb-3 d-none">
    <div class="card-body py-3">
      <div class="d-flex align-items-center gap-3">
        <img id="triageSelStudentPic" src="<?= asset_url('images/default-avatar.svg') ?>" class="rounded-circle" width="60" height="60" style="object-fit:cover;">
        <div>
          <h6 class="mb-1 fw-semibold" id="triageSelStudentName">—</h6>
          <small class="text-muted" id="triageSelStudentDetails">—</small>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger ms-auto" id="triageClearStudentBtn"><i class="bi bi-x"></i></button>
      </div>
    </div>
  </div>
  
  <!-- Temp Visitor Form -->
  <div id="triageTempVisitorForm" class="d-none">
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
      <select class="form-select" name="priority_level" id="triagePriorityLevel" required>
        <option value="normal">Normal</option>
        <option value="urgent">Urgent</option>
        <option value="emergency" class="text-danger">Emergency</option>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label small fw-semibold">Chief Complaint</label>
      <input class="form-control" name="chief_complaint" id="triageChiefComplaint" required placeholder="e.g. Headache, Fever">
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
    <div class="col-4 col-md-3"><label class="form-label small">Height (cm)</label><input type="number" step="0.1" class="form-control" name="height_cm" id="triageHeightCm" placeholder="170"></div>
    <div class="col-4 col-md-3"><label class="form-label small">Weight (kg)</label><input type="number" step="0.1" class="form-control" name="weight_kg" id="triageWeightKg" placeholder="65"></div>
    <div class="col-4 col-md-3">
      <label class="form-label small">BMI</label>
      <div class="form-control-plaintext py-1" id="triageBmiDisplay">
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
        <input class="form-check-input" type="checkbox" name="need_follow_up" id="triageNeedFollowUp" value="1">
        <label class="form-check-label small" for="triageNeedFollowUp">Needs Follow-up</label>
      </div>
    </div>
    <div class="col-md-6 d-none" id="triageFollowUpDateDiv">
      <label class="form-label small">Follow-up Date</label>
      <input type="date" class="form-control" name="follow_up_date">
    </div>
  </div>
</form>
</div>
<div class="modal-footer border-0 justify-content-between">
  <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
  <button class="btn btn-primary" id="triageSaveWalkinBtn"><i class="bi bi-save me-1"></i> Save &amp; Add to Queue</button>
</div>
</div></div></div>

