<?php
/**
 * pages/student/dashboard.php
 * =====================================================================
 * STUDENT Dashboard — My Health Portal
 * Friendly, privacy-first: summaries only, no sensitive clinical detail.
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('student');

$db = getDB();
$u  = current_user();

$studentFirstName = !empty($u['first_name']) ? e($u['first_name']) : 'Student';
$fullName = trim(($u['first_name'] ?? '') . ' ' . ($u['middle_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
$initial  = strtoupper(substr((string)($u['first_name'] ?? 'S'), 0, 1) . substr((string)($u['last_name'] ?? ''), 0, 1));

// -------------------------------------------------------------------------
// 1. Student profile record
// -------------------------------------------------------------------------
$stmt = $db->prepare("SELECT * FROM students WHERE user_id = ? LIMIT 1");
$stmt->execute([$u['id']]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
$studentId = $student ? (int)$student['id'] : 0;

$studentNumber = $student['student_number'] ?? '—';
$courseLabel   = trim(($student['course'] ?? '') . ' ' . ($student['year_level'] ?? ''));
$sectionLabel  = $student['section'] ?? '—';

// -------------------------------------------------------------------------
// 2. Clinic status (derived from real queue state)
// -------------------------------------------------------------------------
$queueOpenToday = (int)$db->query("
    SELECT COUNT(*) FROM queue_numbers
    WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
")->fetchColumn();

$waitingCount = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'waiting'")->fetchColumn();
$clinicOpen   = $queueOpenToday > 0;

// -------------------------------------------------------------------------
// 3. My queue ticket (real data via existing helper)
// -------------------------------------------------------------------------
$activeQueue = $studentId > 0 ? get_active_patient_queue_ticket($db, $studentId, 'student') : null;

$nowServing = $db->query("
    SELECT q.queue_number, q.status, q.called_at,
           COALESCE(CONCAT(us.first_name, ' ', SUBSTRING(us.last_name, 1, 1), '.'), q.full_name, 'Patient') AS serving_name
    FROM queue_numbers q
    LEFT JOIN students s ON s.id = q.student_id
    LEFT JOIN users us ON us.id = s.user_id
    WHERE DATE(q.created_at) = CURDATE() AND q.status IN ('serving','called')
    ORDER BY FIELD(q.status,'serving','called'), q.id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$patientsAhead = null;
$estWait = null;
if ($activeQueue) {
    $aheadStmt = $db->prepare("
        SELECT COUNT(*) FROM queue_numbers
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
          AND status = 'waiting'
          AND (
            FIELD(priority, 'emergency', 'urgent', 'normal') < FIELD(?, 'emergency', 'urgent', 'normal')
            OR (priority = ? AND created_at < ?)
          )
    ");
    $aheadStmt->execute([$activeQueue['priority'], $activeQueue['priority'], $activeQueue['created_at']]);
    $patientsAhead = (int)$aheadStmt->fetchColumn();
    $estWait = calculate_queue_wait_time($db, $patientsAhead);
}

// -------------------------------------------------------------------------
// 4. Health summary (non-sensitive aggregates only)
// -------------------------------------------------------------------------
$totalVisits = 0;
$lastVisit = null;
$latestVitals = null;
if ($studentId > 0) {
    $vs = $db->prepare("SELECT COUNT(*) FROM consultations WHERE student_id = ?");
    $vs->execute([$studentId]);
    $totalVisits = (int)$vs->fetchColumn();

    $ls = $db->prepare("SELECT consultation_date, chief_complaint FROM consultations WHERE student_id = ? ORDER BY consultation_date DESC LIMIT 1");
    $ls->execute([$studentId]);
    $lastVisit = $ls->fetch(PDO::FETCH_ASSOC) ?: null;

    // Only the two most recent readings are summarised — no clinical history here.
    $hs = $db->prepare("SELECT bmi, bmi_status, blood_pressure, heart_rate, pulse_rate, recorded_at FROM health_monitoring WHERE student_id = ? ORDER BY recorded_at DESC LIMIT 1");
    $hs->execute([$studentId]);
    $latestVitals = $hs->fetch(PDO::FETCH_ASSOC) ?: null;
}

// -------------------------------------------------------------------------
// 5. Announcements + certificates
// -------------------------------------------------------------------------
$announcements = $db->query("SELECT id, title, content, is_pinned, created_at FROM announcements WHERE audience IN ('all','students') ORDER BY is_pinned DESC, created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

$certCount = 0;
if ($studentId > 0) {
    $cs = $db->prepare("SELECT COUNT(*) FROM certificates WHERE student_id = ?");
    $cs->execute([$studentId]);
    $certCount = (int)$cs->fetchColumn();
}

$pageTitle = 'My Dashboard';
$pageDescription = 'Your clinic information, services, and queue';
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
           STUDENT HERO
           ============================================================ -->
      <?php
      dbg_hero([
          'role'  => 'Student Portal',
          'title' => dbg_greeting() . ', ' . $studentFirstName . ' ',
          'desc'  => 'Your BPC School Clinic is here to support your health.',
          'chips' => [
              ['label' => 'Clinic status', 'value' => $clinicOpen ? 'Open' : 'Closed', 'dot' => $clinicOpen ? '' : 'warn'],
              ['label' => 'In queue', 'value' => $activeQueue ? ('#L' . $activeQueue['queue_number']) : 'No'],
              ['label' => 'Waiting now', 'value' => (string) $waitingCount],
          ],
          'badge_icon' => 'hospital',
          'badge_label'=> $clinicOpen ? 'Open Now' : 'Closed',
      ]);
      ?>

      <!-- ============================================================
           MY CLINIC PROFILE  +  MY CLINIC QR
           ============================================================ -->
      <div class="row g-3 g-xl-4 mb-3 mb-xl-4" data-aos="fade-up" data-aos-delay="50">
        <!-- Profile card -->
        <div class="col-xl-5">
          <div class="dbg-profile" style="height:100%;display:flex;flex-direction:column;">
            <div class="dbg-profile__top">
              <span class="dbg-profile__avatar">
                <?php $pic = trim((string)($u['profile_picture'] ?? '')); ?>
                <?php if ($pic !== ''): ?>
                  <img src="<?= e(app_url($pic)) ?>" alt="<?= e($fullName) ?>" onerror="this.remove()">
                <?php else: ?>
                  <?= e($initial ?: 'S') ?>
                <?php endif; ?>
              </span>
              <span style="min-width:0;">
                <h3 class="dbg-profile__name"><?= e($fullName !== '' ? $fullName : $studentFirstName) ?></h3>
                <span class="dbg-profile__sub"><?= e($courseLabel !== '' ? $courseLabel : 'BPC Student') ?></span>
              </span>
            </div>

            <div class="dbg-profile__facts">
              <div class="dbg-profile__fact">
                <span>Student ID</span>
                <b class="dbg-mono"><?= e($studentNumber) ?></b>
              </div>
              <div class="dbg-profile__fact">
                <span>Section</span>
                <b><?= e($sectionLabel) ?></b>
              </div>
              <div class="dbg-profile__fact">
                <span>Certificates</span>
                <b><?= $certCount ?> on file</b>
              </div>
            </div>

            <div class="dbg-btn-row" style="margin-top:18px;position:relative;z-index:1;">
              <a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="<?= APP_URL ?>/pages/student/medical_record.php">
                <i class="bi bi-file-earmark-medical"></i> View Health Record
              </a>
              <a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="<?= APP_URL ?>/pages/student/health_monitoring.php">
                <i class="bi bi-activity"></i> My Vitals
              </a>
            </div>
          </div>
        </div>

        <!-- QR showcase -->
        <div class="col-xl-4">
          <div class="dbg-qr">
            <span class="dbg-qr__label">My Clinic QR</span>
            <div class="dbg-qr__frame" id="dashboardQrFrame">
              <div class="dbg-skel dbg-skel--block" style="width:100%;height:100%;border-radius:8px;"></div>
            </div>
            <div class="dbg-qr__name"><?= e($fullName !== '' ? $fullName : $studentFirstName) ?></div>
            <div class="dbg-qr__meta"><?= e($courseLabel !== '' ? $courseLabel . ' · ' . $sectionLabel : $sectionLabel) ?></div>
            <div class="dbg-qr__hint">
              <i class="bi bi-info-circle me-1"></i>Show this QR code to clinic staff during your visit.
            </div>
            <div class="dbg-btn-row" style="margin-top:14px;width:100%;">
              <a class="dbg-btn dbg-btn--primary dbg-btn--sm" href="<?= APP_URL ?>/pages/student/my_qr.php">
                <i class="bi bi-qr-code"></i> View QR
              </a>
              <a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="<?= APP_URL ?>/pages/student/queue.php">
                <i class="bi bi-ticket-perforated"></i> <?= $activeQueue ? 'My Queue' : 'Join Queue' ?>
              </a>
            </div>
          </div>
        </div>

        <!-- My clinic queue -->
        <div class="col-xl-3">
          <div class="dbg-queue" style="height:100%;display:flex;flex-direction:column;">
            <div class="dbg-queue__head">
              <span class="dbg-pulse"></span> My Clinic Queue
            </div>
            <?php if ($activeQueue): ?>
              <div style="padding:16px;">
                <div class="dbg-queue__caption">Your queue number</div>
                <div class="dbg-queue__ticket">#L<?= e($activeQueue['queue_number']) ?></div>
                <div style="margin-top:10px;">
                  <span class="dbg-badge <?= $activeQueue['status'] === 'serving' ? '' : ($activeQueue['status'] === 'called' ? 'dbg-badge--orange' : 'dbg-badge--muted') ?>">
                    <?= e(ucfirst((string)$activeQueue['status'])) ?>
                  </span>
                </div>

                <hr class="dbg-divider" style="margin:14px 0;">

                <div class="dbg-queue__caption">Currently serving</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--bpc-green);line-height:1.1;">
                  <?= $nowServing ? '#L' . e($nowServing['queue_number']) : '—' ?>
                </div>

                <div class="d-flex align-items-center gap-2" style="margin-top:12px;">
                  <span class="dbg-count-pill"><i class="bi bi-people"></i> <?= (int)$patientsAhead ?> ahead</span>
                  <?php if ($estWait !== null && $estWait > 0): ?>
                    <span class="dbg-count-pill dbg-count-pill--muted"><i class="bi bi-hourglass"></i> ~<?= (int)$estWait ?> min</span>
                  <?php endif; ?>
                </div>
              </div>
            <?php else: ?>
              <div style="padding:16px;">
                <div class="dbg-queue__caption">Currently serving</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--bpc-green);line-height:1.1;">
                  <?= $nowServing ? '#L' . e($nowServing['queue_number']) : '—' ?>
                </div>
                <p class="dbg-muted" style="font-size:0.78rem;margin-top:6px;">
                  You're not currently in the clinic queue.
                </p>
                <?php if ($waitingCount > 0): ?>
                  <div class="dbg-count-pill" style="margin-top:8px;"><i class="bi bi-hourglass"></i> <?= $waitingCount ?> waiting</div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <div class="dbg-queue__foot" style="margin-top:auto;">
              <a class="dbg-btn dbg-btn--primary dbg-btn--sm dbg-btn--block" href="<?= APP_URL ?>/pages/student/queue.php">
                <i class="bi bi-broadcast"></i>
                <?= $activeQueue ? 'View Live Queue' : 'Join Clinic Queue' ?>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- ============================================================
           HEALTH SUMMARY  +  MESSAGE THE CLINIC
           ============================================================ -->
      <div class="row g-3 g-xl-4 mb-3 mb-xl-4" data-aos="fade-up" data-aos-delay="100">
        <!-- Health summary (summaries only) -->
        <div class="col-xl-7">
          <?php dbg_panel_open([
              'icon'     => 'heart-pulse-fill',
              'title'    => 'My Health Summary',
              'subtitle' => 'Summaries only — full details live in your health record',
              'action'   => '<a class="dbg-btn dbg-btn--soft dbg-btn--sm" href="' . APP_URL . '/pages/student/medical_record.php">Health record</a>',
          ]); ?>
            <?php if ($totalVisits === 0 && empty($latestVitals)): ?>
              <?php dbg_empty([
                  'icon'  => 'clipboard2-pulse',
                  'title' => 'No health records yet',
                  'desc'  => 'Once the clinic records your first visit, your summary will appear here.',
                  'action'=> '<a class="dbg-btn dbg-btn--primary dbg-btn--sm" href="' . APP_URL . '/pages/student/queue.php"><i class="bi bi-ticket-perforated"></i> Book a clinic visit</a>',
              ]); ?>
            <?php else: ?>
              <div class="dbg-stats dbg-stats--3 dbg-stats--tight mb-3">
                <?php
                dbg_stat([
                    'tone'  => 'green',
                    'icon'  => 'clipboard-data',
                    'value' => $totalVisits,
                    'count' => true,
                    'label' => 'Total Visits',
                    'note'  => '<span class="text-muted small">Recorded clinic visits</span>'
                ]);
                $bmiVal = $latestVitals && $latestVitals['bmi'] !== null ? number_format((float)$latestVitals['bmi'], 1) : '—';
                $bmiNote = '';
                if ($latestVitals && !empty($latestVitals['bmi_status'])) {
                    $bmiStatus = ucfirst((string)$latestVitals['bmi_status']);
                    $tone = in_array(strtolower($bmiStatus), ['normal', 'healthy'], true) ? 'teal' : 'orange';
                    $bmiNote = '<span class="dbg-badge dbg-badge--' . $tone . '" style="font-size:0.68rem;">' . e($bmiStatus) . '</span>';
                }
                dbg_stat([
                    'tone'  => 'teal',
                    'icon'  => 'speedometer2',
                    'value' => $bmiVal,
                    'label' => 'Latest BMI',
                    'note'  => $bmiNote ?: '<span class="text-muted small">Body Mass Index</span>'
                ]);
                dbg_stat([
                    'tone'  => 'blue',
                    'icon'  => 'calendar-check',
                    'value' => $lastVisit ? e(format_date($lastVisit['consultation_date'])) : '—',
                    'label' => 'Last Visit',
                    'note'  => $lastVisit && !empty($lastVisit['chief_complaint']) ? '<span class="dbg-clamp-1 text-muted small">' . e($lastVisit['chief_complaint']) . '</span>' : '<span class="text-muted small">Clinic checkup</span>'
                ]);
                ?>
              </div>
              <div class="p-3 rounded-3 border bg-light d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-3">
                  <span class="dbg-avatar bg-primary-light text-primary" style="width:42px;height:42px;font-size:1.2rem;">
                    <i class="bi bi-heart-pulse"></i>
                  </span>
                  <div>
                    <div style="font-weight:600;font-size:0.92rem;color:var(--text-primary);">
                      <?= $latestVitals && !empty($latestVitals['blood_pressure']) ? 'BP: <strong>' . e($latestVitals['blood_pressure']) . '</strong>' : 'No vitals recorded yet' ?>
                      <?php if ($latestVitals && !empty($latestVitals['heart_rate'])): ?>
                        <span class="ms-2 text-secondary fw-normal">&middot; Pulse: <strong><?= (int)$latestVitals['heart_rate'] ?></strong> bpm</span>
                      <?php endif; ?>
                    </div>
                    <div class="text-muted" style="font-size:0.75rem;">
                      <i class="bi bi-clock me-1"></i><?= !empty($latestVitals['recorded_at']) ? 'Updated ' . format_datetime($latestVitals['recorded_at']) : 'Visit clinic for health assessment' ?>
                    </div>
                  </div>
                </div>
                <a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="<?= APP_URL ?>/pages/student/health_monitoring.php">
                  <i class="bi bi-activity"></i> Check my vitals
                </a>
              </div>
            <?php endif; ?>
          <?php dbg_panel_close(); ?>
        </div>

        <!-- Message the clinic -->
        <div class="col-xl-5">
          <?php dbg_panel_open([
              'icon'     => 'chat-heart-fill',
              'title'    => 'Message the Clinic',
              'subtitle' => 'Nurses currently on duty',
              'action'   => '<a class="dbg-btn dbg-btn--soft dbg-btn--sm" href="' . APP_URL . '/pages/student/messages.php">Open inbox</a>',
          ]); ?>
            <div id="studentStaffHost">
              <?php dbg_skeleton_rows(3); ?>
            </div>
          <?php dbg_panel_close(); ?>
        </div>
      </div>

      <!-- ============================================================
           CLINIC SERVICES + ANNOUNCEMENTS
           ============================================================ -->
      <div class="row g-3 g-xl-4" data-aos="fade-up" data-aos-delay="150">
        <div class="col-xl-7">
          <?php dbg_panel_open([
              'icon'  => 'grid-1x2-fill',
              'title' => 'Clinic Services',
          ]); ?>
            <div class="dbg-actions dbg-actions--auto">
              <?php
              dbg_action_tile(['tone' => 'green',  'icon' => 'ticket-perforated-fill', 'title' => 'Clinic Visit',    'desc' => 'Join the queue or book',       'href' => APP_URL . '/pages/student/queue.php']);
              dbg_action_tile(['tone' => 'blue',   'icon' => 'file-earmark-medical-fill', 'title' => 'Medical Records', 'desc' => 'View your history',           'href' => APP_URL . '/pages/student/medical_record.php']);
              dbg_action_tile(['tone' => 'orange', 'icon' => 'file-medical-fill',       'title' => 'Certificates',    'desc' => $certCount . ' on file',      'href' => APP_URL . '/pages/student/certificates.php']);
              dbg_action_tile(['tone' => 'teal',   'icon' => 'chat-dots-fill',          'title' => 'Messages',        'desc' => 'Talk to a nurse',             'href' => APP_URL . '/pages/student/messages.php']);
              ?>
            </div>
          <?php dbg_panel_close('<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="dbg-muted" style="font-size:0.78rem;">
              <i class="bi bi-shield-fill-check me-1"></i>Your medical details stay private and are only visible to clinic staff.
            </span>
            <a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="<?= APP_URL ?>/pages/student/vaccination.php">
              <i class="bi bi-syringe"></i> Vaccination
            </a>
          </div>'); ?>
        </div>

        <div class="col-xl-5">
          <?php dbg_panel_open([
              'icon'     => 'megaphone-fill',
              'title'    => 'Clinic Announcements',
              'action'   => '<a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="' . APP_URL . '/pages/student/announcements.php">View all <i class="bi bi-arrow-right"></i></a>',
          ]); ?>
            <?php if (empty($announcements)): ?>
              <?php dbg_empty([
                  'icon'  => 'inbox',
                  'title' => 'No announcements yet',
                  'desc'  => 'Clinic announcements and health reminders will appear here.',
              ]); ?>
            <?php else: ?>
              <div class="dbg-list">
                <?php foreach ($announcements as $an): ?>
                  <div class="dbg-news">
                    <span class="dbg-news__icon"><i class="bi bi-megaphone-fill"></i></span>
                    <div style="min-width:0;">
                      <div class="dbg-news__title">
                        <span><?= e($an['title']) ?></span>
                        <?php if (!empty($an['is_pinned'])): ?><span class="dbg-tag">Pinned</span><?php endif; ?>
                      </div>
                      <div class="dbg-news__desc"><?= e((string)$an['content']) ?></div>
                      <div class="dbg-news__time"><?= format_datetime($an['created_at']) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php dbg_panel_close(); ?>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>

<script>
(function () {
  'use strict';

  // ------------------------------------------------------------------
  // 1. Health Pass QR — reuses the existing endpoint
  // ------------------------------------------------------------------
  var QR_ERROR_HTML = <?= json_encode(dbg_error_state('Unable to load your clinic QR.')) ?>;

  function loadQrCode() {
    var frame = document.getElementById('dashboardQrFrame');
    if (!frame) return;

    function showError() {
      frame.innerHTML = '<div style="text-align:center;color:var(--bpc-ink-2);font-size:0.78rem;">'
        + '<i class="bi bi-qr-code d-block" style="font-size:1.6rem;opacity:0.5;"></i>'
        + 'QR unavailable</div>';
    }

    if (!window.QRCode) return showError();

    $.ajax({
      url: APP_URL + '/api/qr_codes.php',
      method: 'GET',
      dataType: 'json',
      data: { action: 'student_health_pass' },
      success: function (res) {
        if (res && res.success && res.qr && res.qr.qr_data && window.QRCode) {
          frame.innerHTML = '';
          new QRCode(frame, {
            text: res.qr.qr_data,
            width: 154,
            height: 154,
            correctLevel: QRCode.CorrectLevel.M
          });
        } else {
          showError();
        }
      },
      error: function () { showError(); }
    });
  }
  loadQrCode();

  // ------------------------------------------------------------------
  // 2. Available clinic staff — reuses the existing messages endpoint
  // ------------------------------------------------------------------
  var STAFF_ERROR_HTML = <?= json_encode(dbg_error_state('Unable to load clinic staff availability.')) ?>;

  function staffRow(n) {
    var dot = n.presence_status === 'online' ? '' : (n.presence_status === 'away' ? ' dbg-live-dot--away' : ' dbg-live-dot--offline');
    return '<div class="dbg-staff__row">'
      + '<span class="dbg-avatar"><img src="' + (n.avatar || '') + '" alt="" onerror="this.style.display=\'none\'"></span>'
      + '<span class="dbg-staff__meta">'
      + '<span class="dbg-staff__name">' + (n.name || 'Clinic Nurse') + '</span>'
      + '<span class="dbg-staff__status"><span class="dbg-live-dot' + dot + '"></span>' + (n.availability_label || 'Offline') + '</span>'
      + '</span>'
      + '<span class="dbg-badge dbg-badge--muted">Nurse</span>'
      + '</div>';
  }

  function loadStaff() {
    var host = document.getElementById('studentStaffHost');
    if (!host) return;

    $.ajax({
      url: APP_URL + '/api/messages.php',
      method: 'GET',
      dataType: 'json',
      data: { action: 'available_staff' },
      success: function (res) {
        var staff = (res && res.success && res.data && res.data.staff) ? res.data.staff : [];
        if (!staff.length) {
          host.innerHTML = '<div class="dbg-empty"><div class="dbg-empty__icon"><i class="bi bi-person-x"></i></div>'
            + '<h6>No nurses available right now</h6>'
            + '<p>You can still send a message — the clinic will reply as soon as a nurse is free.</p></div>';
          return;
        }
        var online = staff.filter(function (n) { return n.is_online; });
        host.innerHTML =
          '<div class="d-flex align-items-center gap-2 mb-2">'
          + '<span class="dbg-badge' + (online.length ? '' : ' dbg-badge--muted') + '">Available now: ' + online.length + '</span>'
          + '</div>'
          + '<div class="dbg-staff">' + staff.slice(0, 4).map(staffRow).join('') + '</div>'
          + '<div class="dbg-btn-row" style="margin-top:14px;">'
          + '<a class="dbg-btn dbg-btn--primary dbg-btn--sm" href="' + APP_URL + '/pages/student/messages.php">'
          + '<i class="bi bi-chat-dots"></i> Message Clinic</a></div>';
      },
      error: function () {
        host.innerHTML = STAFF_ERROR_HTML;
      }
    });
  }

  function whenReady(fn) {
    if (typeof $ !== 'undefined') return fn();
    document.addEventListener('DOMContentLoaded', fn);
  }
  whenReady(loadStaff);
})();
</script>
</body>
</html>