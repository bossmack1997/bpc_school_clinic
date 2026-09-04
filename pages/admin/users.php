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
} elseif ($roleFilter === 'admin') {
    $where = "WHERE u.role = 'admin'";
} elseif ($roleFilter === 'adviser') {
    $where = "WHERE u.role = 'adviser'";
}

$users = $db->prepare("
  SELECT u.*,
         s.student_number,
         COALESCE(s.valid_id_path, n.valid_id_path) AS valid_id_path,
         COALESCE(n.employee_id, t.employee_id, trr.teacher_id_number) AS employee_id,
         n.license_number
  FROM users u
  LEFT JOIN students s ON s.user_id = u.id
  LEFT JOIN nurses n ON n.user_id = u.id
  LEFT JOIN teachers t ON t.user_id = u.id
  LEFT JOIN teacher_registration_requests trr ON trr.user_id = u.id AND trr.status = 'pending'
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
    <div class="content-area page-enter">
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h6 class="section-title mb-0"><i class="bi bi-people-fill text-success me-1"></i> User Management</h6>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createNurseModal">
            <i class="bi bi-person-plus-fill me-1"></i> Create Nurse Account
          </button>
          <div class="btn-group btn-group-sm">
            <a href="?role=all" class="btn btn-outline-primary <?= $roleFilter === 'all' ? 'active' : '' ?>">All</a>
            <a href="?role=student" class="btn btn-outline-primary <?= $roleFilter === 'student' ? 'active' : '' ?>">Students</a>
            <a href="?role=nurse" class="btn btn-outline-primary <?= $roleFilter === 'nurse' ? 'active' : '' ?>">Nurses</a>
            <a href="?role=adviser" class="btn btn-outline-primary <?= $roleFilter === 'adviser' ? 'active' : '' ?>">Teachers / Advisers</a>
            <a href="?role=admin" class="btn btn-outline-primary <?= $roleFilter === 'admin' ? 'active' : '' ?>">Admins</a>
          </div>
        </div>
      </div>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <button class="btn btn-sm btn-success" id="bulkApproveBtn" disabled>Approve Selected</button>
      </div>
      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="usersTable" style="width:100%">
            <thead><tr><th><input type="checkbox" id="masterCheckbox"></th><th>Name</th><th>Role</th><th>Identifier</th><th>Email</th><th>Status</th><th>Registered</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($users as $u): ?>
              <tr>
                <td><?php if ($u['role'] !== 'admin'): ?><input type="checkbox" class="user-checkbox" value="<?= $u['id'] ?>"><?php endif; ?></td>
                <td><?= e($u['first_name'] . ' ' . $u['last_name']) ?></td>
                <td><span class="badge bg-info"><?= e(get_role_display_name($u['role'])) ?></span></td>
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
                  <?php if ($u['role'] === 'student'): ?>
                    <button class="btn btn-sm btn-outline-primary view-docs-btn ms-1" data-id="<?= $u['id'] ?>" data-name="<?= e($u['first_name'] . ' ' . $u['last_name']) ?>" data-pic="<?= e($u['profile_picture'] ?? '') ?>" data-id-doc="<?= e($u['valid_id_path'] ?? '') ?>">View Documents</button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$users): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">No users found</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Create Nurse Account Modal (Administrator Only) -->
<div class="modal fade" id="createNurseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <form id="createNurseForm" novalidate>
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="bi bi-person-badge-fill me-2"></i>Create New Nurse Account</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <p class="text-muted small mb-3">Nurse accounts are created with immediate active status to manage clinical operations.</p>
          <div class="row g-2 mb-3">
            <div class="col-md-4">
              <label class="form-label small fw-semibold">First Name *</label>
              <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Middle Name</label>
              <input type="text" name="middle_name" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Last Name *</label>
              <input type="text" name="last_name" class="form-control" required>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Employee / Nurse ID *</label>
              <input type="text" name="employee_id" class="form-control" placeholder="e.g. NURSE-2026-001" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">PRC License Number</label>
              <input type="text" name="license_number" class="form-control" placeholder="Optional">
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Gender *</label>
              <select name="gender" class="form-select" required>
                <option value="">Select</option>
                <option value="Female">Female</option>
                <option value="Male">Male</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Date of Birth</label>
              <input type="date" name="date_of_birth" class="form-control" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Contact Number</label>
              <input type="text" name="contact_number" class="form-control" placeholder="09171234567">
            </div>
          </div>
          <hr class="my-3">
          <h6 class="fw-semibold small text-muted mb-2"><i class="bi bi-shield-lock-fill me-1"></i> Login Credentials</h6>
          <div class="row g-2 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Email Address *</label>
              <input type="email" name="email" class="form-control" required placeholder="nurse@bpc.edu.ph">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Username *</label>
              <input type="text" name="username" class="form-control" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_]+" placeholder="nurse_username">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Initial Password *</label>
              <input type="password" name="password" id="nursePw" class="form-control" required minlength="8" placeholder="Min. 8 characters">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Confirm Password *</label>
              <input type="password" name="confirm_password" id="nurseConfirmPw" class="form-control" required minlength="8">
            </div>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="saveNurseBtn">Create Nurse Account</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Student Documents Modal -->
<div class="modal fade" id="studentDocsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Student Verification</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="studentDocsBody">
        <p class="text-muted">Loading...</p>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');

$('.view-docs-btn').on('click', function () {
  const name = $(this).data('name');
  const pic = $(this).data('pic');
  const idDoc = $(this).data('id-doc');
  const appUrl = APP_URL || window.APP_URL || '';

  let html = '<h6 class="fw-semibold mb-3">' + escapeHtml(name) + '</h6>';

  html += '<div class="mb-3"><strong>Profile Picture</strong><br>';
  if (pic && pic !== 'assets/images/default-avatar.png') {
    html += '<img src="' + appUrl + '/' + escapeHtml(pic) + '" class="img-fluid rounded border mt-2" style="max-height:200px;">';
  } else {
    html += '<span class="text-muted small">No custom profile picture uploaded.</span>';
  }
  html += '</div>';

  html += '<div><strong>Student ID / Valid ID</strong><br>';
  if (idDoc) {
    html += '<a href="' + appUrl + '/' + escapeHtml(idDoc) + '" target="_blank" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-file-earmark-text me-1"></i> View ID Document</a>';
  } else {
    html += '<span class="text-muted small">No ID document uploaded.</span>';
  }
  html += '</div>';

  $('#studentDocsBody').html(html);
  new bootstrap.Modal(document.getElementById('studentDocsModal')).show();
});

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

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

// Create Nurse Form Submission
$('#createNurseForm').on('submit', function (e) {
  e.preventDefault();
  const form = $(this);
  const pw = $('#nursePw').val();
  const confirmPw = $('#nurseConfirmPw').val();

  if (pw.length < 8) {
    alert('Password must be at least 8 characters.');
    return;
  }
  if (pw !== confirmPw) {
    alert('Passwords do not match.');
    return;
  }

  const btn = $('#saveNurseBtn');
  btn.prop('disabled', true).text('Creating...');

  const formData = form.serialize() + '&action=create_nurse&csrf_token=' + encodeURIComponent(csrfToken);

  $.post(APP_URL + '/api/users.php', formData, function (res) {
    btn.prop('disabled', false).text('Create Nurse Account');
    if (res.success) {
      toastSuccess(res.message);
      bootstrap.Modal.getInstance(document.getElementById('createNurseModal')).hide();
      form[0].reset();
      location.reload();
    } else {
      toastError(res.message);
    }
  }, 'json').fail(function () {
    btn.prop('disabled', false).text('Create Nurse Account');
    toastError('Failed to create nurse account. Please try again.');
  });
});

const masterCheckbox = document.getElementById('masterCheckbox');
const bulkApproveBtn = document.getElementById('bulkApproveBtn');

function updateMasterCheckbox() {
  const checkboxes = document.querySelectorAll('.user-checkbox');
  const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
  const totalCount = checkboxes.length;

  if (checkedCount === 0) {
    masterCheckbox.checked = false;
    masterCheckbox.indeterminate = false;
  } else if (checkedCount === totalCount) {
    masterCheckbox.checked = true;
    masterCheckbox.indeterminate = false;
  } else {
    masterCheckbox.checked = false;
    masterCheckbox.indeterminate = true;
  }

  bulkApproveBtn.disabled = checkedCount === 0;
}

masterCheckbox.addEventListener('change', function () {
  const checkboxes = document.querySelectorAll('.user-checkbox');
  checkboxes.forEach(function (cb) { cb.checked = masterCheckbox.checked; });
  updateMasterCheckbox();
});

document.querySelectorAll('.user-checkbox').forEach(function (cb) {
  cb.addEventListener('change', updateMasterCheckbox);
});

bulkApproveBtn.addEventListener('click', function () {
  const selectedIds = Array.from(document.querySelectorAll('.user-checkbox:checked')).map(function (cb) { return cb.value; });
  if (!selectedIds.length) return;

  confirmAction('Are you sure you want to approve the selected accounts?', function () {
    $.post(APP_URL + '/api/users.php', { action: 'bulk_approve', ids: selectedIds, csrf_token: csrfToken }, function (res) {
      if (res.success) {
        toastSuccess(res.message);
        location.reload();
      } else {
        toastError(res.message);
      }
    }, 'json');
  });
});

updateMasterCheckbox();
</script>
</body>
</html>