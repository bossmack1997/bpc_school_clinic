<?php
/**
 * pages/nurse/dashboard.php
 * =====================================================================
 * NURSE Dashboard — Clinic Operations Console
 * Focus: patient care, live queue, emergencies, medicine supply.
 * Deliberately NOT a copy of the admin layout.
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');

$db = getDB();
$u  = current_user();

$nurseFirstName = !empty($u['first_name']) ? e($u['first_name']) : 'Nurse';
$nurseFullName  = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
$nurseInitial   = strtoupper(substr((string)($u['first_name'] ?? 'N'), 0, 1) . substr((string)($u['last_name'] ?? ''), 0, 1));

// -------------------------------------------------------------------------
// 1. Today's clinic summary
// -------------------------------------------------------------------------
$todaysVisits     = (int)$db->query("SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) = CURDATE()")->fetchColumn();
$waitingCount     = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'waiting'")->fetchColumn();
$servingCount     = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status IN ('called','serving')")->fetchColumn();
$completedCount   = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'completed'")->fetchColumn();
$cancelledCount   = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status IN ('cancelled','no_show')")->fetchColumn();
$patientsToday    = (int)$db->query("SELECT COUNT(DISTINCT student_id) FROM consultations WHERE DATE(consultation_date) = CURDATE()")->fetchColumn();

// -------------------------------------------------------------------------
// 2. Live queue — now serving + next in line
// -------------------------------------------------------------------------
$nowServing = $db->query("
    SELECT q.id, q.queue_number, q.status, q.priority, q.called_at, q.created_at,
           s.student_number, s.course,
           COALESCE(CONCAT(us.first_name, ' ', SUBSTRING(us.last_name, 1, 1), '.'),
                    CONCAT(ut.first_name, ' ', SUBSTRING(ut.last_name, 1, 1), '.'),
                    q.full_name, 'Patient') AS display_name,
           COALESCE(w.chief_complaint, '') AS chief_complaint
    FROM queue_numbers q
    LEFT JOIN students s ON s.id = q.student_id
    LEFT JOIN users us ON us.id = s.user_id
    LEFT JOIN teachers t ON t.id = q.teacher_id
    LEFT JOIN users ut ON ut.id = t.user_id
    LEFT JOIN walk_in_visits w ON w.queue_id = q.id
    WHERE DATE(q.created_at) = CURDATE() AND q.status IN ('serving','called')
    ORDER BY FIELD(q.status,'serving','called'), q.id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$nextInLine = $db->query("
    SELECT q.id, q.queue_number, q.priority, q.created_at,
           COALESCE(CONCAT(us.first_name, ' ', SUBSTRING(us.last_name, 1, 1), '.'),
                    CONCAT(ut.first_name, ' ', SUBSTRING(ut.last_name, 1, 1), '.'),
                    q.full_name, 'Patient') AS display_name,
           COALESCE(w.chief_complaint, '') AS chief_complaint
    FROM queue_numbers q
    LEFT JOIN students s ON s.id = q.student_id
    LEFT JOIN users us ON us.id = s.user_id
    LEFT JOIN teachers t ON t.id = q.teacher_id
    LEFT JOIN users ut ON ut.id = t.user_id
    LEFT JOIN walk_in_visits w ON w.queue_id = q.id
    WHERE DATE(q.created_at) = CURDATE() AND q.status = 'waiting'
    ORDER BY FIELD(q.priority, 'emergency', 'urgent', 'normal'), q.created_at ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------------
// 3. Emergency alerts
// -------------------------------------------------------------------------
$activeEmergencies = $db->query("
    SELECT e.id, e.emergency_type, e.status, e.priority, e.source, e.created_at, e.location,
           CONCAT(us.first_name, ' ', us.last_name) AS student_name,
           s.student_number
    FROM sos_emergencies e
    LEFT JOIN students s ON s.id = e.student_id
    LEFT JOIN users us ON us.id = s.user_id
    WHERE e.status IN ('new','active','acknowledged','responding') AND e.resolved_at IS NULL
    ORDER BY FIELD(e.priority,'emergency','urgent','normal'), e.created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
$activeEmergencyCount = count($activeEmergencies);

// -------------------------------------------------------------------------
// 4. Medicine inventory health
// -------------------------------------------------------------------------
$lowStockCount = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= reorder_level AND reorder_level > 0 AND (expiration_date IS NULL OR expiration_date >= CURDATE())")->fetchColumn();
$outOfStock    = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= 0")->fetchColumn();
$expiringSoon  = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity > 0 AND expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$expiredCount  = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity > 0 AND expiration_date IS NOT NULL AND expiration_date < CURDATE()")->fetchColumn();

$attentionMeds = $db->query("
    SELECT id, medicine_name AS name, quantity, reorder_level, unit, expiration_date
    FROM medicines
    WHERE is_archived = 0
      AND (quantity <= reorder_level OR (expiration_date IS NOT NULL AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)))
    ORDER BY (quantity - reorder_level) ASC, expiration_date ASC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------------
// 5. Today's patient activity
// -------------------------------------------------------------------------
$recentConsultations = $db->query("
    SELECT c.id, c.consultation_date, c.chief_complaint, c.diagnosis, c.visit_type,
           s.student_number,
           CONCAT(us.first_name, ' ', us.last_name) AS student_name
    FROM consultations c
    JOIN students s ON s.id = c.student_id
    JOIN users us ON us.id = s.user_id
    WHERE DATE(c.consultation_date) = CURDATE()
    ORDER BY c.consultation_date DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------------
// 6. Hourly throughput for today's queue (real data)
// -------------------------------------------------------------------------
$hourly = array_fill(0, 12, 0);
$hourRows = $db->query("
    SELECT HOUR(created_at) AS hr, COUNT(*) AS total
    FROM queue_numbers
    WHERE DATE(created_at) = CURDATE()
    GROUP BY HOUR(created_at)
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($hourRows as $hr) {
    $h = (int)$hr['hr'];
    if ($h >= 7 && $h <= 18) $hourly[$h - 7] = (int)$hr['total'];
}
$hourLabels = [];
for ($h = 7; $h <= 18; $h++) {
    $hourLabels[] = date('g A', strtotime(sprintf('%02d:00', $h)));
}

$clinicOpen = $waitingCount > 0 || $servingCount > 0 || $todaysVisits > 0;

$pageTitle = 'Nurse Dashboard';
$pageDescription = 'Today’s clinic activity, queue, and patient care';
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
           NURSE HERO
           ============================================================ -->
      <?php
      dbg_hero([
          'role'  => 'Clinic Nurse',
          'title' => dbg_greeting() . ', Nurse ' . $nurseFirstName . ' ',
          'desc'  => "Here's today's clinic activity.",
          'chips' => [
              ['label' => 'Clinic', 'value' => $clinicOpen ? 'Open' : 'No activity yet', 'dot' => $clinicOpen ? '' : 'warn'],
              ['label' => 'Queue', 'value' => $waitingCount > 0 ? 'Active' : 'Idle', 'dot' => $waitingCount > 0 ? '' : 'warn'],
              ['label' => 'Emergencies', 'value' => (string) $activeEmergencyCount, 'dot' => $activeEmergencyCount > 0 ? 'danger' : ''],
          ],
          'badge_icon'  => 'heart-pulse-fill',
          'badge_label' => 'On Duty',
      ]);
      ?>

      <!-- ============================================================
           TODAY'S CLINIC SUMMARY — snapshot cards
           ============================================================ -->
      <div class="dbg-stats dbg-stats--4" data-aos="fade-up">
        <?php
        dbg_stat([
            'solid'  => true,
            'tone'   => 'green',
            'icon'   => 'person-check-fill',
            'value'  => $todaysVisits,
            'count'  => true,
            'label'  => 'Patients Today',
            'note'   => '<span><i class="bi bi-person"></i> ' . $patientsToday . ' unique student(s)</span>',
        ]);
        dbg_stat([
            'tone'   => 'orange',
            'icon'   => 'hourglass-split',
            'value'  => $waitingCount,
            'count'  => true,
            'id'     => 'dashStatWaiting',
            'label'  => 'Waiting',
            'note'   => '<span>' . ($waitingCount > 0 ? 'Line is building up' : 'No one in line') . '</span>',
        ]);
        dbg_stat([
            'tone'   => 'blue',
            'icon'   => 'stethoscope',
            'value'  => $servingCount,
            'count'  => true,
            'id'     => 'dashStatServing',
            'label'  => 'In Consultation',
            'note'   => '<span>' . ($servingCount > 0 ? 'Station(s) occupied' : 'Stations free') . '</span>',
        ]);
        dbg_stat([
            'tone'   => 'teal',
            'icon'   => 'check2-circle',
            'value'  => $completedCount,
            'count'  => true,
            'id'     => 'dashStatCompleted',
            'label'  => 'Completed',
            'note'   => '<span>' . $cancelledCount . ' cancelled / no-show</span>',
        ]);
        ?>
      </div>

      <!-- ============================================================
           LIVE PATIENT QUEUE + EMERGENCY ALERTS
           ============================================================ -->
      <div class="row g-3 g-xl-4 mb-3 mb-xl-4" data-aos="fade-up" data-aos-delay="60">
        <div class="col-xl-7">
          <div class="dbg-queue">
            <div class="dbg-queue__head">
              <span class="dbg-pulse"></span>
              Live Clinic Queue
            </div>

            <!-- Now serving -->
            <?php if ($nowServing): ?>
              <div class="dbg-queue__now">
                <div>
                  <div class="dbg-queue__ticket">#L<?= e($nowServing['queue_number']) ?></div>
                  <div class="dbg-queue__caption">Now serving</div>
                </div>
                <div class="dbg-queue__who">
                  <div class="dbg-queue__name"><?= e($nowServing['display_name']) ?></div>
                  <div class="dbg-queue__meta">
                    <?= e($nowServing['chief_complaint'] ?: 'General consultation') ?>
                    &middot; <span class="dbg-badge <?= $nowServing['priority'] === 'emergency' ? 'dbg-badge--red' : ($nowServing['priority'] === 'urgent' ? 'dbg-badge--orange' : 'dbg-badge--muted') ?>"><?= e(ucfirst((string)$nowServing['priority'])) ?></span>
                  </div>
                </div>
                <a class="dbg-btn dbg-btn--soft dbg-btn--sm" href="<?= APP_URL ?>/pages/nurse/queue.php">
                  <i class="bi bi-box-arrow-up-right"></i> Open
                </a>
              </div>
            <?php else: ?>
              <div class="dbg-queue__now" style="border-style:solid;background:var(--dbg-surface-2);">
                <div>
                  <div class="dbg-queue__ticket dbg-muted">—</div>
                  <div class="dbg-queue__caption">Now serving</div>
                </div>
                <div class="dbg-queue__who">
                  <div class="dbg-queue__name">No patient in consultation</div>
                  <div class="dbg-queue__meta">All stations are available right now.</div>
                </div>
              </div>
            <?php endif; ?>

            <!-- Waiting line -->
            <?php if (!empty($nextInLine)): ?>
              <ul class="dbg-queue__list" id="dashQueueLiveRegion">
                <?php foreach ($nextInLine as $idx => $next): ?>
                  <li class="dbg-queue__row<?= $idx === 0 ? ' dbg-queue__row--next' : '' ?>">
                    <span class="dbg-queue__num">#L<?= e($next['queue_number']) ?></span>
                    <span class="dbg-queue__text">
                      <?= e($next['chief_complaint'] ?: 'Awaiting consultation') ?>
                      <small><?= e($next['display_name']) ?> &middot; <?= e(ucfirst((string)$next['priority'])) ?> &middot; queued <?= format_datetime($next['created_at'], 'g:i A') ?></small>
                    </span>
                    <?php if ($idx === 0): ?><span class="dbg-badge dbg-badge--orange">Next</span><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div style="padding:6px 18px 0;" id="dashQueueLiveRegion">
                <?php dbg_empty([
                    'icon'  => 'people',
                    'title' => 'No patients waiting',
                    'desc'  => 'The waiting line is clear. New arrivals will appear here automatically.',
                ]); ?>
              </div>
            <?php endif; ?>

            <div class="dbg-queue__foot">
              <div class="dbg-btn-row">
                <a class="dbg-btn dbg-btn--primary dbg-btn--sm" href="<?= APP_URL ?>/pages/nurse/queue.php">
                  <i class="bi bi-broadcast"></i> Open Queue
                </a>
              </div>
            </div>
          </div>
        </div>

        <!-- Emergency panel -->
        <div class="col-xl-5">
          <?php dbg_panel_open([
              'icon'  => 'exclamation-triangle-fill',
              'title' => 'Emergency Alerts',
          ]); ?>
            <?php
            dbg_monitor_open([
                'active' => $activeEmergencyCount > 0,
                'count'  => $activeEmergencyCount,
                'label'  => $activeEmergencyCount > 0
                    ? 'Emergency response required'
                    : 'No active emergencies. Everything is currently normal.',
            ]);
            ?>
            <?php if ($activeEmergencyCount > 0): ?>
              <ul class="dbg-monitor__list" style="margin-top:14px;">
                <?php foreach ($activeEmergencies as $emg): ?>
                  <li class="dbg-monitor__item">
                    <span class="dbg-timeline__node dbg-timeline__node--red"><i class="bi bi-exclamation-octagon-fill"></i></span>
                    <span class="dbg-staff__meta">
                      <span class="mi-title"><?= e($emg['emergency_type'] ?: 'Emergency') ?></span>
                      <span class="mi-sub"><?= e($emg['student_name'] ?: 'Unknown student') ?> &middot; <?= e($emg['student_number'] ?: '—') ?><?= !empty($emg['location']) ? ' &middot; ' . e($emg['location']) : '' ?> &middot; <?= format_datetime($emg['created_at']) . (($relativeTime = format_relative_datetime($emg['created_at'])) !== '' ? ' &middot; ' . e($relativeTime) : '') ?></span>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
              <div class="dbg-btn-row" style="margin-top:14px;">
                <a class="dbg-btn dbg-btn--danger dbg-btn--sm" href="<?= APP_URL ?>/pages/nurse/emergencies.php">
                  <i class="bi bi-shield-fill-exclamation"></i> Respond
                </a>
              </div>
            <?php else: ?>
              <div class="dbg-btn-row" style="margin-top:16px;">
                <a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="<?= APP_URL ?>/pages/nurse/emergencies.php">
                  <i class="bi bi-journal-medical"></i> Emergency history
                </a>
              </div>
            <?php endif; ?>
          <?php dbg_panel_close(); ?>
        </div>
      </div>

      <!-- ============================================================
           MEDICINE INVENTORY + TODAY'S VISITS
           ============================================================ -->
      <div class="row g-3 g-xl-4 mb-3 mb-xl-4" data-aos="fade-up" data-aos-delay="120">
        <!-- Medicine inventory -->
        <div class="col-xl-5">
          <?php dbg_panel_open([
              'icon'     => 'capsule-pill',
              'title'    => 'Medicine Inventory',
              'subtitle' => 'Items needing attention before the next rush',
              'action'   => '<a class="dbg-btn dbg-btn--soft dbg-btn--sm" href="' . APP_URL . '/pages/nurse/inventory.php">Open inventory</a>',
          ]); ?>
            <div class="dbg-stats dbg-stats--3 dbg-stats--tight">
              <?php
              dbg_stat(['tone' => 'orange', 'icon' => 'arrow-down-circle', 'value' => $lowStockCount, 'count' => true, 'label' => 'Low Stock']);
              dbg_stat(['tone' => 'red',    'icon' => 'x-octagon',       'value' => $outOfStock,    'count' => true, 'label' => 'Out of Stock']);
              dbg_stat(['tone' => 'purple', 'icon' => 'calendar2-week',   'value' => $expiringSoon,  'count' => true, 'label' => 'Expiring Soon']);
              ?>
            </div>

            <?php if (empty($attentionMeds)): ?>
              <?php dbg_empty([
                  'icon'  => 'check2-all',
                  'title' => 'Stock levels are healthy',
                  'desc'  => 'Every medicine is above its reorder level with no near-term expiry.',
              ]); ?>
            <?php else: ?>
              <div class="dbg-meters">
                <?php foreach ($attentionMeds as $med): ?>
                  <?php
                  $qty    = (int)$med['quantity'];
                  $reordr = (int)$med['reorder_level'];
                  $pct    = $reordr > 0 ? min(100, (int)round(($qty / max(1, $reordr * 2)) * 100)) : ($qty > 0 ? 100 : 0);
                  $cls    = $qty <= 0 ? 'dbg-meter__fill--crit' : ($qty <= $reordr ? 'dbg-meter__fill--warn' : '');
                  ?>
                  <div>
                    <div class="dbg-meter__top">
                      <span class="dbg-meter__name dbg-clamp-1"><?= e($med['name']) ?></span>
                      <span class="dbg-meter__value"><?= $qty ?> <?= e($med['unit'] ?: 'pcs') ?><?= $reordr > 0 ? ' / min ' . $reordr : '' ?></span>
                    </div>
                    <div class="dbg-meter__bar"><span class="dbg-meter__fill <?= $cls ?>" style="width:<?= max(3, $pct) ?>%"></span></div>
                    <?php if (!empty($med['expiration_date'])): ?>
                      <div class="dbg-muted" style="font-size:0.7rem;margin-top:4px;">Expires <?= format_date($med['expiration_date']) ?></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php dbg_panel_close(); ?>
        </div>

        <!-- Today's visits + throughput -->
        <div class="col-xl-7">
          <?php dbg_panel_open([
              'icon'     => 'clipboard2-pulse',
              'title'    => "Today's Visits",
              'subtitle' => $recentConsultations ? 'Most recent consultations first' : 'Activity recorded today',
              'action'   => '<a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="' . APP_URL . '/pages/nurse/medical_records.php">All records <i class="bi bi-arrow-right"></i></a>',
          ]); ?>
            <?php if (empty($recentConsultations)): ?>
              <?php dbg_empty([
                  'icon'  => 'calendar-x',
                  'title' => 'No clinic visits today',
                  'desc'  => 'Consultations recorded today will show up here in real time.',
                  'action'=> '<a class="dbg-btn dbg-btn--primary dbg-btn--sm" href="' . APP_URL . '/pages/nurse/walkin.php"><i class="bi bi-person-plus"></i> Register walk-in</a>',
              ]); ?>
            <?php else: ?>
              <div class="row g-3">
                <div class="col-lg-7">
                  <ul class="dbg-timeline">
                    <?php foreach ($recentConsultations as $c): ?>
                      <li class="dbg-timeline__item">
                        <span class="dbg-timeline__node"><i class="bi bi-file-medical"></i></span>
                        <span class="dbg-timeline__body">
                          <span class="dbg-timeline__title"><?= e($c['student_name']) ?></span>
                          <span class="dbg-timeline__desc"><?= e($c['chief_complaint'] ?: 'General check-up') ?><?= !empty($c['diagnosis']) ? ' &middot; ' . e($c['diagnosis']) : '' ?></span>
                          <span class="dbg-timeline__desc dbg-muted">
                            <span class="dbg-mono"><?= e($c['student_number'] ?: '—') ?></span>
                            &middot; <?= $c['visit_type'] === 'appointment' ? 'Appointment' : 'Walk-in' ?>
                          </span>
                        </span>
                        <span class="dbg-timeline__time"><?= format_datetime($c['consultation_date'], 'g:i A') ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <div class="col-lg-5">
                  <div class="dbg-chart-box dbg-chart-box--sm">
                    <canvas id="nurseThroughputChart" aria-label="Queue arrivals by hour today"></canvas>
                  </div>
                </div>
              </div>
            <?php endif; ?>
          <?php dbg_panel_close(); ?>
        </div>
      </div>

      <!-- ============================================================
           NURSE QUICK ACTIONS
           ============================================================ -->
      <?php dbg_panel_open([
          'icon'  => 'lightning-charge-fill',
          'title' => 'Quick Actions',
          'flush' => true,
      ]); ?>
        <div style="padding:18px;">
          <div class="dbg-actions dbg-actions--6">
            <?php
            dbg_action_tile(['tone' => 'green',  'icon' => 'qr-code-scan',      'title' => 'Scan Patient QR', 'desc' => 'Open the camera scanner',       'onclick' => 'if (typeof openQrScannerModal === "function") { openQrScannerModal(); } else { window.location.href = APP_URL + "/pages/nurse/queue.php"; }']);
            dbg_action_tile(['tone' => 'blue',   'icon' => 'broadcast',          'title' => 'Open Queue',       'desc' => 'Call, serve, and complete tickets','href' => APP_URL . '/pages/nurse/queue.php']);
            dbg_action_tile(['tone' => 'teal',   'icon' => 'search-heart-fill',  'title' => 'Medical Records',  'desc' => 'Search student history',        'href' => APP_URL . '/pages/nurse/medical_records.php']);
            dbg_action_tile(['tone' => 'purple', 'icon' => 'capsule-pill',       'title' => 'Dispense Medicine','desc' => 'Issue and log medicines',       'href' => APP_URL . '/pages/nurse/dispensing.php']);
            dbg_action_tile(['tone' => 'red',    'icon' => 'shield-fill-exclamation', 'title' => 'Emergency Center','desc' => 'Respond to active SOS alerts','href' => APP_URL . '/pages/nurse/emergencies.php']);
            dbg_action_tile(['tone' => 'orange', 'icon' => 'file-medical-fill',  'title' => 'Certificates',     'desc' => 'Issue medical certificates',    'href' => APP_URL . '/pages/nurse/certificates.php']);
            ?>
          </div>
        </div>
        <?php
        dbg_panel_close(
            '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">'
            . '<div class="d-flex align-items-center gap-2">'
            . '<span class="dbg-avatar">' . e($nurseInitial ?: 'N') . '</span>'
            . '<span><span class="d-block" style="font-weight:600;font-size:0.88rem;">' . e($nurseFullName !== '' ? $nurseFullName : $nurseFirstName) . '</span>'
            . '<span class="dbg-muted" style="font-size:0.75rem;">' . $todaysVisits . ' consultation(s) handled today</span></span></div>'
            . '<div class="dbg-btn-row">'
            . '<a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="' . APP_URL . '/pages/nurse/walkin.php"><i class="bi bi-person-plus"></i> Walk-in</a>'
            . '<a class="dbg-btn dbg-btn--ghost dbg-btn--sm" href="' . APP_URL . '/pages/nurse/messages.php"><i class="bi bi-chat-heart"></i> Messages</a>'
            . '</div></div>'
        );
        ?>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/patient_workspace_modal.php'; ?>
<?php include __DIR__ . '/../../includes/qr_scanner_modal.php'; ?>
<?php include __DIR__ . '/../../components/footer.php'; ?>

<script>
(function () {
  'use strict';

  // ---- Today's arrivals by hour ----
  function renderThroughput() {
    var canvas = document.getElementById('nurseThroughputChart');
    if (!canvas || typeof Chart === 'undefined') return;
    new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: <?= json_encode($hourLabels) ?>,
        datasets: [{
          label: 'Arrivals',
          data: <?= json_encode(array_map('intval', $hourly)) ?>,
          backgroundColor: 'rgba(13,148,136,0.75)',
          hoverBackgroundColor: '#0D9488',
          borderRadius: 6,
          maxBarThickness: 22
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#075B2E',
            cornerRadius: 8,
            callbacks: { label: function (c) { return c.parsed.y + ' arrival(s)'; } }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, color: '#6B7280', font: { size: 10 } } },
          y: { beginAtZero: true, grid: { color: 'rgba(31,41,55,0.06)' }, ticks: { precision: 0, color: '#6B7280', font: { size: 10 } } }
        }
      }
    });
  }

  function whenChartReady() {
    if (typeof Chart !== 'undefined') return renderThroughput();
    var tries = 0;
    (function wait() {
      if (typeof Chart !== 'undefined' || tries > 25) return renderThroughput();
      tries++; setTimeout(wait, 120);
    })();
  }
  whenChartReady();

  // ---- Live queue refresh (reuses the existing endpoint) ----
  var ERROR_HTML = <?= json_encode(dbg_error_state('Unable to refresh the live queue.')) ?>;

  function refreshQueue() {
    if (document.hidden) return;
    $.ajax({
      url: APP_URL + '/api/queue.php',
      method: 'GET',
      dataType: 'json',
      data: { action: 'get_status' },
      success: function (res) {
        if (!res || !res.success || !res.data) return;
        var d = res.data;
        var waiting = parseInt(d.total_waiting, 10) || 0;
        var done = parseInt(d.total_completed, 10) || 0;

        var w1 = document.getElementById('dashStatWaiting');
        if (w1) w1.textContent = waiting.toLocaleString();

        var doneEl = document.getElementById('dashStatCompleted');
        if (doneEl) doneEl.textContent = done.toLocaleString();

        // If the line clears out, keep the panel meaningful instead of stale
        var host = document.getElementById('dashQueueLiveRegion');
        if (host && waiting === 0 && !d.now_serving) {
          host.innerHTML =
            '<div class="dbg-empty"><div class="dbg-empty__icon"><i class="bi bi-people"></i></div>'
            + '<h6>No patients waiting</h6><p>The waiting line is clear right now.</p></div>';
        }
      },
      error: function () {
        var host = document.getElementById('dashQueueLiveRegion');
        if (host && !host.dataset.errored) {
          host.dataset.errored = '1';
          host.innerHTML = ERROR_HTML;
        }
      }
    });
  }

  // First refresh is delayed so the animated counters can finish
  // their 1s intro before the live values replace them.
  setTimeout(refreshQueue, 1200);
  setInterval(refreshQueue, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshQueue(); });
})();
</script>
</body>
</html>
