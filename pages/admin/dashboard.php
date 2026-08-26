<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$u = current_user();

// Dynamic Admin Statistics
$pendingApprovals = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
$todaysAppts      = (int)$db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn();
$totalStudents    = (int)$db->query("SELECT COUNT(*) FROM students")->fetchColumn();
$todaysVisits     = (int)$db->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = CURDATE()")->fetchColumn();
$totalRecords     = (int)$db->query("SELECT COUNT(*) FROM consultations")->fetchColumn();
$lowStockAlerts   = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= reorder_level")->fetchColumn();

// 6-Month Visits Trend
$visitsStmt = $db->query("
  SELECT DATE_FORMAT(consultation_date, '%Y-%m') ym, COUNT(*) cnt
  FROM consultations
  WHERE consultation_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  GROUP BY ym ORDER BY ym");
$visits = $visitsStmt->fetchAll();

// Top Diagnoses / Illnesses
$illnessStmt = $db->query("
  SELECT COALESCE(NULLIF(diagnosis,''),'Unspecified') dx, COUNT(*) cnt
  FROM consultations GROUP BY dx ORDER BY cnt DESC LIMIT 6");
$illnesses = $illnessStmt->fetchAll();

// Announcements
$announcements = $db->query("SELECT * FROM announcements ORDER BY is_pinned DESC, created_at DESC LIMIT 4")->fetchAll();

// Recent Pending Users (Top 5)
$pendingUsers = $db->query("
  SELECT u.*, s.student_number, n.employee_id
  FROM users u
  LEFT JOIN students s ON s.user_id = u.id
  LEFT JOIN nurses n ON n.user_id = u.id
  WHERE u.status = 'pending'
  ORDER BY u.created_at DESC
  LIMIT 5
")->fetchAll();

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

      <!-- Welcome Card — Admin Command Center (Matching Student Portal Visual Language) -->
      <div class="welcome-card" data-aos="fade-down" data-aos-duration="600">
        <div class="welcome-content">
          <div class="clinic-status open"><span class="status-dot"></span> Clinic Open Today</div>
          <h1>Welcome, <?= e($u['first_name']) ?>! 👋</h1>
          <div class="welcome-sub">BPC Clinic Management System</div>
          <div class="welcome-desc">Administrator Portal · Manage clinic operations, users, appointments, records, announcements, and reports.</div>
          <div class="welcome-actions">
            <a href="users.php" class="btn btn-white"><i class="bi bi-people-fill me-1"></i> Manage Users</a>
            <a href="appointments.php" class="btn btn-outline-white"><i class="bi bi-calendar-check me-1"></i> Appointments</a>
            <a href="medical_records.php" class="btn btn-outline-white"><i class="bi bi-file-earmark-medical me-1"></i> Health Records</a>
            <a href="announcements.php" class="btn btn-outline-white"><i class="bi bi-megaphone me-1"></i> Announcements</a>
            <a href="#" class="btn btn-outline-white" onclick="if(typeof MICA!=='undefined'&&MICA.open)MICA.open();return false;"><i class="bi bi-robot me-1"></i> Ask MICA</a>
          </div>
          <div class="welcome-note mt-2 small"><i class="bi bi-info-circle me-1"></i> Full administrative oversight of clinic appointments, student records, user accounts, and system analytics.</div>
        </div>
        <div class="welcome-visual">
          <div class="welcome-icon"><i class="bi bi-shield-lock"></i></div>
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

      <!-- Summary Stat Cards (Matching Student Gradient Cards) -->
      <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="100">
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-green">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-person-check-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$pendingApprovals ?>"><?= (int)$pendingApprovals ?></span></div>
            </div>
            <div>
              <div class="msc-label">Pending Account Approvals</div>
              <div class="msc-trend up"><i class="bi bi-hourglass-split"></i> <?= $pendingApprovals > 0 ? $pendingApprovals . ' awaiting review' : 'All approved' ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-blue">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-calendar-check-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$todaysAppts ?>"><?= (int)$todaysAppts ?></span></div>
            </div>
            <div>
              <div class="msc-label">Today's Appointments</div>
              <div class="msc-trend up"><i class="bi bi-calendar-event"></i> Scheduled today</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-teal">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-people-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$totalStudents ?>"><?= (int)$totalStudents ?></span></div>
            </div>
            <div>
              <div class="msc-label">Total Students</div>
              <div class="msc-trend up"><i class="bi bi-mortarboard"></i> Registered</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-purple">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-hospital-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$todaysVisits ?>"><?= (int)$todaysVisits ?></span></div>
            </div>
            <div>
              <div class="msc-label">Clinic Visits Today</div>
              <div class="msc-trend up"><i class="bi bi-arrow-up-short"></i> Consultations</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions — Large Interactive Cards (Matching Student Grid) -->
      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-lightning-fill"></i> Quick Actions</h6>
      <div class="quick-actions-grid" data-aos="fade-up" data-aos-delay="50">
        <a href="users.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-people-fill"></i></div>
          <div class="qa-title-lg">Manage Users</div>
          <div class="qa-sub-lg">Accounts &amp; roles</div>
        </a>
        <a href="appointments.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-calendar-check-fill"></i></div>
          <div class="qa-title-lg">Appointments</div>
          <div class="qa-sub-lg">Manage all bookings</div>
        </a>
        <a href="medical_records.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-file-earmark-medical-fill"></i></div>
          <div class="qa-title-lg">Health Records</div>
          <div class="qa-sub-lg">Student medical files</div>
        </a>
        <a href="inventory.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-capsule-pill"></i></div>
          <div class="qa-title-lg">Medicine Inventory</div>
          <div class="qa-sub-lg">Stock &amp; supplies</div>
        </a>
        <a href="reports.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-file-earmark-bar-graph-fill"></i></div>
          <div class="qa-title-lg">Reports &amp; Logs</div>
          <div class="qa-sub-lg">Export data &amp; audits</div>
        </a>
        <a href="announcements.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-megaphone-fill"></i></div>
          <div class="qa-title-lg">Announcements</div>
          <div class="qa-sub-lg">Post clinic notices</div>
        </a>
      </div>

      <!-- Info Alert Bar -->
      <div class="alert alert-light border d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="bi bi-info-circle-fill text-primary"></i>
        <div class="small"><strong>Administrator Command Center:</strong> Monitor clinic operations, manage account permissions, verify pending registrations, review appointments, and track medical supplies across BPC campuses.</div>
      </div>

      <!-- Main Content Grid (Matching Student 2-Column Proportions) -->
      <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="150">
        <div class="col-xl-7">
          <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-graph-up text-primary"></i>
                <span>Monthly Clinic Visits</span>
              </div>
              <a href="reports.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-bar-chart me-1"></i> Full Analytics</a>
            </div>
            <div class="card-body">
              <canvas id="visitsChart" height="160"></canvas>
            </div>
          </div>
        </div>
        <div class="col-xl-5">
          <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-megaphone-fill text-primary"></i>
                <span>Clinic Announcements</span>
              </div>
              <a href="announcements.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-circle me-1"></i> Post</a>
            </div>
            <div class="card-body">
              <?php foreach ($announcements as $an): ?>
                <div class="border-bottom py-2">
                  <div class="fw-semibold small"><?= e($an['title']) ?> <?= $an['is_pinned'] ? '<span class="badge bg-warning text-dark" style="font-size:.65rem;">Pinned</span>' : '' ?></div>
                  <div class="text-muted" style="font-size:.8rem"><?= e(mb_substr($an['content'],0,90)) ?><?= mb_strlen($an['content'])>90?'…':'' ?></div>
                  <div class="text-muted" style="font-size:.7rem"><?= format_date($an['created_at']) ?></div>
                </div>
              <?php endforeach; ?>
              <?php if (!$announcements): ?>
                <div class="text-center text-muted py-3">
                  <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                  <p class="mb-0 small">No announcements yet.</p>
                </div>
              <?php endif; ?>
              <a href="announcements.php" class="btn btn-sm btn-outline-primary w-100 mt-2">View All Announcements</a>
            </div>
          </div>
        </div>
      </div>

      <!-- Secondary Row — Pending Approvals & Illnesses -->
      <div class="row g-3" data-aos="fade-up" data-aos-delay="200">
        <div class="col-xl-7">
          <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-hourglass-split text-primary"></i>
                <span>Pending Account Approvals</span>
              </div>
              <a href="pending_accounts.php" class="btn btn-sm btn-success"><i class="bi bi-check2-circle me-1"></i> Review All</a>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                  <thead class="bg-light small text-muted">
                    <tr>
                      <th class="ps-3">Name</th>
                      <th>Role</th>
                      <th>Identifier</th>
                      <th>Registered</th>
                      <th class="text-end pe-3">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($pendingUsers as $pu): ?>
                      <tr>
                        <td class="ps-3 fw-medium text-dark"><?= e($pu['first_name'] . ' ' . $pu['last_name']) ?></td>
                        <td><span class="badge bg-info text-capitalize"><?= e($pu['role']) ?></span></td>
                        <td><span class="font-monospace small"><?= e($pu['student_number'] ?? $pu['employee_id'] ?? '—') ?></span></td>
                        <td><span class="small text-muted"><?= format_date($pu['created_at']) ?></span></td>
                        <td class="text-end pe-3">
                          <a href="pending_accounts.php" class="btn btn-sm btn-outline-success py-0 px-2">Review</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (!$pendingUsers): ?>
                      <tr>
                        <td colspan="5" class="text-center text-muted py-3 small">
                          <i class="bi bi-check-circle text-success me-1"></i> No pending account approvals.
                        </td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-5">
          <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-activity text-primary"></i>
                <span>Common Illnesses</span>
              </div>
              <a href="reports.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-medical me-1"></i> Reports</a>
            </div>
            <div class="card-body">
              <canvas id="illnessChart" height="160"></canvas>
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
</script>
</body>
</html>
