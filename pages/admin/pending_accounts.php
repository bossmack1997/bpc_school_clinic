<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$pageTitle = 'Pending Accounts';

$pendingUsers = $db->query("
  SELECT u.*, s.student_number, n.employee_id, n.license_number
  FROM users u
  LEFT JOIN students s ON s.user_id = u.id
  LEFT JOIN nurses n ON n.user_id = u.id
  WHERE u.status = 'pending'
  ORDER BY u.created_at DESC
")->fetchAll();
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
      <div class="card p-3">
        <h6 class="section-title mb-3"><i class="bi bi-hourglass-split text-success me-1"></i> Pending Account Approvals</h6>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="pendingTable" style="width:100%">
            <thead><tr><th>Name</th><th>Role</th><th>Identifier</th><th>Email</th><th>Status</th><th>Registered</th><th>Action</th></tr></thead>
            <tbody>
              <?php foreach ($pendingUsers as $u): ?>
              <tr>
                <td><?= e($u['first_name'] . ' ' . $u['last_name']) ?></td>
                <td><span class="badge bg-info"><?= ucfirst($u['role']) ?></span></td>
                <td><?= e($u['student_number'] ?? $u['employee_id'] ?? '—') ?></td>
                <td><?= e($u['email']) ?></td>
                <td><span class="badge bg-warning text-dark">Pending</span></td>
                <td><?= format_date($u['created_at']) ?></td>
                <td>
                  <button class="btn btn-sm btn-success approve-btn" data-id="<?= $u['id'] ?>">Approve</button>
                  <button class="btn btn-sm btn-danger reject-btn" data-id="<?= $u['id'] ?>">Reject</button>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$pendingUsers): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">No pending accounts</td></tr>
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
</script>
</body>
</html>