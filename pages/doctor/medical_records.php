<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor');
$db = getDB();
$u = current_user();

$studentIdReq = (int)($_GET['student_id'] ?? 0);
$selectedStudent = null;
$historyConsults = [];
$vitalHistory = [];

if ($studentIdReq > 0) {
    $stmt = $db->prepare("
        SELECT s.*, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.gender, u.date_of_birth, u.profile_picture
        FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?
    ");
    $stmt->execute([$studentIdReq]);
    $selectedStudent = $stmt->fetch();

    if ($selectedStudent) {
        $stmt = $db->prepare("SELECT * FROM consultations WHERE student_id = ? ORDER BY consultation_date DESC");
        $stmt->execute([$studentIdReq]);
        $historyConsults = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT * FROM health_monitoring WHERE student_id = ? ORDER BY recorded_at DESC LIMIT 20");
        $stmt->execute([$studentIdReq]);
        $vitalHistory = $stmt->fetchAll();
    }
}

$pageTitle = 'Confidential Medical Records';
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
          <h3 class="fw-bold mb-1"><i class="bi bi-file-earmark-medical-fill text-primary me-2"></i>Patient Medical Chart</h3>
          <p class="text-muted mb-0">Confidential clinical health summary, vital signs history, and past consultation logs.</p>
        </div>
        <a href="patients.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Back to Patients</a>
      </div>

      <?php if (!$selectedStudent): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center text-muted">
          <i class="bi bi-person-search fs-1 mb-2 text-secondary"></i>
          <h5>No Patient Selected</h5>
          <p>Please select a patient from the <a href="patients.php">Patient Records roster</a> to view their medical chart.</p>
        </div>
      <?php else: ?>
        <div class="row g-4">
          <!-- Patient Summary Card -->
          <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 text-center p-4">
              <img src="<?= APP_URL ?>/<?= e($selectedStudent['profile_picture'] ?: 'assets/images/default-avatar.png') ?>" class="rounded-circle mx-auto mb-3" style="width:90px;height:90px;object-fit:cover;">
              <h5 class="fw-bold mb-1"><?= e($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']) ?></h5>
              <div class="badge bg-primary-subtle text-primary mb-3"><?= e($selectedStudent['student_number']) ?></div>
              <ul class="list-group list-group-flush text-start small">
                <li class="list-group-item d-flex justify-content-between px-0"><span>Course / Section:</span><strong><?= e($selectedStudent['course'] . ' ' . $selectedStudent['year_level'] . '-' . $selectedStudent['section']) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0"><span>Gender / DOB:</span><strong><?= e($selectedStudent['gender'] ?: '—') ?> / <?= e($selectedStudent['date_of_birth'] ?: '—') ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0"><span>Contact:</span><strong><?= e($selectedStudent['phone'] ?: '—') ?></strong></li>
                <li class="list-group-item d-flex justify-content-between px-0"><span>Email:</span><strong class="text-truncate" style="max-width:160px;"><?= e($selectedStudent['email']) ?></strong></li>
              </ul>
              <a href="consultations.php?student_id=<?= (int)$selectedStudent['id'] ?>" class="btn btn-primary w-100 mt-3 rounded-pill">
                <i class="bi bi-plus-circle me-1"></i> Start New Consultation
              </a>
            </div>
          </div>

          <!-- Medical History & Vitals -->
          <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
              <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history text-primary me-2"></i>Clinical Consultation History</h5>
              </div>
              <div class="card-body px-4 py-3">
                <?php if (empty($historyConsults)): ?>
                  <p class="text-muted text-center py-4">No previous consultations recorded for this student.</p>
                <?php else: ?>
                  <div class="timeline">
                    <?php foreach ($historyConsults as $hc): ?>
                      <div class="border-start border-3 border-primary ps-3 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                          <strong class="text-dark"><?= e($hc['diagnosis'] ?: $hc['chief_complaint']) ?></strong>
                          <small class="text-muted"><?= date('M d, Y h:i A', strtotime($hc['consultation_date'])) ?></small>
                        </div>
                        <p class="small text-muted mb-1"><strong>Complaint:</strong> <?= e($hc['chief_complaint']) ?></p>
                        <?php if ($hc['treatment']): ?><p class="small text-muted mb-1"><strong>Treatment:</strong> <?= e($hc['treatment']) ?></p><?php endif; ?>
                        <?php if ($hc['notes']): ?><p class="small text-muted mb-0"><strong>Physician Notes:</strong> <?= e($hc['notes']) ?></p><?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Vital Signs Table -->
            <div class="card border-0 shadow-sm rounded-4">
              <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-heart-pulse-fill text-danger me-2"></i>Recorded Vital Signs</h5>
              </div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="table-light text-muted small">
                      <tr>
                        <th class="ps-4">Date</th>
                        <th>BP</th>
                        <th>Temp (°C)</th>
                        <th>Heart Rate</th>
                        <th>BMI</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($vitalHistory)): ?>
                        <tr><td colspan="5" class="text-center py-3 text-muted">No recorded vitals.</td></tr>
                      <?php else: ?>
                        <?php foreach ($vitalHistory as $vh): ?>
                          <tr>
                            <td class="ps-4"><?= date('M d, Y', strtotime($vh['recorded_at'])) ?></td>
                            <td><?= e($vh['blood_pressure'] ?: '—') ?></td>
                            <td><?= e($vh['temperature'] ?: '—') ?></td>
                            <td><?= e($vh['heart_rate'] ?: '—') ?> bpm</td>
                            <td><?= e($vh['bmi'] ?: '—') ?></td>
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
      <?php endif; ?>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>