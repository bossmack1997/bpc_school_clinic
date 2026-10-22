<?php
/** Nurse operations using the shared full-width dashboard design. */
require_once __DIR__.'/../../config/config.php';
require_role('nurse');
require_once __DIR__.'/../../includes/nurse_dashboard_data.php';
require_once __DIR__.'/../../includes/clinic_availability.php';
$db=getDB();$u=current_user();$dashboard=null;$dashboardError='';
$nurseName=trim(($u['first_name']??'').' '.($u['last_name']??''));
$clinic=clinic_availability($db);
try {$dashboard=NurseDashboardData::snapshot($db);}
catch(Throwable $e){error_log('[Nurse dashboard] '.$e->getMessage());$dashboardError='Clinic data is temporarily unavailable. Automatic refresh will retry.';}
$pageTitle='Nurse Dashboard';$pageDescription='Clinic activity, queue and patient care';
?>
<!DOCTYPE html><html lang="en"><head>
<?php include __DIR__.'/../../includes/head.php'; ?>
<style>
.nurse-dashboard #nurseVisitsChartEmpty{min-height:180px;padding:24px 16px;gap:8px}.nurse-dashboard #nurseVisitsChartEmpty .adm-empty__desc{max-width:52ch;line-height:1.5}.nurse-dashboard .nurse-workflow-links{margin-top:16px}.nurse-dashboard .adm-qs__value{overflow-wrap:anywhere}.nurse-dashboard [hidden]{display:none!important}
</style></head><body><div class="app-wrapper">
<?php include __DIR__.'/../../components/sidebar.php'; ?>
<div class="main-content"><?php include __DIR__.'/../../components/navbar.php'; ?>
<main class="content-area adm-page nurse-dashboard adm-page-enter" id="nurseDashboard" data-endpoint="<?= e(APP_URL.'/api/nurse_dashboard.php') ?>">
  <?= dbg_welcome_banner(['name' => $nurseName, 'clinic' => $clinic]) ?>
  <p id="nurseDashboardStatus" class="text-danger small" role="status" <?= $dashboardError ? '' : 'hidden' ?>><?= e($dashboardError) ?></p>
  <div class="adm-dashboard-panels">
    <section class="adm-card adm-attention" aria-labelledby="nurseAttentionTitle">
      <div class="adm-card__head"><div><h3 class="adm-card__title" id="nurseAttentionTitle"><?= bpc_icon('alert-circle') ?> Needs Attention</h3><p class="adm-card__sub">Emergency response and medicine replenishment</p></div></div>
      <div class="adm-card__body"><div class="adm-alerts">
        <a class="adm-alert" href="<?= APP_URL ?>/pages/nurse/emergencies.php"><span class="adm-alert__ico adm-alert__ico--danger"><?= bpc_icon('shield-alert') ?></span><span class="adm-alert__body"><span class="adm-alert__title">Active Emergency SOS Alert <span class="adm-badge adm-badge--danger">DANGER</span></span><span class="adm-alert__desc">Active emergency reports requiring clinic response.</span></span><span class="adm-alert__count" data-metric="emergencies"><?= $dashboard['emergencies'] ?? 'Unavailable' ?></span><span class="adm-alert__go"><?= bpc_icon('chevron-right') ?></span></a>
        <a class="adm-alert" href="<?= APP_URL ?>/pages/nurse/inventory.php"><span class="adm-alert__ico adm-alert__ico--warning"><?= bpc_icon('pill') ?></span><span class="adm-alert__body"><span class="adm-alert__title">Low Stock Inventory Alert <span class="adm-badge adm-badge--warning">WARNING</span></span><span class="adm-alert__desc">Medicines with usable stock at or below their configured reorder level.</span></span><span class="adm-alert__count" data-metric="low_stock"><?= $dashboard['low_stock'] ?? 'Unavailable' ?></span><span class="adm-alert__go"><?= bpc_icon('chevron-right') ?></span></a>
      </div></div>
    </section>
        <!-- Clinic activity -->
        <section class="adm-card adm-activity" aria-labelledby="admActivityTitle">
          <div class="adm-card__head">
            <div>
              <h3 class="adm-card__title" id="admActivityTitle"><?= bpc_icon('chart') ?> Clinic Activity</h3>
              <p class="adm-card__sub">Consultation volume and live queue</p>
            </div>
            <div class="adm-btn-row" role="group" aria-label="Select chart range">
              <button type="button" class="adm-btn adm-btn--sm adm-btn--primary" data-nurse-range="week" aria-pressed="true">This Week</button>
              <button type="button" class="adm-btn adm-btn--sm adm-btn--secondary" data-nurse-range="today" aria-pressed="false">Today</button>
              <button type="button" class="adm-btn adm-btn--sm adm-btn--secondary" data-nurse-range="month" aria-pressed="false">This Month</button>
            </div>
          </div>

          <div class="adm-card__body">
            <div class="adm-chart-block">
              <div class="adm-chart" id="nurseVisitsChartWrap" hidden>
                <canvas id="nurseVisitsChart" role="img"
                        aria-label="Patient visits chart. Switch range using the buttons above."></canvas>
              </div>
              <div class="adm-empty" id="nurseVisitsChartEmpty">
                <span class="adm-empty__ico"><?= bpc_icon('chart') ?></span>
                <span class="adm-empty__title" data-nurse-empty-title><?= $dashboard ? 'No visits recorded' : 'Unable to load clinic activity' ?></span>
                <span class="adm-empty__desc" data-nurse-empty-desc>
                  Consultation activity will appear here once patients are seen in this period.
                </span>
              </div>
            </div>

            <!-- Live queue summary (each figure updates via the
                 dashboard auto-refresh; no separate card needed) -->
            <div class="adm-btn-row nurse-workflow-links"><a class="adm-btn adm-btn--sm adm-btn--secondary" href="<?= APP_URL ?>/pages/nurse/queue.php">Open Queue</a><a class="adm-btn adm-btn--sm adm-btn--secondary" href="<?= APP_URL ?>/pages/nurse/queue.php">Consultation Workspace</a></div>
            <div class="adm-queue-summary" aria-label="Current queue status">
              <div class="adm-qs">
                <span class="adm-qs__value adm-qs__value--warn" data-metric="queue_waiting"><?= $dashboard['queue_waiting'] ?? 'Unavailable' ?></span>
                <span class="adm-qs__label">Waiting</span>
              </div>
              <div class="adm-qs">
                <span class="adm-qs__value adm-qs__value--info" data-metric="queue_serving"><?= $dashboard['queue_serving'] ?? 'Unavailable' ?></span>
                <span class="adm-qs__label">Serving</span>
              </div>
              <div class="adm-qs">
                <span class="adm-qs__value adm-qs__value--ok" data-metric="queue_completed"><?= $dashboard['queue_completed'] ?? 'Unavailable' ?></span>
                <span class="adm-qs__label">Completed</span>
              </div>
            </div>
          </div>
        </section>


  </div>
</main></div></div>
<?php include __DIR__.'/../../includes/patient_workspace_modal.php'; ?>
<?php include __DIR__.'/../../includes/qr_scanner_modal.php'; ?>
<?php include __DIR__.'/../../components/footer.php'; ?>
<script id="nurseDashboardData" type="application/json"><?= json_encode($dashboard,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="<?= asset_url('js/nurse-dashboard.js') . '?v=' . filemtime(__DIR__.'/../../assets/js/nurse-dashboard.js') ?>"></script>
</body></html>
