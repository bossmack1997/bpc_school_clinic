<?php
require_once __DIR__ . '/../../config/config.php';
require_role('teacher');
$db = getDB();
$u = current_user();

$pageTitle = 'Emergency Reports';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?>
<style>
.emg-tag { font-size: 0.68rem; font-weight: 600; padding: 4px 8px; border-radius: 10px; display: inline-block; }
.emg-tag--pending { background: #fff3cd; color: #8a6d00; border: 1px solid #ffe08a; }
.emg-tag--acknowledged { background: #cfe2ff; color: #0d47a1; border: 1px solid #9ec5ff; }
.emg-tag--responding { background: #e2e8f0; color: #334155; border: 1px solid #cbd5e1; }
.emg-tag--resolved { background: #d1fae5; color: #065f46; border: 1px solid #86efac; }
.emg-tag--cancelled, .emg-tag--false_alarm { background: #e2e3e5; color: #41464b; border: 1px solid #cfd3d6; }
.emg-modal .form-label { font-size: 0.82rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; color: var(--text-secondary); }
.student-search-wrap { position: relative; }
.student-search-results { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid var(--border); border-radius: 8px; box-shadow: var(--shadow-md); z-index: 1055; max-height: 260px; overflow-y: auto; display: none; }
.student-search-results .result-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f1f3f5; font-size: 0.88rem; display: flex; justify-content: space-between; align-items: center; }
.student-search-results .result-item:last-child { border-bottom: none; }
.student-search-results .result-item:hover, .student-search-results .result-item.selected { background: var(--primary-light); color: var(--primary); }
.student-search-results .result-item .muted { font-size: 0.76rem; color: var(--text-muted); }
.student-search-results .result-item.selected .muted { color: var(--primary); }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <h6 class="section-title mb-0"><i class="bi bi-shield-fill-exclamation text-danger me-1"></i> Emergency Reports</h6>
          <p class="text-muted small mb-0">Submit and track emergency reports for your advisory students.</p>
        </div>
        <button type="button" class="btn btn-danger" id="btnReportEmergency" data-bs-toggle="modal" data-bs-target="#emergencyReportModal">
          <i class="bi bi-plus-lg me-1"></i> + Report Emergency
        </button>
      </div>

      <!-- Emergency History -->
      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="emgTable" style="width:100%">
              <thead>
                <tr>
                  <th>Student</th><th>Type</th><th>Severity</th><th>Location</th><th>Status</th><th>Reported</th><th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ================================================================
     REPORT EMERGENCY MODAL
     ================================================================ -->
<div class="modal fade emg-modal" id="emergencyReportModal" tabindex="-1" aria-hidden="true" aria-labelledby="emergencyReportModalLabel">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow">
      <form id="emergencyReportForm" novalidate>
        <div class="modal-header bg-dark text-white">
          <h5 class="modal-title" id="emergencyReportModalLabel"><?= bpc_icon('shield-fill-exclamation') ?> Report Emergency</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <!-- Student -->
            <div class="col-md-6">
              <label class="emg-modal__label">Student Name or Student ID <span class="text-danger">*</span></label>
              <div class="student-search-wrap">
                <input type="text" id="emgStudentSearch" class="form-control" placeholder="Type student name or ID..." autocomplete="off" required>
                <input type="hidden" id="emgStudentId" name="student_id" value="">
                <div class="student-search-results" id="emgStudentResults"></div>
              </div>
              <div class="invalid-feedback">Please select a valid student from your roster.</div>
              <div class="form-text">Only students in your advisory sections are available.</div>
            </div>

            <!-- Emergency Type -->
            <div class="col-md-6">
              <label class="emg-modal__label">Emergency Type <span class="text-danger">*</span></label>
              <select class="form-select" name="emergency_type" id="emgType" required>
                <option value="">Select emergency type…</option>
                <option>Injury / Accident</option>
                <option>Severe Bleeding</option>
                <option>Difficulty Breathing</option>
                <option>Chest Pain</option>
                <option>Unconscious / Fainting</option>
                <option>Seizure</option>
                <option>Mental Health Crisis</option>
                <option>Other</option>
              </select>
              <div class="invalid-feedback">Please select an emergency type.</div>
            </div>

            <!-- Severity -->
            <div class="col-md-6">
              <label class="emg-modal__label">Emergency Severity</label>
              <select class="form-select" name="priority" id="emgPriority">
                <option value="soon">Soon</option>
                <option value="urgent" selected>Urgent</option>
                <option value="routine">Low / Routine</option>
              </select>
              <div class="form-text">Priority level used by the clinic for triage.</div>
            </div>

            <!-- Location -->
            <div class="col-md-6">
              <label class="emg-modal__label">Location / Room Number <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="location" id="emgLocation" placeholder="e.g. Room 204, BSIS Building" required>
              <div class="invalid-feedback">Location is required.</div>
            </div>

            <!-- Date & Time -->
            <div class="col-md-6">
              <label class="emg-modal__label">Date and Time</label>
              <input type="datetime-local" class="form-control" name="occurred_at" id="emgDateTime" value="">
              <div class="form-text">Defaults to now if left blank.</div>
            </div>

            <!-- Description -->
            <div class="col-12">
              <label class="emg-modal__label">Description of Incident <span class="text-danger">*</span></label>
              <textarea class="form-control" name="description" id="emgDescription" rows="3" placeholder="What happened? What is the student's condition?" required></textarea>
              <div class="invalid-feedback">Please describe the incident.</div>
            </div>

            <!-- Notes -->
            <div class="col-12">
              <label class="emg-modal__label">Additional Notes (optional)</label>
              <textarea class="form-control" name="notes" id="emgNotes" rows="2" placeholder="Any additional context for the clinic…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger" id="emgSubmitBtn">
            <i class="bi bi-shield-fill-exclamation me-1"></i> Submit Report
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const emgStatusLabels = {
  new: 'Pending', active: 'Pending', acknowledged: 'Acknowledged', responding: 'Responding',
  responded: 'Responding', resolved: 'Resolved', cancelled: 'Cancelled', false_alarm: 'False Alarm'
};
const emgStatusClasses = {
  new: 'emg-tag--pending', active: 'emg-tag--pending', acknowledged: 'emg-tag--acknowledged',
  responding: 'emg-tag--responding', responded: 'emg-tag--responding', resolved: 'emg-tag--resolved',
  cancelled: 'emg-tag--cancelled', false_alarm: 'emg-tag--cancelled'
};

function loadEmergencies() {
  $.get(APP_URL + '/api/emergencies.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    const rows = (res.data || []).map(e => {
      const status = e.status || 'new';
      return [
        '<strong>' + escapeHtml(e.student_number || '—') + '</strong><br><small class="text-muted">Student</small>',
        escapeHtml(e.emergency_type || 'Emergency'),
        escapeHtml(e.priority || 'urgent'),
        escapeHtml(e.location || '—'),
        '<span class="emg-tag ' + (emgStatusClasses[status] || 'emg-tag--pending') + '">' + emgStatusLabels[status] + '</span>',
        e.created_at ? e.created_at.replace(' ', ' · ') : '—',
        '<div class="d-flex gap-1 justify-content-end">'
          + '<a href="student_detail.php?id=' + (e.student_id || '') + '" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i>View</a>'
          + '</div>'
      ];
    });
    if (window.__emgDt) window.__emgDt.destroy();
    window.__emgDt = $('#emgTable').DataTable({
      data: rows,
      columns: [{},{},{},{},{},{},{orderable:false}],
      destroy: true,
      pageLength: 10,
      order: [[5, 'desc']]
    });
  }, 'json');
}
loadEmergencies();
setInterval(loadEmergencies, 15000);
if (new URLSearchParams(window.location.search).get('report') === '1') {
  bootstrap.Modal.getOrCreateInstance(document.getElementById('emergencyReportModal')).show();
}

// Prefill date/time with now
(function () {
  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  document.getElementById('emgDateTime').value = now.toISOString().slice(0, 16);
})();

// --- Student searchable typeahead ---
(function () {
  const searchIn = document.getElementById('emgStudentSearch');
  const idIn = document.getElementById('emgStudentId');
  const resultsBox = document.getElementById('emgStudentResults');
  let timeout = null;

  function showResults(items) {
    resultsBox.innerHTML = '';
    if (!items.length) { resultsBox.style.display = 'none'; return; }
    items.forEach(function (it) {
      const div = document.createElement('div');
      div.className = 'result-item';
      div.innerHTML = '<span>' + escapeHtml(it.label) + '</span><span class="muted">' + escapeHtml(it.section) + '</span>';
      div.onclick = function () {
        searchIn.value = it.label;
        idIn.value = it.id;
        resultsBox.style.display = 'none';
      };
      resultsBox.appendChild(div);
    });
    resultsBox.style.display = 'block';
  }

  searchIn.addEventListener('input', function () {
    const q = this.value.trim();
    clearTimeout(timeout);
    if (q.length < 2) { resultsBox.style.display = 'none'; return; }
    timeout = setTimeout(function () {
      $.get(APP_URL + '/api/emergencies.php', { action: 'teacher_students', q: q }, function (res) {
        if (res && res.success) showResults(res.data || []);
      }, 'json');
    }, 300);
  });

  searchIn.addEventListener('blur', function () { setTimeout(function () { resultsBox.style.display = 'none'; }, 180); });
  searchIn.addEventListener('focus', function () { if (searchIn.value.trim().length >= 2) resultsBox.style.display = 'block'; });

  document.getElementById('emergencyReportForm').addEventListener('submit', function (e) {
    if (!idIn.value) {
      e.preventDefault();
      searchIn.classList.add('is-invalid');
      return;
    }
  });
})();

// --- Form submit ---
$('#emergencyReportForm').on('submit', function (e) {
  e.preventDefault();
  const $btn = $('#emgSubmitBtn');
  const $search = $('#emgStudentSearch');
  const studentId = $('#emgStudentId').val();
  if (!studentId) { $search.addClass('is-invalid').focus(); return; }
  if (!this.checkValidity()) { this.classList.add('was-validated'); return; }
  if (!window.confirm('Send this emergency report now? The clinic team will be alerted immediately.')) return;

  $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Submitting…');
  const data = $(this).serialize() + '&action=report&csrf_token=' + $('meta[name="csrf-token"]').attr('content');

  $.post(APP_URL + '/api/emergencies.php', data, function (res) {
    $btn.prop('disabled', false).html('<i class="bi bi-shield-fill-exclamation me-1"></i> Submit Report');
    if (res.success) {
      $('#emergencyReportModal').modal('hide');
      $('#emergencyReportForm')[0].reset();
      $('#emgStudentId').val('');
      Swal.fire({ icon: 'success', title: 'Emergency Reported', text: res.message || 'The clinic has been notified.', timer: 4000, timerProgressBar: true });
      loadEmergencies();
    } else {
      Swal.fire({ icon: 'error', title: 'Report Failed', text: res.message || 'Unable to submit the emergency report.' });
    }
  }, 'json').fail(function () {
    $btn.prop('disabled', false).html('<i class="bi bi-shield-fill-exclamation me-1"></i> Submit Report');
    Swal.fire({ icon: 'error', title: 'Connection Error', text: 'Failed to submit the report. Please try again.' });
  });
});

function escapeHtml(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, function (c) { return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;" }[c]; });
}
</script>
</body>
</html>
