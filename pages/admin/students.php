<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$pageTitle = 'Student Management';

$search = trim($_GET['search'] ?? '');
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE u.first_name LIKE ? OR u.last_name LIKE ? OR s.student_number LIKE ?";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
}

$stmt = $db->prepare("
  SELECT u.*, s.id AS student_id, s.student_number, s.course, s.year_level, s.section
  FROM users u
  JOIN students s ON s.user_id = u.id
  $where
  ORDER BY u.created_at DESC
");
$stmt->execute($params);
$students = $stmt->fetchAll();

// Get active sections for modal dropdown
$sectionsList = $db->query("SELECT name, course, year_level FROM sections WHERE status = 'active' ORDER BY course, name")->fetchAll();
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
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
          <h6 class="section-title mb-0"><i class="bi bi-people-fill text-success me-1"></i> Student Management</h6>
          <small class="text-muted">Manage student accounts, course assignments, and section enrollments.</small>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <div class="input-group" style="width:300px;">
            <input type="text" id="searchInput" class="form-control" placeholder="Search students..." value="<?= e($search) ?>">
            <button class="btn btn-outline-primary" onclick="searchStudents()"><i class="bi bi-search"></i></button>
          </div>
        </div>
      </div>
      <div class="card p-3 shadow-sm">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="studentsTable" style="width:100%">
            <thead><tr><th>Name</th><th>Student No.</th><th>Course</th><th>Year</th><th>Section</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($students as $s): ?>
              <tr>
                <td class="fw-medium"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                <td><?= e($s['student_number']) ?></td>
                <td><?= e($s['course'] ?? '—') ?></td>
                <td><?= e($s['year_level'] ?? '—') ?></td>
                <td><span class="badge bg-light text-dark border"><?= e($s['section'] ?? '—') ?></span></td>
                <td><?= e($s['email']) ?></td>
                <td>
                  <?php if ($s['status'] === 'active'): ?>
                    <span class="badge bg-success">Active</span>
                  <?php elseif ($s['status'] === 'pending'): ?>
                    <span class="badge bg-warning text-dark">Pending</span>
                  <?php else: ?>
                    <span class="badge bg-danger">Inactive</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary edit-enrollment-btn"
                            data-student-id="<?= $s['student_id'] ?>"
                            data-name="<?= e($s['first_name'] . ' ' . $s['last_name']) ?>"
                            data-student-no="<?= e($s['student_number']) ?>"
                            data-course="<?= e($s['course'] ?? '') ?>"
                            data-year="<?= e($s['year_level'] ?? '') ?>"
                            data-section="<?= e($s['section'] ?? '') ?>"
                            title="Edit Enrollment">
                      <i class="bi bi-pencil-square me-1"></i> Edit
                    </button>
                    <button class="btn btn-outline-danger deactivate-btn" data-id="<?= $s['id'] ?>" data-role="student" title="Deactivate Account">Deactivate</button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$students): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">No students found</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Edit Student Enrollment Modal -->
<div class="modal fade" id="enrollmentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="bi bi-mortarboard-fill me-2"></i> Edit Student Enrollment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="enrollmentForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_enrollment">
        <input type="hidden" name="student_id" id="editStudentId" value="">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-semibold">Student Name</label>
            <input type="text" id="editStudentName" class="form-control" readonly disabled>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Student Number *</label>
            <input type="text" name="student_number" id="editStudentNo" class="form-control" required>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Course / Program</label>
              <input type="text" name="course" id="editCourse" class="form-control" placeholder="e.g. BSIS" list="courseList">
              <datalist id="courseList">
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
              <select name="year_level" id="editYear" class="form-select">
                <option value="">Select Year</option>
                <option value="1st Year">1st Year</option>
                <option value="2nd Year">2nd Year</option>
                <option value="3rd Year">3rd Year</option>
                <option value="4th Year">4th Year</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Section</label>
            <input type="text" name="section" id="editSection" class="form-control" placeholder="e.g. A, B, 4A, or BSIS 4A" list="sectionsSuggest">
            <datalist id="sectionsSuggest">
              <?php foreach ($sectionsList as $sl): ?>
                <option value="<?= e($sl['name']) ?>"><?= e(($sl['course'] ? $sl['course'].' ' : '').$sl['name']) ?></option>
              <?php endforeach; ?>
            </datalist>
            <small class="text-muted">Enter section letter (e.g. B) or full section name (e.g. BSIS 4B).</small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success fw-semibold" id="saveEnrollmentBtn"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');

function searchStudents() {
  const q = $('#searchInput').val();
  window.location.href = '?search=' + encodeURIComponent(q);
}

$('#searchInput').on('keyup', function (e) {
  if (e.key === 'Enter') searchStudents();
});

$('.edit-enrollment-btn').on('click', function () {
  const $btn = $(this);
  $('#editStudentId').val($btn.data('student-id'));
  $('#editStudentName').val($btn.data('name'));
  $('#editStudentNo').val($btn.data('student-no'));
  $('#editCourse').val($btn.data('course'));
  $('#editYear').val($btn.data('year'));
  $('#editSection').val($btn.data('section'));
  const modal = new bootstrap.Modal(document.getElementById('enrollmentModal'));
  modal.show();
});

$('#enrollmentForm').on('submit', function (e) {
  e.preventDefault();
  const $btn = $('#saveEnrollmentBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
  $.post(APP_URL + '/api/students_api.php', $(this).serialize(), function (res) {
    if (res.success) {
      toastSuccess(res.message);
      setTimeout(function () { location.reload(); }, 600);
    } else {
      toastError(res.message);
    }
  }, 'json').fail(function () {
    toastError('Server error occurred.');
  }).always(function () {
    $btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> Save Changes');
  });
});

$('.deactivate-btn').on('click', function () {
  const id = $(this).data('id');
  confirmAction('Deactivate this student account?', function () {
    $.post(APP_URL + '/api/users.php', { action: 'deactivate', id, csrf_token: csrfToken }, function (res) {
      if (res.success) { toastSuccess(res.message); location.reload(); }
      else toastError(res.message);
    }, 'json');
  });
});
</script>
</body>
</html>