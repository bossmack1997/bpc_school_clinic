<?php
require_once __DIR__ . '/../../config/config.php';
require_role('guidance');
$db = getDB();
$u = current_user();
$pageTitle = 'Student Well-being & Counseling';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../../includes/head.php'; ?>
<style>
  .confidential-card {
    border-radius: 12px;
    border: 1px solid var(--border-color, #E2E8F0);
    box-shadow: 0 4px 12px rgba(0,0,0,0.04);
    background: #fff;
  }
  .confidential-header {
    background: linear-gradient(135deg, rgba(14, 122, 62, 0.08) 0%, rgba(14, 122, 62, 0.02) 100%);
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-color, #E2E8F0);
    border-radius: 12px 12px 0 0;
  }
  .info-group-title {
    font-size: 0.82rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted, #718096);
    margin-bottom: 4px;
  }
  .info-group-val {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--dark, #1A202C);
    margin-bottom: 14px;
  }
</style>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <!-- Header -->
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
          <h5 class="fw-bold mb-1"><i class="bi bi-person-heart text-success me-2"></i> Student Well-being &amp; Counseling</h5>
          <p class="text-muted small mb-0">Confidential psychological, emotional, and support preference records for student guidance.</p>
        </div>
        <a href="referrals.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-send me-1"></i> View Support Referrals</a>
      </div>

      <!-- Confidentiality Notice Banner -->
      <div class="alert alert-light border d-flex align-items-center gap-3 mb-4" role="alert">
        <div class="d-inline-flex align-items-center justify-content-center bg-success-light text-success rounded-circle p-2" style="width: 40px; height: 40px;">
          <i class="bi bi-shield-lock-fill fs-5"></i>
        </div>
        <div class="small">
          <strong>Confidential Guidance Record:</strong> Access to mental and emotional well-being details, concerns, and counseling support preferences is strictly restricted to Guidance Counselors.
        </div>
      </div>

      <div class="row g-3">
        <!-- Left: Search Student -->
        <div class="col-lg-4">
          <div class="card p-3 mb-3">
            <h6 class="section-title mb-3"><i class="bi bi-search text-success me-1"></i> Select Student</h6>
            <div class="mb-3">
              <label class="form-label small fw-semibold">Search by Name or Student ID</label>
              <input type="text" id="studentSearch" class="form-control" placeholder="Type name or ID...">
              <div id="searchResults" class="list-group mt-2"></div>
            </div>

            <div id="selectedStudentCard" class="d-none mt-3 p-3 bg-light rounded-3 border">
              <div class="d-flex align-items-center gap-3 mb-2">
                <div class="avatar-sm rounded-circle bg-success text-white fw-bold d-flex align-items-center justify-content-center" style="width: 44px; height: 44px; font-size: 1.1rem;" id="stAvatar">
                  —
                </div>
                <div>
                  <div class="fw-bold text-dark fs-6" id="stName">—</div>
                  <div class="small text-muted" id="stNumber">—</div>
                </div>
              </div>
              <div class="small text-muted mt-2 pt-2 border-top">
                <div><strong>Course:</strong> <span id="stCourse">—</span></div>
                <div><strong>Section:</strong> <span id="stSection">—</span></div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-success w-100 mt-3" id="createRefBtn">
                <i class="bi bi-send me-1"></i> Create Support Referral
              </button>
            </div>
          </div>
        </div>

        <!-- Right: Confidential Well-being & Counseling Record -->
        <div class="col-lg-8">
          <!-- Initial Placeholder -->
          <div id="noStudentPlaceholder" class="card p-5 text-center">
            <div class="mb-3 text-muted">
              <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle p-4" style="width: 76px; height: 76px;">
                <i class="bi bi-person-heart text-secondary fs-1"></i>
              </div>
            </div>
            <h6 class="fw-bold text-dark mb-1">No Student Selected</h6>
            <p class="text-muted small mb-0">Search and select a student from the left panel to view their confidential mental &amp; emotional well-being and counseling records.</p>
          </div>

          <!-- Loading State -->
          <div id="loadingWellbeing" class="card p-5 text-center d-none">
            <div class="spinner-border text-success mx-auto mb-3" role="status"></div>
            <div class="text-muted small">Loading confidential student records…</div>
          </div>

          <!-- Well-being Details Container -->
          <div id="wellbeingContainer" class="d-none">

            <!-- 1. Mental & Emotional Well-being Card -->
            <div class="card confidential-card mb-3">
              <div class="confidential-header d-flex align-items-center justify-content-between">
                <h6 class="fw-bold mb-0 text-success"><i class="bi bi-emoji-smile-fill me-2"></i> Mental &amp; Emotional Well-being</h6>
                <span class="badge bg-success-light text-success fw-semibold">Confidential</span>
              </div>
              <div class="card-body p-4">
                <div class="row g-3">
                  <div class="col-12">
                    <div class="info-group-title">Concerns Shared by Student</div>
                    <div class="info-group-val p-3 bg-light rounded-3" id="valConcerns">—</div>
                  </div>
                  <div class="col-md-6">
                    <div class="info-group-title">Previous Professional Support</div>
                    <div class="info-group-val" id="valPrevSupport">—</div>
                  </div>
                  <div class="col-md-6">
                    <div class="info-group-title">Previous Diagnosis / Condition (Disclosed)</div>
                    <div class="info-group-val" id="valDiagnosis">—</div>
                  </div>
                  <div class="col-md-6">
                    <div class="info-group-title">Current Support / Treatment</div>
                    <div class="info-group-val" id="valCurrSupport">—</div>
                  </div>
                  <div class="col-md-6">
                    <div class="info-group-title">Other Relevant Information</div>
                    <div class="info-group-val" id="valOtherWellbeing">—</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- 2. Support Preferences Card -->
            <div class="card confidential-card mb-3">
              <div class="confidential-header d-flex align-items-center justify-content-between">
                <h6 class="fw-bold mb-0 text-primary"><i class="bi bi-people-fill me-2"></i> Support Preferences &amp; Guidance Notes</h6>
                <span class="badge bg-primary-light text-primary fw-semibold">Preferences</span>
              </div>
              <div class="card-body p-4">
                <div class="mb-3">
                  <div class="info-group-title">Preferred Support Approach</div>
                  <div id="valPrefsContainer" class="d-flex flex-wrap gap-2 mt-1">—</div>
                </div>
                <div class="mb-3">
                  <div class="info-group-title">Additional Student Notes</div>
                  <div class="info-group-val p-3 bg-light rounded-3" id="valStudentNotes">—</div>
                </div>
                <div class="small text-muted pt-2 border-top">
                  <i class="bi bi-shield-check text-success me-1"></i> Privacy Consent Acknowledged: <strong id="valConsentAt">—</strong>
                </div>
              </div>
            </div>

            <!-- 3. Holistic Medical Context Card -->
            <div class="card confidential-card mb-3">
              <div class="confidential-header d-flex align-items-center justify-content-between">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-heart-pulse-fill text-danger me-2"></i> Medical Context (For Holistic Care)</h6>
                <span class="badge bg-light text-muted border">Medical Background</span>
              </div>
              <div class="card-body p-4">
                <div class="row g-3">
                  <div class="col-md-6">
                    <div class="info-group-title">Known Medical Conditions</div>
                    <div class="info-group-val" id="valMedConditions">—</div>
                  </div>
                  <div class="col-md-6">
                    <div class="info-group-title">Allergies</div>
                    <div class="info-group-val" id="valAllergies">—</div>
                  </div>
                  <div class="col-md-6">
                    <div class="info-group-title">Current Medications</div>
                    <div class="info-group-val" id="valMedications">—</div>
                  </div>
                  <div class="col-md-6">
                    <div class="info-group-title">Other Important Health Info</div>
                    <div class="info-group-val" id="valOtherMed">—</div>
                  </div>
                </div>
              </div>
            </div>

          </div>

          <!-- Empty Profile State (When student has not submitted well-being form) -->
          <div id="emptyWellbeingState" class="card p-5 text-center d-none">
            <div class="mb-3">
              <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle p-4" style="width: 76px; height: 76px;">
                <i class="bi bi-clipboard-x text-secondary fs-1"></i>
              </div>
            </div>
            <h6 class="fw-bold text-dark mb-1">No Well-being Profile Submitted Yet</h6>
            <p class="text-muted small mb-0" style="max-width: 420px; margin: 0 auto;">
              This student has not yet submitted a voluntary health and well-being form.
            </p>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>

<!-- Support Referral Modal -->
<div class="modal fade" id="guidanceReferralModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header border-0 bg-success text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-send-fill me-2"></i> Create Support Referral</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="guidanceReferralForm">
          <input type="hidden" name="student_id" id="refStudentId">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Student</label>
            <input type="text" class="form-control" id="refStudentNameDisplay" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Referral Destination / Service</label>
            <input type="text" class="form-control" name="referred_to" value="Guidance Counseling Program" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Reason for Support</label>
            <textarea class="form-control" name="reason" rows="3" placeholder="Describe the counseling/support goals..." required></textarea>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-semibold">Priority</label>
              <select class="form-select" name="priority">
                <option value="routine">Routine</option>
                <option value="soon">Soon</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-6 d-flex align-items-end">
              <div class="form-check pb-2">
                <input class="form-check-input" type="checkbox" name="student_informed" id="guidanceSi" value="1" checked>
                <label class="form-check-label small" for="guidanceSi">Student informed</label>
              </div>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Confidential Notes</label>
            <textarea class="form-control" name="notes" rows="2" placeholder="Private internal notes..."></textarea>
          </div>
          <button class="btn btn-success w-100 fw-semibold" type="submit"><i class="bi bi-send me-1"></i> Submit Referral</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$(function () {
  'use strict';

  let currentStudent = null;

  // Search Students
  $('#studentSearch').on('keyup', function () {
    const q = $(this).val().trim();
    if (q.length < 2) {
      $('#searchResults').empty();
      return;
    }
    $.get(APP_URL + '/api/students_api.php', { action: 'search', q: q }, function (res) {
      const $r = $('#searchResults').empty();
      if (!res.success || !res.data.length) {
        $r.html('<div class="p-2 text-muted small">No matching students found.</div>');
        return;
      }
      res.data.forEach(s => {
        $r.append(
          `<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center select-student-btn"
                   data-id="${s.id}"
                   data-name="${escapeHtml(s.first_name + ' ' + s.last_name)}"
                   data-number="${escapeHtml(s.student_number || '')}"
                   data-course="${escapeHtml(s.course || '—')}"
                   data-section="${escapeHtml(s.section || '—')}">
            <div>
              <div class="fw-bold text-dark">${escapeHtml(s.first_name + ' ' + s.last_name)}</div>
              <div class="small text-muted">${escapeHtml(s.student_number || '')} · ${escapeHtml(s.course || '')}</div>
            </div>
            <i class="bi bi-chevron-right text-muted small"></i>
          </button>`
        );
      });
    }, 'json');
  });

  // Select Student
  $(document).on('click', '.select-student-btn', function () {
    const st = {
      id: $(this).data('id'),
      name: $(this).data('name'),
      number: $(this).data('number'),
      course: $(this).data('course'),
      section: $(this).data('section')
    };
    currentStudent = st;

    $('#searchResults').empty();
    $('#studentSearch').val('');

    $('#stName').text(st.name);
    $('#stNumber').text(st.number);
    $('#stCourse').text(st.course);
    $('#stSection').text(st.section);
    $('#stAvatar').text(st.name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase());
    $('#selectedStudentCard').removeClass('d-none');

    loadStudentWellbeing(st.id);
  });

  function valOrNone(val) {
    if (!val || !val.trim()) {
      return '<span class="text-muted fst-italic">None reported</span>';
    }
    return $('<div>').text(val).html();
  }

  function loadStudentWellbeing(studentId) {
    $('#noStudentPlaceholder').addClass('d-none');
    $('#wellbeingContainer').addClass('d-none');
    $('#emptyWellbeingState').addClass('d-none');
    $('#loadingWellbeing').removeClass('d-none');

    $.get(APP_URL + '/api/wellbeing.php', { action: 'get', student_id: studentId }, function (res) {
      $('#loadingWellbeing').addClass('d-none');

      if (!res.success || !res.exists || !res.profile) {
        $('#emptyWellbeingState').removeClass('d-none');
        return;
      }

      const p = res.profile;

      // Mental & Emotional
      $('#valConcerns').html(valOrNone(p.concerns));
      $('#valPrevSupport').html(valOrNone(p.previous_support));
      $('#valDiagnosis').html(valOrNone(p.diagnosis_disclosed));
      $('#valCurrSupport').html(valOrNone(p.current_support));
      $('#valOtherWellbeing').html(valOrNone(p.other_wellbeing));

      // Support Preferences
      let prefs = [];
      try {
        const dec = JSON.parse(p.support_preferences || '[]');
        if (Array.isArray(dec)) prefs = dec;
      } catch (e) {
        prefs = [];
      }

      if (prefs.length > 0) {
        const badgesHtml = prefs.map(pref => `<span class="badge bg-primary-light text-primary px-3 py-2 fw-medium fs-7"><i class="bi bi-check2 me-1"></i> ${escapeHtml(pref)}</span>`).join('');
        $('#valPrefsContainer').html(badgesHtml);
      } else {
        $('#valPrefsContainer').html('<span class="text-muted fst-italic">No specific preferences selected</span>');
      }

      $('#valStudentNotes').html(valOrNone(p.student_notes));
      $('#valConsentAt').text(p.consent_at || 'Acknowledged');

      // Medical Context
      $('#valMedConditions').html(valOrNone(p.known_medical_conditions));
      $('#valAllergies').html(valOrNone(p.allergies));
      $('#valMedications').html(valOrNone(p.current_medications));
      $('#valOtherMed').html(valOrNone(p.other_medical_info));

      $('#wellbeingContainer').removeClass('d-none');
    }, 'json').fail(function () {
      $('#loadingWellbeing').addClass('d-none');
      toastError('Unable to load student record.');
    });
  }

  // Create Support Referral button
  $('#createRefBtn').on('click', function () {
    if (!currentStudent) return;
    $('#refStudentId').val(currentStudent.id);
    $('#refStudentNameDisplay').val(currentStudent.name + ' (' + currentStudent.number + ')');
    new bootstrap.Modal(document.getElementById('guidanceReferralModal')).show();
  });

  // Submit Referral
  $('#guidanceReferralForm').on('submit', function (e) {
    e.preventDefault();
    if (!$('#refStudentId').val()) {
      toastError('No student selected.');
      return;
    }
    const data = $(this).serialize() + '&action=create&csrf_token=' + $('meta[name="csrf-token"]').attr('content');
    $.post(APP_URL + '/api/referrals.php', data, function (res) {
      if (res.success) {
        toastSuccess(res.message || 'Support referral created successfully.');
        $('#guidanceReferralForm')[0].reset();
        bootstrap.Modal.getInstance(document.getElementById('guidanceReferralModal')).hide();
      } else {
        toastError(res.message || 'Failed to create referral.');
      }
    }, 'json');
  });

  function escapeHtml(str) {
    if (!str) return '';
    return $('<div>').text(str).html();
  }
});
</script>
</body>
</html>

