<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor');
$db = getDB();
$u = current_user();

$stmt = $db->prepare("SELECT * FROM doctors WHERE user_id = ?");
$stmt->execute([$u['id']]);
$doctor = $stmt->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $spec      = trim($_POST['specialization'] ?? '');
    $license   = trim($_POST['license_number'] ?? '');

    if ($firstName && $lastName) {
        $stmt = $db->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE id = ?");
        $stmt->execute([$firstName, $lastName, $phone, $u['id']]);

        $stmt = $db->prepare("UPDATE doctors SET specialization = ?, license_number = ?, contact_number = ? WHERE user_id = ?");
        $stmt->execute([$spec, $license, $phone, $u['id']]);

        // Refresh session
        $_SESSION['user']['first_name'] = $firstName;
        $_SESSION['user']['last_name'] = $lastName;
        $_SESSION['user']['phone'] = $phone;

        set_flash('success', 'Profile updated successfully.');
        redirect('profile.php');
    }
}

$pageTitle = 'Doctor Profile';
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
      
      <div class="card border-0 shadow-sm rounded-4 p-4" style="max-width:700px; margin: 0 auto;">
        <h4 class="fw-bold mb-3"><i class="bi bi-person-circle text-primary me-2"></i>Doctor Profile &amp; Credentials</h4>
        
        <?php if ($flash = get_flash()): ?>
          <div class="alert alert-<?= e($flash['type']) ?> py-2 small d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-check-circle-fill"></i>
            <span><?= e($flash['message']) ?></span>
          </div>
        <?php endif; ?>

        <form method="POST">
          <?= csrf_field() ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">First Name *</label>
              <input type="text" name="first_name" class="form-control" required value="<?= e($u['first_name']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Last Name *</label>
              <input type="text" name="last_name" class="form-control" required value="<?= e($u['last_name']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Email</label>
              <input type="email" class="form-control" value="<?= e($u['email']) ?>" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Contact Number</label>
              <input type="text" name="phone" class="form-control" value="<?= e($u['phone'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">PRC Medical License Number</label>
              <input type="text" name="license_number" class="form-control" value="<?= e($doctor['license_number'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Specialization</label>
              <input type="text" name="specialization" class="form-control" value="<?= e($doctor['specialization'] ?? 'General Physician') ?>">
            </div>
          </div>
          <button type="submit" class="btn btn-primary mt-4 rounded-pill px-4">Update Profile</button>
        </form>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>