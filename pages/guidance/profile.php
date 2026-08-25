<?php
require_once __DIR__ . '/../../config/config.php';
require_role('guidance');
$db = getDB();
$u = current_user();

// Fetch additional staff info from teachers table or requests table
$staffInfo = null;
try {
    $stmt = $db->prepare("SELECT employee_id, department FROM teachers WHERE user_id = ? LIMIT 1");
    $stmt->execute([$u['id']]);
    $staffInfo = $stmt->fetch();
    if (!$staffInfo) {
        $stmt = $db->prepare("SELECT teacher_id_number AS employee_id FROM teacher_registration_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$u['id']]);
        $staffInfo = $stmt->fetch();
    }
} catch (Exception $e) {
    error_log('Profile staff info lookup error: ' . $e->getMessage());
}

$employeeId = $staffInfo['employee_id'] ?? '—';
$department = !empty($staffInfo['department']) ? $staffInfo['department'] : 'Guidance & Counseling Office';

// Fetch fresh user record from DB
$userStmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$u['id']]);
$userRecord = $userStmt->fetch() ?: $u;

$pageTitle = 'My Profile';
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
    <div class="content-area page-enter">

      <h6 class="section-title mb-3"><i class="bi bi-person-circle text-success me-1"></i> My Profile</h6>

      <!-- Profile Header Summary -->
      <div class="card p-3 mb-3">
        <div class="row g-3 align-items-center">
          <div class="col-auto text-center position-relative">
            <img src="<?= APP_URL . '/' . e($userRecord['profile_picture']) ?>" id="profileAvatar" onerror="this.src='https://via.placeholder.com/100/0B6B3A/ffffff?text=GC'" class="rounded-circle border" width="100" height="100" style="object-fit:cover">
            <label for="pictureInput" class="btn btn-sm btn-primary rounded-circle position-absolute bottom-0 end-0" style="padding: 4px 8px;" title="Upload Profile Picture">
              <i class="bi bi-camera-fill"></i>
            </label>
            <input type="file" id="pictureInput" class="d-none" accept="image/*">
          </div>
          <div class="col">
            <h5 class="fw-bold mb-1"><?= e($userRecord['first_name'] . ($userRecord['middle_name'] ? ' ' . $userRecord['middle_name'] : '') . ' ' . $userRecord['last_name']) ?></h5>
            <p class="text-muted small mb-1"><i class="bi bi-envelope me-1"></i> <?= e($userRecord['email']) ?></p>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
              <span class="badge bg-success"><i class="bi bi-heart-pulse-fill me-1"></i> Guidance Counselor</span>
              <span class="badge bg-primary-light text-primary"><i class="bi bi-building me-1"></i> <?= e($department) ?></span>
              <span class="badge bg-secondary"><i class="bi bi-person-badge me-1"></i> ID: <?= e($employeeId) ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <!-- Personal Information Edit Form -->
        <div class="col-lg-7">
          <div class="card p-3 mb-3">
            <h6 class="section-title mb-3"><i class="bi bi-person-vcard text-success me-1"></i> Personal Information</h6>
            <form id="infoForm">
              <div class="row g-2">
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">First Name *</label>
                  <input type="text" class="form-control" name="first_name" required value="<?= e($userRecord['first_name']) ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Middle Name</label>
                  <input type="text" class="form-control" name="middle_name" value="<?= e($userRecord['middle_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Last Name *</label>
                  <input type="text" class="form-control" name="last_name" required value="<?= e($userRecord['last_name']) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Email Address *</label>
                  <input type="email" class="form-control" name="email" required value="<?= e($userRecord['email']) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Contact Number</label>
                  <input type="text" class="form-control" name="phone" value="<?= e($userRecord['phone'] ?? '') ?>">
                </div>
              </div>
              <button class="btn btn-primary mt-3 w-100 fw-semibold" type="submit" id="saveInfoBtn">
                <i class="bi bi-check-lg me-1"></i> Save Personal Info
              </button>
            </form>
          </div>

          <!-- Password Change Form -->
          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-shield-lock text-success me-1"></i> Password Settings</h6>
            <form id="pwForm">
              <div class="row g-2">
                <div class="col-12">
                  <label class="form-label small fw-semibold">Current Password *</label>
                  <input type="password" class="form-control" name="current_password" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">New Password *</label>
                  <input type="password" class="form-control" name="new_password" minlength="8" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Confirm New Password *</label>
                  <input type="password" class="form-control" name="confirm_password" minlength="8" required>
                </div>
              </div>
              <button class="btn btn-outline-primary mt-3 w-100 fw-semibold" type="submit" id="savePwBtn">
                <i class="bi bi-key me-1"></i> Update Password
              </button>
            </form>
          </div>
        </div>

        <!-- Professional Information & Scope -->
        <div class="col-lg-5">
          <div class="card p-3 mb-3">
            <h6 class="section-title mb-3"><i class="bi bi-briefcase-fill text-success me-1"></i> Professional Information</h6>
            <div class="row g-2 small">
              <div class="col-6"><div class="text-muted">Staff / Employee ID</div><div class="fw-bold text-dark"><?= e($employeeId) ?></div></div>
              <div class="col-6"><div class="text-muted">Role</div><div class="fw-bold text-success">Guidance Counselor</div></div>
              <div class="col-12"><div class="text-muted">Department / Office</div><div class="fw-bold text-dark"><?= e($department) ?></div></div>
              <div class="col-6"><div class="text-muted">Account Status</div><div><span class="badge bg-success">Active</span></div></div>
              <div class="col-6"><div class="text-muted">Username</div><div class="fw-bold text-dark"><?= e($userRecord['username']) ?></div></div>
            </div>
            <div class="alert alert-light border mt-3 mb-0 py-2 small">
              <i class="bi bi-info-circle text-primary me-1"></i>
              <span>Role, Employee ID, and Department are server-managed by school administration and cannot be self-modified.</span>
            </div>
          </div>

          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-shield-check text-success me-1"></i> Role Permissions</h6>
            <ul class="small mb-0 text-muted ps-3">
              <li class="mb-1">Receive &amp; process student support referrals from faculty and clinic staff.</li>
              <li class="mb-1">Coordinate student well-being and update referral statuses.</li>
              <li class="mb-1">Access clinic announcements and system notifications.</li>
              <li class="mb-1">View student health records only when authorized for support.</li>
              <li>Medical prescribing, inventory management, and admin settings remain disallowed.</li>
            </ul>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
(function () {
  'use strict';
  const csrfToken = $('meta[name="csrf-token"]').attr('content');

  // Personal Info Form Submit
  $('#infoForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $('#saveInfoBtn');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    const data = $(this).serialize() + '&action=update_info&csrf_token=' + csrfToken;
    $.post(APP_URL + '/api/profile.php', data, function (res) {
      btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> Save Personal Info');
      if (res.success) {
        toastSuccess(res.message);
        setTimeout(function () { location.reload(); }, 1000);
      } else {
        toastError(res.message);
      }
    }, 'json').fail(function () {
      btn.prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i> Save Personal Info');
      toastError('Failed to update profile.');
    });
  });

  // Profile Picture Upload
  $('#pictureInput').on('change', function () {
    if (!this.files.length) return;
    const file = this.files[0];
    const fd = new FormData();
    fd.append('picture', file);
    fd.append('action', 'upload_picture');
    fd.append('csrf_token', csrfToken);

    toastSuccess('Uploading picture...');
    $.ajax({
      url: APP_URL + '/api/profile.php',
      type: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json',
      success: function (res) {
        if (res.success) {
          toastSuccess(res.message);
          $('#profileAvatar').attr('src', res.path);
          setTimeout(function () { location.reload(); }, 1000);
        } else {
          toastError(res.message);
        }
      },
      error: function () {
        toastError('Failed to upload profile picture.');
      }
    });
  });

  // Password Form Submit
  $('#pwForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $('#savePwBtn');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Updating...');

    const fd = new FormData(this);
    fd.append('action', 'change_password');
    fd.append('csrf_token', csrfToken);

    $.ajax({
      url: APP_URL + '/api/profile.php',
      type: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json',
      success: function (res) {
        btn.prop('disabled', false).html('<i class="bi bi-key me-1"></i> Update Password');
        if (res.success) {
          toastSuccess(res.message);
          document.getElementById('pwForm').reset();
        } else {
          toastError(res.message);
        }
      },
      error: function () {
        btn.prop('disabled', false).html('<i class="bi bi-key me-1"></i> Update Password');
        toastError('Failed to update password.');
      }
    });
  });
})();
</script>
</body>
</html>