<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();

$totalStudents   = $db->query("SELECT COUNT(*) FROM students")->fetchColumn();
$todaysAppts     = $db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn();
$totalRecords    = $db->query("SELECT COUNT(*) FROM consultations")->fetchColumn();
$medicineCount   = $db->query("SELECT COALESCE(SUM(quantity),0) FROM medicines WHERE is_archived = 0")->fetchColumn();
$lowStockAlerts  = $db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= reorder_level")->fetchColumn();
$pendingRequests = $db->query("SELECT COUNT(*) FROM appointments WHERE status = 'pending'")->fetchColumn();

$visitsStmt = $db->query("
  SELECT DATE_FORMAT(consultation_date, '%Y-%m') ym, COUNT(*) cnt
  FROM consultations
  WHERE consultation_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  GROUP BY ym ORDER BY ym");
$visits = $visitsStmt->fetchAll();

$illnessStmt = $db->query("
  SELECT COALESCE(NULLIF(diagnosis,''),'Unspecified') dx, COUNT(*) cnt
  FROM consultations GROUP BY dx ORDER BY cnt DESC LIMIT 6");
$illnesses = $illnessStmt->fetchAll();

$medConsumeStmt = $db->query("
  SELECT m.medicine_name, COALESCE(SUM(CASE WHEN l.action='stock_out' THEN l.quantity ELSE 0 END),0) used
  FROM medicines m LEFT JOIN medicine_logs l ON l.medicine_id = m.id
  WHERE m.is_archived = 0
  GROUP BY m.id ORDER BY used DESC LIMIT 6");
$medConsume = $medConsumeStmt->fetchAll();

$bmiTrendStmt = $db->query("
  SELECT DATE_FORMAT(recorded_at,'%Y-%m') ym, ROUND(AVG(bmi),1) avg_bmi
  FROM health_monitoring
  WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND bmi IS NOT NULL
  GROUP BY ym ORDER BY ym");
$bmiTrend = $bmiTrendStmt->fetchAll();

$pageTitle = 'Admin Dashboard';
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

      <!-- Welcome Card — Medical Command Center -->
      <div class="welcome-card" data-aos="fade-down" data-aos-duration="600">
        <div class="welcome-content">
          <div class="clinic-status open"><span class="status-dot"></span> Clinic Open Today</div>
          <h1>AI-Powered School Clinic Management System</h1>
          <div class="welcome-sub">Health Monitoring and Appointment Assistance</div>
          <div class="welcome-desc">Professional healthcare management platform for Bulacan Polytechnic College. Monitor student health, manage appointments, and track medical records.</div>
          <div class="welcome-actions">
            <a href="appointments.php" class="btn btn-white"><i class="bi bi-calendar-plus me-1"></i> View Appointments</a>
            <a href="inventory.php" class="btn btn-outline-white"><i class="bi bi-capsule me-1"></i> Medicine Inventory</a>
            <a href="reports.php" class="btn btn-outline-white"><i class="bi bi-file-earmark-bar-graph me-1"></i> Reports</a>
          </div>
        </div>
        <div class="welcome-visual">
          <div class="welcome-icon"><i class="bi bi-building"></i></div>
        </div>
        <!-- Subtle ECG line -->
        <div class="ecg-line" style="position:absolute;bottom:0;left:0;right:0;opacity:0.06;">
          <svg viewBox="0 0 400 40" preserveAspectRatio="none">
            <path class="ecg-path" d="M0,20 L50,20 L60,8 L70,32 L80,20 L130,20 L140,10 L145,20 L150,20 L200,20 L210,12 L220,28 L230,20 L280,20 L290,15 L295,20 L300,20 L350,20 L360,18 L370,22 L380,20 L400,20" stroke="white" stroke-width="2" fill="none"/>
          </svg>
        </div>
      </div>

      <?php if (getenv('FIREBASE_PROJECT_ID')): ?>
      <div class="alert alert-success d-flex align-items-center gap-3 fade-in" data-aos="fade-up" data-aos-delay="200">
        <div class="fs-3"><i class="bi bi-bell"></i></div>
        <div class="flex-grow-1">
          <strong>Enable Push Notifications</strong>
          <div class="small text-muted">Receive SOS alerts and clinic updates directly on your device.</div>
        </div>
        <button class="btn btn-success" id="enableFcmBtn">
          <i class="bi bi-bell-fill me-1"></i> Enable Notifications
        </button>
      </div>
      <?php endif; ?>

      <!-- Medical Stat Cards (Gradient) -->
      <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="100">
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-green">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-people-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$totalStudents ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Total Students</div>
              <div class="msc-trend up"><i class="bi bi-arrow-up-short"></i> Enrolled</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-blue">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-calendar-check-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$todaysAppts ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Today's Appointments</div>
              <div class="msc-trend up"><i class="bi bi-arrow-up-short"></i> Active</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-teal">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-file-earmark-medical-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$totalRecords ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Medical Records</div>
              <div class="msc-trend up"><i class="bi bi-arrow-up-short"></i> Documented</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-red">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$lowStockAlerts ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Health / Stock Alerts</div>
              <div class="msc-trend down"><i class="bi bi-arrow-down-short"></i> Needs attention</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions — Large Interactive Cards -->
      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-lightning-fill"></i> Quick Actions</h6>
      <div class="quick-actions-grid" data-aos="fade-up" data-aos-delay="50">
        <a href="appointments.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-calendar-plus-fill"></i></div>
          <div class="qa-title-lg">Manage Appointments</div>
          <div class="qa-sub-lg">View & manage all bookings</div>
        </a>
        <a href="users.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-people-fill"></i></div>
          <div class="qa-title-lg">User Management</div>
          <div class="qa-sub-lg">Manage accounts & roles</div>
        </a>
        <a href="medical_records.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-file-earmark-medical-fill"></i></div>
          <div class="qa-title-lg">Medical Records</div>
          <div class="qa-sub-lg">View student records</div>
        </a>
        <a href="inventory.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-capsule-pill"></i></div>
          <div class="qa-title-lg">Medicine Inventory</div>
          <div class="qa-sub-lg">Manage stock levels</div>
        </a>
        <a href="reports.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-file-earmark-bar-graph-fill"></i></div>
          <div class="qa-title-lg">Generate Reports</div>
          <div class="qa-sub-lg">Export clinic data</div>
        </a>
        <a href="announcements.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-megaphone-fill"></i></div>
          <div class="qa-title-lg">Announcements</div>
          <div class="qa-sub-lg">Post clinic updates</div>
        </a>
      </div>

      <!-- Charts Row -->
      <div class="row g-3" data-aos="fade-up" data-aos-delay="150">
        <div class="col-xl-6">
          <div class="card p-3 h-100">
            <div class="card-header d-flex align-items-center gap-2">
              <i class="bi bi-graph-up text-primary"></i>
              <span>Monthly Clinic Visits</span>
            </div>
            <div class="card-body">
              <canvas id="visitsChart" height="180"></canvas>
            </div>
          </div>
        </div>
        <div class="col-xl-6">
          <div class="card p-3 h-100">
            <div class="card-header d-flex align-items-center gap-2">
              <i class="bi bi-activity text-primary"></i>
              <span>Common Illnesses</span>
            </div>
            <div class="card-body">
              <canvas id="illnessChart" height="180"></canvas>
            </div>
          </div>
        </div>
        <div class="col-xl-6">
          <div class="card p-3 h-100">
            <div class="card-header d-flex align-items-center gap-2">
              <i class="bi bi-capsule text-primary"></i>
              <span>Medicine Consumption</span>
            </div>
            <div class="card-body">
              <canvas id="medChart" height="180"></canvas>
            </div>
          </div>
        </div>
        <div class="col-xl-6">
          <div class="card p-3 h-100">
            <div class="card-header d-flex align-items-center gap-2">
              <i class="bi bi-heart-pulse-fill text-primary"></i>
              <span>Student Health Trends (Avg BMI)</span>
            </div>
            <div class="card-body">
              <canvas id="bmiChart" height="180"></canvas>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
(function () {
  'use strict';
  const btn = document.getElementById('enableFcmBtn');
  if (btn && typeof window.enableFCMNotifications === 'function') {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Requesting...';
      window.enableFCMNotifications();
      setTimeout(function () {
        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> Enabled';
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-success');
      }, 2000);
    });
  }
})();
</script>
<script>
const visitsData = <?= json_encode($visits) ?>;
const illnessData = <?= json_encode($illnesses) ?>;
const medData = <?= json_encode($medConsume) ?>;
const bmiData = <?= json_encode($bmiTrend) ?>;
const green = '#0B6B3A';

new Chart(document.getElementById('visitsChart'), {
  type: 'line',
  data: { labels: visitsData.map(v=>v.ym), datasets: [{ label:'Visits', data: visitsData.map(v=>v.cnt), borderColor: green, backgroundColor:'rgba(11,107,58,0.12)', fill:true, tension:.35 }] },
  options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, grid:{color:'rgba(0,0,0,0.04)'}}}, responsive:true }
});
new Chart(document.getElementById('illnessChart'), {
  type: 'bar',
  data: { labels: illnessData.map(v=>v.dx), datasets: [{ label:'Cases', data: illnessData.map(v=>v.cnt), backgroundColor: green, borderRadius:6 }] },
  options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true, grid:{color:'rgba(0,0,0,0.04)'}}}, responsive:true }
});
new Chart(document.getElementById('medChart'), {
  type: 'doughnut',
  data: { labels: medData.map(v=>v.medicine_name), datasets: [{ data: medData.map(v=>v.used), backgroundColor:['#0B6B3A','#2563EB','#F59E0B','#DC3545','#16A34A','#0891B2'] }] },
  options: { plugins:{legend:{position:'bottom'}}, responsive:true }
});
new Chart(document.getElementById('bmiChart'), {
  type: 'line',
  data: { labels: bmiData.map(v=>v.ym), datasets: [{ label:'Avg BMI', data: bmiData.map(v=>v.avg_bmi), borderColor:'#2563EB', backgroundColor:'rgba(37,99,235,0.12)', fill:true, tension:.35 }] },
  options: { plugins:{legend:{display:false}}, scales:{y:{grid:{color:'rgba(0,0,0,0.04)'}}}, responsive:true }
});
</script>
</body>
</html>
