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
  SELECT u.*, s.student_number, s.course, s.year_level, s.section
  FROM users u
  JOIN students s ON s.user_id = u.id
  $where
  ORDER BY u.created_at DESC
");
$stmt->execute($params);
$students = $stmt->fetchAll();
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
        <h6 class="section-title mb-0">Student Management</h6>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-sm btn-success" id="importStudentsBtn"><i class="bi bi-file-earmark-arrow-up me-1"></i> Import Students</button>
          <div class="input-group" style="width:300px;">
            <input type="text" id="searchInput" class="form-control" placeholder="Search students..." value="<?= e($search) ?>">
            <button class="btn btn-outline-primary" onclick="searchStudents()"><i class="bi bi-search"></i></button>
          </div>
        </div>
      </div>

      <!-- Import Students Modal -->
      <div class="modal fade" id="importStudentsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
          <div class="modal-content border-0 rounded-4">
            <div class="modal-header border-0 bg-success text-white rounded-top-4">
              <h5 class="modal-title"><i class="bi bi-people-fill me-2"></i> Import Students (CSV)</h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <p class="small text-muted">Upload a CSV file to bulk-register students. A permanent QR code is automatically generated for each successfully imported student.</p>
              <div class="alert alert-info small py-2">
                <strong>Required columns (in order):</strong>
                <code>Student Number, First Name, Last Name, Course, Year Level, Section, Email, Username, Password, Gender, Birthdate, Phone</code>
                <br>Password must be at least 8 characters.
              </div>
              <div class="mb-3">
                <label class="form-label fw-semibold">CSV File</label>
                <input type="file" class="form-control" id="importFile" accept=".csv,text/csv">
              </div>
              <div id="importResult" class="d-none">
                <hr>
                <h6 class="text-success"><i class="bi bi-clipboard-check me-1"></i> Import Report</h6>
                <div class="row g-2 text-center small">
                  <div class="col-3"><div class="border rounded p-2 bg-light"><div class="fw-bold fs-5" id="impTotal">0</div><div class="text-muted">Total</div></div></div>
                  <div class="col-3"><div class="border rounded p-2 bg-success-light"><div class="fw-bold fs-5 text-success" id="impInserted">0</div><div class="text-muted">Imported</div></div></div>
                  <div class="col-3"><div class="border rounded p-2 bg-warning-light"><div class="fw-bold fs-5 text-warning" id="impDuplicates">0</div><div class="text-muted">Skipped</div></div></div>
                  <div class="col-3"><div class="border rounded p-2 bg-danger-light"><div class="fw-bold fs-5 text-danger" id="impErrors">0</div><div class="text-muted">Errors</div></div></div>
                </div>
                <div id="importDetails" class="mt-2 small"></div>
              </div>
            </div>
            <div class="modal-footer border-0 justify-content-between">
              <button type="button" class="btn btn-outline-secondary" id="downloadTemplateBtn"><i class="bi bi-download me-1"></i> Download Template</button>
              <div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-success" id="importSubmitBtn"><i class="bi bi-upload me-1"></i> Import</button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="studentsTable" style="width:100%">
            <thead><tr><th>Name</th><th>Student No.</th><th>Course</th><th>Year</th><th>Section</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($students as $s): ?>
              <tr>
                <td><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                <td><?= e($s['student_number']) ?></td>
                <td><?= e($s['course'] ?? '—') ?></td>
                <td><?= e($s['year_level'] ?? '—') ?></td>
                <td><?= e($s['section'] ?? '—') ?></td>
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
                  <button class="btn btn-sm btn-outline-danger deactivate-btn" data-id="<?= $s['id'] ?>" data-role="student">Deactivate</button>
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

$('.deactivate-btn').on('click', function () {
  const id = $(this).data('id');
  confirmAction('Deactivate this student account?', function () {
    $.post(APP_URL + '/api/users.php', { action: 'deactivate', id, csrf_token: csrfToken }, function (res) {
      if (res.success) { toastSuccess(res.message); location.reload(); }
      else toastError(res.message);
    }, 'json');
  });
});

// ============================================================
// Import Students (CSV) — auto-generates QR codes
// ============================================================
$('#importStudentsBtn').on('click', function () {
  $('#importResult').addClass('d-none');
  $('#importFile').val('');
  new bootstrap.Modal(document.getElementById('importStudentsModal')).show();
});

// Download template
$('#downloadTemplateBtn').on('click', function () {
  const header = 'Student Number,First Name,Last Name,Course,Year Level,Section,Email,Username,Password,Gender,Birthdate,Phone';
  const sample = '2024-00001,Juan,Dela Cruz,BSIT,1,A,juan@bpc.edu.ph,juan2024,Password123,Male,2005-01-15,09171234567';
  const csv = header + '\n' + sample + '\n';
  const blob = new Blob([csv], { type: 'text/csv' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'student_import_template.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(a.href);
});

// Submit import
$('#importSubmitBtn').on('click', function () {
  const fileInput = document.getElementById('importFile');
  if (!fileInput.files.length) { toastError('Please choose a CSV file.'); return; }

  const fd = new FormData();
  fd.append('file', fileInput.files[0]);
  fd.append('action', 'import_students');
  fd.append('csrf_token', csrfToken);

  const $btn = $(this);
  $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Importing...');

  $.ajax({
    url: APP_URL + '/api/students_api.php',
    type: 'POST',
    data: fd,
    processData: false,
    contentType: false,
    dataType: 'json',
    success: function (res) {
      $btn.prop('disabled', false).html('<i class="bi bi-upload me-1"></i> Import');
      if (!res.success) { toastError(res.message); return; }

      $('#impTotal').text(res.total);
      $('#impInserted').text(res.inserted);
      $('#impDuplicates').text(res.duplicates);
      $('#impErrors').text((res.errors || []).length);
      $('#importResult').removeClass('d-none');

      let html = '';
      if (res.skipped && res.skipped.length) {
        html += '<div class="mt-2"><strong>Skipped:</strong><ul class="mb-0">' + res.skipped.slice(0, 10).map(s =>
          '<li>Line ' + s.line + ' (' + s.student + '): ' + escapeHtml(s.reason) + '</li>').join('') + '</ul></div>';
      }
      if (res.errors && res.errors.length) {
        html += '<div class="mt-2 text-danger"><strong>Errors:</strong><ul class="mb-0">' + res.errors.slice(0, 10).map(er =>
          '<li>Line ' + er.line + ': ' + escapeHtml(er.reason) + '</li>').join('') + '</ul></div>';
      }
      $('#importDetails').html(html);

      toastSuccess(res.message);
      // Refresh the table after a short delay
      setTimeout(function () { location.reload(); }, 1800);
    },
    error: function () {
      $btn.prop('disabled', false).html('<i class="bi bi-upload me-1"></i> Import');
      toastError('Upload failed.');
    },
  });
});
</script>
</body>
</html>