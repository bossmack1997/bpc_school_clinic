<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$pageTitle = 'User Management';

$roleFilter = $_GET['role'] ?? 'all';

$where = '';
$params = [];
if ($roleFilter === 'student') {
    $where = "WHERE u.role = 'student'";
} elseif ($roleFilter === 'nurse') {
    $where = "WHERE u.role = 'nurse'";
}

$users = $db->prepare("
  SELECT u.*, s.student_number, n.employee_id, n.license_number
  FROM users u
  LEFT JOIN students s ON s.user_id = u.id
  LEFT JOIN nurses n ON n.user_id = u.id
  $where
  ORDER BY u.created_at DESC
");
$users->execute($params);
$users = $users->fetchAll();
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
        <h6 class="section-title mb-0">User Management</h6>
        <div class="btn-group btn-group-sm">
          <a href="?role=all" class="btn btn-outline-primary <?= $roleFilter === 'all' ? 'active' : '' ?>">All</a>
          <a href="?role=student" class="btn btn-outline-primary <?= $roleFilter === 'student' ? 'active' : '' ?>">Students</a>
          <a href="?role=nurse" class="btn btn-outline-primary <?= $roleFilter === 'nurse' ? 'active' : '' ?>">Nurses</a>
        </div>
      </div>
      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="usersTable" style="width:100%">
            <thead><tr><th>Name</th><th>Role</th><th>Identifier</th><th>Email</th><th>Status</th><th>Registered</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($users as $u): ?>
              <tr>
                <td><?= e($u['first_name'] . ' ' . $u['last_name']) ?></td>
                <td><span class="badge bg-info"><?= ucfirst($u['role']) ?></span></td>
                <td><?= e($u['student_number'] ?? $u['employee_id'] ?? '—') ?></td>
                <td><?= e($u['email']) ?></td>
                <td>
                  <?php if ($u['status'] === 'active'): ?>
                    <span class="badge bg-success">Active</span>
                  <?php elseif ($u['status'] === 'pending'): ?>
                    <span class="badge bg-warning text-dark">Pending</span>
                  <?php else: ?>
                    <span class="badge bg-danger">Inactive</span>
                  <?php endif; ?>
                </td>
                <td><?= format_date($u['created_at']) ?></td>
                <td>
                  <?php if ($u['status'] === 'pending'): ?>
                    <button class="btn btn-sm btn-success approve-btn" data-id="<?= $u['id'] ?>">Approve</button>
                    <button class="btn btn-sm btn-danger reject-btn" data-id="<?= $u['id'] ?>">Reject</button>
                  <?php endif; ?>
                  <button class="btn btn-sm btn-outline-danger deactivate-btn" data-id="<?= $u['id'] ?>" data-role="<?= $u['role'] ?>">Deactivate</button>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$users): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">No users found</td></tr>
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

$('.approve-btn').on('click', function () {
  const id = $(this).data('id');
  confirmAction('Approve this account? The user will be able to log in.', function () {
    $.post(APP_URL + '/api/users.php', { action: 'approve', id, csrf_token: csrfToken }, function (res) {
      if (res.success) { toastSuccess(res.message); location.reload(); }
      else toastError(res.message);
    }, 'json');
  });
});

$('.reject-btn').on('click', function () {
  const id = $(this).data('id');
  confirmAction('Reject this account? The user will not be able to log in.', function () {
    $.post(APP_URL + '/api/users.php', { action: 'reject', id, csrf_token: csrfToken }, function (res) {
      if (res.success) { toastSuccess(res.message); location.reload(); }
      else toastError(res.message);
    }, 'json');
  });
});

$('.deactivate-btn').on('click', function () {
  const id = $(this).data('id');
  const role = $(this).data('role');
  confirmAction('Deactivate this ' + role + ' account?', function () {
    $.post(APP_URL + '/api/users.php', { action: 'deactivate', id, csrf_token: csrfToken }, function (res) {
      if (res.success) { toastSuccess(res.message); location.reload(); }
      else toastError(res.message);
    }, 'json');
  });
});
</script>
</body>
</html>