<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor');
$db = getDB();
$u = current_user();

$studentIdReq = (int)($_GET['student_id'] ?? 0);
$selectedStudent = null;
if ($studentIdReq > 0) {
    $stmt = $db->prepare("
        SELECT s.*, u.first_name, u.middle_name, u.last_name, u.email, u.gender, u.date_of_birth, u.phone
        FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ?
    ");
    $stmt->execute([$studentIdReq]);
    $selectedStudent = $stmt->fetch();
}

// Handle New Consultation POST
$flashMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_consultation'])) {
    csrf_verify_or_die();
    $targetStudentId = (int)$_POST['student_id'];
    $chiefComplaint  = trim($_POST['chief_complaint'] ?? '');
    $diagnosis       = trim($_POST['diagnosis'] ?? '');
    $treatment       = trim($_POST['treatment'] ?? '');
    $bp              = trim($_POST['blood_pressure'] ?? '');
    $temp            = trim($_POST['temperature'] ?? '');
    $pulse           = trim($_POST['pulse_rate'] ?? '');
    $notes           = trim($_POST['notes'] ?? '');

    if ($targetStudentId > 0 && ($chiefComplaint || $diagnosis)) {
        $stmt = $db->prepare("
            INSERT INTO consultations (student_id, nurse_id, consultation_date, chief_complaint, diagnosis, treatment, notes)
            VALUES (?, ?, NOW(), ?, ?, ?, ?)
        ");
        $stmt->execute([$targetStudentId, $u['id'], $chiefComplaint, $diagnosis, $treatment, $notes]);
        $consultId = $db->lastInsertId();

        // If vitals provided, record to health_monitoring
        if ($bp || $temp || $pulse) {
            $stmt = $db->prepare("
                INSERT INTO health_monitoring (student_id, blood_pressure, temperature, heart_rate, recorded_at, recorded_by)
                VALUES (?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([$targetStudentId, $bp ?: null, $temp ?: null, $pulse ?: null, $u['id']]);
        }

        log_activity($u['id'], 'Recorded medical consultation', 'consultations', "Student #$targetStudentId, Consult #$consultId");
        set_flash('success', 'Clinical consultation and diagnosis recorded successfully.');
        redirect("consultations.php?student_id=$targetStudentId");
    }
}

// Fetch all students for dropdown
$allStudents = $db->query("
    SELECT s.id, s.student_number, u.first_name, u.last_name, s.course, s.section
    FROM students s JOIN users u ON u.id = s.user_id ORDER BY u.last_name ASC
")->fetchAll();

// Recent consultations list
$consults = $db->query("
    SELECT c.*, s.student_number, u.first_name, u.last_name, u.gender
    FROM consultations c
    JOIN students s ON s.id = c.student_id
    JOIN users u ON u.id = s.user_id
    ORDER BY c.consultation_date DESC LIMIT 50
")->fetchAll();

$pageTitle = 'Medical Consultations';
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
          <h3 class="fw-bold mb-1"><i class="bi bi-stethoscope text-primary me-2"></i>Medical Consultations &amp; Clinical Notes</h3>
          <p class="text-muted mb-0">Record physical exams, clinical diagnoses, vital signs, and treatment plans.</p>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newConsultModal">
          <i class="bi bi-plus-circle me-1"></i> New Clinical Consultation
        </button>
      </div>

      <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?> py-2 small d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-check-circle-fill"></i>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
          <h5 class="fw-bold text-dark mb-0">Consultation History</h5>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light text-muted small">
                <tr>
                  <th class="ps-4">Date &amp; Time</th>
                  <th>Patient</th>
                  <th>Chief Complaint</th>
                  <th>Clinical Diagnosis</th>
                  <th>Treatment Plan</th>
                  <th class="text-end pe-4">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($consults)): ?>
                  <tr><td colspan="6" class="text-center py-5 text-muted">No consultation records logged yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($consults as $c): ?>
                    <tr>
                      <td class="ps-4">
                        <div class="fw-semibold text-dark"><?= date('M d, Y', strtotime($c['consultation_date'])) ?></div>
                        <small class="text-muted"><?= date('h:i A', strtotime($c['consultation_date'])) ?></small>
                      </td>
                      <td>
                        <div class="fw-semibold text-dark"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></div>
                        <small class="text-muted"><?= e($c['student_number']) ?></small>
                      </td>
                      <td><?= e($c['chief_complaint']) ?></td>
                      <td><span class="badge bg-info-subtle text-info border border-info-subtle"><?= e($c['diagnosis'] ?: 'Unspecified') ?></span></td>
                      <td><?= e($c['treatment'] ?: '—') ?></td>
                      <td class="text-end pe-4">
                        <a href="medical_records.php?student_id=<?= (int)$c['student_id'] ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                          <i class="bi bi-file-medical"></i> View Chart
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

<!-- Modal: New Clinical Consultation -->
<div class="modal fade" id="newConsultModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow rounded-4">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-stethoscope text-primary me-2"></i>Record Clinical Consultation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="save_consultation" value="1">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-bold">Select Patient *</label>
            <select name="student_id" class="form-select" required>
              <option value="">-- Choose Patient --</option>
              <?php foreach ($allStudents as $st): ?>
                <option value="<?= (int)$st['id'] ?>" <?= ($selectedStudent && $selectedStudent['id'] == $st['id']) ? 'selected' : '' ?>>
                  <?= e($st['last_name'] . ', ' . $st['first_name'] . ' (' . $st['student_number'] . ' - ' . $st['course'] . ' ' . $st['section'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Blood Pressure</label>
              <input type="text" name="blood_pressure" class="form-control" placeholder="e.g. 120/80">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Temperature (°C)</label>
              <input type="number" step="0.1" name="temperature" class="form-control" placeholder="e.g. 36.5">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Heart / Pulse Rate (bpm)</label>
              <input type="number" name="pulse_rate" class="form-control" placeholder="e.g. 75">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-bold">Chief Complaint &amp; Symptoms *</label>
            <textarea name="chief_complaint" class="form-control" rows="2" required placeholder="Describe chief complaint, onset, and symptoms"></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-bold">Clinical Diagnosis *</label>
            <input type="text" name="diagnosis" class="form-control" required placeholder="e.g. Acute Upper Respiratory Tract Infection (URTI), Tension Headache">
          </div>

          <div class="mb-3">
            <label class="form-label small fw-bold">Treatment Plan &amp; Medication Orders</label>
            <textarea name="treatment" class="form-control" rows="2" placeholder="Medications, dosage, frequency, rest recommendations"></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold">Physician Clinical Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Additional observations, follow-up instructions, referral recommendations"></textarea>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary rounded-pill px-4">Save Consultation</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($selectedStudent): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('newConsultModal')).show();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>