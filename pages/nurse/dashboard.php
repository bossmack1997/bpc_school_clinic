<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$db = getDB();
$u = current_user();

$stmt = $db->prepare("SELECT id FROM nurses WHERE user_id = ?");
$stmt->execute([$u['id']]);
$nurseId = $stmt->fetchColumn();

$todaysPatients = $db->prepare("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = CURDATE()");
$todaysPatients->execute();
$todaysPatients = $todaysPatients->fetchColumn();

$todaysAppts = $db->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status IN ('approved','pending')");
$todaysAppts->execute();
$todaysAppts = $todaysAppts->fetchColumn();

$pendingAppts = $db->query("SELECT COUNT(*) FROM appointments WHERE status='pending'")->fetchColumn();
$lowStock = $db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= reorder_level")->fetchColumn();
$expiredMeds = $db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND expiration_date IS NOT NULL AND expiration_date < CURDATE()")->fetchColumn();

$upcoming = $db->query("
  SELECT a.*, s.student_number, u.first_name, u.last_name
  FROM appointments a
  JOIN students s ON s.id = a.student_id
  JOIN users u ON u.id = s.user_id
  WHERE a.appointment_date >= CURDATE() AND a.status IN ('pending','approved')
  ORDER BY a.appointment_date, a.appointment_time LIMIT 8
")->fetchAll();

$pageTitle = 'Nurse Dashboard';
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

      <!-- Welcome Card — Nurse Station -->
      <div class="welcome-card" data-aos="fade-down" data-aos-duration="600">
        <div class="welcome-content">
          <div class="clinic-status open"><span class="status-dot"></span> Nurse Station — Open</div>
          <h1>Nurse Station Dashboard</h1>
          <div class="welcome-sub">Welcome, <?= e($u['first_name']) ?>! 👋</div>
          <div class="welcome-desc">Manage patient consultations, appointments, and health monitoring for Bulacan Polytechnic College students.</div>
          <div class="welcome-actions">
            <a href="walkin.php" class="btn btn-white"><i class="bi bi-person-walking me-1"></i> Walk-in Patient</a>
            <a href="appointments.php" class="btn btn-outline-white"><i class="bi bi-calendar-check me-1"></i> Appointments</a>
            <a href="health_monitoring.php" class="btn btn-outline-white"><i class="bi bi-heart-pulse me-1"></i> Record Vitals</a>
          </div>
        </div>
        <div class="welcome-visual">
          <div class="welcome-icon"><i class="bi bi-hospital"></i></div>
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

      <!-- Medical Stat Cards -->
      <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="100">
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-green">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-hospital-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$todaysPatients ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Today's Patients</div>
              <div class="msc-trend up"><i class="bi bi-arrow-up-short"></i> Active</div>
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
              <div class="msc-trend up"><i class="bi bi-arrow-up-short"></i> Scheduled</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-orange">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-hourglass-split"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$pendingAppts ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Pending Requests</div>
              <div class="msc-trend down"><i class="bi bi-arrow-down-short"></i> Awaiting</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-red">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$lowStock ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Low Stock Medicines</div>
              <div class="msc-trend down"><i class="bi bi-arrow-down-short"></i> Needs restock</div>
            </div>
          </div>
        </div>
      </div>

      <?php if ($expiredMeds > 0): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 py-2 px-3 rounded-3" data-aos="fade-up" role="alert">
          <i class="bi bi-exclamation-octagon-fill"></i>
          <span><?= (int)$expiredMeds ?> medicine(s) have expired. Please review <a href="inventory.php" class="alert-link">Inventory</a>.</span>
        </div>
      <?php endif; ?>

      <!-- Quick Actions — Large Interactive Cards -->
      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-lightning-fill"></i> Quick Actions</h6>
      <div class="quick-actions-grid" data-aos="fade-up" data-aos-delay="50">
        <a href="walkin.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-person-walking"></i></div>
          <div class="qa-title-lg">Walk-in Patient</div>
          <div class="qa-sub-lg">Register a new visit</div>
        </a>
        <a href="appointments.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-calendar-check-fill"></i></div>
          <div class="qa-title-lg">Appointments</div>
          <div class="qa-sub-lg">Manage bookings</div>
        </a>
        <a href="health_monitoring.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-heart-pulse-fill"></i></div>
          <div class="qa-title-lg">Record Vitals</div>
          <div class="qa-sub-lg">Monitor student health</div>
        </a>
        <a href="dispensing.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-capsule"></i></div>
          <div class="qa-title-lg">Dispense Medicine</div>
          <div class="qa-sub-lg">Issue medications</div>
        </a>
        <a href="certificates.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-file-medical-fill"></i></div>
          <div class="qa-title-lg">Medical Certificates</div>
          <div class="qa-sub-lg">Issue medical certs</div>
        </a>
        <a href="#" class="quick-action-card-lg text-decoration-none" onclick="if(typeof MICA!=='undefined'&&MICA.open)MICA.open();return false;">
          <div class="qa-icon-lg"><i class="bi bi-robot"></i></div>
          <div class="qa-title-lg">Ask MICA</div>
          <div class="qa-sub-lg">Get AI health guidance</div>
        </a>
      </div>

      <!-- Upcoming Appointments -->
      <div class="card" data-aos="fade-up" data-aos-delay="150">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-calendar-week text-primary"></i>
          <span>Upcoming Appointments</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Student No.</th>
                  <th>Date</th>
                  <th>Time</th>
                  <th>Reason</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($upcoming as $a): ?>
                <tr>
                  <td class="fw-medium"><?= e($a['first_name'].' '.$a['last_name']) ?></td>
                  <td><?= e($a['student_number']) ?></td>
                  <td><?= format_date($a['appointment_date']) ?></td>
                  <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
                  <td><?= e($a['reason']) ?></td>
                  <td><span class="badge status-<?= e($a['status']) ?>"><?= ucfirst($a['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$upcoming): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox me-1"></i> No upcoming appointments</td></tr>
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
</body>
</html>
