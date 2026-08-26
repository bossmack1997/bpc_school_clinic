<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
$db = getDB();
$u = current_user();

$stmt = $db->prepare("SELECT * FROM students WHERE user_id = ?");
$stmt->execute([$u['id']]);
$student = $stmt->fetch();

$upcomingAppt = $db->prepare("SELECT * FROM appointments WHERE student_id=? AND appointment_date >= CURDATE() AND status IN ('pending','approved') ORDER BY appointment_date LIMIT 1");
$upcomingAppt->execute([$student['id']]);
$upcomingAppt = $upcomingAppt->fetch();

$latestHealth = $db->prepare("SELECT * FROM health_monitoring WHERE student_id=? ORDER BY recorded_at DESC LIMIT 1");
$latestHealth->execute([$student['id']]);
$latestHealth = $latestHealth->fetch();

$totalVisits = $db->prepare("SELECT COUNT(*) FROM consultations WHERE student_id=?");
$totalVisits->execute([$student['id']]);
$totalVisits = $totalVisits->fetchColumn();

$announcements = $db->query("SELECT * FROM announcements WHERE audience IN ('all','students') ORDER BY is_pinned DESC, created_at DESC LIMIT 4")->fetchAll();

$bmiHistory = $db->prepare("SELECT recorded_at, bmi FROM health_monitoring WHERE student_id=? AND bmi IS NOT NULL ORDER BY recorded_at ASC LIMIT 12");
$bmiHistory->execute([$student['id']]);
$bmiHistory = $bmiHistory->fetchAll();

$pageTitle = 'My Dashboard';
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

<!-- Welcome Card — Student Health Portal -->
      <div class="welcome-card" data-aos="fade-down" data-aos-duration="600">
        <div class="welcome-content">
          <div class="clinic-status open"><span class="status-dot"></span> Clinic Open Today</div>
          <h1>Welcome, <?= e($u['first_name']) ?>! 👋</h1>
          <div class="welcome-sub">BPC Student Health Portal</div>
          <div class="welcome-desc">Student No. <?= e($student['student_number']) ?> · <?= e($student['course'] ?: '—') ?> <?= e($student['year_level'] ?: '') ?></div>
          <div class="welcome-actions">
            <a href="my_qr.php" class="btn btn-white"><i class="bi bi-qr-code-scan me-1"></i> Visit Clinic / Check-in</a>
            <a href="wellbeing.php" class="btn btn-outline-white"><i class="bi bi-person-heart me-1"></i> My Health &amp; Well-being</a>
            <a href="#" class="btn btn-outline-white" onclick="if(typeof MICA!=='undefined'&&MICA.open)MICA.open();return false;"><i class="bi bi-robot me-1"></i> Ask MICA</a>
            <button type="button" class="btn btn-danger shadow-sm" data-bs-toggle="modal" data-bs-target="#sosModal"><i class="bi bi-shield-fill-exclamation me-1"></i> 🚨 SOS Emergency</button>
          </div>
          <div class="welcome-note mt-2 small"><i class="bi bi-info-circle me-1"></i> No appointment is required for a normal clinic visit — just walk in or scan your QR at the clinic.</div>
        </div>
        <div class="welcome-visual">
          <div class="welcome-icon"><i class="bi bi-person-circle"></i></div>
        </div>
        <!-- Subtle ECG line -->
        <div class="ecg-line" style="position:absolute;bottom:0;left:0;right:0;opacity:0.06;">
          <svg viewBox="0 0 400 40" preserveAspectRatio="none">
            <path class="ecg-path" d="M0,20 L50,20 L60,8 L70,32 L80,20 L130,20 L140,10 L145,20 L150,20 L200,20 L210,12 L220,28 L230,20 L280,20 L290,15 L295,20 L300,20 L350,20 L360,18 L370,22 L380,20 L400,20" stroke="white" stroke-width="2" fill="none"/>
          </svg>
        </div>
      </div>

      <!-- Medical Stat Cards -->
      <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="100">
        <div class="col-md-4">
          <div class="medical-stat-card gradient-green">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-calendar-check-fill"></i></div>
              <div class="msc-value" style="font-size:1.1rem"><?= $upcomingAppt ? format_date($upcomingAppt['appointment_date']) : 'None' ?></div>
            </div>
            <div>
              <div class="msc-label">Upcoming Appointment</div>
              <div class="msc-trend up"><i class="bi bi-calendar-event"></i> <?= $upcomingAppt ? date('g:i A', strtotime($upcomingAppt['appointment_time'])) : 'No upcoming' ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="medical-stat-card gradient-blue">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-activity"></i></div>
              <div class="msc-value"><?= $latestHealth['bmi'] ?? '—' ?></div>
            </div>
            <div>
              <div class="msc-label">Latest BMI</div>
              <div class="msc-trend up"><i class="bi bi-activity"></i> <?= e($latestHealth['bmi_status'] ?? 'No record') ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="medical-stat-card gradient-teal">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-file-earmark-medical-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$totalVisits ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Total Clinic Visits</div>
              <div class="msc-trend up"><i class="bi bi-arrow-up-short"></i> All time</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions — Large Interactive Cards -->
      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-lightning-fill"></i> Quick Actions</h6>
      <div class="quick-actions-grid" data-aos="fade-up" data-aos-delay="50">
        <a href="my_qr.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-qr-code-scan"></i></div>
          <div class="qa-title-lg">Visit Clinic / Check-in</div>
          <div class="qa-sub-lg">Walk in or scan your QR</div>
        </a>
        <a href="wellbeing.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-person-heart"></i></div>
          <div class="qa-title-lg">Health &amp; Well-being</div>
          <div class="qa-sub-lg">Share info voluntarily</div>
        </a>
        <a href="medical_record.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-file-earmark-medical-fill"></i></div>
          <div class="qa-title-lg">Medical Records</div>
          <div class="qa-sub-lg">View your health files</div>
        </a>
        <a href="appointments.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-calendar-check-fill"></i></div>
          <div class="qa-title-lg">Scheduled Services</div>
          <div class="qa-sub-lg">Optional follow-ups, etc.</div>
        </a>
        <a href="certificates.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-file-medical-fill"></i></div>
          <div class="qa-title-lg">Clinic Services</div>
          <div class="qa-sub-lg">Download forms & certs</div>
        </a>
        <a href="#" class="quick-action-card-lg text-decoration-none" onclick="if(typeof MICA!=='undefined'&&MICA.open)MICA.open();return false;">
          <div class="qa-icon-lg"><i class="bi bi-robot"></i></div>
          <div class="qa-title-lg">Ask MICA</div>
          <div class="qa-sub-lg">Get wellness guidance</div>
        </a>
      </div>

      <div class="alert alert-light border d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="bi bi-info-circle-fill text-primary"></i>
        <div class="small"><strong>No appointment is required for normal clinic visits.</strong> You can go directly to the school clinic whenever you need care. Scheduled Services are optional and intended for follow-ups, vaccinations, screenings, and other non-urgent services.</div>
      </div>

      <!-- BMI & Announcements Row -->
      <div class="row g-3" data-aos="fade-up" data-aos-delay="150">
        <div class="col-xl-7">
          <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
              <i class="bi bi-graph-up text-primary"></i>
              <span>BMI History</span>
            </div>
            <div class="card-body">
              <canvas id="bmiChart" height="160"></canvas>
            </div>
          </div>
        </div>
        <div class="col-xl-5">
          <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
              <i class="bi bi-megaphone-fill text-primary"></i>
              <span>Clinic Announcements</span>
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

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const bmiHist = <?= json_encode($bmiHistory) ?>;
new Chart(document.getElementById('bmiChart'), {
  type:'line',
  data:{ labels: bmiHist.map(h=>h.recorded_at.split(' ')[0]), datasets:[{ label:'BMI', data:bmiHist.map(h=>h.bmi), borderColor:'#0B6B3A', backgroundColor:'rgba(11,107,58,0.12)', fill:true, tension:.35 }]},
  options:{ plugins:{legend:{display:false}}, scales:{y:{grid:{color:'rgba(0,0,0,0.04)'}}}, responsive:true }
});
</script>
</body>
</html>
