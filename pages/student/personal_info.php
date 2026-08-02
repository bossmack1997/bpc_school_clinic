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

$pageTitle = 'Personal Information';
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
            <img src="<?= APP_URL . '/' . e($u['profile_picture']) ?>" onerror="this.src='https://via.placeholder.com/120'" class="rounded-circle mx-auto mb-3" width="120" height="120" style="object-fit:cover">
            <h6 class="fw-semibold"><?= e($u['first_name'] . ' ' . $u['last_name']) ?></h6>
            <p class="text-muted small mb-0"><?= e($student['student_number']) ?></p>
            <p class="text-muted small"><?= e($student['course'] ?? '—') ?> · <?= e($student['year_level'] ?? '—') ?>-<?= e($student['section'] ?? '') ?></p>
          </div>
        </div>
        <div class="col-lg-8">
          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-person-vcard text-success me-1"></i> Personal Details</h6>
            <div class="row g-2">
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">First Name</small>
                <span class="fw-medium"><?= e($u['first_name']) ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Last Name</small>
                <span class="fw-medium"><?= e($u['last_name']) ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Email</small>
                <span class="fw-medium"><?= e($u['email']) ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Phone</small>
                <span class="fw-medium"><?= e($u['phone'] ?? '—') ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Student Number</small>
                <span class="fw-medium"><?= e($student['student_number']) ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Gender</small>
                <span class="fw-medium"><?= e($student['gender'] ?? '—') ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Course</small>
                <span class="fw-medium"><?= e($student['course'] ?? '—') ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Year Level</small>
                <span class="fw-medium"><?= e($student['year_level'] ?? '—') ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Section</small>
                <span class="fw-medium"><?= e($student['section'] ?? '—') ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Birthdate</small>
                <span class="fw-medium"><?= $student['birthdate'] ? format_date($student['birthdate']) : '—' ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Blood Type</small>
                <span class="fw-medium"><?= e($student['blood_type'] ?? '—') ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Address</small>
                <span class="fw-medium"><?= e($student['address'] ?? '—') ?></span>
              </div>
              <div class="col-12 mb-2">
                <small class="text-muted d-block">Allergies</small>
                <span class="fw-medium"><?= e($student['allergies'] ?? 'None recorded') ?></span>
              </div>
            </div>
          </div>
          <div class="card p-3 mt-3">
            <h6 class="section-title mb-3"><i class="bi bi-telephone text-success me-1"></i> Emergency Contact</h6>
            <div class="row g-2">
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Guardian Name</small>
                <span class="fw-medium"><?= e($student['guardian_name'] ?? '—') ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Guardian Contact</small>
                <span class="fw-medium"><?= e($student['guardian_contact'] ?? '—') ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Emergency Contact Person</small>
                <span class="fw-medium"><?= e($student['emergency_contact_name'] ?? '—') ?></span>
              </div>
              <div class="col-md-6 mb-2">
                <small class="text-muted d-block">Emergency Contact Number</small>
                <span class="fw-medium"><?= e($student['emergency_contact_number'] ?? '—') ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
