<?php
/**
 * pages/adviser/dashboard.php
 * =====================================================================
 * TEACHER / ADVISER Dashboard — Student Monitoring & Advisory Access
 * Shows only students this teacher is authorized to see.
 * No inventory, no nurse-only data, no administrative statistics.
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('teacher');

$db = getDB();
$u  = current_user();

$teacherFirstName = !empty($u['first_name']) ? e($u['first_name']) : 'Teacher';
$teacherFullName  = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
$teacherInitial   = strtoupper(substr((string)($u['first_name'] ?? 'T'), 0, 1) . substr((string)($u['last_name'] ?? ''), 0, 1));
$teacherId        = (int)$u['id'];

// -------------------------------------------------------------------------
// 1. Advisory sections + authorized students (existing access helpers)
// -------------------------------------------------------------------------
$assignments  = get_adviser_active_assignment($db, $teacherId);
$students     = get_adviser_students($db, $teacherId);
$studentIds   = array_map(fn($s) => (int)$s['id'], $students);
$studentCount = count($students);
$sectionCount = count($assignments);

// -------------------------------------------------------------------------
// 2. Group students per assigned section for the section panel
// -------------------------------------------------------------------------
$sectionGroups = [];
foreach ($assignments as $a) {
    $members = array_values(array_filter($students, function ($st) use ($a) {
        return (!empty($st['assigned_section_id']) && (int)$st['assigned_section_id'] === (int)$a['section_id'])
            || (!empty($st['assigned_section_name']) && strcasecmp($st['assigned_section_name'], $a['section_name']) === 0)
            || (!empty($st['section']) && strcasecmp($st['section'], $a['section_name']) === 0);
    }));
    $sectionGroups[] = [
        'id'         => (int)$a['section_id'],
        'name'       => trim((string)$a['section_name']),
        'course'     => trim((string)($a['course'] ?? '')),
        'year_level' => trim((string)($a['year_level'] ?? '')),
        'students'   => $members,
        'count'      => count($members),
    ];
}

// -------------------------------------------------------------------------
// 3. Clinic activity scoped to MY students only
// -------------------------------------------------------------------------
$studentIdList = $studentIds ? implode(',', $studentIds) : '0';

$studentsInClinic = (int)$db->query("
    SELECT COUNT(DISTINCT s.id)
    FROM queue_numbers q
    JOIN students s ON s.id = q.student_id
    WHERE DATE(q.created_at) = CURDATE()
      AND q.status IN ('waiting','called','serving')
      AND s.id IN ($studentIdList)
")->fetchColumn();

$myVisitsToday = (int)$db->query("
    SELECT COUNT(*)
    FROM consultations c
    WHERE DATE(c.consultation_date) = CURDATE()
      AND c.student_id IN ($studentIdList)
")->fetchColumn();

$clinicNotices = (int)$db->query("
    SELECT COUNT(*) FROM announcements
    WHERE audience IN ('all','teachers')
      AND (is_pinned = 1 OR created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY))
")->fetchColumn();

// -------------------------------------------------------------------------
// 4. Clinic alerts scoped to my students + reports I submitted
// -------------------------------------------------------------------------
$myEmergencies = $db->query("
    SELECT e.id, e.emergency_type, e.status, e.priority, e.created_at,
           CONCAT(us.first_name, ' ', us.last_name) AS student_name,
           s.student_number
    FROM sos_emergencies e
    JOIN students s ON s.id = e.student_id
    JOIN users us ON us.id = s.user_id
    WHERE e.status IN ('new','active','acknowledged','responding','responded')
      AND s.id IN ($studentIdList)
    ORDER BY FIELD(e.priority,'emergency','urgent','normal'), e.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT COUNT(*) FROM sos_emergencies WHERE user_id = ? AND status IN ('new','active','acknowledged','responding')");
$stmt->execute([$teacherId]);
$reportedByMe = (int)$stmt->fetchColumn();

$alertTotal = count($myEmergencies) + ($reportedByMe > 0 ? 1 : 0);

// -------------------------------------------------------------------------
// 5. Recent clinic visits for my students
// -------------------------------------------------------------------------
$myStudentsVisits = $db->query("
    SELECT c.id, c.consultation_date, c.chief_complaint, s.student_number, s.section,
           CONCAT(us.first_name, ' ', us.last_name) AS student_name
    FROM consultations c
    JOIN students s ON s.id = c.student_id
    JOIN users us ON us.id = s.user_id
    WHERE c.student_id IN ($studentIdList)
    ORDER BY c.consultation_date DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------------
// 6. Notifications for the teacher account
// -------------------------------------------------------------------------
$stmt = $db->prepare("SELECT id, type, title, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$teacherId]);
$recentNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$clinicOpen = $studentsInClinic > 0 || $myVisitsToday > 0;

$pageTitle = 'Teacher Dashboard';
$pageDescription = 'Monitor your assigned students and clinic access';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../../includes/head.php'; ?>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area dbg-canvas page-enter">

      <!-- ============================================================
           TEACHER HERO
           ============================================================ -->
      <?php
      dbg_hero([
          'role'  => 'Teacher / Adviser',
          'title' => dbg_greeting() . ', ' . $teacherFirstName . ' ',
          'desc'  => 'Monitor your assigned students and access clinic services.',
          'tone'  => 'slate',
          'badge_icon'  => 'mortarboard-fill',
          'badge_label' => 'Faculty',
      ]);
      ?>

      <!-- ============================================================
           TEACHER OVERVIEW
           ============================================================ -->
      <div class="dbg-stats dbg-stats--4" data-aos="fade-up">
        <?php
        dbg_stat([
            'solid'  => true,
            'tone'   => 'green',
            'icon'   => 'collection-fill',
            'value'  => $sectionCount,
            'count'  => true,
            'label'  => 'Assigned Sections',
            'note'   => '<span>' . ($sectionCount > 0 ? 'Advisory classes under your care' : 'No section assigned yet') . '</span>',
        ]);
        dbg_stat([
            'tone'   => 'blue',
            'icon'   => 'people-fill',
            'value'  => $studentCount,
            'count'  => true,
            'label'  => 'Students',
            'note'   => '<span><a href="' . APP_URL . '/pages/adviser/students.php">Open roster</a></span>',
        ]);
        dbg_stat([
            'tone'   => $alertTotal > 0 ? 'orange' : 'teal',
            'icon'   => 'exclamation-triangle-fill',
            'value'  => $alertTotal,
            'count'  => true,
            'label'  => 'Clinic Alerts',
            'note'   => '<span>' . ($alertTotal > 0 ? 'Emergency activity to review' : 'Nothing to review') . '</span>',
        ]);
        dbg_stat([
            'tone'   => 'teal',
            'icon'   => 'calendar-check-fill',
            'value'  => $myVisitsToday,
            'count'  => true,
            'label'  => "Today's Clinic Activity",
            'note'   => '<span><i class="bi bi-person"></i> ' . $studentsInClinic . ' of your students in clinic</span>',
        ]);
        ?>
      </div>

      <!-- ============================================================
           ASSIGNED SECTIONS  +  EMERGENCY ACCESS
           ============================================================ -->
      <div class="row g-3 g-xl-4 mb-3 mb-xl-4" data-aos="fade-up" data-aos-delay="60">
        <div class="col-xl-7">
          <?php dbg_panel_open([
              'icon'     => 'collection-fill',
              'title'    => 'My Assigned Sections',
              'subtitle' => $sectionCount > 0 ? 'Tap a section to open its roster' : 'No advisory section assigned yet',
          ]); ?>
            <?php if (empty($sectionGroups)): ?>
              <?php dbg_empty([
                  'icon'   => 'inboxes',
                  'title'  => 'No advisory class assigned',
                  'desc'   => 'Select your advisory course, year level, and section to start monitoring your students.',
                  'action' => '<a class="dbg-btn dbg-btn--primary dbg-btn--sm" href="' . APP_URL . '/pages/adviser/my_advisory.php"><i class="bi bi-plus-circle"></i> Set up my advisory class</a>',
              ]); ?>
            <?php else: ?>
              <div class="dbg-sections">
                <?php foreach ($sectionGroups as $group): ?>
                  <a class="dbg-section-card" href="<?= APP_URL ?>/pages/adviser/students.php">
                    <div class="dbg-section-card__top">
                      <span class="dbg-section-card__icon"><i class="bi bi-mortarboard-fill"></i></span>
                      <span style="min-width:0;">
                        <span class="dbg-section-card__name dbg-clamp-1"><?= e($group['name']) ?></span>
                        <span class="dbg-section-card__meta">
                          <?= e($group['course'] ?: 'Advisory Section') ?><?= $group['year_level'] !== '' ? ' &middot; ' . e($group['year_level']) : '' ?>
                        </span>
                      </span>
                    </div>
                    <div class="dbg-progress">
                      <span style="width: <?= $studentCount > 0 ? max(6, (int)round(($group['count'] / $studentCount) * 100)) : 0 ?>%"></span>
                    </div>
                    <div class="dbg-section-card__foot">
                      <span class="dbg-count-pill<?= $group['count'] === 0 ? ' dbg-count-pill--muted' : '' ?>">
                        <i class="bi bi-people"></i> <?= $group['count'] ?> <?= $group['count'] === 1 ? 'Student' : 'Students' ?>
                      </span>
                      <span class="dbg-muted" style="font-size:0.74rem;">View <i class="bi bi-arrow-right"></i></span>
                    </div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php
          dbg_panel_close(!empty($sectionGroups)
              ? '<div class="dbg-btn-row">'
                . '<a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="' . APP_URL . '/pages/adviser/students.php"><i class="bi bi-people"></i> Full advisory roster</a>'
                . '<a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="' . APP_URL . '/pages/adviser/my_advisory.php"><i class="bi bi-pencil-square"></i> Change advisory class</a>'
                . '</div>'
              : null);
          ?>
        </div>

        <!-- Emergency access -->
        <div class="col-xl-5">
          <?php dbg_panel_open([
              'icon'  => 'shield-fill-exclamation',
              'title' => 'Emergency Access',
          ]); ?>
            <?php
            dbg_monitor_open([
                'active' => $alertTotal > 0,
                'count'  => $alertTotal,
                'label'  => $alertTotal > 0
                    ? 'Emergency activity involving your students'
                    : 'No active student emergencies',
                'badge'  => $alertTotal > 0 ? 'Review Needed' : 'All Clear',
            ]);
            ?>
            <?php if (!empty($myEmergencies)): ?>
              <ul class="dbg-monitor__list" style="margin-top:14px;">
                <?php foreach ($myEmergencies as $emg): ?>
                  <li class="dbg-monitor__item">
                    <span class="dbg-timeline__node dbg-timeline__node--red"><i class="bi bi-heart-pulse-fill"></i></span>
                    <span class="dbg-staff__meta">
                      <span class="mi-title"><?= e($emg['student_name'] ?: 'Student') ?></span>
                      <span class="mi-sub"><?= e($emg['emergency_type'] ?: 'Emergency') ?> &middot; <?= e($emg['student_number'] ?: '—') ?> &middot; <?= format_datetime($emg['created_at']) ?><?php $relativeTime = format_relative_datetime($emg['created_at']); ?><?= $relativeTime !== '' ? ' &middot; ' . e($relativeTime) : '' ?></span>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <?php if ($reportedByMe > 0): ?>
              <div style="margin-top:14px;">
                <div class="dbg-alert dbg-alert--warning">
                  <div class="dbg-alert__icon"><i class="bi bi-megaphone-fill"></i></div>
                  <div class="dbg-alert__body">
                    <div class="dbg-alert__title">Reports you submitted</div>
                    <div class="dbg-alert__desc"><?= $reportedByMe ?> emergency report(s) awaiting clinic resolution.</div>
                  </div>
                  <div class="dbg-alert__count" style="color:#B45309;"><?= $reportedByMe ?></div>
                </div>
              </div>
            <?php endif; ?>

            <div class="dbg-btn-row" style="margin-top:16px;">
              <a class="dbg-btn dbg-btn--<?= $alertTotal > 0 ? 'danger' : 'ghost' ?> dbg-btn--sm" href="<?= APP_URL ?>/pages/adviser/emergencies.php">
                <i class="bi bi-shield-fill-exclamation"></i>
                <?= $alertTotal > 0 ? 'Review Emergencies' : 'Emergency Access' ?>
              </a>
              <?php if ($alertTotal === 0): ?>
                <a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="<?= APP_URL ?>/pages/adviser/queue.php">
                  <i class="bi bi-broadcast"></i> Clinic Queue
                </a>
              <?php endif; ?>
            </div>
          <?php dbg_panel_close(); ?>
        </div>
      </div>

      <!-- ============================================================
           MY STUDENTS (authorized only)  +  RECENT CLINIC ACTIVITY
           ============================================================ -->
      <div class="row g-3 g-xl-4 mb-3 mb-xl-4" data-aos="fade-up" data-aos-delay="120">
        <div class="col-xl-7">
          <?php dbg_panel_open([
              'icon'     => 'search-heart',
              'title'    => 'My Students',
              'subtitle' => 'Students you are authorized to access',
              'action'   => '<a class="dbg-btn dbg-btn--soft dbg-btn--sm" href="' . APP_URL . '/pages/adviser/students.php">View all</a>',
          ]); ?>
            <?php if (empty($students)): ?>
              <?php dbg_empty([
                  'icon'  => 'people',
                  'title' => $sectionCount > 0 ? 'No students in your sections yet' : 'No advisory section assigned',
                  'desc'  => $sectionCount > 0
                      ? 'Students will appear here once they are enrolled in your advisory sections.'
                      : 'Assign your advisory class to see and monitor your students.',
              ]); ?>
            <?php else: ?>
              <div class="dbg-search">
                <i class="bi bi-search"></i>
                <input type="search" id="myStudentsSearch" placeholder="Search name or ID" aria-label="Search my students">
              </div>
              <div class="dbg-roster" id="myStudentsRoster">
                <?php foreach (array_slice($students, 0, 40) as $s): ?>
                  <?php
                  $initials = strtoupper(substr((string)($s['first_name'] ?? ''), 0, 1) . substr((string)($s['last_name'] ?? ''), 0, 1));
                  $haystack = strtolower(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '') . ' ' . ($s['student_number'] ?? '') . ' ' . ($s['section'] ?? '')));
                  ?>
                  <a class="dbg-roster__row" href="<?= APP_URL ?>/pages/adviser/student_detail.php?id=<?= (int)$s['id'] ?>"
                     data-student="<?= e($haystack) ?>">
                    <span class="avatar dbg-avatar">
                      <?php if (!empty($s['profile_picture'])): ?>
                        <img class="avatar" src="<?= e(app_url($s['profile_picture'])) ?>" alt="<?= e($s['first_name'] ?? '') ?>" onerror="this.onerror=null;this.src='<?= e(asset_url('images/default-avatar.svg')) ?>'">
                      <?php else: ?>
                        <?= e($initials ?: '—') ?>
                      <?php endif; ?>
                    </span>
                    <span class="dbg-roster__info">
                      <span class="dbg-roster__name"><?= e(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))) ?></span>
                      <span class="dbg-roster__id"><?= e($s['student_number'] ?: 'No student no.') ?> &middot; <?= e($s['section'] ?: '—') ?></span>
                    </span>
                    <i class="bi bi-chevron-right dbg-muted"></i>
                  </a>
                <?php endforeach; ?>
              </div>
              <div id="myStudentsEmpty" style="display:none;">
                <?php dbg_empty([
                    'icon'  => 'search',
                    'title' => 'No matching students',
                    'desc'  => 'Try a different name or student number.',
                ]); ?>
              </div>
              <?php if ($studentCount > 40): ?>
                <div class="dbg-muted" style="font-size:0.74rem;margin-top:10px;">
                  Showing 40 of <?= $studentCount ?> students &mdash;
                  <a href="<?= APP_URL ?>/pages/adviser/students.php">view the complete roster</a>.
                </div>
              <?php endif; ?>
            <?php endif; ?>
          <?php dbg_panel_close(); ?>
        </div>

        <div class="col-xl-5">
          <?php dbg_panel_open([
              'icon'     => 'clipboard2-pulse',
              'title'    => 'Recent Clinic Activity',
              'subtitle' => 'Consultations involving your students',
              'action'   => '<a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="' . APP_URL . '/pages/adviser/consultations.php">All activity <i class="bi bi-arrow-right"></i></a>',
          ]); ?>
            <?php if (empty($myStudentsVisits)): ?>
              <?php dbg_empty([
                  'icon'  => 'calendar-x',
                  'title' => 'No clinic visits recorded',
                  'desc'  => 'Visits by your students will appear here as the clinic logs them.',
              ]); ?>
            <?php else: ?>
              <ul class="dbg-timeline">
                <?php foreach ($myStudentsVisits as $v): ?>
                  <li class="dbg-timeline__item">
                    <span class="dbg-timeline__node dbg-timeline__node--blue"><i class="bi bi-file-medical"></i></span>
                    <span class="dbg-timeline__body">
                      <span class="dbg-timeline__title"><?= e($v['student_name']) ?></span>
                      <span class="dbg-timeline__desc"><?= e($v['chief_complaint'] ?: 'General check-up') ?></span>
                      <span class="dbg-timeline__desc dbg-muted">
                        <span class="dbg-mono"><?= e($v['student_number'] ?: '—') ?></span> &middot; <?= e($v['section'] ?: '—') ?>
                      </span>
                    </span>
                    <span class="dbg-timeline__time"><?= format_datetime($v['consultation_date']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          <?php dbg_panel_close(); ?>
        </div>
      </div>

      <!-- ============================================================
           TEACHER QUICK ACTIONS
           ============================================================ -->
      <?php dbg_panel_open([
          'icon'  => 'lightning-charge-fill',
          'title' => 'Quick Actions',
          'flush' => true,
      ]); ?>
        <div style="padding:18px;">
          <div class="dbg-actions dbg-actions--auto">
            <?php
            dbg_action_tile(['tone' => 'green',  'icon' => 'people-fill',   'title' => 'My Students',     'desc' => 'Search your advisory roster', 'href' => APP_URL . '/pages/adviser/students.php']);
            dbg_action_tile(['tone' => 'blue',   'icon' => 'collection',     'title' => 'My Sections',     'desc' => 'Manage advisory classes',     'href' => APP_URL . '/pages/adviser/my_advisory.php']);
            dbg_action_tile(['tone' => 'red',    'icon' => 'shield-fill-exclamation', 'title' => 'Emergency Access', 'desc' => 'Student emergencies',    'href' => APP_URL . '/pages/adviser/emergencies.php']);
            dbg_action_tile(['tone' => 'teal',   'icon' => 'broadcast',      'title' => 'Clinic Information','desc' => 'Live clinic queue',      'href' => APP_URL . '/pages/adviser/queue.php']);
            dbg_action_tile(['tone' => 'purple', 'icon' => 'chat-heart-fill','title' => 'Messages',         'desc' => 'Message the clinic',        'href' => APP_URL . '/pages/adviser/messages.php']);
            ?>
          </div>
        </div>
        <?php
        dbg_panel_close(
            '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">'
            . '<div class="d-flex align-items-center gap-2">'
            . '<span class="dbg-avatar">' . e($teacherInitial ?: 'T') . '</span>'
            . '<span><span class="d-block" style="font-weight:600;font-size:0.88rem;">' . e($teacherFullName !== '' ? $teacherFullName : $teacherFirstName) . '</span>'
            . '<span class="dbg-muted" style="font-size:0.75rem;">' . $clinicNotices . ' clinic notice(s) &middot; '
            . ($clinicOpen ? 'Clinic active today' : 'No clinic activity today') . '</span></span></div>'
            . '<div class="dbg-btn-row">'
            . '<a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="' . APP_URL . '/pages/adviser/announcements.php"><i class="bi bi-megaphone"></i> Announcements</a>'
            . '<a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="' . APP_URL . '/pages/adviser/notifications.php"><i class="bi bi-bell"></i> Notifications</a>'
            . '</div></div>'
        );
        ?>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>

<script>
(function () {
  'use strict';

  // Authorized-student roster filter (client side only — no extra API calls)
  var input = document.getElementById('myStudentsSearch');
  var roster = document.getElementById('myStudentsRoster');
  var empty = document.getElementById('myStudentsEmpty');
  if (!input || !roster) return;

  input.addEventListener('input', function () {
    var term = this.value.trim().toLowerCase();
    var visible = 0;
    roster.querySelectorAll('[data-student]').forEach(function (row) {
      var hit = row.getAttribute('data-student').indexOf(term) !== -1;
      row.style.display = hit ? '' : 'none';
      if (hit) visible++;
    });
    if (empty) empty.style.display = visible === 0 ? 'block' : 'none';
  });
})();
</script>
</body>
</html>
