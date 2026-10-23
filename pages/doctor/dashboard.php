<?php
require_once __DIR__ . '/../../config/config.php';
require_doctor();
require_once __DIR__ . '/../../includes/doctor_clinical_service.php';
$data = (new DoctorClinicalService(getDB(), current_user()))->summary();
$pageTitle = 'Clinical Oversight Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <main id="mainContent" class="content-area">
      <div class="app-page-head">
        <h1 class="h4">Clinical Oversight Dashboard</h1>
        <p class="small text-muted">Doctor / Clinical Co-Administrator — Verified clinic data as of <?= e($data['generated_at']) ?></p>
      </div>

      <div class="row g-3 mb-3">
        <?php foreach ([
          'today_consultations' => 'Consultations today',
          'queue_awaiting_completion' => 'Queue awaiting completion',
          'pending_nurse_verifications' => 'Nurse Verifications Pending',
          'active_nurses' => 'Active nurse accounts',
          'stock_warnings' => 'Medicine stock warnings',
          'active_emergencies' => 'Active SOS emergencies'
        ] as $key => $label): ?>
          <div class="col-6 col-xl-4 col-xxl-2">
            <div class="card p-3 border-0 shadow-sm h-100">
              <span class="small text-muted"><?= $label ?></span>
              <strong class="fs-3 <?= ($key === 'pending_nurse_verifications' && !empty($data[$key])) ? 'text-warning' : '' ?>"><?= (int)($data[$key] ?? 0) ?></strong>
              <?php if ($key === 'pending_nurse_verifications' && !empty($data[$key])): ?>
                <a href="nurse_verification.php" class="small text-decoration-none mt-1">Review requests &rarr;</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="nurse_verification.php" class="btn btn-sm btn-success">
          <i class="bi bi-person-check me-1"></i>Nurse Verification
          <?php if (!empty($data['pending_nurse_verifications'])): ?>
            <span class="badge bg-warning text-dark ms-1"><?= (int)$data['pending_nurse_verifications'] ?></span>
          <?php endif; ?>
        </a>
        <a href="consultations.php" class="btn btn-sm btn-primary">Document consultation</a>
        <a href="patients.php" class="btn btn-sm btn-outline-primary">Patient Records</a>
        <a href="../admin/inventory.php" class="btn btn-sm btn-outline-primary">Medicine Oversight</a>
        <a href="../admin/emergencies.php" class="btn btn-sm btn-outline-danger">Emergency Oversight</a>
      </div>

      <section class="card p-3 border-0 shadow-sm">
        <h2 class="h6">Recorded consultation volume, last seven calendar days</h2>
        <p class="small text-muted">Counts are encounters, not unique patients. Active nurse accounts describe availability in the system, not staffing or attendance.</p>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>Date</th>
                <th>Consultations</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($data['trend'] as $row): ?>
                <tr>
                  <td><?= e($row['day']) ?></td>
                  <td><?= (int)$row['consultations'] ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$data['trend']): ?>
                <tr>
                  <td colspan="2" class="text-muted text-center py-3">No recorded consultations in this period.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
