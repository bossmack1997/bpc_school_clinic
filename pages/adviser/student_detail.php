<?php
require_once __DIR__ . '/../../config/config.php';
require_role('teacher');
$db = getDB();
$u = current_user();

$studentId = (int)($_GET['id'] ?? 0);
if ($studentId <= 0 || !adviser_can_access_student($db, $u['id'], $studentId)) {
    set_flash('danger', 'You do not have access to this student.');
    redirect(APP_URL . '/pages/adviser/students.php');
}

$stmt = $db->prepare("
    SELECT s.id, s.student_number, s.course, s.year_level, s.section, s.gender, s.blood_type,
           s.emergency_contact_name, s.emergency_contact_number, s.allergies,
           u.first_name, u.last_name, u.email, u.phone
    FROM students s JOIN users u ON u.id = s.user_id
    WHERE s.id = ?
");
$stmt->execute([$studentId]);
$student = $stmt->fetch();

$pageTitle = 'Student Detail';
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

      <a href="students.php" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i> Back to Advisory Class</a>

      <div class="row g-3">
        <div class="col-12">
          <div class="card p-3">
            <h6 class="section-title mb-3"><i class="bi bi-person-badge-fill text-success me-1"></i> Student Information</h6>
            <div class="row g-2 small">
              <div class="col-6"><div class="text-muted">Name</div><div class="fw-semibold"><?= e($student['first_name'].' '.$student['last_name']) ?></div></div>
              <div class="col-6"><div class="text-muted">Student No.</div><div class="fw-semibold"><?= e($student['student_number']) ?></div></div>
              <div class="col-6"><div class="text-muted">Course</div><div class="fw-semibold"><?= e($student['course'] ?: '—') ?></div></div>
              <div class="col-6"><div class="text-muted">Year Level</div><div class="fw-semibold"><?= e($student['year_level'] ?: '—') ?></div></div>
              <div class="col-6"><div class="text-muted">Section</div><div class="fw-semibold"><?= e($student['section'] ?: '—') ?></div></div>
              <div class="col-6"><div class="text-muted">Gender</div><div class="fw-semibold"><?= e($student['gender'] ?: '—') ?></div></div>
              <div class="col-6"><div class="text-muted">Blood Type</div><div class="fw-semibold"><?= e($student['blood_type'] ?: '—') ?></div></div>
              <div class="col-6"><div class="text-muted">Allergies</div><div class="fw-semibold"><?= e($student['allergies'] ?: 'None recorded') ?></div></div>
              <div class="col-6"><div class="text-muted">Contact</div><div class="fw-semibold"><?= e($student['phone'] ?: '—') ?></div></div>
              <div class="col-6"><div class="text-muted">Emergency Contact</div><div class="fw-semibold"><?= e($student['emergency_contact_name'] ?: '—') ?></div></div>
              <div class="col-6"><div class="text-muted">Emergency Number</div><div class="fw-semibold"><?= e($student['emergency_contact_number'] ?: '—') ?></div></div>
            </div>
            <div class="alert alert-warning mt-3 mb-0 small">
              <i class="bi bi-shield-lock me-1"></i> Advisory access is limited to basic school & emergency information. Detailed medical, psychological, and nurse records are not shown here.
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
