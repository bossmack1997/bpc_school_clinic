<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor');
$db = getDB();
$u = current_user();

$topDiagnoses = $db->query("
    SELECT COALESCE(NULLIF(diagnosis,''),'Unspecified') dx, COUNT(*) cnt
    FROM consultations GROUP BY dx ORDER BY cnt DESC LIMIT 10
")->fetchAll();

$monthlyConsults = $db->query("
    SELECT DATE_FORMAT(consultation_date, '%Y-%m') ym, COUNT(*) cnt
    FROM consultations
    WHERE consultation_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym ASC
")->fetchAll();

$pageTitle = 'Clinical Reports';
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
          <h3 class="fw-bold mb-1"><i class="bi bi-file-earmark-bar-graph-fill text-primary me-2"></i>Clinical &amp; Morbidity Reports</h3>
          <p class="text-muted mb-0">Statistical summary of clinic consultations, illness prevalence, and patient health trends.</p>
        </div>
      </div>

      <div class="row g-4">
        <!-- Top Diagnoses -->
        <div class="col-lg-6">
          <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
              <h5 class="fw-bold text-dark mb-0"><i class="bi bi-pie-chart-fill text-primary me-2"></i>Top Diagnoses &amp; Morbidity</h5>
            </div>
            <div class="card-body px-4 py-3">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-muted">
                  <tr>
                    <th>Condition / Diagnosis</th>
                    <th class="text-end">Cases Logged</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($topDiagnoses)): ?>
                    <tr><td colspan="2" class="text-center py-4 text-muted">No diagnosis records logged.</td></tr>
                  <?php else: ?>
                    <?php foreach ($topDiagnoses as $td): ?>
                      <tr>
                        <td class="fw-medium text-dark"><?= e($td['dx']) ?></td>
                        <td class="text-end"><span class="badge bg-primary rounded-pill px-3"><?= (int)$td['cnt'] ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Consultation Summary -->
        <div class="col-lg-6">
          <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
              <h5 class="fw-bold text-dark mb-0"><i class="bi bi-graph-up text-success me-2"></i>Monthly Consultation Volume</h5>
            </div>
            <div class="card-body px-4 py-3">
              <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-muted">
                  <tr>
                    <th>Month</th>
                    <th class="text-end">Total Consultations</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($monthlyConsults)): ?>
                    <tr><td colspan="2" class="text-center py-4 text-muted">No consultation records in the last 6 months.</td></tr>
                  <?php else: ?>
                    <?php foreach ($monthlyConsults as $mc): ?>
                      <tr>
                        <td class="fw-medium text-dark"><?= date('F Y', strtotime($mc['ym'] . '-01')) ?></td>
                        <td class="text-end"><span class="badge bg-success rounded-pill px-3"><?= (int)$mc['cnt'] ?></span></td>
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
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>