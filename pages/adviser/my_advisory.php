 <?php
/**
 * pages/adviser/my_advisory.php
 * ------------------------------------------------------------------
 * TEACHER self-service advisory class management.
 * Teacher selects an existing section (course → year level → section)
 * and sets it as their advisory class. Max 3 active assignments.
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../../config/config.php';
require_role('adviser');
$db = getDB();
$u = current_user();
$pageTitle = 'My Advisory Class';
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

      <div class="alert alert-light border d-flex align-items-center gap-2 mb-3" role="alert">
        <i class="bi bi-info-circle-fill text-primary"></i>
        <div class="small">Choose your advisory class from the school's official sections. You may hold up to <strong>3 active</strong> advisory classes. Your access to students updates immediately.</div>
      </div>

      <div class="row g-3">
        <!-- Current assignments -->
        <div class="col-lg-5">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3"><i class="bi bi-person-check-fill text-success me-1"></i> My Advisory Classes</h6>
            <div id="myAssignments"><div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span>Loading…</div></div>
          </div>
        </div>

        <!-- Section picker -->
        <div class="col-lg-7">
          <div class="card p-3 h-100">
            <h6 class="section-title mb-3"><i class="bi bi-plus-circle-fill text-success me-1"></i> Set an Advisory Class</h6>

            <div class="row g-2 mb-2">
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Course / Program</label>
                <select id="pickCourse" class="form-select form-select-sm"><option value="">Loading…</option></select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Year Level</label>
                <select id="pickYear" class="form-select form-select-sm" disabled><option value="">All</option></select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Section</label>
                <select id="pickSection" class="form-select form-select-sm" disabled><option value="">Select…</option></select>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <button class="btn btn-success btn-sm" id="setAdvisoryBtn" disabled><i class="bi bi-check-lg me-1"></i> Set as My Advisory Class</button>
              <span class="text-muted small" id="sectionHint"></span>
            </div>

            <hr>
            <h6 class="section-title mb-2"><i class="bi bi-list-ul text-success me-1"></i> All Available Sections</h6>
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle mb-0" id="sectionsTable" style="width:100%">
                <thead><tr><th>Section</th><th>Course</th><th>Year</th><th>School Year</th><th>Status</th><th></th></tr></thead>
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
const csrf = $('meta[name="csrf-token"]').attr('content');
let SECTIONS = [], TAKEN = {}, MAX_ACTIVE = 3;

function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

function loadAdvisory() {
  $.get(APP_URL + '/api/my_advisory.php', { action: 'list' }, function (res) {
    if (!res.success) { toastError(res.message || 'Failed to load.'); return; }
    SECTIONS = res.sections || [];
    TAKEN = res.taken || {};
    MAX_ACTIVE = res.max_active || 3;
    renderMine(res.assignments || []);
    renderPicker();
    renderTable();
  }, 'json').fail(function () { toastError('Unable to reach the server.'); });
}

function renderMine(assignments) {
  const $box = $('#myAssignments').empty();
  if (!assignments.length) {
    $box.html('<div class="text-center text-muted py-4"><i class="bi bi-inboxes fs-3 d-block mb-2"></i>No advisory class yet.<br><span class="small">Pick a section on the right to set one.</span></div>');
    return;
  }
  assignments.forEach(function (a) {
    const card = $(`
      <div class="border rounded-3 p-3 mb-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <div class="fw-bold">${esc(a.section_name)}</div>
          <div class="small text-muted">${esc(a.course || '—')} · ${esc(a.year_level || '—')}${a.school_year ? ' · SY ' + esc(a.school_year) : ''}</div>
          <div class="small text-muted">Since ${esc(a.start_date || a.created_at || '')}</div>
        </div>
        <button class="btn btn-sm btn-outline-danger remove-btn" data-id="${a.id}"><i class="bi bi-x-lg me-1"></i>Remove</button>
      </div>`);
    $box.append(card);
  });
}

function renderPicker() {
  const courses = [...new Set(SECTIONS.map(s => s.course || 'Unspecified'))].sort();
  const $c = $('#pickCourse').empty().append('<option value="">Select course…</option>');
  courses.forEach(c => $c.append(`<option value="${esc(c)}">${esc(c)}</option>`));
  $('#pickYear').prop('disabled', true).empty().append('<option value="">All</option>');
  $('#pickSection').prop('disabled', true).empty().append('<option value="">Select…</option>');
  $('#setAdvisoryBtn').prop('disabled', true);
  $('#sectionHint').text('');
}

function refreshYears() {
  const course = $('#pickCourse').val();
  const years = [...new Set(SECTIONS.filter(s => (s.course || 'Unspecified') === course).map(s => s.year_level || 'N/A'))];
  const $y = $('#pickYear').empty().append('<option value="">All</option>');
  years.forEach(y => $y.append(`<option value="${esc(y)}">${esc(y)}</option>`));
  $('#pickYear').prop('disabled', false);
  refreshSections();
}

function refreshSections() {
  const course = $('#pickCourse').val(), year = $('#pickYear').val();
  const list = SECTIONS.filter(s => (s.course || 'Unspecified') === course && (!year || (s.year_level || 'N/A') === year));
  const $s = $('#pickSection').empty().append('<option value="">Select…</option>');
  list.forEach(s => $s.append(`<option value="${s.id}">${esc(s.name)}</option>`));
  $('#pickSection').prop('disabled', false);
  $('#setAdvisoryBtn').prop('disabled', true);
  $('#sectionHint').text('');
}

function renderTable() {
  const rows = SECTIONS.map(function (s) {
    const mine = SECTIONS.some(x => x.id === s.id); // placeholder, replaced below
    return null;
  });
  let html = '';
  SECTIONS.forEach(function (s) {
    const takenBy = TAKEN[s.id] ? `<span class="badge bg-secondary">${esc(TAKEN[s.id])}</span>` : '<span class="badge bg-success">Available</span>';
    html += `<tr>
      <td class="fw-medium">${esc(s.name)}</td>
      <td>${esc(s.course || '—')}</td>
      <td>${esc(s.year_level || '—')}</td>
      <td>${esc(s.school_year || '—')}</td>
      <td>${takenBy}</td>
      <td><button class="btn btn-sm btn-outline-success pick-btn" data-id="${s.id}" data-name="${esc(s.name)}">Set</button></td>
    </tr>`;
  });
  if (!SECTIONS.length) html = '<tr><td colspan="6" class="text-center text-muted py-4">No sections available yet. Please contact the administrator.</td></tr>';
  $('#sectionsTable tbody').html(html);

  if ($.fn.DataTable && window.__secDt) { window.__secDt.destroy(); }
  window.__secDt = $('#sectionsTable').DataTable({ pageLength: 10, destroy: true });
}

$('#pickCourse').on('change', refreshYears);
$('#pickYear').on('change', refreshSections);
$('#pickSection').on('change', function () {
  const secId = $(this).val();
  if (!secId) {
    $('#setAdvisoryBtn').prop('disabled', true);
    $('#sectionHint').html('');
    return;
  }
  const takenBy = TAKEN[secId];
  const secName = $('#pickSection option:selected').text();
  if (takenBy) {
    $('#setAdvisoryBtn').prop('disabled', true);
    $('#sectionHint').html('<span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i> ' + esc(secName) + ' is already assigned to another adviser (' + esc(takenBy) + '). Please select another section.</span>');
  } else {
    $('#setAdvisoryBtn').prop('disabled', false);
    $('#sectionHint').html('<span class="text-success small"><i class="bi bi-check-circle-fill me-1"></i> ' + esc(secName) + ' is available. Ready to set as your advisory class.</span>');
  }
});

function setAdvisory(sectionId, sectionName) {
  if ((window.__mineCount || 0) >= MAX_ACTIVE) {
    toastError('You already have ' + MAX_ACTIVE + ' active advisory classes.');
    return;
  }
  confirmAction('Set "' + sectionName + '" as your advisory class?', function () {
    $.post(APP_URL + '/api/my_advisory.php', { action: 'set', section_id: sectionId, csrf_token: csrf }, function (res) {
      if (res.success) { toastSuccess(res.message); loadAdvisory(); }
      else toastError(res.message);
    }, 'json');
  });
}

$('#setAdvisoryBtn').on('click', function () {
  const id = $('#pickSection').val();
  if (!id) return;
  const name = $('#pickSection option:selected').text();
  setAdvisory(id, name);
});

$(document).on('click', '.pick-btn', function () {
  setAdvisory($(this).data('id'), $(this).data('name'));
});

$(document).on('click', '.remove-btn', function () {
  const id = $(this).data('id');
  confirmAction('Remove this advisory class? You will lose access to its students.', function () {
    $.post(APP_URL + '/api/my_advisory.php', { action: 'remove', assignment_id: id, csrf_token: csrf }, function (res) {
      if (res.success) { toastSuccess(res.message); loadAdvisory(); }
      else toastError(res.message);
    }, 'json');
  });
});

// Track my active count for client-side guard
const _origRenderMine = renderMine;
renderMine = function (assignments) {
  window.__mineCount = assignments.length;
  _origRenderMine(assignments);
};

loadAdvisory();
</script>
</body>
</html>
