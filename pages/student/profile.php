<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
$db = getDB();
$u = current_user();

$stmt = $db->prepare("SELECT * FROM students WHERE user_id = ?");
$stmt->execute([$u['id']]);
$student = $stmt->fetch();

if (!$student) {
    die('Student record not found. Please contact the clinic.');
}

// Fresh user record
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

      <!-- Profile Header Summary Card -->
      <div class="card p-3 mb-3">
        <div class="row g-3 align-items-center">
          <div class="col-auto text-center position-relative">
            <img src="<?= APP_URL . '/' . e($userRecord['profile_picture']) ?>" id="profileAvatar" onerror="this.src='<?= asset_url('images/default-avatar.png') ?>'" class="rounded-circle border" width="100" height="100" style="object-fit:cover">
            <label for="pictureInput" class="btn btn-sm btn-primary rounded-circle position-absolute bottom-0 end-0" style="padding: 4px 8px;" title="Upload Profile Picture">
              <i class="bi bi-camera-fill"></i>
            </label>
            <input type="file" id="pictureInput" class="d-none" accept="image/*">
          </div>
          <div class="col">
            <h5 class="fw-bold mb-1"><?= e($userRecord['first_name'] . ($userRecord['middle_name'] ? ' ' . $userRecord['middle_name'] : '') . ' ' . $userRecord['last_name']) ?></h5>
            <p class="text-muted small mb-1"><i class="bi bi-envelope me-1"></i> <?= e($userRecord['email']) ?></p>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
              <span class="badge bg-success"><i class="bi bi-mortarboard-fill me-1"></i> Student</span>
              <span class="badge bg-primary-light text-primary"><i class="bi bi-book-fill me-1"></i> <?= e($student['course'] ?? '—') ?> <?= e($student['year_level'] ?? '') ?><?= !empty($student['section']) ? ' - ' . e($student['section']) : '' ?></span>
              <span class="badge bg-secondary"><i class="bi bi-person-badge me-1"></i> ID: <?= e($student['student_number']) ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <!-- Left Column: Personal Information & Password -->
        <div class="col-lg-7">
          <!-- Personal Information Form -->
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
                  <input type="text" class="form-control" name="phone" value="<?= e($userRecord['phone'] ?? '') ?>" placeholder="e.g. 09123456789">
                </div>
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Gender</label>
                  <select class="form-select" name="gender">
                    <option value="">Select...</option>
                    <option value="Male" <?= $student['gender']==='Male'?'selected':'' ?>>Male</option>
                    <option value="Female" <?= $student['gender']==='Female'?'selected':'' ?>>Female</option>
                    <option value="Other" <?= $student['gender']==='Other'?'selected':'' ?>>Other</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Birthdate</label>
                  <input type="date" class="form-control" name="birthdate" max="<?= date('Y-m-d') ?>" value="<?= e($student['birthdate'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label small fw-semibold">Blood Type</label>
                  <input type="text" class="form-control" name="blood_type" placeholder="e.g. O+, A+" value="<?= e($student['blood_type'] ?? '') ?>">
                </div>
                <div class="col-12">
                  <label class="form-label small fw-semibold">Home Address</label>
                  <input type="text" class="form-control" name="address" placeholder="Complete address" value="<?= e($student['address'] ?? '') ?>">
                </div>
                <div class="col-12">
                  <label class="form-label small fw-semibold">Allergies &amp; Medical Conditions</label>
                  <textarea class="form-control" name="allergies" rows="2" placeholder="List any known allergies, asthma, or chronic conditions..."><?= e($student['allergies'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Guardian Name</label>
                  <input type="text" class="form-control" name="guardian_name" value="<?= e($student['guardian_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Guardian Contact</label>
                  <input type="text" class="form-control" name="guardian_contact" value="<?= e($student['guardian_contact'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Emergency Contact Person</label>
                  <input type="text" class="form-control" name="emergency_contact_name" value="<?= e($student['emergency_contact_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Emergency Contact Number</label>
                  <input type="text" class="form-control" name="emergency_contact_number" value="<?= e($student['emergency_contact_number'] ?? '') ?>">
                </div>
              </div>
              <button class="btn btn-primary mt-3 w-100 fw-semibold" type="submit" id="saveInfoBtn">
                <i class="bi bi-check-lg me-1"></i> Save Personal Info
              </button>
            </form>
          </div>

          <!-- Password Settings Form -->
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

        <!-- Right Column: Academic Information & Role Permissions -->
        <div class="col-lg-5">
          <!-- Academic Information Card -->
          <div class="card p-3 mb-3">
            <h6 class="section-title mb-3"><i class="bi bi-book-fill text-success me-1"></i> Academic Information</h6>
            <div class="row g-2 small">
              <div class="col-6"><div class="text-muted">Student Number</div><div class="fw-bold text-dark"><?= e($student['student_number']) ?></div></div>
              <div class="col-6"><div class="text-muted">Role</div><div class="fw-bold text-success">Student</div></div>
              <div class="col-12"><div class="text-muted">Course Program</div><div class="fw-bold text-dark"><?= e($student['course'] ?? '—') ?></div></div>
              <div class="col-6"><div class="text-muted">Year Level</div><div class="fw-bold text-dark"><?= e($student['year_level'] ?? '—') ?></div></div>
              <div class="col-6"><div class="text-muted">Section</div><div class="fw-bold text-dark"><?= e($student['section'] ?? '—') ?></div></div>
              <div class="col-6"><div class="text-muted">Account Status</div><div><span class="badge bg-success">Active</span></div></div>
              <div class="col-6"><div class="text-muted">Username</div><div class="fw-bold text-dark"><?= e($userRecord['username']) ?></div></div>
            </div>
            <div class="alert alert-light border mt-3 mb-0 py-2 small">
              <i class="bi bi-info-circle text-primary me-1"></i>
              <span>Academic program, student number, and year level are managed by the school registrar and cannot be self-modified.</span>
            </div>
          </div>

          <!-- Role Permissions Card -->
          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-shield-check text-success me-1"></i> Role Permissions</h6>
            <ul class="small mb-0 text-muted ps-3">
              <li class="mb-1">View personal health monitoring, vital signs &amp; BMI records.</li>
              <li class="mb-1">Request online scheduled clinic services and appointments.</li>
              <li class="mb-1">Generate and use digital QR Health Pass for clinic check-ins.</li>
              <li class="mb-1">Access clinic announcements and health education advisories.</li>
              <li>Trigger SOS emergency alerts with live GPS location to clinic nurses.</li>
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

    const data = $(this).serialize() + '&action=update_personal_info&csrf_token=' + csrfToken;
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
      error: function (xhr) {
        const message = xhr.responseJSON && xhr.responseJSON.message
          ? xhr.responseJSON.message
          : 'Failed to upload profile picture. Refresh the page and try again.';
        toastError(message);
        $('#pictureInput').val('');
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
