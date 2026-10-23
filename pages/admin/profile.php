<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$u = current_user();

// Fresh user record
$userStmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$u['id']]);
$userRecord = $userStmt->fetch() ?: $u;

$isDoctor = permission_profile($u, $db) === 'doctor';
$professional = [];
if($isDoctor){$q=$db->prepare('SELECT official_designation,appointment_reference,clinical_registration FROM admin_profiles WHERE user_id=?');$q->execute([$u['id']]);$professional=$q->fetch()?:[];}
$isDoctorSettings = $isDoctor
    && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'settings.php';
$pageTitle = $isDoctorSettings ? 'Account Settings' : 'My Profile';
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

      <!-- Profile Header Summary Card -->
      <div class="card p-3 mb-3">
        <div class="row g-3 align-items-center">
          <div class="col-auto text-center position-relative">
            <img src="<?= !empty($userRecord['profile_picture']) ? APP_URL . '/' . e($userRecord['profile_picture']) : asset_url('images/default-avatar.png') ?>" id="profileAvatar" onerror="this.src='<?= asset_url('images/default-avatar.png') ?>'" class="rounded-circle border" width="100" height="100" style="object-fit:cover" alt="Profile picture of <?= e($userRecord['first_name'] . ' ' . $userRecord['last_name']) ?>">
            <label for="pictureInput" class="btn btn-sm btn-primary rounded-circle position-absolute bottom-0 end-0" style="padding: 4px 8px;" title="Upload Profile Picture">
              <i class="bi bi-camera-fill"></i>
            </label>
            <input type="file" id="pictureInput" class="d-none" accept="image/*">
          </div>
          <div class="col">
            <h5 class="fw-bold mb-1"><?= e($userRecord['first_name'] . ($userRecord['middle_name'] ? ' ' . $userRecord['middle_name'] : '') . ' ' . $userRecord['last_name']) ?></h5>
            <p class="text-muted small mb-1"><i class="bi bi-envelope me-1"></i> <?= e($userRecord['email']) ?></p>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
              <span class="badge bg-success"><i class="bi bi-shield-lock-fill me-1"></i> <?= $isDoctor ? 'Doctor / Clinical Co-Administrator' : 'System Administrator' ?></span>
              <span class="badge bg-primary-light text-primary"><i class="bi bi-building me-1"></i> <?= $isDoctor ? 'Clinical Governance' : 'Clinic Administration' ?></span>
              <span class="badge bg-secondary"><i class="bi bi-person-badge me-1"></i> ID: <?= e($userRecord['username']) ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 align-items-stretch">
        <div class="col-lg-6">
          <section class="card p-4 h-100">
            <h2 class="h6 section-title mb-3"><i class="bi bi-briefcase-fill text-success me-1"></i> Professional Information</h2>
            <div class="row g-3 small">
              <div class="col-sm-6"><div class="text-muted">Role</div><div class="fw-bold text-success"><?= $isDoctor ? 'Doctor / Clinical Co-Administrator' : 'System Administrator' ?></div></div>
              <div class="col-sm-6"><div class="text-muted">Department</div><div class="fw-bold text-dark"><?= $isDoctor ? 'Clinical Services' : 'Clinic Management &amp; IT' ?></div></div>
              <div class="col-sm-6"><div class="text-muted">Account Status</div><span class="badge bg-success">Active</span></div>
              <div class="col-sm-6"><div class="text-muted">Username</div><div class="fw-bold text-dark"><?= e($userRecord['username']) ?></div></div>
            </div>
            <div class="alert alert-light border mt-4 mb-0 py-2 small">
              <i class="bi bi-info-circle text-primary me-1"></i>
              <?= $isDoctor ? 'Professional appointment details are verified by MIS. Clinical permissions do not include technical account administration.' : 'Administrator privileges and security policies are managed at the root system configuration level.' ?>
            </div>
          </section>
        </div>
        <div class="col-lg-6">
          <section class="card p-4 h-100">
            <h2 class="h6 section-title mb-3"><i class="bi bi-shield-check text-success me-1"></i> Role Permissions</h2>
            <?php if($isDoctor): ?>
            <dl class="small"><dt>Designation</dt><dd><?=e($professional['official_designation']??'')?></dd><dt>Appointment reference</dt><dd><?=e($professional['appointment_reference']??'')?></dd><dt>Professional registration</dt><dd><?=e($professional['clinical_registration']??'Not recorded')?></dd></dl>
            <p class="small text-muted mb-0">Clinical oversight and supported clinical governance functions. Nurse authorization is reserved for Phase 2.</p>
            <?php else: ?>
            <ul class="small mb-0 text-muted ps-3">
              <li class="mb-2">Manage user accounts, role access, and staff registrations.</li>
              <li class="mb-2">Access clinic performance analytics, reports, and logs.</li>
              <li class="mb-2">Configure QR scanner settings and system parameters.</li>
              <li class="mb-2">Publish and manage campus-wide clinic announcements.</li>
              <li>Monitor security audit trails, database backups, and activity.</li>
            </ul>
            <?php endif; ?>
          </section>
        </div>
      </div>

      <?php if ($isDoctorSettings): ?>
      <div class="row g-3 mt-1">
        <div class="col-xl-7">
          <section class="card p-4 h-100">
            <h2 class="h6 section-title mb-3"><i class="bi bi-person-vcard text-success me-1"></i> Personal Information</h2>
            <form id="infoForm"><div class="row g-3">
              <div class="col-md-4"><label class="form-label" for="doctor-first-name">First Name</label><input id="doctor-first-name" class="form-control" name="first_name" maxlength="60" required value="<?= e($userRecord['first_name'] ?? '') ?>"></div>
              <div class="col-md-4"><label class="form-label" for="doctor-middle-name">Middle Name</label><input id="doctor-middle-name" class="form-control" name="middle_name" maxlength="60" value="<?= e($userRecord['middle_name'] ?? '') ?>"></div>
              <div class="col-md-4"><label class="form-label" for="doctor-last-name">Last Name</label><input id="doctor-last-name" class="form-control" name="last_name" maxlength="60" required value="<?= e($userRecord['last_name'] ?? '') ?>"></div>
              <div class="col-md-6"><label class="form-label" for="doctor-email">Email Address</label><input id="doctor-email" type="email" class="form-control" name="email" maxlength="120" required value="<?= e($userRecord['email'] ?? '') ?>"></div>
              <div class="col-md-6"><label class="form-label" for="doctor-phone">Contact Number</label><input id="doctor-phone" type="tel" class="form-control" name="phone" maxlength="30" value="<?= e($userRecord['phone'] ?? '') ?>"></div>
            </div><button class="btn btn-success mt-3" type="submit"><i class="bi bi-check-circle me-1"></i>Save Personal Information</button></form>
          </section>
        </div>
        <div class="col-xl-5">
          <section class="card p-4 h-100">
            <h2 class="h6 section-title mb-2"><i class="bi bi-shield-lock text-success me-1"></i> Password Settings</h2>
            <p class="small text-muted">Use at least 8 characters with uppercase, lowercase, a number, and a symbol.</p>
            <form id="pwForm"><div class="row g-3">
              <div class="col-12"><label class="form-label" for="doctor-current-password">Current Password</label><input id="doctor-current-password" type="password" class="form-control" name="current_password" autocomplete="current-password" required></div>
              <div class="col-md-6"><label class="form-label" for="doctor-new-password">New Password</label><input id="doctor-new-password" type="password" class="form-control" name="new_password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
              <div class="col-md-6"><label class="form-label" for="doctor-confirm-password">Confirm Password</label><input id="doctor-confirm-password" type="password" class="form-control" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
            </div><button class="btn btn-outline-success mt-3" type="submit"><i class="bi bi-key me-1"></i>Update Password</button></form>
          </section>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
(function () {
  'use strict';
  const csrfToken = $('meta[name="csrf-token"]').attr('content');

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
      error: function (xhr) {
        const message = xhr.responseJSON && xhr.responseJSON.message
          ? xhr.responseJSON.message
          : 'Failed to upload profile picture. Refresh the page and try again.';
        toastError(message);
        $('#pictureInput').val('');
      }
    });
  });

  function bindAccountForm(selector, action) {
    $(selector).on('submit', function (event) {
      event.preventDefault();
      const form = this;
      if (action === 'change_password' && form.new_password.value !== form.confirm_password.value) {
        toastError('New passwords do not match.');
        form.confirm_password.focus();
        return;
      }
      const button = $(form).find('[type="submit"]');
      button.prop('disabled', true);
      const data = new FormData(form);
      data.append('action', action);
      data.append('csrf_token', csrfToken);
      $.ajax({url: APP_URL + '/api/profile.php', type:'POST', data:data, processData:false, contentType:false, dataType:'json'})
        .done(function (res) { if (!res.success) { toastError(res.message); return; } toastSuccess(res.message); if (action === 'change_password') form.reset(); else setTimeout(function () { location.reload(); }, 700); })
        .fail(function (xhr) { toastError(xhr.responseJSON?.message || 'Unable to save changes.'); })
        .always(function () { button.prop('disabled', false); });
    });
  }
  bindAccountForm('#infoForm', 'update_info');
  bindAccountForm('#pwForm', 'change_password');

})();
</script>
</body>
</html>
