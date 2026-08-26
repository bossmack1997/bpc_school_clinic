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

      <!-- Quick Actions -->
      <h6 class="section-title mb-3" data-aos="fade-up"><i class="bi bi-lightning-fill text-warning me-1"></i> Quick Actions</h6>
      <div class="quick-actions-grid mb-4" data-aos="fade-up" data-aos-delay="50">
        <a href="students.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-people-fill text-success"></i></div>
          <div class="qa-title-lg">My Advisory Class</div>
          <div class="qa-sub-lg">View student roster</div>
        </a>
        <a href="emergencies.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-exclamation-triangle-fill text-danger"></i></div>
          <div class="qa-title-lg">Emergency Reports</div>
          <div class="qa-sub-lg">Manage SOS &amp; alerts</div>
        </a>
        <a href="my_qr.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-qr-code-scan text-success"></i></div>
          <div class="qa-title-lg">My QR Code</div>
          <div class="qa-sub-lg">Check in at the clinic</div>
        </a>
        <a href="consultations.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-file-earmark-medical text-primary"></i></div>
          <div class="qa-title-lg">My Consultations</div>
          <div class="qa-sub-lg">Your clinic records</div>
        </a>
        <a href="announcements.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-megaphone-fill text-warning"></i></div>
          <div class="qa-title-lg">Announcements</div>
          <div class="qa-sub-lg">Read clinic notices</div>
        </a>
        <a href="notifications.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-bell-fill text-info"></i></div>
          <div class="qa-title-lg">Notifications</div>
          <div class="qa-sub-lg">View system alerts</div>
        </a>
        <a href="profile.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-person-circle text-secondary"></i></div>
          <div class="qa-title-lg">My Profile</div>
          <div class="qa-sub-lg">Account settings</div>
        </a>
        <a href="<?= APP_URL ?>/pages/ai_assistant.php" class="quick-action-card-lg text-decoration-none">
          <div class="qa-icon-lg"><i class="bi bi-robot text-success"></i></div>
          <div class="qa-title-lg">Ask MICA</div>
          <div class="qa-sub-lg">AI Health Assistant</div>
        </a>
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

      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3" data-aos="fade-up">
        <h6 class="section-title mb-0"><i class="bi bi-people-fill text-success me-1"></i> My Advisory Class Roster</h6>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="$('.dash-advisory-collapse').collapse('show'); $('.dash-advisory-header').attr('aria-expanded','true').removeClass('collapsed');">
            <i class="bi bi-arrows-expand me-1"></i> Expand All
          </button>
          <button type="button" class="btn btn-outline-secondary btn-sm" onclick="$('.dash-advisory-collapse').collapse('hide'); $('.dash-advisory-header').attr('aria-expanded','false').addClass('collapsed');">
            <i class="bi bi-arrows-collapse me-1"></i> Collapse All
          </button>
          <a href="students.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye me-1"></i> Full Roster</a>
        </div>
      </div>

      <?php
        // Prepare section groups for dashboard
        $dashSectionGroups = [];
        if (!empty($assignments)) {
            foreach ($assignments as $a) {
                $secId = (int)$a['section_id'];
                $secName = trim((string)$a['section_name']);
                $secCourse = trim((string)($a['course'] ?? ''));
                $secYear = trim((string)($a['year_level'] ?? ''));

                $secStudents = array_values(array_filter($students, function ($st) use ($a) {
                    return (!empty($st['assigned_section_id']) && (int)$st['assigned_section_id'] === (int)$a['section_id']) ||
                           (!empty($st['assigned_section_name']) && strcasecmp($st['assigned_section_name'], $a['section_name']) === 0) ||
                           (!empty($st['section']) && strcasecmp($st['section'], $a['section_name']) === 0);
                }));

                $dashSectionGroups[] = [
                    'id'          => $secId,
                    'name'        => $secName,
                    'course'      => $secCourse,
                    'year_level'  => $secYear,
                    'students'    => $secStudents,
                    'count'       => count($secStudents)
                ];
            }
        }
      ?>

      <?php if (!empty($dashSectionGroups)): ?>
        <div class="row g-3 mb-4" data-aos="fade-up" data-aos-delay="100">
          <?php foreach ($dashSectionGroups as $idx => $group): ?>
            <?php
              $collapseId = 'dashCollapseSec_' . $idx;
              $count = $group['count'];
              $countLabel = $count . ' ' . ($count === 1 ? 'Student' : 'Students');
            ?>
            <div class="col-12">
              <div class="card border overflow-hidden">
                <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 cursor-pointer dash-advisory-header collapsed"
                     data-bs-toggle="collapse"
                     data-bs-target="#<?= $collapseId ?>"
                     aria-expanded="false"
                     style="user-select: none;">
                  <div class="d-flex align-items-center gap-3">
                    <div class="d-inline-flex align-items-center justify-content-center bg-success-light text-success rounded-circle" style="width: 38px; height: 38px;">
                      <i class="bi bi-mortarboard-fill"></i>
                    </div>
                    <div>
                      <h6 class="fw-bold mb-0 text-dark"><?= e($group['name']) ?></h6>
                      <div class="small text-muted">
                        <?= e($group['course'] ?: 'Advisory Section') ?> <?= $group['year_level'] ? '· ' . e($group['year_level']) : '' ?>
                      </div>
                    </div>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge <?= $count > 0 ? 'bg-success-light text-success' : 'bg-light text-muted border' ?> fw-semibold px-3 py-2">
                      <i class="bi <?= $count > 0 ? 'bi-person-check-fill' : 'bi-person-x' ?> me-1"></i> <?= e($countLabel) ?>
                    </span>
                    <i class="bi bi-chevron-down text-muted ms-1"></i>
                  </div>
                </div>

                <div id="<?= $collapseId ?>" class="collapse dash-advisory-collapse">
                  <div class="card-body p-0">
                    <?php if ($count > 0): ?>
                      <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                          <thead class="bg-light small text-muted">
                            <tr>
                              <th class="ps-4">Student</th>
                              <th>Student No.</th>
                              <th>Course</th>
                              <th>Year</th>
                              <th>Section</th>
                              <th class="text-end pe-4">Action</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($group['students'] as $s): ?>
                              <tr>
                                <td class="ps-4 fw-medium text-dark"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></td>
                                <td><span class="font-monospace"><?= e($s['student_number']) ?></span></td>
                                <td><span class="badge bg-primary-light text-primary"><?= e($s['course'] ?: '—') ?></span></td>
                                <td><?= e($s['year_level'] ?: '—') ?></td>
                                <td><span class="badge bg-light text-dark border"><?= e($s['assigned_section_name'] ?: $s['section']) ?></span></td>
                                <td class="text-end pe-4">
                                  <a href="student_detail.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye me-1"></i> View
                                  </a>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php else: ?>
                      <div class="text-center py-4 text-muted">
                        <i class="bi bi-people fs-3 text-secondary mb-2 d-block"></i>
                        <span class="small fw-semibold">No students enrolled in this section yet.</span>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
