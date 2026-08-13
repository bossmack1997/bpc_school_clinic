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

$pageTitle = 'My Profile';
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
      <h6 class="section-title mb-3"><i class="bi bi-person-circle text-success me-1"></i> My Profile</h6>

      <!-- Profile Header -->
      <div class="card p-3 mb-3">
        <div class="row g-3 align-items-center">
          <div class="col-auto text-center">
            <img id="profilePic" src="<?= APP_URL . '/' . e($u['profile_picture']) ?>" onerror="this.src='https://via.placeholder.com/120'" class="rounded-circle" width="100" height="100" style="object-fit:cover">
          </div>
          <div class="col">
            <h5 class="fw-bold mb-1"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></h5>
            <p class="text-muted small mb-1"><?= e($student['student_number']) ?></p>
            <p class="text-muted small mb-0"><?= e($student['course'] ?? '—') ?> · <?= e($student['year_level'] ?? '—') ?>-<?= e($student['section'] ?? '') ?></p>
          </div>
          <div class="col-auto">
            <form id="picForm" class="d-inline">
              <input type="file" name="picture" id="picInput" accept="image/*" class="d-none" onchange="previewProfileImage(event)">
              <button class="btn btn-outline-primary btn-sm" type="button" onclick="document.getElementById('picInput').click()"><i class="bi bi-camera me-1"></i> Change Photo</button>
              <button class="btn btn-primary btn-sm ms-1" type="submit">Upload</button>
            </form>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-8">
          <!-- Personal Information -->
          <div class="card p-3 mb-3">
            <h6 class="section-title mb-3"><i class="bi bi-person-vcard text-success me-1"></i> Personal Information</h6>
            <form id="personalInfoForm">
              <input type="hidden" name="email" value="<?= e($u['email']) ?>">
              <input type="hidden" name="phone" value="<?= e($u['phone'] ?? '') ?>">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label small">First Name</label><input class="form-control" name="first_name" value="<?= e($u['first_name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label small">Last Name</label><input class="form-control" name="last_name" value="<?= e($u['last_name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label small">Gender</label>
                  <select class="form-select" name="gender">
                    <option value="">Select...</option>
                    <option value="Male" <?= $student['gender']==='Male'?'selected':'' ?>>Male</option>
                    <option value="Female" <?= $student['gender']==='Female'?'selected':'' ?>>Female</option>
                    <option value="Other" <?= $student['gender']==='Other'?'selected':'' ?>>Other</option>
                  </select>
                </div>
                <div class="col-md-6"><label class="form-label small">Birthdate</label><input type="date" class="form-control" name="birthdate" max="<?= date('Y-m-d') ?>" value="<?= e($student['birthdate'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label small">Blood Type</label><input class="form-control" name="blood_type" value="<?= e($student['blood_type'] ?? '') ?>"></div>
                <div class="col-12"><label class="form-label small">Address</label><input class="form-control" name="address" value="<?= e($student['address'] ?? '') ?>"></div>
                <div class="col-12"><label class="form-label small">Allergies</label><textarea class="form-control" name="allergies" rows="2"><?= e($student['allergies'] ?? '') ?></textarea></div>
              </div>
              <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-check-lg me-1"></i> Save Personal Information</button>
            </form>
          </div>

          <!-- Contact Information -->
          <div class="card p-3 mb-3">
            <h6 class="section-title mb-3"><i class="bi bi-envelope text-success me-1"></i> Contact Information</h6>
            <form id="contactForm">
              <input type="hidden" name="first_name" value="<?= e($u['first_name']) ?>">
              <input type="hidden" name="last_name" value="<?= e($u['last_name']) ?>">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label small">Email</label><input type="email" class="form-control" name="email" value="<?= e($u['email']) ?>" required></div>
                <div class="col-md-6"><label class="form-label small">Phone</label><input class="form-control" name="phone" value="<?= e($u['phone'] ?? '') ?>"></div>
              </div>
              <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-check-lg me-1"></i> Save Contact Information</button>
            </form>
          </div>

          <!-- Emergency Contact -->
          <div class="card p-3 mb-3">
            <h6 class="section-title mb-3"><i class="bi bi-telephone text-success me-1"></i> Emergency Contact</h6>
            <form id="emergencyForm">
              <input type="hidden" name="first_name" value="<?= e($u['first_name']) ?>">
              <input type="hidden" name="last_name" value="<?= e($u['last_name']) ?>">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label small">Guardian Name</label><input class="form-control" name="guardian_name" value="<?= e($student['guardian_name'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label small">Guardian Contact</label><input class="form-control" name="guardian_contact" value="<?= e($student['guardian_contact'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label small">Emergency Contact Person</label><input class="form-control" name="emergency_contact_name" value="<?= e($student['emergency_contact_name'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label small">Emergency Contact Number</label><input class="form-control" name="emergency_contact_number" value="<?= e($student['emergency_contact_number'] ?? '') ?>"></div>
              </div>
              <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-check-lg me-1"></i> Save Emergency Contact</button>
            </form>
          </div>
        </div>

        <div class="col-lg-4">
          <!-- Academic Information (Read-only) -->
          <div class="card p-3 mb-3">
            <h6 class="section-title mb-3"><i class="bi bi-book text-success me-1"></i> Academic Information</h6>
            <div class="row g-2">
              <div class="col-12 mb-2"><small class="text-muted d-block">Student Number</small><span class="fw-medium"><?= e($student['student_number']) ?></span></div>
              <div class="col-12 mb-2"><small class="text-muted d-block">Course</small><span class="fw-medium"><?= e($student['course'] ?? '—') ?></span></div>
              <div class="col-12 mb-2"><small class="text-muted d-block">Year Level</small><span class="fw-medium"><?= e($student['year_level'] ?? '—') ?></span></div>
              <div class="col-12 mb-2"><small class="text-muted d-block">Section</small><span class="fw-medium"><?= e($student['section'] ?? '—') ?></span></div>
            </div>
          </div>

          <!-- Account Settings -->
          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-shield-lock text-success me-1"></i> Account Settings</h6>
            <form id="pwForm">
              <div class="row g-2">
                <div class="col-12"><label class="form-label small">Current Password</label><input type="password" class="form-control" name="current_password" required></div>
                <div class="col-12"><label class="form-label small">New Password</label><input type="password" class="form-control" name="new_password" minlength="8" required></div>
                <div class="col-12"><label class="form-label small">Confirm New Password</label><input type="password" class="form-control" name="confirm_password" minlength="8" required></div>
              </div>
              <button class="btn btn-primary mt-3 w-100" type="submit"><i class="bi bi-check-lg me-1"></i> Update Password</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');

function submitForm(formId, action, extraFields) {
  const fd = new FormData(document.getElementById(formId));
  fd.append('action', action);
  fd.append('csrf_token', csrfToken);
  if (extraFields) {
    extraFields(fd);
  }
  $.ajax({
    url: APP_URL + '/api/profile.php',
    type: 'POST',
    data: fd,
    processData: false,
    contentType: false,
    success: function (res) {
      if (res.success) {
        toastSuccess(res.message);
        if (formId === 'personalInfoForm' || formId === 'contactForm' || formId === 'emergencyForm') {
          location.reload();
        }
        if (formId === 'pwForm') {
          document.getElementById('pwForm').reset();
        }
      } else {
        toastError(res.message);
      }
    }
  });
}

$('#personalInfoForm').on('submit', function (e) {
  e.preventDefault();
  submitForm('personalInfoForm', 'update_personal_info');
});

$('#contactForm').on('submit', function (e) {
  e.preventDefault();
  submitForm('contactForm', 'update_personal_info');
});

$('#emergencyForm').on('submit', function (e) {
  e.preventDefault();
  submitForm('emergencyForm', 'update_personal_info');
});

$('#pwForm').on('submit', function (e) {
  e.preventDefault();
  submitForm('pwForm', 'change_password');
});

$('#picForm').on('submit', function (e) {
  e.preventDefault();
  const fd = new FormData(this);
  fd.append('action', 'upload_picture');
  fd.append('csrf_token', csrfToken);
  $.ajax({
    url: APP_URL + '/api/profile.php',
    type: 'POST',
    data: fd,
    processData: false,
    contentType: false,
    success: function (res) {
      if (res.success) {
        toastSuccess(res.message);
        $('#profilePic').attr('src', res.path);
      } else {
        toastError(res.message);
      }
    }
  });
});
</script>
</body>
</html>
