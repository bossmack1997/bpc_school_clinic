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
require_primary_admin();
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

    if ($action === 'invite_doctor') {
        $firstName     = trim($_POST['first_name'] ?? '');
        $middleName    = trim($_POST['middle_name'] ?? '');
        $lastName      = trim($_POST['last_name'] ?? '');
        $email         = strtolower(trim($_POST['email'] ?? ''));
        $username      = trim($_POST['username'] ?? '');
        $contact       = trim($_POST['contact_number'] ?? '');
        $designation   = trim($_POST['official_designation'] ?? '');
        $license       = trim($_POST['professional_license'] ?? '');
        $appointment   = trim($_POST['appointment_reference'] ?? '');
        $verification  = trim($_POST['verification_status'] ?? 'pending');

        if ($firstName === '' || $lastName === '') {
            $errors[] = 'First and last name are required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            $errors[] = 'Username must be 3-50 characters (letters, numbers, underscores only).';
        }
        if ($designation === '') $errors[] = 'Professional designation is required.';
        if ($appointment === '') $errors[] = 'Appointment reference is required.';
        if (!in_array($verification, ['pending', 'verified'], true)) $errors[] = 'Select a valid appointment verification status.';

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
                $temporarySecret = bin2hex(random_bytes(32));
                $hash = password_hash($temporarySecret, PASSWORD_DEFAULT);
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiresAt = date('Y-m-d H:i:s', time() + 86400);

                $stmt = $db->prepare("
                    INSERT INTO users (
                        role, admin_type, username, email, password_hash,
                        first_name, middle_name, last_name, phone,
                        profile_picture, status, email_verified,
                        registration_status, validation_reason, validation_confidence, risk_level
                    ) VALUES (
                        'admin', 'co_admin', ?, ?, ?,
                        ?, ?, ?, ?,
                        'assets/images/default-avatar.png', 'pending', 0,
                        ?, ?, ?, 'low'
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
                    $verification === 'verified' ? 'approved' : 'review',
                    $verification === 'verified' ? 'Doctor appointment verified; secure password setup pending' : 'Doctor appointment verification and secure password setup pending',
                    $verification === 'verified' ? 1.00 : 0.50,
                ]);
                $newId = (int)$db->lastInsertId();

                $profile = $db->prepare("INSERT INTO admin_profiles (user_id,profile,official_designation,appointment_reference,clinical_registration,verified_by,verified_at) VALUES (?,'doctor',?,?,?,?,NOW())");
                $profile->execute([$newId, $designation, $appointment, $license ?: null, $u['id']]);
                $invite = $db->prepare("INSERT INTO admin_invitations (user_id,token_hash,created_by,expires_at) VALUES (?,?,?,?)");
                $invite->execute([$newId, $tokenHash, $u['id'], $expiresAt]);
                $invitationId = (int)$db->lastInsertId();

                log_activity($u['id'], 'Created Doctor account invitation', 'admin_profiles',
                    "Doctor account #$newId provisioned; appointment reference recorded; password not set by MIS");

                $db->commit();
                unset($temporarySecret);

                $setupLink = APP_URL . '/auth/setup_account.php?token=' . rawurlencode($token);
                $mailError = null;
                $sent = send_doctor_invitation_email($email, trim("$firstName $lastName"), $setupLink, $expiresAt, $appointment, $mailError);
                $delivery = $sent ? 'sent' : 'manual';
                $db->prepare('UPDATE admin_invitations SET delivery_status=?,delivery_error=? WHERE id=?')
                    ->execute([$delivery, $sent ? null : mb_substr((string)$mailError, 0, 255), $invitationId]);
                if ($sent) {
                    set_flash('success', "Secure account setup invitation sent to {$email}.");
                } else {
                    $_SESSION['manual_doctor_invitation'] = ['url'=>$setupLink, 'expires_at'=>$expiresAt, 'email'=>$email];
                    set_flash('warning', 'The account is pending, but email delivery was unavailable. Use the one-time authorized setup link shown below.');
                }
                redirect('admin_accounts.php');
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Admin account creation error: ' . $e->getMessage());
                $errors[] = safe_error_message(
                    $e,
                    'Failed to create the Doctor invitation. No partial account was retained.',
                    'admin_accounts/invite_doctor'
                );
            }
        }
    }

    if ($action === 'update_status') {
        http_response_code(410);
        $errors[]='Use Account Security Exceptions with a documented reason and evidence reference.';
    }

}

// Fetch all admin accounts
$stmt = $db->query("
    SELECT u.id, u.role, u.admin_type, u.username, u.email, u.first_name, u.middle_name, u.last_name, u.phone,
           u.status, u.created_at, u.last_login, p.profile, p.official_designation, p.appointment_reference,
           (SELECT ai.delivery_status FROM admin_invitations ai WHERE ai.user_id=u.id ORDER BY ai.id DESC LIMIT 1) invitation_status
    FROM users u
    LEFT JOIN admin_profiles p ON p.user_id=u.id
    WHERE u.role = 'admin'
    ORDER BY u.admin_type DESC, u.created_at DESC
    LIMIT 500
");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
$manualInvitation = $_SESSION['manual_doctor_invitation'] ?? null;
unset($_SESSION['manual_doctor_invitation']);
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

      <?php if ($manualInvitation): ?>
        <div class="alert alert-warning" role="status">
          <div class="fw-semibold mb-1">Authorized manual delivery required</div>
          <p class="small mb-2">Give this single-use link only to <?= e($manualInvitation['email']) ?> after independently verifying the appointee. It expires <?= e(format_date($manualInvitation['expires_at'], true)) ?>.</p>
          <input class="form-control form-control-sm" type="text" readonly value="<?= e($manualInvitation['url']) ?>" aria-label="One-time Doctor account setup link">
        </div>
      <?php endif; ?>

      <!-- Hero / Header -->
      <div class="app-page-head">
        <div class="app-page-head__text">
          <div class="app-page-head__eyebrow">System</div>
          <h2 class="app-page-head__title">Administrator Management</h2>
          <p class="app-page-head__sub">Manage MIS continuity and verified Doctor clinical appointments without sharing unrestricted privileges.</p>
        </div>
        <div class="app-page-head__aside">
          <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal">
            <i class="bi bi-plus-lg me-1"></i> Add Doctor / Clinical Co-Administrator
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
                  <th>Designation / Role</th>
                  <th>Verification</th>
                  <th>Status</th>
                    <th>Last Login</th>
                    <th class="text-end">Actions</th>
                  </tr>
            </thead>
            <tbody>
              <?php if (empty($admins)): ?>
                <tr>
                  <td colspan="7">
                    <div class="empty-state">
                      <div class="empty-state__icon"><i class="bi bi-shield-lock"></i></div>
                      <h6 class="empty-state__title">No administrator accounts found</h6>
                      <p class="empty-state__text">Administrative appointments will appear here.</p>
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
                      <div class="small fw-semibold"><?= e($admin['official_designation'] ?: 'MIS / IT Personnel') ?></div><span class="app-badge app-badge--primary"><i class="bi bi-star-fill me-1"></i> MIS Main Administrator</span>
                    <?php elseif (($admin['profile'] ?? '') === 'doctor'): ?>
                      <div class="small fw-semibold"><?= e($admin['official_designation'] ?: 'Doctor') ?></div><span class="app-badge app-badge--info"><i class="bi bi-heart-pulse me-1"></i> Clinical Co-Administrator</span>
                    <?php else: ?>
                      <span class="app-badge app-badge--neutral"><i class="bi bi-person-badge me-1"></i> Legacy Administrator</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="app-badge app-badge--<?= ($admin['profile'] ?? '') === 'doctor' && $admin['invitation_status'] === 'sent' ? 'info' : (($admin['profile'] ?? '') === 'mis' ? 'success' : 'neutral') ?>"><?= e(($admin['profile'] ?? '') === 'mis' ? 'Verified' : ucfirst($admin['invitation_status'] ?: 'Recorded')) ?></span></td>
                  <td>
                    <span class="app-badge app-badge--<?= $admin['status'] === 'active' ? 'success' : 'neutral' ?>"><?= e(ucfirst($admin['status'])) ?></span>
                  </td>
                  <td>
                    <div class="small"><?= $admin['last_login'] ? e(format_date($admin['last_login'], true)) : '<span class="text-muted">Never</span>' ?></div>
                  </td>
                  <td class="text-end pe-2">
                    <?php if ($admin['admin_type'] !== 'primary_admin' && (int)$admin['id'] !== (int)$u['id']): ?>
                      <a class="btn btn-sm btn-outline-secondary" href="account_exceptions.php">Security exception</a>
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
        <input type="hidden" name="action" value="invite_doctor">
        <div class="modal-header border-0" style="background: linear-gradient(135deg, rgba(11, 107, 58, 0.05), rgba(11, 107, 58, 0.03));">
          <h6 class="modal-title fw-semibold" id="addAdminModalTitle"><i class="bi bi-person-plus-fill me-1 text-primary"></i> Add Doctor / Clinical Co-Administrator</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">Provision a clinical-only account. The Doctor sets their own password through an expiring, single-use invitation; MIS never sees it.</p>

          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
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
              <label class="form-label small fw-semibold">Professional Designation <span class="text-danger">*</span></label>
              <input type="text" name="official_designation" class="form-control form-control-sm" required maxlength="150" placeholder="School Physician">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Professional License Information</label>
              <input type="text" name="professional_license" class="form-control form-control-sm" maxlength="150" placeholder="License / registration reference">
            </div>
            <div class="col-md-8">
              <label class="form-label small fw-semibold">Appointment Reference <span class="text-danger">*</span></label>
              <input type="text" name="appointment_reference" class="form-control form-control-sm" required maxlength="255" placeholder="Approved memorandum / board reference">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Verification Status</label>
              <select name="verification_status" class="form-select form-select-sm">
                <option value="pending">Pending review</option>
                <option value="verified">Verified appointment</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-success">Create Pending Invitation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
(function () {
  'use strict';

  const addForm = document.getElementById('addAdminForm');
  if (addForm) {
    addForm.addEventListener('submit', function (e) {
      const btn = this.querySelector('button[type="submit"]');
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating invitation...';
    });
  }

})();
</script>
</body>
</html>
