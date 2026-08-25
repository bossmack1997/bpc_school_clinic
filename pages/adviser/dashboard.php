<?php
require_once __DIR__ . '/../../config/config.php';
require_role('adviser');
$db = getDB();
$u = current_user();

$assignments = get_adviser_active_assignment($db, $u['id']);
$sections = array_map(fn($a) => $a['section_name'], $assignments);

$students = get_adviser_students($db, $u['id']);
$studentCount = count($students);

$activeEmergencies = 0;
$pendingResponses = 0;
$stmt = $db->prepare("SELECT COUNT(*) FROM sos_emergencies WHERE source = 'adviser' AND user_id = ? AND status IN ('new','active','acknowledged','responding','responded')");
$stmt->execute([$u['id']]);
$activeEmergencies = (int)$stmt->fetchColumn();
$stmt = $db->prepare("SELECT COUNT(*) FROM sos_emergencies WHERE source = 'adviser' AND user_id = ? AND status IN ('new','active')");
$stmt->execute([$u['id']]);
$pendingResponses = (int)$stmt->fetchColumn();

$pageTitle = 'Adviser Dashboard';
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

      <div class="welcome-card" data-aos="fade-down" data-aos-duration="600">
        <div class="welcome-content">
          <div class="clinic-status open"><span class="status-dot"></span> Adviser Station</div>
          <h1>Welcome, <?= e($u['first_name']) ?>! 👋</h1>
          <div class="welcome-sub">
            Advisory Sections:
            <?php if ($sections): ?>
              <?php foreach ($sections as $s): ?><span class="badge bg-success me-1"><?= e($s) ?></span><?php endforeach; ?>
            <?php else: ?>Not assigned<?php endif; ?>
          </div>
          <div class="welcome-desc">You can access only students in your current advisory section(s). Use this panel for advisory oversight and emergency coordination.</div>
          <div class="welcome-actions">
            <a href="students.php" class="btn btn-white"><i class="bi bi-people me-1"></i> My Advisory Class</a>
            <a href="emergencies.php" class="btn btn-outline-white"><i class="bi bi-exclamation-triangle me-1"></i> Emergency Reports</a>
          </div>
        </div>
        <div class="welcome-visual">
          <div class="welcome-icon"><i class="bi bi-person-video3"></i></div>
        </div>
      </div>

      <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="100">
        <div class="col-md-4">
          <div class="medical-stat-card gradient-green">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-people-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$studentCount ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">My Advisory Students</div>
              <div class="msc-trend up"><i class="bi bi-mortarboard"></i> <?= count($sections) ? count($sections) . ' section(s)' : 'No section' ?></div>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="medical-stat-card gradient-red">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$activeEmergencies ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Active Emergency Requests</div>
              <div class="msc-trend down"><i class="bi bi-broadcast"></i> Needs clinic response</div>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="medical-stat-card gradient-blue">
            <div class="msc-top">
              <div class="msc-icon"><i class="bi bi-hourglass-split"></i></div>
              <div class="msc-value"><span class="counter-animate" data-count="<?= (int)$pendingResponses ?>">0</span></div>
            </div>
            <div>
              <div class="msc-label">Pending Clinic Responses</div>
              <div class="msc-trend up"><i class="bi bi-arrow-repeat"></i> Awaiting update</div>
            </div>
          </div>
        </div>
      </div>

      <!-- MY CLINIC -->
      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-hospital-fill"></i> My Clinic</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <a href="my_qr.php" class="text-decoration-none">
            <div class="card p-3 h-100 shadow-sm text-center">
              <div class="text-success mb-2"><i class="bi bi-qr-code-scan fs-3"></i></div>
              <div class="fw-semibold">My QR Code</div>
              <div class="small text-muted">Check in at the clinic</div>
            </div>
          </a>
        </div>
        <div class="col-md-4">
          <a href="consultations.php" class="text-decoration-none">
            <div class="card p-3 h-100 shadow-sm text-center">
              <div class="text-primary mb-2"><i class="bi bi-file-earmark-medical fs-3"></i></div>
              <div class="fw-semibold">My Consultations</div>
              <div class="small text-muted">Your own clinic records</div>
            </div>
          </a>
        </div>
        <div class="col-md-4">
          <a href="profile.php" class="text-decoration-none">
            <div class="card p-3 h-100 shadow-sm text-center">
              <div class="text-info mb-2"><i class="bi bi-person-circle fs-3"></i></div>
              <div class="fw-semibold">My Profile</div>
              <div class="small text-muted">Account &amp; contact info</div>
            </div>
          </a>
        </div>
      </div>

      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-people-fill text-success me-1"></i> My Advisory Class</h6>
      <div class="card p-3 mb-4" data-aos="fade-up" data-aos-delay="100">
        <?php if ($assignments): ?>
          <?php foreach ($assignments as $a): ?>
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 pb-3 border-bottom mb-3 last-border-0">
              <div>
                <h5 class="fw-bold mb-1 text-dark"><?= e($a['section_name']) ?></h5>
                <div class="small text-muted mb-2">
                  <span class="me-3"><i class="bi bi-book me-1"></i> <strong>Course:</strong> <?= e($a['course'] ?: 'Unspecified') ?></span>
                  <span class="me-3"><i class="bi bi-mortarboard me-1"></i> <strong>Year Level:</strong> <?= e($a['year_level'] ?: 'Unspecified') ?></span>
                  <span><i class="bi bi-layers me-1"></i> <strong>Section:</strong> <?= e($a['section_name']) ?></span>
                </div>
                <?php
                  $secStudentCount = count(array_filter($students, function ($st) use ($a) {
                      return (!empty($st['assigned_section_id']) && (int)$st['assigned_section_id'] === (int)$a['section_id']) ||
                             (!empty($st['assigned_section_name']) && strcasecmp($st['assigned_section_name'], $a['section_name']) === 0);
                  }));
                ?>
                <div class="badge bg-success-light text-success fw-semibold p-2">
                  <i class="bi bi-people-fill me-1"></i> <?= $secStudentCount ?> Student(s) Enrolled
                </div>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <a href="students.php" class="btn btn-primary btn-sm"><i class="bi bi-people me-1"></i> View My Students</a>
                <a href="my_advisory.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil-square me-1"></i> Change Advisory Class</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="text-center py-4">
            <div class="text-muted mb-2"><i class="bi bi-inboxes fs-2"></i></div>
            <h6 class="fw-bold text-dark mb-1">No Advisory Class Assigned</h6>
            <p class="text-muted small mb-3">You have not selected your advisory class yet. Select your assigned course, year level, and section below.</p>
            <a href="my_advisory.php" class="btn btn-success fw-semibold"><i class="bi bi-plus-circle me-1"></i> Set Up My Advisory Class</a>
          </div>
        <?php endif; ?>
      </div>

      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-list-ul text-success me-1"></i> Advisory Students Roster</h6>
      <div class="card" data-aos="fade-up" data-aos-delay="100">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr><th>Student</th><th>Student No.</th><th>Course</th><th>Year</th><th>Section</th><th></th></tr>
              </thead>
              <tbody>
                <?php foreach ($students as $s): ?>
                <tr>
                  <td class="fw-medium"><?= e($s['first_name'].' '.$s['last_name']) ?></td>
                  <td><?= e($s['student_number']) ?></td>
                  <td><?= e($s['course'] ?: '—') ?></td>
                  <td><?= e($s['year_level'] ?: '—') ?></td>
                  <td><span class="badge bg-light text-dark border fw-medium"><?= e($s['assigned_section_name'] ?: $s['section']) ?></span></td>
                  <td><a href="student_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$students): ?>
                <tr>
                  <td colspan="6" class="text-center py-4">
                    <div class="p-3 my-2">
                      <div class="mb-2 text-muted">
                        <div class="d-inline-flex align-items-center justify-content-center bg-light rounded-circle p-3" style="width: 60px; height: 60px;">
                          <i class="bi bi-people fs-2 text-secondary"></i>
                        </div>
                      </div>
                      <h5 class="fw-bold text-dark mb-1">No Students Yet</h5>
                      <p class="text-muted small mb-0" style="max-width: 480px; margin: 0 auto;">
                        There are currently no students enrolled in your advisory section, <strong><?= e(implode(', ', $sections) ?: 'Unassigned') ?></strong>.<br>
                        Once students are enrolled in this section, they will appear here.
                      </p>
                    </div>
                  </td>
                </tr>
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
</body>
</html>
