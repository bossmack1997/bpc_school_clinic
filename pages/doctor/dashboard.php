<?php
require_once __DIR__ . '/../../config/config.php';
require_role('doctor');
$db = getDB();
$u = current_user();

// Fetch Doctor profile
$stmt = $db->prepare("SELECT * FROM doctors WHERE user_id = ?");
$stmt->execute([$u['id']]);
$doctor = $stmt->fetch() ?: [];

// Statistics
$todaysConsults = $db->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = CURDATE()")->fetchColumn();
$todaysAppts = $db->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE() AND status IN ('approved','pending')")->fetchColumn();
$pendingAppts = $db->query("SELECT COUNT(*) FROM appointments WHERE status = 'pending'")->fetchColumn();
$totalPatients = $db->query("SELECT COUNT(*) FROM students")->fetchColumn();

// Upcoming scheduled patient appointments
$upcomingAppts = $db->query("
    SELECT a.*, s.student_number, s.course, s.year_level, s.section, u.first_name, u.last_name, u.gender, u.phone
    FROM appointments a
    JOIN students s ON s.id = a.student_id
    JOIN users u ON u.id = s.user_id
    WHERE a.appointment_date >= CURDATE() AND a.status IN ('pending','approved')
    ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 6
")->fetchAll();

// Recent consultations
$recentConsults = $db->query("
    SELECT c.*, s.student_number, u.first_name, u.last_name
    FROM consultations c
    JOIN students s ON s.id = c.student_id
    JOIN users u ON u.id = s.user_id
    ORDER BY c.consultation_date DESC LIMIT 6
")->fetchAll();

$pageTitle = 'Doctor Dashboard';
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

      <!-- Welcome Banner -->
      <div class="welcome-card" data-aos="fade-down" data-aos-duration="600" style="background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);">
        <div class="welcome-content">
          <div class="clinic-status open"><span class="status-dot" style="background:#38bdf8;"></span> Physician Station — Active</div>
          <h1>Doctor Dashboard</h1>
          <div class="welcome-sub">Welcome, Dr. <?= e($u['first_name'] . ' ' . $u['last_name']) ?>! 🩺</div>
          <div class="welcome-desc">Specialization: <?= e($doctor['specialization'] ?? 'General Physician') ?> &bull; License: <?= e($doctor['license_number'] ?? 'Verified PRC License') ?></div>
          <div class="welcome-actions">
            <a href="consultations.php" class="btn btn-white"><i class="bi bi-stethoscope me-1"></i> New Consultation</a>
            <a href="appointments.php" class="btn btn-outline-white"><i class="bi bi-calendar2-check me-1"></i> Appointments (<?= (int)$pendingAppts ?>)</a>
            <a href="patients.php" class="btn btn-outline-white"><i class="bi bi-people me-1"></i> Patient Records</a>
          </div>
        </div>
        <div class="welcome-visual">
          <div class="welcome-icon"><i class="bi bi-heart-pulse-fill"></i></div>
        </div>
      </div>

      <!-- Medical Stat Cards -->
      <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="100">
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-blue">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-clipboard2-pulse-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$todaysConsults ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Today's Consultations</div>
              <div class="msc-trend up"><i class="bi bi-activity"></i> Clinical</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-green">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-calendar-event-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$todaysAppts ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Today's Appointments</div>
              <div class="msc-trend up"><i class="bi bi-check-circle"></i> Scheduled</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-amber">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-hourglass-split"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$pendingAppts ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Pending Reviews</div>
              <div class="msc-trend warn"><i class="bi bi-clock"></i> Awaiting</div>
            </div>
          </div>
        </div>
        <div class="col-md-6 col-xl-3">
          <div class="medical-stat-card gradient-purple">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-person-hearts"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$totalPatients ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Enrolled Students</div>
              <div class="msc-trend up"><i class="bi bi-people-fill"></i> Active</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Two-Column Clinical Schedule & Recent Consultations -->
      <div class="row g-4">
        <!-- Upcoming Patient Appointments -->
        <div class="col-lg-7" data-aos="fade-up" data-aos-delay="150">
          <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
              <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-calendar-check text-primary me-2"></i>Upcoming Clinical Appointments</h5>
              <a href="appointments.php" class="btn btn-sm btn-outline-primary rounded-pill px-3">View All</a>
            </div>
            <div class="card-body px-4 py-3">
              <?php if (empty($upcomingAppts)): ?>
                <div class="text-center py-5 text-muted">
                  <i class="bi bi-calendar-x fs-1 d-block mb-2 text-secondary"></i>
                  No upcoming appointments scheduled today.
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table align-middle table-hover mb-0">
                    <thead class="table-light text-muted small">
                      <tr>
                        <th>Patient</th>
                        <th>Course / Section</th>
                        <th>Schedule</th>
                        <th>Purpose</th>
                        <th>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($upcomingAppts as $app): ?>
                        <tr>
                          <td>
                            <div class="fw-semibold text-dark"><?= e($app['first_name'] . ' ' . $app['last_name']) ?></div>
                            <small class="text-muted"><?= e($app['student_number']) ?></small>
                          </td>
                          <td><?= e($app['course'] . ' ' . $app['year_level'] . '-' . $app['section']) ?></td>
                          <td>
                            <div class="fw-medium text-dark"><?= date('M d, Y', strtotime($app['appointment_date'])) ?></div>
                            <small class="text-muted"><?= date('h:i A', strtotime($app['appointment_time'])) ?></small>
                          </td>
                          <td><span class="badge bg-light text-dark border"><?= e($app['service_type'] ?? 'Consultation') ?></span></td>
                          <td>
                            <a href="consultations.php?student_id=<?= (int)$app['student_id'] ?>&appointment_id=<?= (int)$app['id'] ?>" class="btn btn-sm btn-primary rounded-pill px-2">
                              <i class="bi bi-stethoscope"></i> Consult
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Recent Consultations -->
        <div class="col-lg-5" data-aos="fade-up" data-aos-delay="200">
          <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0 d-flex justify-content-between align-items-center">
              <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history text-info me-2"></i>Recent Consultations</h5>
              <a href="consultations.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">View All</a>
            </div>
            <div class="card-body px-4 py-3">
              <?php if (empty($recentConsults)): ?>
                <div class="text-center py-5 text-muted">
                  <i class="bi bi-journal-medical fs-1 d-block mb-2 text-secondary"></i>
                  No recent consultations recorded.
                </div>
              <?php else: ?>
                <ul class="list-group list-group-flush">
                  <?php foreach ($recentConsults as $c): ?>
                    <li class="list-group-item px-0 py-3 d-flex justify-content-between align-items-start border-bottom">
                      <div>
                        <div class="fw-semibold text-dark"><?= e($c['first_name'] . ' ' . $c['last_name']) ?> <small class="text-muted">(<?= e($c['student_number']) ?>)</small></div>
                        <div class="small text-muted text-truncate" style="max-width: 260px;"><?= e($c['diagnosis'] ?: $c['chief_complaint']) ?></div>
                      </div>
                      <div class="text-end">
                        <small class="text-muted d-block"><?= date('M d, Y', strtotime($c['consultation_date'])) ?></small>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Recorded</span>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
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