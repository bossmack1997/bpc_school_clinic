<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$u = current_user();
$pageTitle = 'Profile Settings';
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
      <div class="row g-3">
        <div class="col-lg-4">
          <div class="card p-3 text-center">
            <img id="profilePic" src="<?= APP_URL . '/' . e($u['profile_picture']) ?>" onerror="this.src='https://via.placeholder.com/120'" class="rounded-circle mx-auto mb-3" width="120" height="120" style="object-fit:cover">
            <form id="picForm">
              <input type="file" name="picture" id="picInput" accept="image/*" class="form-control mb-2" onchange="previewProfileImage(event)">
              <button class="btn btn-outline-primary btn-sm w-100" type="submit">Upload Photo</button>
            </form>
          </div>
        </div>
        <div class="col-lg-8">
          <?php include __DIR__ . '/../../components/voice_enrollment.php'; ?>
          <div class="card p-3 mb-3">
            <h6 class="section-title mb-3">Personal Information</h6>
            <form id="infoForm">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label small">First Name</label><input class="form-control" name="first_name" value="<?= e($u['first_name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label small">Last Name</label><input class="form-control" name="last_name" value="<?= e($u['last_name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label small">Email</label><input type="email" class="form-control" name="email" value="<?= e($u['email']) ?>" required></div>
                <div class="col-md-6"><label class="form-label small">Phone</label><input class="form-control" name="phone"></div>
              </div>
              <button class="btn btn-primary mt-3" type="submit">Save Changes</button>
            </form>
          </div>
          <div class="card p-3">
            <h6 class="section-title mb-3">Change Password</h6>
            <form id="pwForm">
              <div class="row g-2">
                <div class="col-md-4"><label class="form-label small">Current Password</label><input type="password" class="form-control" name="current_password" required></div>
                <div class="col-md-4"><label class="form-label small">New Password</label><input type="password" class="form-control" name="new_password" minlength="8" required></div>
                <div class="col-md-4"><label class="form-label small">Confirm Password</label><input type="password" class="form-control" name="confirm_password" minlength="8" required></div>
              </div>
              <button class="btn btn-primary mt-3" type="submit">Update Password</button>
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

$('#infoForm').on('submit', function (e) {
  e.preventDefault();
  $.post(APP_URL + '/api/profile.php', $(this).serialize() + '&action=update_info&csrf_token=' + csrfToken, function (res) {
    res.success ? toastSuccess(res.message) : toastError(res.message);
  }, 'json');
});

$('#pwForm').on('submit', function (e) {
  e.preventDefault();
  $.post(APP_URL + '/api/profile.php', $(this).serialize() + '&action=change_password&csrf_token=' + csrfToken, function (res) {
    if (res.success) { toastSuccess(res.message); $('#pwForm')[0].reset(); } else toastError(res.message);
  }, 'json');
});

$('#picForm').on('submit', function (e) {
  e.preventDefault();
  const fd = new FormData(this);
  fd.append('action', 'upload_picture');
  fd.append('csrf_token', csrfToken);
  $.ajax({ url: APP_URL + '/api/profile.php', type:'POST', data: fd, processData:false, contentType:false,
    success: function (res) { if (res.success) { toastSuccess(res.message); $('#profilePic').attr('src', res.path); } else toastError(res.message); }
  });
});
</script>
</body>
</html>
