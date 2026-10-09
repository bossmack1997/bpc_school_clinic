<?php
/**
 * pages/admin/dashboard.php
 * =====================================================================
 * Admin Dashboard - clinic oversight with a calm, scannable hierarchy.
 *
 * Answers, in order: what is happening today, what needs attention,
 * how the clinic is trending, and what happened most recently.
 * All figures come from the existing database schema and helpers.
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

$queueToday   = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$queueWaiting = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'waiting'")->fetchColumn();

// ---------------------------------------------------------------------
// 3. Patient visits - three ready ranges for the chart toggle
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
      AND consultation_date <  CURDATE() + INTERVAL 1 DAY
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
// 4. Active emergencies and recent administrative activity
// ---------------------------------------------------------------------
$emergencyList = [];
if ($activeEmergencies > 0) {
    $emergencyList = $db->query("
        SELECT e.id, e.emergency_type, e.status, e.priority, e.created_at,
               CONCAT(us.first_name, ' ', us.last_name) AS student_name,
               s.student_number
        FROM sos_emergencies e
        LEFT JOIN students s ON s.id = e.student_id
        LEFT JOIN users us ON us.id = s.user_id
        WHERE e.status IN ('new','active','acknowledged','responding') AND e.resolved_at IS NULL
        ORDER BY FIELD(e.priority,'emergency','urgent','normal'), e.created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$recentActivity = $db->query("
    SELECT al.id, al.action, al.module, al.details, al.created_at,
           u.first_name, u.last_name, u.role
    FROM activity_logs al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.created_at DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------
// 5. Priority feed and overall status
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
           SECTION 1 - Welcome / context
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
            <?= bpc_icon('calendar') ?> <?= date('l, F j, Y') ?>
          </span>
          <span class="adm-badge adm-badge--<?= e($statusTone === 'warning' ? 'warning' : $statusTone) ?>">
            <span class="adm-dot adm-dot--sm adm-dot--<?= $statusTone === 'success' ? 'ok' : ($statusTone === 'warning' ? 'warn' : e($statusTone)) ?>"></span>
            <?= e($statusLabel) ?>
          </span>
        </div>
      </section>

      <!-- ============================================================
           SECTION 2 - Key statistics (four cards, no more)
           ============================================================ -->
      <section class="adm-grid adm-grid--4 adm-stagger" aria-label="Key clinic statistics">

        <a class="adm-stat adm-stat--link" href="<?= APP_URL ?>/pages/admin/reports.php">
          <div class="adm-stat__top">
            <p class="adm-stat__label">Today's visits</p>
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
            <p class="adm-stat__label">Students</p>
            <span class="adm-stat__ico adm-stat__ico--info"><?= bpc_icon('graduation') ?></span>
          </div>
          <div class="adm-stat__value counter-animate" data-count="<?= $activeStudents ?>" data-metric="active_students"><?= number_format($activeStudents) ?></div>
          <div class="adm-stat__note">
            <?= number_format($studentCount) ?> registered
            <span aria-hidden="true">&middot;</span>
            <span><?= number_format($activeUsers) ?> active accounts system-wide</span>
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

        <a class="adm-stat adm-stat--link" href="<?= APP_URL ?>/pages/admin/inventory.php?status=low_stock">
          <div class="adm-stat__top">
            <p class="adm-stat__label">Low stock medicines</p>
            <span class="adm-stat__ico adm-stat__ico--<?= $outOfStockCount > 0 ? 'danger' : ($lowStockCount > 0 ? 'warning' : 'success') ?>">
              <?= bpc_icon('pill') ?>
            </span>
          </div>
          <div class="adm-stat__value counter-animate" data-count="<?= $lowStockCount ?>" data-metric="low_stock"><?= number_format($lowStockCount) ?></div>
          <div class="adm-stat__note">
            <?= $lowStockCount > 0 ? 'At or below reorder level' : 'Stock levels are healthy' ?>
            <span aria-hidden="true">&middot;</span>
            <span><?= $outOfStockCount ?> out of stock</span>
          </div>
        </a>
      </section>

      <!-- ============================================================
           SECTION 3 - Priority items + emergency monitor
           ============================================================ -->
      <section class="adm-split" aria-label="Priority items and emergencies">

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
          <div class="adm-alerts">
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

        <div class="adm-card">
          <div class="adm-card__head">
            <div>
              <h3 class="adm-card__title"><?= bpc_icon('shield-alert') ?> Emergency Monitor</h3>
              <p class="adm-card__sub">Active SOS reports across the campus</p>
            </div>
          </div>

          <div class="adm-monitor<?= $activeEmergencies > 0 ? ' adm-monitor--danger' : '' ?>">
            <span class="adm-monitor__ico">
              <?= bpc_icon($activeEmergencies > 0 ? 'alert-triangle' : 'shield-check') ?>
            </span>
            <span class="adm-monitor__text">
              <span class="adm-monitor__title">
                <?= $activeEmergencies > 0 ? 'Response required' : 'No active emergencies' ?>
              </span>
              <span class="adm-monitor__sub">
                <?= $activeEmergencies > 0
                      ? $activeEmergencies . ' unresolved ' . ($activeEmergencies === 1 ? 'report' : 'reports')
                      : 'All SOS reports are resolved' ?>
              </span>
            </span>
            <span class="adm-monitor__count"><?= $activeEmergencies ?></span>
          </div>

          <?php if (!empty($emergencyList)): ?>
            <ul class="adm-links">
              <?php foreach ($emergencyList as $emg): ?>
                <li>
                  <a class="adm-link" href="<?= APP_URL ?>/pages/admin/emergencies.php">
                    <span class="adm-link__ico adm-link__ico--danger">
                      <?= bpc_icon('alert-triangle') ?>
                    </span>
                    <span class="adm-link__body">
                      <span class="adm-link__title">
                        <?= e($emg['emergency_type'] ?: 'Emergency') ?>
                        <?php if ($emg['priority'] === 'emergency'): ?>
                          <span class="adm-badge adm-badge--danger">Critical</span>
                        <?php endif; ?>
                      </span>
                      <span class="adm-link__desc">
                        <?= e($emg['student_name'] ?: 'Unknown student') ?>
                        <span aria-hidden="true">&middot;</span>
                        <?= e(ucfirst((string)$emg['status'])) ?>
                        <span aria-hidden="true">&middot;</span>
                        <?= e(format_datetime($emg['created_at'])) ?>
                      </span>
                    </span>
                    <span class="adm-link__go"><?= bpc_icon('chevron-right') ?></span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="adm-empty">
              <span class="adm-empty__ico"><?= bpc_icon('shield-check') ?></span>
              <span class="adm-empty__title">Clinic is calm</span>
              <span class="adm-empty__desc">
                No student or staff emergency has been reported in the current cycle.
              </span>
            </div>
          <?php endif; ?>

          <div class="adm-card__foot">
            <a class="adm-link-inline" href="<?= APP_URL ?>/pages/admin/emergencies.php">
              Emergency history <?= bpc_icon('arrow-right') ?>
            </a>
          </div>
        </div>
      </section>

      <!-- ============================================================
           SECTION 4 - Patient visits trend + inventory health
           ============================================================ -->
      <section class="adm-split adm-split--wide" aria-label="Patient visits and inventory">

        <div class="adm-card">
          <div class="adm-card__head">
            <div>
              <h3 class="adm-card__title"><?= bpc_icon('chart') ?> Patient Visits</h3>
              <p class="adm-card__sub">Consultation volume recorded by the clinic</p>
            </div>
            <div class="adm-btn-row" role="group" aria-label="Select chart range">
              <button type="button" class="adm-btn adm-btn--sm adm-btn--primary" data-range="week" aria-pressed="true">This Week</button>
              <button type="button" class="adm-btn adm-btn--sm adm-btn--secondary" data-range="today" aria-pressed="false">Today</button>
              <button type="button" class="adm-btn adm-btn--sm adm-btn--secondary" data-range="month" aria-pressed="false">This Month</button>
            </div>
          </div>

          <div class="adm-metrics" aria-hidden="true">
            <div class="adm-metric">
              <div class="adm-metric__value"><?= number_format($todaysVisits) ?></div>
              <div class="adm-metric__label">Today</div>
            </div>
            <div class="adm-metric">
              <div class="adm-metric__value"><?= number_format($weekVisits) ?></div>
              <div class="adm-metric__label">This week</div>
            </div>
            <div class="adm-metric">
              <div class="adm-metric__value"><?= number_format($monthVisits) ?></div>
              <div class="adm-metric__label">This month</div>
            </div>
            <div class="adm-metric">
              <div class="adm-metric__value"><?= number_format($queueToday) ?></div>
              <div class="adm-metric__label">Queue tickets today</div>
            </div>
          </div>

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
        </div>

        <div class="adm-card">
          <div class="adm-card__head">
            <div>
              <h3 class="adm-card__title"><?= bpc_icon('pill') ?> Inventory Health</h3>
              <p class="adm-card__sub"><?= number_format($totalMedicines) ?> active medicine items</p>
            </div>
          </div>

          <div class="adm-meter">
            <div class="adm-meter__top">
              <span class="adm-meter__name">Stock availability</span>
              <span class="adm-meter__value"><?= $stockHealth ?>%</span>
            </div>
            <div class="adm-meter__track">
              <span class="adm-meter__fill<?= $stockHealth < 60 ? ' adm-meter__fill--danger' : ($stockHealth < 90 ? ' adm-meter__fill--warn' : '') ?>"
                    style="width:<?= (int)$stockHealth ?>%"></span>
            </div>
          </div>

          <div class="adm-meters">
            <?php
            $stockRows = [
                ['label' => 'Low stock', 'count' => $lowStockCount, 'tone' => $lowStockCount > 0 ? 'warning' : 'success', 'icon' => 'trending-down', 'link' => 'inventory.php?status=low_stock'],
                ['label' => 'Out of stock', 'count' => $outOfStockCount, 'tone' => $outOfStockCount > 0 ? 'danger' : 'success', 'icon' => 'x-circle', 'link' => 'inventory.php?status=out_of_stock'],
                ['label' => 'Expired', 'count' => $expiredCount, 'tone' => $expiredCount > 0 ? 'danger' : 'success', 'icon' => 'calendar-x', 'link' => 'inventory.php?status=expired'],
                ['label' => 'Expiring in 30 days', 'count' => $expiringSoonCount, 'tone' => $expiringSoonCount > 0 ? 'warning' : 'success', 'icon' => 'calendar', 'link' => 'inventory.php?status=near_expiration'],
            ];
            ?>
            <div class="adm-alerts">
              <?php foreach ($stockRows as $row): ?>
                <a class="adm-alert" href="<?= APP_URL ?>/pages/admin/<?= e(ltrim((string)$row['link'], '/')) ?>">
                  <span class="adm-alert__ico adm-alert__ico--<?= $row['tone'] === 'success' ? 'success' : e($row['tone']) ?>">
                    <?= bpc_icon($row['icon']) ?>
                  </span>
                  <span class="adm-alert__body">
                    <span class="adm-alert__title">
                      <?= e($row['label']) ?>
                      <?php if ($row['count'] === 0): ?>
                        <span class="adm-badge adm-badge--success">Clear</span>
                      <?php endif; ?>
                    </span>
                  </span>
                  <span class="adm-alert__count"><?= (int)$row['count'] ?></span>
                  <span class="adm-alert__go"><?= bpc_icon('chevron-right') ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="adm-card__foot">
            <a class="adm-link-inline" href="<?= APP_URL ?>/pages/admin/inventory.php">
              Manage inventory <?= bpc_icon('arrow-right') ?>
            </a>
          </div>
        </div>
      </section>

      <!-- ============================================================
           SECTION 5 - Recent activity + quick actions
           ============================================================ -->
      <section class="adm-split" aria-label="Recent activity and quick actions">

        <div class="adm-card">
          <div class="adm-card__head">
            <div>
              <h3 class="adm-card__title"><?= bpc_icon('history') ?> Recent Activity</h3>
              <p class="adm-card__sub">Latest actions recorded in the audit trail</p>
            </div>
            <a class="adm-link-inline" href="<?= APP_URL ?>/pages/admin/activity_logs.php">
              Full log <?= bpc_icon('arrow-right') ?>
            </a>
          </div>

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

        <div class="adm-card">
          <div class="adm-card__head">
            <div>
              <h3 class="adm-card__title"><?= bpc_icon('sliders') ?> Quick Actions</h3>
              <p class="adm-card__sub">Common administrative tasks</p>
            </div>
          </div>

          <?php
          $quickActions = [
              ['user-check',  'Review approvals',  $pendingTotal > 0 ? $pendingTotal . ' waiting for review' : 'No pending requests',  'users.php?tab=pending',      $pendingTotal > 0],
              ['pill',       'Manage inventory',  $stockAlerts > 0 ? $stockAlerts . ' ' . ($stockAlerts === 1 ? 'item' : 'items') . ' flagged' : 'Stock levels healthy',       'inventory.php',               $stockAlerts > 0],
              ['shield-alert', 'Emergency centre', $activeEmergencies > 0 ? $activeEmergencies . ' active now' : 'No active reports',          'emergencies.php',            $activeEmergencies > 0],
              ['chart',      'View reports',      'Analytics and data exports',                                   'reports.php',                 false],
              ['history',    'Activity logs',     number_format($totalUsers) . ' accounts tracked',                'activity_logs.php',          false],
              ['shield-lock', 'Admin accounts',   $adminCount . ' active ' . ($adminCount === 1 ? 'administrator' : 'administrators'), 'admin_accounts.php',         false],
          ];
          ?>
          <div class="adm-links">
            <?php foreach ($quickActions as $act): ?>
              <a class="adm-link" href="<?= APP_URL ?>/pages/admin/<?= e(ltrim((string)$act[3], '/')) ?>">
                <span class="adm-link__ico"><?= bpc_icon($act[0]) ?></span>
                <span class="adm-link__body">
                  <span class="adm-link__title"><?= e($act[1]) ?></span>
                  <span class="adm-link__desc"><?= e($act[2]) ?></span>
                </span>
                <?php if ($act[4]): ?>
                  <span class="adm-badge adm-badge--warning">Action</span>
                <?php endif; ?>
                <span class="adm-link__go"><?= bpc_icon('chevron-right') ?></span>
              </a>
            <?php endforeach; ?>
          </div>

          <div class="adm-card__foot">
            <span class="text-muted" style="font-size:.76rem;">
              <?= e($adminFullName !== '' ? $adminFullName : $adminFirstName) ?>
              <span aria-hidden="true">&middot;</span>
              <?= $nurseCount ?> <?= $nurseCount === 1 ? 'nurse' : 'nurses' ?> on duty
              <span aria-hidden="true">&middot;</span>
              <?= $queueWaiting ?> waiting
            </span>
            <a class="adm-link-inline" href="<?= APP_URL ?>/pages/admin/profile.php">My profile</a>
          </div>
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
   Keeps the overview cards and the attention badge current without a
   full page reload. Requests never overlap, run only while the tab
   is visible, and fail silently so a transient network blip never
   nags the administrator. The server remains the single source of
   truth — every figure is recomputed from the database.
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
