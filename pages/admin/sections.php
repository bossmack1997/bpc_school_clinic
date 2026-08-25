<?php
/**
 * pages/admin/sections.php
 * ------------------------------------------------------------------
 * ADMIN Sections & Courses Management
 * Allows Administrator to:
 *  - Create & edit courses and sections
 *  - Assign year levels and school years
 *  - Activate / deactivate sections
 *  - Assign & manage advisers for each section
 *  - View enrolled students per section
 * ------------------------------------------------------------------
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$pageTitle = 'Sections & Courses Management';
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

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <h6 class="section-title mb-0"><i class="bi bi-grid-3x3-gap-fill text-success me-1"></i> Sections &amp; Courses Management</h6>
          <small class="text-muted">Create courses, configure sections, assign advisers, and monitor student enrollments.</small>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-success btn-sm fw-semibold" onclick="openCreateModal()"><i class="bi bi-plus-circle me-1"></i> Add New Section</button>
        </div>
      </div>

      <!-- Stats row -->
      <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
          <div class="card p-3 border-0 shadow-sm text-center">
            <div class="text-primary fs-3 mb-1"><i class="bi bi-layers-fill"></i></div>
            <div class="fw-bold fs-4" id="statTotalSections">0</div>
            <div class="small text-muted">Total Sections</div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card p-3 border-0 shadow-sm text-center">
            <div class="text-success fs-3 mb-1"><i class="bi bi-check-circle-fill"></i></div>
            <div class="fw-bold fs-4 text-success" id="statActiveSections">0</div>
            <div class="small text-muted">Active Sections</div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card p-3 border-0 shadow-sm text-center">
            <div class="text-info fs-3 mb-1"><i class="bi bi-person-video3"></i></div>
            <div class="fw-bold fs-4 text-info" id="statAssignedAdvisers">0</div>
            <div class="small text-muted">Assigned Advisers</div>
          </div>
        </div>
        <div class="col-sm-6 col-lg-3">
          <div class="card p-3 border-0 shadow-sm text-center">
            <div class="text-warning fs-3 mb-1"><i class="bi bi-people-fill"></i></div>
            <div class="fw-bold fs-4 text-warning" id="statTotalStudents">0</div>
            <div class="small text-muted">Enrolled Students</div>
          </div>
        </div>
      </div>

      <!-- Sections Table Card -->
      <div class="card p-3 shadow-sm">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="sectionsTable" style="width:100%">
            <thead>
              <tr>
                <th>Section</th>
                <th>Course</th>
                <th>Year Level</th>
                <th>School Year</th>
                <th>Assigned Adviser</th>
                <th>Enrolled</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr><td colspan="8" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Loading sections...</td></tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Add / Edit Section Modal -->
<div class="modal fade" id="sectionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="sectionModalTitle"><i class="bi bi-layers-fill me-2"></i> Add New Section</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="sectionForm">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="secId" value="">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Section Name *</label>
            <input type="text" name="name" id="secName" class="form-control" placeholder="e.g. BSIS 4A" required>
            <small class="text-muted">Unique section name or identifier.</small>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Course / Program</label>
              <input type="text" name="course" id="secCourse" class="form-control" placeholder="e.g. BSIS" list="courseSuggestions">
              <datalist id="courseSuggestions">
                <option value="BSIS">
                <option value="BSIT">
                <option value="BSOM">
                <option value="BSED">
                <option value="BEED">
                <option value="BSHM">
              </datalist>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Year Level</label>
              <select name="year_level" id="secYear" class="form-select">
                <option value="">Select Year Level</option>
                <option value="1st Year">1st Year</option>
                <option value="2nd Year">2nd Year</option>
                <option value="3rd Year">3rd Year</option>
                <option value="4th Year">4th Year</option>
              </select>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">School Year</label>
              <input type="text" name="school_year" id="secSy" class="form-control" placeholder="e.g. 2026-2027">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Semester</label>
              <select name="semester" id="secSem" class="form-select">
                <option value="">Select Semester</option>
                <option value="1st Semester">1st Semester</option>
                <option value="2nd Semester">2nd Semester</option>
                <option value="Summer">Summer</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Assign Adviser / Teacher</label>
            <select name="adviser_id" id="secAdviser" class="form-select">
              <option value="0">-- Unassigned --</option>
            </select>
          </div>
          <div class="mb-2" id="secStatusGroup">
            <label class="form-label small fw-semibold">Status</label>
            <select name="status" id="secStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success fw-semibold" id="secSaveBtn"><i class="bi bi-check-lg me-1"></i> Save Section</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Section Students Roster Modal -->
<div class="modal fade" id="sectionStudentsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="studentsModalTitle"><i class="bi bi-people-fill me-2"></i> Section Students</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
          <div>
            <strong id="modalSectionName" class="fs-6 text-dark"></strong>
            <div id="modalSectionMeta" class="small text-muted"></div>
          </div>
          <div id="modalSectionAdviser" class="badge bg-info text-dark p-2"></div>
        </div>
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
          <table class="table table-hover align-middle mb-0" id="modalStudentsTable">
            <thead>
              <tr><th>Student</th><th>Student No.</th><th>Course</th><th>Year</th><th>Contact</th></tr>
            </thead>
            <tbody>
              <tr><td colspan="5" class="text-center text-muted py-3">Loading students...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');
let ALL_SECTIONS = [], ALL_ADVISERS = [];

function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

function loadSections() {
  $.get(APP_URL + '/api/sections.php', { action: 'list' }, function (res) {
    if (!res.success) {
      toastError(res.message || 'Failed to load sections.');
      return;
    }
    ALL_SECTIONS = res.sections || [];
    ALL_ADVISERS = res.advisers || [];
    renderStats();
    renderAdviserDropdown();
    renderTable();
  }, 'json').fail(function () {
    toastError('Unable to connect to server.');
  });
}

function renderStats() {
  const total = ALL_SECTIONS.length;
  const active = ALL_SECTIONS.filter(s => s.status === 'active').length;
  const assigned = ALL_SECTIONS.filter(s => s.status === 'active' && s.teacher_id).length;
  const students = ALL_SECTIONS.reduce((sum, s) => sum + (parseInt(s.student_count) || 0), 0);

  $('#statTotalSections').text(total);
  $('#statActiveSections').text(active);
  $('#statAssignedAdvisers').text(assigned);
  $('#statTotalStudents').text(students);
}

function renderAdviserDropdown() {
  const $sel = $('#secAdviser').empty().append('<option value="0">-- Unassigned --</option>');
  ALL_ADVISERS.forEach(function (adv) {
    const name = esc(adv.first_name + ' ' + adv.last_name + (adv.employee_id ? ' (' + adv.employee_id + ')' : ''));
    $sel.append(`<option value="${adv.id}">${name}</option>`);
  });
}

function renderTable() {
  let html = '';
  ALL_SECTIONS.forEach(function (s) {
    const isAct = s.status === 'active';
    const statusBadge = isAct ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>';
    const adviserBadge = s.adviser_name ? `<span class="badge bg-info text-dark fw-medium"><i class="bi bi-person-check me-1"></i>${esc(s.adviser_name)}</span>` : '<span class="badge bg-light text-muted border">Unassigned</span>';
    const countBadge = `<span class="badge bg-primary-light text-primary fw-bold">${parseInt(s.student_count) || 0} students</span>`;

    html += `<tr>
      <td class="fw-bold">${esc(s.name)}</td>
      <td>${esc(s.course || '—')}</td>
      <td>${esc(s.year_level || '—')}</td>
      <td>${esc(s.school_year || '—')}${s.semester ? ' (' + esc(s.semester) + ')' : ''}</td>
      <td>${adviserBadge}</td>
      <td>
        <button class="btn btn-sm btn-link p-0 text-decoration-none view-roster-btn" data-id="${s.id}" data-name="${esc(s.name)}">
          ${countBadge}
        </button>
      </td>
      <td>${statusBadge}</td>
      <td>
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-primary edit-sec-btn" data-id="${s.id}" title="Edit Section"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-outline-info view-roster-btn" data-id="${s.id}" data-name="${esc(s.name)}" title="View Enrolled Students"><i class="bi bi-people"></i></button>
          <button class="btn btn-outline-${isAct ? 'warning' : 'success'} toggle-status-btn" data-id="${s.id}" title="${isAct ? 'Deactivate' : 'Activate'}">
            <i class="bi bi-${isAct ? 'pause-fill' : 'play-fill'}"></i>
          </button>
        </div>
      </td>
    </tr>`;
  });

  if (!ALL_SECTIONS.length) {
    html = '<tr><td colspan="8" class="text-center text-muted py-4">No sections found. Click "Add New Section" to create one.</td></tr>';
  }

  $('#sectionsTable tbody').html(html);
  if ($.fn.DataTable && window.__adminSecDt) { window.__adminSecDt.destroy(); }
  window.__adminSecDt = $('#sectionsTable').DataTable({ pageLength: 10, destroy: true });
}

function openCreateModal() {
  $('#sectionForm')[0].reset();
  $('#secId').val('');
  $('#secModalTitle').html('<i class="bi bi-plus-circle me-2"></i> Add New Section');
  $('#secStatusGroup').hide();
  $('#secAdviser').val('0');
  const modal = new bootstrap.Modal(document.getElementById('sectionModal'));
  modal.show();
}

$(document).on('click', '.edit-sec-btn', function () {
  const id = $(this).data('id');
  const sec = ALL_SECTIONS.find(x => parseInt(x.id) === parseInt(id));
  if (!sec) return;

  $('#secId').val(sec.id);
  $('#secName').val(sec.name);
  $('#secCourse').val(sec.course || '');
  $('#secYear').val(sec.year_level || '');
  $('#secSy').val(sec.school_year || '');
  $('#secSem').val(sec.semester || '');
  $('#secStatus').val(sec.status || 'active');
  $('#secAdviser').val(sec.teacher_id || '0');

  $('#secModalTitle').html('<i class="bi bi-pencil-square me-2"></i> Edit Section: ' + esc(sec.name));
  $('#secStatusGroup').show();
  const modal = new bootstrap.Modal(document.getElementById('sectionModal'));
  modal.show();
});

$('#sectionForm').on('submit', function (e) {
  e.preventDefault();
  const id = $('#secId').val();
  const action = id ? 'update' : 'create';
  const $btn = $('#secSaveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

  $.post(APP_URL + '/api/sections.php', $(this).serialize() + '&action=' + action + '&csrf_token=' + csrfToken, function (res) {
    if (res.success) {
      toastSuccess(res.message);
      bootstrap.Modal.getInstance(document.getElementById('sectionModal')).hide();
      loadSections();
    } else {
      toastError(res.message);
    }
  }, 'json').fail(function () {
    toastError('Server error occurred.');
  }).always(function () {
    $btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> Save Section');
  });
});

$(document).on('click', '.toggle-status-btn', function () {
  const id = $(this).data('id');
  confirmAction('Change the active status of this section?', function () {
    $.post(APP_URL + '/api/sections.php', { action: 'toggle_status', id: id, csrf_token: csrfToken }, function (res) {
      if (res.success) {
        toastSuccess(res.message);
        loadSections();
      } else {
        toastError(res.message);
      }
    }, 'json');
  });
});

$(document).on('click', '.view-roster-btn', function () {
  const id = $(this).data('id');
  const sec = ALL_SECTIONS.find(x => parseInt(x.id) === parseInt(id));
  if (!sec) return;

  $('#modalSectionName').text(sec.name);
  $('#modalSectionMeta').text((sec.course || 'Unspecified') + ' · ' + (sec.year_level || 'Unspecified') + (sec.school_year ? ' · SY ' + sec.school_year : ''));
  $('#modalSectionAdviser').text(sec.adviser_name ? 'Adviser: ' + sec.adviser_name : 'No Adviser Assigned');
  $('#modalStudentsTable tbody').html('<tr><td colspan="5" class="text-center py-3"><span class="spinner-border spinner-border-sm me-1"></span> Loading roster...</td></tr>');

  const modal = new bootstrap.Modal(document.getElementById('sectionStudentsModal'));
  modal.show();

  $.get(APP_URL + '/api/sections.php', { action: 'section_students', id: id }, function (res) {
    if (!res.success) {
      $('#modalStudentsTable tbody').html('<tr><td colspan="5" class="text-center text-danger py-3">Failed to load students.</td></tr>');
      return;
    }
    const list = res.students || [];
    if (!list.length) {
      $('#modalStudentsTable tbody').html('<tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-people fs-3 d-block mb-1"></i>No students enrolled in this section yet.</td></tr>');
      return;
    }
    let rows = '';
    list.forEach(function (s) {
      rows += `<tr>
        <td class="fw-medium">${esc(s.first_name + ' ' + s.last_name)}</td>
        <td>${esc(s.student_number)}</td>
        <td>${esc(s.course || '—')}</td>
        <td>${esc(s.year_level || '—')}</td>
        <td>${esc(s.phone || s.email || '—')}</td>
      </tr>`;
    });
    $('#modalStudentsTable tbody').html(rows);
  }, 'json');
});

loadSections();
</script>
</body>
</html>

