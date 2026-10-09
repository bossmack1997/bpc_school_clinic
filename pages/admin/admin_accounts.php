<?php
/**
 * pages/admin/admin_accounts.php
 * =====================================================================
 * Admin Accounts Management
 * Create and manage administrator accounts from the Admin Dashboard only.
 * Only Primary Administrators can create/modify admin accounts.
 * Public registration never exposes the Admin role.
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
require_login();

$db = getDB();
$u = current_user();

// Permission gate: only Primary Administrator can manage admin accounts
if (!is_primary_admin($u)) {
    set_flash('danger', 'Access denied: Only the Primary Administrator can manage admin accounts.');
    redirect(APP_URL . '/pages/admin/dashboard.php');
}

$pageTitle = 'Admin Accounts';

$errors = [];
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_admin') {
        $firstName     = trim($_POST['first_name'] ?? '');
        $middleName    = trim($_POST['middle_name'] ?? '');
        $lastName      = trim($_POST['last_name'] ?? '');
        $email         = strtolower(trim($_POST['email'] ?? ''));
        $username      = trim($_POST['username'] ?? '');
        $password      = $_POST['password'] ?? '';
        $confirmPass   = $_POST['confirm_password'] ?? '';
        $contact       = trim($_POST['contact_number'] ?? '');
        $status        = trim($_POST['status'] ?? 'active');

        if ($firstName === '' || $lastName === '') {
            $errors[] = 'First name and Last name are required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3-50 characters (letters, numbers, underscores only).';
        }
        if ($password === '') {
            $errors[] = 'Password is required.';
        } elseif ($password !== $confirmPass) {
            $errors[] = 'Passwords do not match.';
        } else {
            $pwErrors = validate_password_strength($password);
            if (!empty($pwErrors)) {
                $errors = array_merge($errors, $pwErrors);
            }
        }
        if (!in_array($status, ['active', 'inactive', 'pending'], true)) {
            $status = 'active';
        }

        if (!$errors) {
            $chk = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $chk->execute([$username, $email]);
            if ($chk->fetch()) {
                $errors[] = 'This account already exists.';
            }
        }

        if (!$errors) {
            try {
                $db->beginTransaction();
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $db->prepare("
                    INSERT INTO users (
                        role, admin_type, username, email, password_hash,
                        first_name, middle_name, last_name, phone,
                        profile_picture, status, email_verified,
                        registration_status, validation_reason, validation_confidence, risk_level
                    ) VALUES (
                        'admin', 'co_admin', ?, ?, ?,
                        ?, ?, ?, ?,
                        'assets/images/default-avatar.png', ?, 1,
                        'approved', 'Administrator account created by Primary Administrator', 1.00, 'low'
                    )
                ");
                $stmt->execute([
                    $username,
                    $email,
                    $hash,
                    $firstName,
                    $middleName ?: null,
                    $lastName,
                    $contact ?: null,
                    $status,
                ]);
                $newId = (int)$db->lastInsertId();

                log_activity($u['id'], 'Created administrator account', 'admin',
                    "Admin account #$newId created for {$firstName} {$lastName} (@{$username})");

                $db->commit();
                set_flash('success', "Administrator account for {$firstName} {$lastName} created successfully.");
                redirect('admin_accounts.php');
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Admin account creation error: ' . $e->getMessage());
                $errors[] = safe_error_message(
                    $e,
                    'Failed to create the administrator account. Please check the details and try again.',
                    'admin_accounts/create'
                );
            }
        }
    }

    if ($action === 'update_status') {
        $id = (int)($_POST['user_id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? '');

        if ($id > 0 && $id !== (int)$u['id']) {
            $stmt = $db->prepare("SELECT role, admin_type FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $target = $stmt->fetch();

            if ($target && $target['role'] === 'admin') {
                if ($target['admin_type'] === 'primary_admin') {
                    $errors[] = 'The Primary Administrator account cannot be modified.';
                } elseif (in_array($newStatus, ['active', 'inactive', 'pending'], true)) {
                    $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
                    log_activity($u['id'], 'Updated admin account status', 'admin',
                        "Admin ID $id status set to {$newStatus}");
                    set_flash('success', 'Admin account status updated.');
                    redirect('admin_accounts.php');
                }
            }
        }
    }
}

// Fetch all admin accounts
$stmt = $db->query("
    SELECT id, role, admin_type, username, email, first_name, middle_name, last_name, phone,
           status, created_at, last_login
    FROM users
    WHERE role = 'admin'
    ORDER BY admin_type DESC, created_at DESC
    LIMIT 500
");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../../includes/head.php'; ?>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter fade-in">

      <!-- Flash messages -->
      <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?> py-2 mb-3 d-flex align-items-center gap-2">
          <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-3 d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
              <li><?= e($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- Hero / Header -->
      <div class="app-page-head">
        <div class="app-page-head__text">
          <div class="app-page-head__eyebrow">System</div>
          <h2 class="app-page-head__title">Admin Accounts</h2>
          <p class="app-page-head__sub">Manage authorized administrators who can access the BPC School Clinic system.</p>
        </div>
        <div class="app-page-head__aside">
          <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal">
            <i class="bi bi-plus-lg me-1"></i> Add Admin
          </button>
        </div>
      </div>

      <!-- Summary Stats -->
      <div class="app-stats app-stats--4 mb-4">
        <div class="dbg-stat dbg-stat--green">
          <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-shield-lock"></i></div></div>
          <div class="dbg-stat__value"><?= count($admins) ?></div>
          <div class="dbg-stat__label">Total Admins</div>
        </div>
        <div class="dbg-stat dbg-stat--teal">
          <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-person-check"></i></div></div>
          <div class="dbg-stat__value"><?= count(array_filter($admins, fn($a) => $a['status'] === 'active')) ?></div>
          <div class="dbg-stat__label">Active</div>
        </div>
        <div class="dbg-stat dbg-stat--orange">
          <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-person-x"></i></div></div>
          <div class="dbg-stat__value"><?= count(array_filter($admins, fn($a) => $a['status'] === 'inactive')) ?></div>
          <div class="dbg-stat__label">Inactive</div>
        </div>
        <div class="dbg-stat dbg-stat--blue">
          <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-star"></i></div></div>
          <div class="dbg-stat__value"><?= count(array_filter($admins, fn($a) => $a['admin_type'] === 'primary_admin')) ?></div>
          <div class="dbg-stat__label">Primary Admin</div>
        </div>
      </div>

      <!-- Admin Accounts Table -->
      <div class="app-card">
        <div class="app-card__body app-card__body--flush">
          <div class="table-responsive">
            <table class="table mb-0" id="adminAccountsTable">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email / Username</th>
                  <th>Role</th>
                  <th>Status</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                  </tr>
            </thead>
            <tbody>
              <?php if (empty($admins)): ?>
                <tr>
                  <td colspan="6">
                    <div class="empty-state">
                      <div class="empty-state__icon"><i class="bi bi-shield-lock"></i></div>
                      <h6 class="empty-state__title">No administrator accounts found</h6>
                      <p class="empty-state__text">Add an administrator to grant another person access to clinic settings.</p>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($admins as $admin): ?>
                <tr>
                  <td>
                    <div class="fw-medium"><?= e($admin['first_name'] . ' ' . ($admin['middle_name'] ? $admin['middle_name'] . ' ' : '') . $admin['last_name']) ?></div>
                  </td>
                  <td>
                    <div class="small"><?= e($admin['email']) ?></div>
                    <div class="text-muted" style="font-size:0.72rem;">@<?= e($admin['username']) ?></div>
                  </td>
                  <td>
                    <?php if ($admin['admin_type'] === 'primary_admin'): ?>
                      <span class="app-badge app-badge--primary"><i class="bi bi-star-fill me-1"></i> Primary Admin</span>
                    <?php else: ?>
                      <span class="app-badge app-badge--info"><i class="bi bi-person-badge me-1"></i> Co-Admin</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="app-badge app-badge--<?= $admin['status'] === 'active' ? 'success' : 'neutral' ?>"><?= e(ucfirst($admin['status'])) ?></span>
                  </td>
                  <td>
                    <div class="small"><?= e(format_date($admin['created_at'])) ?></div>
                  </td>
                  <td class="text-end pe-2">
                    <?php if ($admin['admin_type'] !== 'primary_admin' && (int)$admin['id'] !== (int)$u['id']): ?>
                      <div class="btn-group" role="group">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                onclick="openStatusModal(<?= $admin['id'] ?>, '<?= e(addslashes($admin['first_name'] . ' ' . $admin['last_name'])) ?>', '<?= e($admin['status']) ?>')">
                          <i class="bi bi-pencil"></i> Edit
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                onclick="openDeactivateModal(<?= $admin['id'] ?>, '<?= e(addslashes($admin['first_name'] . ' ' . $admin['last_name'])) ?>')">
                          <i class="bi bi-trash"></i> Disable
                        </button>
                      </div>
                    <?php elseif ($admin['admin_type'] === 'primary_admin'): ?>
                      <span class="text-muted small"><i class="bi bi-lock me-1"></i> Protected</span>
                    <?php else: ?>
                      <span class="text-muted small"><i class="bi bi-person-check me-1"></i> Current Session</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Add Admin Modal -->
<div class="modal fade" id="addAdminModal" tabindex="-1" aria-hidden="true" aria-labelledby="addAdminModalTitle">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <form method="POST" id="addAdminForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_admin">
        <div class="modal-header border-0" style="background: linear-gradient(135deg, rgba(11, 107, 58, 0.05), rgba(11, 107, 58, 0.03));">
          <h6 class="modal-title fw-semibold" id="addAdminModalTitle"><i class="bi bi-person-plus-fill me-1 text-primary"></i> Add Administrator</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">Create a new Administrator account with full System Administrator privileges. The account is created immediately and can log in using the normal login page.</p>

          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" class="form-control form-control-sm" required maxlength="80" placeholder="First name">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" class="form-control form-control-sm" required maxlength="80" placeholder="Last name">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Middle Name</label>
              <input type="text" name="middle_name" class="form-control form-control-sm" maxlength="80" placeholder="Optional">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Email Address <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control form-control-sm" required placeholder="admin@bpc.edu.ph">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Username <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control form-control-sm" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_]+" placeholder="admin_username">
              <div class="form-text small">Letters, numbers, underscores (3-50 chars).</div>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Contact Number</label>
              <input type="text" name="contact_number" class="form-control form-control-sm" maxlength="30" placeholder="e.g. 09123456789">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Password <span class="text-danger">*</span></label>
              <input type="password" name="password" class="form-control form-control-sm" required minlength="8" placeholder="Min. 8 characters">
              <div class="form-text small">Must include uppercase, lowercase, number, and special character.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Confirm Password <span class="text-danger">*</span></label>
              <input type="password" name="confirm_password" class="form-control form-control-sm" required minlength="8" placeholder="Re-enter password">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Status</label>
              <select name="status" class="form-select form-select-sm">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="pending">Pending</option>
              </select>
              <div class="form-text small">Role is automatically set to Administrator. Password is never displayed.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-success">Create Administrator</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true" aria-labelledby="statusModalTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="user_id" id="statusUserId">
        <input type="hidden" name="new_status" id="statusNewValue">
<div class="modal-header">
            <h6 class="modal-title" id="statusModalTitle"><?= bpc_icon('edit') ?> Update Admin Status</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>You are updating the status of <strong id="statusUserName">&mdash;</strong>.</p>
          <label class="form-label small fw-semibold">New Status:</label>
          <select name="new_status" id="statusSelect" class="form-select form-select-sm">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="pending">Pending</option>
          </select>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary">Update Status</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Deactivate Modal -->
<div class="modal fade" id="deactivateModal" tabindex="-1" aria-hidden="true" aria-labelledby="deactivateModalTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="user_id" id="deactivateUserId">
        <input type="hidden" name="new_status" value="inactive">
<div class="modal-header">
            <h6 class="modal-title" id="deactivateModalTitle"><?= bpc_icon('user-x') ?> Disable Administrator</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Disabling <strong id="deactivateUserName">&mdash;</strong> will prevent this administrator from logging in.</p>
          <p class="text-muted small mb-0">Their account record is preserved. Re-enable from this page to restore access.</p>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-danger">Disable Admin</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
(function () {
  'use strict';

  // Password confirmation validation on the Add Admin form
  const addForm = document.getElementById('addAdminForm');
  if (addForm) {
    addForm.addEventListener('submit', function (e) {
      const pw = addForm.querySelector('input[name="password"]').value;
      const confirm = addForm.querySelector('input[name="confirm_password"]').value;

      if (pw !== confirm) {
        e.preventDefault();
        e.stopPropagation();
        alert('Passwords do not match.');
        return false;
      }

      if (pw.length < 8) {
        e.preventDefault();
        e.stopPropagation();
        alert('Password must be at least 8 characters.');
        return false;
      }

      // Check password complexity
      if (!/[A-Z]/.test(pw) || !/[a-z]/.test(pw) || !/[0-9]/.test(pw) || !/[^\w\s]/.test(pw)) {
        e.preventDefault();
        e.stopPropagation();
        alert('Password must contain uppercase, lowercase, number, and special character.');
        return false;
      }

      const btn = this.querySelector('button[type="submit"]');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating...';
    });
  }

  window.openStatusModal = function (id, name, currentStatus) {
    document.getElementById('statusUserId').value = id;
    document.getElementById('statusUserName').textContent = name;
    document.getElementById('statusSelect').value = currentStatus || 'active';
    BPCModal.show('statusModal');
  };

  window.openDeactivateModal = function (id, name) {
    document.getElementById('deactivateUserId').value = id;
    document.getElementById('deactivateUserName').textContent = name;
    BPCModal.show('deactivateModal');
  };
})();
</script>
</body>
</html>