<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$db = getDB();
$u = current_user();

$stmt = $db->prepare("SELECT * FROM nurses WHERE user_id = ?");
$stmt->execute([$u['id']]);
$nurse = $stmt->fetch();

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
            <p class="text-muted small mb-0"><?= e($nurse['specialization'] ?? 'Nurse') ?></p>
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
             <form id="profileForm">
               <div class="row g-2">
                 <div class="col-md-6"><label class="form-label small">First Name</label><input class="form-control" name="first_name" value="<?= e($u['first_name']) ?>" required></div>
                 <div class="col-md-6"><label class="form-label small">Last Name</label><input class="form-control" name="last_name" value="<?= e($u['last_name']) ?>" required></div>
                 <div class="col-md-6"><label class="form-label small">Email</label><input type="email" class="form-control" name="email" value="<?= e($u['email']) ?>" required></div>
                 <div class="col-md-6"><label class="form-label small">Phone</label><input class="form-control" name="phone" value="<?= e($u['phone'] ?? '') ?>"></div>
                 <div class="col-md-6"><label class="form-label small">Gender</label>
                   <select class="form-select" name="gender">
                     <option value="">Select...</option>
                     <option value="Male" <?= $nurse['gender']==='Male'?'selected':'' ?>>Male</option>
                     <option value="Female" <?= $nurse['gender']==='Female'?'selected':'' ?>>Female</option>
                     <option value="Other" <?= $nurse['gender']==='Other'?'selected':'' ?>>Other</option>
                   </select>
                 </div>
                 <div class="col-md-6"><label class="form-label small">Date of Birth</label><input type="date" class="form-control" name="date_of_birth" max="<?= date('Y-m-d') ?>" value="<?= e($nurse['date_of_birth'] ?? '') ?>"></div>
               </div>
             </form>
           </div>

           <!-- Professional Information -->
           <div class="card p-3 mb-3">
             <h6 class="section-title mb-3"><i class="bi bi-hospital text-success me-1"></i> Professional Information</h6>
             <form id="profileForm2">
               <div class="row g-2">
                 <div class="col-md-6"><label class="form-label small">Employee ID</label><input class="form-control" name="employee_id" value="<?= e($nurse['employee_id'] ?? '') ?>"></div>
                 <div class="col-md-6"><label class="form-label small">License Number</label><input class="form-control" name="license_number" value="<?= e($nurse['license_number'] ?? '') ?>"></div>
                 <div class="col-md-6"><label class="form-label small">Specialization</label><input class="form-control" name="specialization" value="<?= e($nurse['specialization'] ?? '') ?>"></div>
                 <div class="col-md-6"><label class="form-label small">Shift Schedule</label><input class="form-control" name="shift_schedule" value="<?= e($nurse['shift_schedule'] ?? '') ?>"></div>
               </div>
             </form>
             <button class="btn btn-primary mt-3" type="button" id="saveBtn"><i class="bi bi-check-lg me-1"></i> Save All Changes</button>
           </div>
         </div>

        <div class="col-lg-4">
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

function submitForm(formId, action) {
  const fd = new FormData(document.getElementById(formId));
  fd.append('action', action);
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
        if (formId === 'pwForm') {
          document.getElementById('pwForm').reset();
        }
      } else {
        toastError(res.message);
      }
    }
  });
}

$('#saveBtn').on('click', function (e) {
  e.preventDefault();
  const fd = new FormData();
  fd.append('action', 'update_nurse_info');
  fd.append('csrf_token', csrfToken);
  // Combine fields from both forms
  const f1 = document.getElementById('profileForm');
  const f2 = document.getElementById('profileForm2');
  new FormData(f1).forEach((v, k) => fd.append(k, v));
  new FormData(f2).forEach((v, k) => fd.append(k, v));
  $.ajax({
    url: APP_URL + '/api/profile.php',
    type: 'POST',
    data: fd,
    processData: false,
    contentType: false,
    success: function (res) {
      if (res.success) {
        toastSuccess(res.message);
        location.reload();
      } else {
        toastError(res.message);
      }
    }
  });
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
