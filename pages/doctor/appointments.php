<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor');
$db = getDB();
$u = current_user();

// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    csrf_verify_or_die();
    $apptId = (int)$_POST['appointment_id'];
    $newStatus = $_POST['status'] ?? 'approved';
    if ($apptId > 0 && in_array($newStatus, ['approved','completed','cancelled','rejected'], true)) {
        $stmt = $db->prepare("UPDATE appointments SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $apptId]);
        log_activity($u['id'], "Updated appointment #$apptId to $newStatus", 'appointments');
        set_flash('success', "Appointment status updated to " . ucfirst($newStatus) . ".");
        redirect('appointments.php');
    }
}

$stmt = $db->query("
    SELECT a.*, s.student_number, s.course, s.year_level, s.section, u.first_name, u.last_name, u.phone, u.email
    FROM appointments a
    JOIN students s ON s.id = a.student_id
    JOIN users u ON u.id = s.user_id
    ORDER BY a.appointment_date DESC, a.appointment_time ASC
");
$appointments = $stmt->fetchAll();

$pageTitle = 'Appointments Schedule';
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
      
      <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
          <h3 class="fw-bold mb-1"><i class="bi bi-calendar2-check text-primary me-2"></i>Physician Appointment Schedule</h3>
          <p class="text-muted mb-0">Review, approve, and manage scheduled clinic visits and student health consultations.</p>
        </div>
      </div>

      <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?> py-2 small d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-check-circle-fill"></i>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light text-muted small">
                <tr>
                  <th class="ps-4">Patient</th>
                  <th>Course / Section</th>
                  <th>Scheduled Date &amp; Time</th>
                  <th>Service Requested</th>
                  <th>Status</th>
                  <th class="text-end pe-4">Clinical Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($appointments)): ?>
                  <tr><td colspan="6" class="text-center py-5 text-muted">No appointments found.</td></tr>
                <?php else: ?>
                  <?php foreach ($appointments as $a): ?>
                    <tr>
                      <td class="ps-4">
                        <div class="fw-semibold text-dark"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></div>
                        <small class="text-muted"><?= e($a['student_number']) ?></small>
                      </td>
                      <td><?= e($a['course'] . ' ' . $a['year_level'] . '-' . $a['section']) ?></td>
                      <td>
                        <div class="fw-medium text-dark"><?= date('M d, Y', strtotime($a['appointment_date'])) ?></div>
                        <small class="text-muted"><?= date('h:i A', strtotime($a['appointment_time'])) ?></small>
                      </td>
                      <td><span class="badge bg-light text-dark border"><?= e($a['service_type'] ?? 'General Consultation') ?></span></td>
                      <td>
                        <?php
                          $badgeClass = match($a['status']) {
                            'approved' => 'bg-success-subtle text-success border-success-subtle',
                            'pending'  => 'bg-warning-subtle text-warning border-warning-subtle',
                            'completed'=> 'bg-primary-subtle text-primary border-primary-subtle',
                            default    => 'bg-secondary-subtle text-secondary border-secondary-subtle'
                          };
                        ?>
                        <span class="badge border <?= $badgeClass ?>"><?= ucfirst(e($a['status'])) ?></span>
                      </td>
                      <td class="text-end pe-4">
                        <?php if ($a['status'] === 'pending'): ?>
                          <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action_type" value="update_status">
                            <input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">
                            <input type="hidden" name="status" value="approved">
                            <button type="submit" class="btn btn-sm btn-success rounded-pill px-2 me-1"><i class="bi bi-check-lg"></i> Approve</button>
                          </form>
                        <?php endif; ?>
                        <a href="consultations.php?student_id=<?= (int)$a['student_id'] ?>&appointment_id=<?= (int)$a['id'] ?>" class="btn btn-sm btn-primary rounded-pill px-2">
                          <i class="bi bi-stethoscope"></i> Consult
                        </a>
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
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>