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
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="section-title mb-0">Student Management</h6>
        <div class="input-group" style="width:300px;">
          <input type="text" id="searchInput" class="form-control" placeholder="Search students..." value="<?= e($search) ?>">
          <button class="btn btn-outline-primary" onclick="searchStudents()"><i class="bi bi-search"></i></button>
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
</script>
</body>
</html>