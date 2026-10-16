<?php
/**
 * pages/admin/dashboard.php
 * =====================================================================
 * Admin Dashboard — operational control center.
 *
 * Restructured around operational priority with a strict
 * redundancy-removal policy. The previous layout repeated the
 * same signals in up to three places (stat card + Needs
 * Attention alert + a dedicated panel). This version shows
 * each signal exactly once:
 *
 *   1. Compact header
 *   2. Four essential, non-duplicated overview cards
 *   3. Operational overview: clinic activity (chart + queue)
 *      beside the single source of truth for actionable alerts
 *   4. One compact recent-activity feed
 *
 * Removed as verified duplication:
 *   - "Emergency Monitor" card  (SOS is already a critical
 *     alert inside Needs Attention, same data, same link)
 *   - "Inventory Health" panel  (repeated the low-stock stat
 *     card and the inventory alerts in Needs Attention)
 *   - "Quick Actions" card      (its links duplicate the stat
 *     cards, panel footers, and the sidebar navigation)
 *   - Patient-visits mini-metrics ("Today" repeated stat card
 *     #1; "Queue tickets" repeated the queue summary)
 *
 * All figures come from the existing database schema and
 * helpers. Nothing is hardcoded.
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');

$db = getDB();
$u  = current_user();

$adminFirstName = !empty($u['first_name']) ? e($u['first_name']) : 'Administrator';
$adminFullName  = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));

// ---------------------------------------------------------------------
// 1. Headline population and today metrics
// ---------------------------------------------------------------------
$totalUsers    = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers   = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$studentCount  = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$activeStudents = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn();
$teacherCount  = (int)$db->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher','adviser')")->fetchColumn();
$nurseCount    = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'nurse' AND status = 'active'")->fetchColumn();
$adminCount    = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();

$todaysVisits = (int)$db->query("SELECT COUNT(*) FROM consultations WHERE consultation_date >= CURDATE() AND consultation_date < CURDATE() + INTERVAL 1 DAY")->fetchColumn();
$yesterVisits = (int)$db->query("SELECT COUNT(*) FROM consultations WHERE consultation_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND consultation_date < CURDATE()")->fetchColumn();
$visitsDelta  = $todaysVisits - $yesterVisits;

$weekVisits = (int)$db->query("SELECT COUNT(*) FROM consultations WHERE consultation_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND consultation_date < CURDATE() + INTERVAL 1 DAY")->fetchColumn();
$monthVisits = (int)$db->query("SELECT COUNT(*) FROM consultations WHERE consultation_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();

// ---------------------------------------------------------------------
// 2. Administrative signals
// ---------------------------------------------------------------------
$pendingStaff    = (int)$db->query("SELECT COUNT(*) FROM users WHERE role IN ('nurse','teacher','adviser','admin') AND status = 'pending'")->fetchColumn();
$pendingStudents = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'pending'")->fetchColumn();
$pendingTotal    = $pendingStaff + $pendingStudents;

$activeEmergencies = (int)$db->query("SELECT COUNT(*) FROM sos_emergencies WHERE status IN ('new','active','acknowledged','responding') AND resolved_at IS NULL")->fetchColumn();

$totalMedicines    = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0")->fetchColumn();
$lowStockCount     = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= reorder_level AND reorder_level > 0 AND (expiration_date IS NULL OR expiration_date >= CURDATE())")->fetchColumn();
$outOfStockCount   = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= 0")->fetchColumn();
$expiredCount      = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity > 0 AND expiration_date IS NOT NULL AND expiration_date < CURDATE()")->fetchColumn();
$expiringSoonCount = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity > 0 AND expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$stockAlerts       = $lowStockCount + $outOfStockCount + $expiredCount;

// Single combined inventory-attention headline. The per-category
// breakdown stays on the dedicated inventory management page.
$inventoryAttention = $lowStockCount + $outOfStockCount + $expiredCount;

// ---------------------------------------------------------------------
// 3. Queue summary (live operational load)
// ---------------------------------------------------------------------
$queueToday    = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$queueWaiting  = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'waiting'")->fetchColumn();
$queueServing  = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status IN ('called','serving')")->fetchColumn();
$queueCompleted = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'completed'")->fetchColumn();

// ---------------------------------------------------------------------
// 4. Patient visits - three ready ranges for the chart toggle
// ---------------------------------------------------------------------
$hoursStmt = $db->query("
    SELECT FLOOR(HOUR(consultation_date) / 2) AS bucket, COUNT(*) AS total
    FROM consultations
    WHERE consultation_date >= CURDATE() AND consultation_date < CURDATE() + INTERVAL 1 DAY
    GROUP BY bucket
");
$hourMap = [];
foreach ($hoursStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $hourMap[(int)$row['bucket']] = (int)$row['total'];
}
$todayLabels = [];
$todayValues = [];
for ($b = 3; $b <= 9; $b++) {
    $hour = $b * 2;
    $suffix = $hour >= 12 ? 'PM' : 'AM';
    $display = $hour % 12 === 0 ? 12 : ($hour % 12);
    $todayLabels[] = $display . ' ' . $suffix;
    $todayValues[] = $hourMap[$b] ?? 0;
}

$weekStmt = $db->query("
    SELECT DATE(consultation_date) AS d, COUNT(*) AS total
    FROM consultations
    WHERE consultation_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
      AND consultation_date <  CURDATE() + INTERVAL 1 DAY
    GROUP BY DATE(consultation_date)
");
$weekMap = [];
foreach ($weekStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $weekMap[$row['d']] = (int)$row['total'];
}
$weekLabels = [];
$weekValues = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $weekLabels[] = date('D', strtotime($day));
    $weekValues[] = $weekMap[$day] ?? 0;
}

$monthStmt = $db->query("
    SELECT DATE(consultation_date) AS d, COUNT(*) AS total
    FROM consultations
    WHERE consultation_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
      AND consultation_date < CURDATE() + INTERVAL 1 DAY
    GROUP BY DATE(consultation_date)
");
$monthMap = [];
foreach ($monthStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $monthMap[$row['d']] = (int)$row['total'];
}
$monthLabels = [];
$monthValues = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $monthLabels[] = ($i % 5 === 0 || $i === 0) ? date('M j', strtotime($day)) : '';
    $monthValues[] = $monthMap[$day] ?? 0;
}

// ---------------------------------------------------------------------
// 5. Recent administrative activity (compact feed)
// ---------------------------------------------------------------------
$recentActivity = $db->query(
    "SELECT al.id, al.action, al.module, al.details, al.created_at,
            u.first_name, u.last_name, u.role
     FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC
     LIMIT 6"
)->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------
// 6. Priority feed and overall status
// ---------------------------------------------------------------------
$attention = get_admin_needs_attention_alerts($db);

$attentionIconMap = [
    'sos_active'          => 'shield-alert',
    'med_expired'         => 'calendar-x',
    'reg_high_risk'       => 'user-x',
    'reg_review'          => 'user-check',
    'med_low_stock'       => 'pill',
    'sec_locked_ips'      => 'shield-lock',
    'med_expiring_soon'   => 'calendar',
];

$stockHealth = $totalMedicines > 0
    ? max(0, min(100, round((($totalMedicines - $stockAlerts) / $totalMedicines) * 100)))
    : 100;

if ($activeEmergencies > 0) {
    $statusLabel = 'Emergency';
    $statusTone  = 'danger';
} elseif ($pendingTotal > 0 || $stockAlerts > 0) {
    $statusLabel = 'Needs review';
    $statusTone  = 'warning';
} else {
    $statusLabel = 'All clear';
    $statusTone  = 'success';
}

$pageTitle       = 'Dashboard';
$pageSection     = 'Administration';
$pageDescription = 'Clinic overview and system activity';
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

    <main class="content-area adm-page adm-page-enter">

      <!-- ============================================================
           1. COMPACT PAGE HEADER
           ============================================================ -->
      <section class="adm-greet" aria-labelledby="admGreeting">
        <div class="adm-greet__text">
          <div class="adm-greet__eyebrow">Clinic overview</div>
          <h2 class="adm-greet__title" id="admGreeting">
            <?= e(dbg_greeting()) ?>, <?= $adminFirstName ?>
          </h2>
          <p class="adm-greet__sub">
            <?php if ($activeEmergencies > 0): ?>
              <?= (int)$activeEmergencies ?> active emergency
              <?= $activeEmergencies === 1 ? 'report needs' : 'reports need' ?> a response.
              <?= $pendingTotal > 0
                    ? $pendingTotal . ' account ' . ($pendingTotal === 1 ? 'approval is' : 'approvals are') . ' also waiting.'
                    : 'No accounts are waiting for approval.' ?>
            <?php elseif ($pendingTotal > 0): ?>
              <?= $pendingTotal ?> account <?= $pendingTotal === 1 ? 'approval is' : 'approvals are' ?> waiting for review
              <?= $stockAlerts > 0 ? ' and ' . $stockAlerts . ' stock ' . ($stockAlerts === 1 ? 'item needs' : 'items need') . ' attention.' : '.' ?>
            <?php else: ?>
              No pending approvals or emergencies. <?= $todaysVisits ?> patient
              <?= $todaysVisits === 1 ? 'visit' : 'visits' ?> recorded today.
            <?php endif; ?>
          </p>
        </div>
        <div class="adm-greet__aside">
          <span class="adm-datechip">
            <?= bpc_icon('calendar') ?> <?= format_datetime('now', 'l, F j, Y') ?>
          </span>
          <span class="adm-badge adm-badge--<?= e($statusTone === 'warning' ? 'warning' : $statusTone) ?>">
            <span class="adm-dot adm-dot--sm adm-dot--<?= $statusTone === 'success' ? 'ok' : ($statusTone === 'warning' ? 'warn' : e($statusTone)) ?>"></span>
            <?= e($statusLabel) ?>
          </span>
        </div>
      </section>

      <!-- ============================================================
           2. ESSENTIAL OVERVIEW — four non-duplicated metrics
           ============================================================ -->
      <section class="adm-grid adm-grid--4 adm-stagger" aria-label="Key clinic statistics">

        <a class="adm-stat adm-stat--link" href="<?= APP_URL ?>/pages/admin/reports.php">
          <div class="adm-stat__top">
            <p class="adm-stat__label">Patient visits today</p>
            <span class="adm-stat__ico"><?= bpc_icon('stethoscope') ?></span>
          </div>
          <div class="adm-stat__value counter-animate" data-count="<?= $todaysVisits ?>" data-metric="visits_today"><?= number_format($todaysVisits) ?></div>
          <div class="adm-stat__note">
            <?php if ($visitsDelta === 0): ?>
              <span class="adm-stat__delta adm-stat__delta--flat"><?= bpc_icon('minus-circle') ?> No change</span>
              <span>from yesterday</span>
            <?php else: ?>
              <span class="adm-stat__delta<?= $visitsDelta < 0 ? ' adm-stat__delta--down' : '' ?>">
                <?= bpc_icon($visitsDelta < 0 ? 'arrow-down' : 'arrow-up') ?>
                <?= abs($visitsDelta) ?>
              </span>
              <span>from yesterday</span>
            <?php endif; ?>
          </div>
        </a>

        <a class="adm-stat adm-stat--link" href="<?= APP_URL ?>/pages/admin/users.php?role=student">
          <div class="adm-stat__top">
            <p class="adm-stat__label">Registered students</p>
            <span class="adm-stat__ico adm-stat__ico--info"><?= bpc_icon('graduation') ?></span>
          </div>
          <div class="adm-stat__value counter-animate" data-count="<?= $activeStudents ?>" data-metric="active_students"><?= number_format($activeStudents) ?></div>
          <div class="adm-stat__note">
            <?= number_format($activeStudents) ?> active
            <span aria-hidden="true">&middot;</span>
            <span><?= number_format($studentCount) ?> registered</span>
          </div>
        </a>

        <a class="adm-stat adm-stat--link" href="<?= APP_URL ?>/pages/admin/users.php?tab=pending">
          <div class="adm-stat__top">
            <p class="adm-stat__label">Pending approvals</p>
            <span class="adm-stat__ico adm-stat__ico--<?= $pendingTotal > 0 ? 'warning' : 'success' ?>">
              <?= bpc_icon('user-check') ?>
            </span>
          </div>
          <div class="adm-stat__value counter-animate" data-count="<?= $pendingTotal ?>" data-metric="pending_approvals"><?= number_format($pendingTotal) ?></div>
          <div class="adm-stat__note">
            <?= $pendingTotal > 0 ? 'Awaiting review' : 'Nothing waiting' ?>
            <span aria-hidden="true">&middot;</span>
            <span><?= $pendingStaff ?> staff, <?= $pendingStudents ?> students</span>
          </div>
        </a>

        <a class="adm-stat adm-stat--link" href="<?= APP_URL ?>/pages/admin/inventory.php">
          <div class="adm-stat__top">
            <p class="adm-stat__label">Inventory attention</p>
            <span class="adm-stat__ico adm-stat__ico--<?= $inventoryAttention > 0 ? ($outOfStockCount > 0 || $expiredCount > 0 ? 'danger' : 'warning') : 'success' ?>">
              <?= bpc_icon('pill') ?>
            </span>
          </div>
          <div class="adm-stat__value counter-animate" data-count="<?= $inventoryAttention ?>" data-metric="inventory_attention"><?= number_format($inventoryAttention) ?></div>
          <div class="adm-stat__note">
            <?= $inventoryAttention > 0 ? 'Need action' : 'Stock healthy' ?>
            <span aria-hidden="true">&middot;</span>
            <span><?= $lowStockCount ?> low &middot; <?= $outOfStockCount ?> out &middot; <?= $expiredCount ?> expired</span>
          </div>
        </a>
      </section>

      <!-- ============================================================
           3. OPERATIONAL OVERVIEW
           Clinic activity (chart + live queue) beside the single
           source of truth for actionable alerts.
           ============================================================ -->
      <section class="adm-split" aria-label="Clinic activity and priority alerts">

        <!-- Clinic activity -->
        <div class="adm-card">
          <div class="adm-card__head">
            <div>
              <h3 class="adm-card__title"><?= bpc_icon('chart') ?> Clinic Activity</h3>
              <p class="adm-card__sub">Consultation volume and live queue</p>
            </div>
            <div class="adm-btn-row" role="group" aria-label="Select chart range">
              <button type="button" class="adm-btn adm-btn--sm adm-btn--primary" data-range="week" aria-pressed="true">This Week</button>
              <button type="button" class="adm-btn adm-btn--sm adm-btn--secondary" data-range="today" aria-pressed="false">Today</button>
              <button type="button" class="adm-btn adm-btn--sm adm-btn--secondary" data-range="month" aria-pressed="false">This Month</button>
            </div>
          </div>

          <div class="adm-card__body">
            <div class="adm-chart-block">
              <div class="adm-chart" id="visitsChartWrap">
                <canvas id="admVisitsChart" role="img"
                        aria-label="Patient visits chart. Switch range using the buttons above."></canvas>
              </div>
              <div class="adm-empty" id="visitsChartEmpty" hidden>
                <span class="adm-empty__ico"><?= bpc_icon('chart') ?></span>
                <span class="adm-empty__title">No visits recorded</span>
                <span class="adm-empty__desc">
                  Consultation activity will appear here once patients are seen in this period.
                </span>
              </div>
            </div>

            <!-- Live queue summary (each figure updates via the
                 dashboard auto-refresh; no separate card needed) -->
            <div class="adm-queue-summary" aria-label="Current queue status">
              <div class="adm-qs">
                <span class="adm-qs__value adm-qs__value--warn" data-metric="queue_waiting"><?= $queueWaiting ?></span>
                <span class="adm-qs__label">Waiting</span>
              </div>
              <div class="adm-qs">
                <span class="adm-qs__value adm-qs__value--info" data-metric="queue_serving"><?= $queueServing ?></span>
                <span class="adm-qs__label">Serving</span>
              </div>
              <div class="adm-qs">
                <span class="adm-qs__value adm-qs__value--ok" data-metric="queue_completed"><?= $queueCompleted ?></span>
                <span class="adm-qs__label">Completed</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Needs attention — the single source of truth for
             actionable alerts (SOS, registrations, inventory,
             security). The former separate "Emergency Monitor"
             and "Inventory Health" panels are removed: that
             information is already surfaced here as alerts. -->
        <div class="adm-card">
          <div class="adm-card__head">
            <div>
              <h3 class="adm-card__title"><?= bpc_icon('alert-circle') ?> Needs Attention</h3>
              <p class="adm-card__sub" data-attention-sub>
                <?= $attention['total'] > 0
                      ? $attention['total'] . ' item' . ($attention['total'] === 1 ? '' : 's') . ' waiting for administrative action'
                      : 'Nothing is waiting for review right now' ?>
              </p>
            </div>
            <span class="adm-badge adm-badge--danger" data-attention-badge<?= $attention['total'] > 0 ? '' : ' hidden'?>><?= (int)$attention['total'] ?> open</span>
          </div>

          <?php $shownAlerts = 0; ?>
          <div class="adm-card__body">
            <div class="adm-alerts adm-alerts--stack">
              <?php foreach ([['critical', 'danger'], ['warning', 'warning'], ['info', 'info']] as [$tier, $tone]):
                  foreach ($attention[$tier] as $alert):
                    $shownAlerts++;
                    $href = str_starts_with((string)$alert['link'], 'http')
                        ? (string)$alert['link']
                        : APP_URL . '/pages/admin/' . ltrim((string)$alert['link'], '/');
              ?>
                <a class="adm-alert" href="<?= e($href) ?>">
                  <span class="adm-alert__ico adm-alert__ico--<?= e($tone) ?>">
                    <?= bpc_icon($attentionIconMap[$alert['id']] ?? 'info') ?>
                  </span>
                  <span class="adm-alert__body">
                    <span class="adm-alert__title">
                      <?= e($alert['title']) ?>
                      <span class="adm-badge adm-badge--<?= e($tone) ?>"><?= e(strtoupper($tone)) ?></span>
                    </span>
                    <span class="adm-alert__desc"><?= e($alert['desc']) ?></span>
                  </span>
                  <span class="adm-alert__count"><?= (int)$alert['count'] ?></span>
                  <span class="adm-alert__go"><?= bpc_icon('chevron-right') ?></span>
                </a>
              <?php endforeach; endforeach; ?>
            </div>

            <?php if ($shownAlerts === 0): ?>
              <div class="adm-empty">
                <span class="adm-empty__ico"><?= bpc_icon('check-circle') ?></span>
                <span class="adm-empty__title">No pending approvals</span>
                <span class="adm-empty__desc">
                  There are currently no registration requests, emergency alerts,
                  or inventory issues requiring review.
                </span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>

      <!-- ============================================================
           4. RECENT ACTIVITY — one compact feed
           ============================================================ -->
      <section class="adm-card" aria-label="Recent activity">
        <div class="adm-card__head">
          <div>
            <h3 class="adm-card__title"><?= bpc_icon('history') ?> Recent Activity</h3>
            <p class="adm-card__sub">Latest actions recorded in the audit trail</p>
          </div>
          <a class="adm-link-inline" href="<?= APP_URL ?>/pages/admin/activity_logs.php">
            Full log <?= bpc_icon('arrow-right') ?>
          </a>
        </div>

        <div class="adm-card__body">
          <?php if (empty($recentActivity)): ?>
            <div class="adm-empty">
              <span class="adm-empty__ico"><?= bpc_icon('history') ?></span>
              <span class="adm-empty__title">No recorded activity yet</span>
              <span class="adm-empty__desc">
                Administrative actions will appear here as clinic staff use the system.
              </span>
            </div>
          <?php else: ?>
            <div class="adm-feed">
              <?php foreach ($recentActivity as $log):
                $action = strtolower((string)($log['action'] ?? ''));
                $tone   = '';
                $icon   = 'activity';
                if (str_contains($action, 'emerg') || str_contains($action, 'sos') || str_contains($action, 'delete')) {
                    $icon = 'shield-alert';
                    $tone = 'danger';
                } elseif (str_contains($action, 'approv') || str_contains($action, 'activ')) {
                    $icon = 'user-check';
                    $tone = 'success';
                } elseif (str_contains($action, 'login') || str_contains($action, 'auth')) {
                    $icon = 'key';
                    $tone = 'info';
                } elseif (str_contains($action, 'setting') || str_contains($action, 'config')) {
                    $icon = 'settings';
                    $tone = 'primary';
                } elseif (str_contains($action, 'inventory') || str_contains($action, 'medicine')) {
                    $icon = 'pill';
                    $tone = 'warning';
                }
                $who = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
              ?>
                <div class="adm-feed__item">
                  <span class="adm-feed__node<?= $tone ? ' adm-feed__node--' . e($tone) : '' ?>">
                    <?= bpc_icon($icon) ?>
                  </span>
                  <span class="adm-feed__body">
                    <span class="adm-feed__title">
                      <strong><?= e($log['action'] ?: 'System action') ?></strong>
                      <?php if ($who !== ''): ?>
                        by <?= e($who) ?>
                      <?php else: ?>
                        by the system
                      <?php endif; ?>
                    </span>
                    <?php if (!empty($log['details'])): ?>
                      <span class="adm-feed__meta"><?= e($log['details']) ?></span>
                    <?php endif; ?>
                  </span>
                  <span class="adm-feed__time"><?= e(format_datetime($log['created_at'])) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

    </main>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>

<script>
(function () {
  'use strict';

  var RANGES = {
    today: { labels: <?= json_encode($todayLabels) ?>, values: <?= json_encode(array_map('intval', $todayValues)) ?>, line: false },
    week:  { labels: <?= json_encode($weekLabels) ?>,  values: <?= json_encode(array_map('intval', $weekValues)) ?>,  line: true },
    month: { labels: <?= json_encode($monthLabels) ?>, values: <?= json_encode(array_map('intval', $monthValues)) ?>, line: true }
  };

  var GREEN = '#0B6B3A';
  var GREEN_SOFT = 'rgba(11,107,58,0.08)';
  var GREEN_BAR = 'rgba(11,107,58,0.85)';
  var MUTED = '#8A94A3';

  var canvas = document.getElementById('admVisitsChart');
  var empty  = document.getElementById('visitsChartEmpty');
  var wrap   = document.getElementById('visitsChartWrap');
  var chart  = null;

  function syncEmptyState(range) {
    var total = (RANGES[range] && RANGES[range].values) ? RANGES[range].values.reduce(function (a, b) { return a + b; }, 0) : 0;
    var hasData = total > 0;
    if (empty) empty.hidden = hasData;
    if (wrap) wrap.hidden = !hasData;
    return hasData;
  }

  function buildChart(range) {
    if (typeof Chart === 'undefined' || !canvas) return;
    var key = RANGES[range] ? range : 'week';
    var cfg = RANGES[key];

    if (chart) { chart.destroy(); chart = null; }

    var hasData = syncEmptyState(key);
    if (!hasData) return;

    chart = new Chart(canvas.getContext('2d'), {
      type: cfg.line ? 'line' : 'bar',
      data: {
        labels: cfg.labels,
        datasets: [{
          label: 'Patient visits',
          data: cfg.values,
          borderColor: GREEN,
          backgroundColor: cfg.line ? GREEN_SOFT : GREEN_BAR,
          borderWidth: 2,
          borderRadius: cfg.line ? 0 : 4,
          fill: cfg.line,
          tension: 0.35,
          pointRadius: 0,
          pointHoverRadius: 4,
          pointHoverBackgroundColor: GREEN,
          pointHoverBorderColor: '#FFFFFF',
          pointHoverBorderWidth: 2,
          barPercentage: 0.6,
          categoryPercentage: 0.8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 380, easing: 'easeOutCubic' },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#1F2937',
            padding: 10,
            cornerRadius: 8,
            displayColors: false,
            titleFont: { family: 'Inter, system-ui, sans-serif', weight: '600', size: 12 },
            bodyFont: { family: 'Inter, system-ui, sans-serif', size: 12 },
            callbacks: {
              label: function (ctx) { return ctx.parsed.y + (ctx.parsed.y === 1 ? ' visit' : ' visits'); }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            border: { display: false },
            ticks: { color: MUTED, font: { family: 'Inter, system-ui, sans-serif', size: 11 }, maxRotation: 0, autoSkipPadding: 12 }
          },
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(31,41,55,0.06)' },
            border: { display: false },
            ticks: { precision: 0, color: MUTED, font: { family: 'Inter, system-ui, sans-serif', size: 11 }, maxTicksLimit: 5 }
          }
        }
      }
    });
  }

  function selectRange(range) {
    var buttons = document.querySelectorAll('[data-range]');
    Array.prototype.forEach.call(buttons, function (btn) {
      var on = btn.getAttribute('data-range') === range;
      btn.classList.toggle('adm-btn--primary', on);
      btn.classList.toggle('adm-btn--secondary', !on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    buildChart(range);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-range]') : null;
    if (btn) selectRange(btn.getAttribute('data-range'));
  });

  // Chart.js is loaded before this script, so init immediately; the empty
  // state is rendered server-side as a fallback if the library is missing.
  selectRange('week');
})();
</script>
<script>
/* ==================================================================
   Controlled dashboard auto-refresh
   ------------------------------------------------------------------
   Keeps the overview cards, the live queue summary and the
   attention badge current without a full page reload. The
   applyMetrics() helper is generic: it updates every element
   carrying a [data-metric] attribute whose key is present in
   the API response, so adding a metric requires no JS change.
   ================================================================== */
(function () {
  'use strict';

  var REFRESH_MS = 60000;
  var inFlight = false;
  var timer = null;

  function fmt(n) {
    return Number(n || 0).toLocaleString('en-US');
  }

  function applyMetrics(metrics) {
    if (!metrics) return;
    document.querySelectorAll('[data-metric]').forEach(function (el) {
      var key = el.getAttribute('data-metric');
      if (Object.prototype.hasOwnProperty.call(metrics, key)) {
        var val = metrics[key];
        el.textContent = fmt(val);
        el.setAttribute('data-count', String(val));
      }
    });
  }

  function applyAttention(total) {
    var sub = document.querySelector('[data-attention-sub]');
    var badge = document.querySelector('[data-attention-badge]');
    if (sub) {
      sub.textContent = total > 0
        ? total + ' item' + (total === 1 ? '' : 's') + ' waiting for administrative action'
        : 'Nothing is waiting for review right now';
    }
    if (badge) {
      if (total > 0) {
        badge.textContent = total + ' open';
        badge.hidden = false;
      } else {
        badge.hidden = true;
      }
    }
  }

  function refresh() {
    if (inFlight) return; // never overlap requests
    if (document.visibilityState && document.visibilityState !== 'visible') return;

    inFlight = true;
    fetch(APP_URL + '/api/admin_dashboard.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (body) {
        if (body && body.success) {
          applyMetrics(body.data && body.data.metrics);
          if (typeof body.data.attention_total === 'number') {
            applyAttention(body.data.attention_total);
          }
        }
      })
      .catch(function () {
        /* Transient failure — the next tick retries. No toast spam. */
      })
      .finally(function () {
        inFlight = false;
      });
  }

  function start() {
    if (timer) clearInterval(timer);
    timer = setInterval(refresh, REFRESH_MS);
  }

  // Pause polling while the tab is hidden to save requests.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      refresh();
      start();
    } else if (timer) {
      clearInterval(timer);
      timer = null;
    }
  });

  start();
})();
</script>
</body>
</html>
