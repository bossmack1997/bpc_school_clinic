<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor');
$db = getDB();
$u = current_user();

// Fetch available medicines
$medicines = $db->query("SELECT id, medicine_name, generic_name, dosage, quantity FROM medicines WHERE is_archived = 0 AND quantity > 0 ORDER BY medicine_name ASC")->fetchAll();
$students = $db->query("SELECT s.id, s.student_number, u.first_name, u.last_name FROM students s JOIN users u ON u.id = s.user_id ORDER BY u.last_name ASC")->fetchAll();

$pageTitle = 'E-Prescriptions';
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
          <h3 class="fw-bold mb-1"><i class="bi bi-capsule-pill text-primary me-2"></i>Physician E-Prescriptions</h3>
          <p class="text-muted mb-0">Generate electronic medical prescriptions and clinic medicine dispensing orders.</p>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-lg-5">
          <div class="card border-0 shadow-sm rounded-4 p-4">
            <h5 class="fw-bold mb-3"><i class="bi bi-pencil-square me-2 text-primary"></i>Write Prescription</h5>
            <form id="rxForm">
              <div class="mb-3">
                <label class="form-label small fw-bold">Select Patient *</label>
                <select id="rxStudent" class="form-select" required>
                  <option value="">-- Choose Patient --</option>
                  <?php foreach ($students as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" data-name="<?= e($s['first_name'] . ' ' . $s['last_name']) ?>" data-sno="<?= e($s['student_number']) ?>">
                      <?= e($s['last_name'] . ', ' . $s['first_name'] . ' (' . $s['student_number'] . ')') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label small fw-bold">Select Clinic Medicine</label>
                <select id="rxMed" class="form-select">
                  <option value="">-- Choose Available Medicine --</option>
                  <?php foreach ($medicines as $m): ?>
                    <option value="<?= e($m['medicine_name']) ?>" data-dosage="<?= e($m['dosage']) ?>">
                      <?= e($m['medicine_name'] . ' (' . $m['generic_name'] . ' - ' . $m['dosage'] . ') - ' . $m['quantity'] . ' in stock') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label small fw-bold">Dosage &amp; Frequency *</label>
                <input type="text" id="rxDosage" class="form-control" placeholder="e.g. 500mg, 1 tablet every 8 hours after meals">
              </div>

              <div class="mb-3">
                <label class="form-label small fw-bold">Duration *</label>
                <input type="text" id="rxDuration" class="form-control" placeholder="e.g. 5 days (15 tablets total)">
              </div>

              <div class="mb-3">
                <label class="form-label small fw-semibold">Special Instructions</label>
                <textarea id="rxInstructions" class="form-control" rows="2" placeholder="Drink plenty of water, avoid citrus, etc."></textarea>
              </div>

              <button type="button" class="btn btn-primary w-100 rounded-pill" onclick="printRx()">
                <i class="bi bi-printer me-1"></i> Generate &amp; Print E-Prescription
              </button>
            </form>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card border-0 shadow-sm rounded-4 p-4" id="rxPreviewArea" style="background:#f8fafc;">
            <div class="text-center pb-3 border-bottom">
              <h5 class="fw-bold text-success mb-1">BULACAN POLYTECHNIC COLLEGE</h5>
              <div class="small text-muted">School Clinic &bull; Medical Services</div>
              <h6 class="fw-bold text-dark mt-2 mb-0">MEDICAL PRESCRIPTION (Rx)</h6>
            </div>
            <div class="py-3 border-bottom small">
              <div class="d-flex justify-content-between mb-1"><span><strong>Patient:</strong> <span id="prevName">—</span></span><span><strong>Date:</strong> <?= date('F d, Y') ?></span></div>
              <div class="d-flex justify-content-between"><span><strong>Student ID:</strong> <span id="prevSno">—</span></span><span><strong>Clinic Doctor:</strong> Dr. <?= e($u['first_name'] . ' ' . $u['last_name']) ?></span></div>
            </div>
            <div class="py-4" style="min-height: 200px;">
              <div class="display-6 fw-bold text-primary mb-3">℞</div>
              <div id="prevMed" class="fs-5 fw-bold text-dark mb-1">Medication details will appear here</div>
              <div id="prevDose" class="text-muted mb-2">Dosage and schedule</div>
              <div id="prevInst" class="small text-secondary fst-italic">Special instructions</div>
            </div>
            <div class="pt-4 border-top d-flex justify-content-between align-items-end small text-muted">
              <div>Bulacan Polytechnic College School Clinic</div>
              <div class="text-center" style="border-top: 1px solid #333; padding-top: 4px; min-width: 180px;">
                <strong>Dr. <?= e($u['first_name'] . ' ' . $u['last_name']) ?></strong><br>
                Licensed Physician
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
function printRx() {
    const s = document.getElementById('rxStudent');
    const med = document.getElementById('rxMed').value;
    const dose = document.getElementById('rxDosage').value;
    const dur = document.getElementById('rxDuration').value;
    const inst = document.getElementById('rxInstructions').value;
    
    if (!s.value || (!med && !dose)) {
        alert('Please select a patient and fill in medication details.');
        return;
    }
    
    const opt = s.options[s.selectedIndex];
    document.getElementById('prevName').textContent = opt.dataset.name;
    document.getElementById('prevSno').textContent = opt.dataset.sno;
    document.getElementById('prevMed').textContent = med || 'Medication Order';
    document.getElementById('prevDose').textContent = (dose ? dose : '') + (dur ? ' &bull; Duration: ' + dur : '');
    document.getElementById('prevInst').textContent = inst ? 'Instructions: ' + inst : '';
    
    window.print();
}
</script>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>