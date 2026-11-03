<?php
/**
 * pages/admin/activity_logs.php
 * =====================================================================
 * Activity History — Central historical system events.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_admin_permission('view_audit_logs');
$db = getDB();
$u = current_user();
$loadMisAdminStyles=true;
$pageTitle = 'Activity History';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
  <style>
    .logs-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .logs-search { flex: 1 1 280px; min-width: 0; }
    .logs-pager { flex-wrap: wrap; justify-content: space-between; display: flex; align-items: center; gap: 6px; margin-top: 10px; }
    #logsTable td { overflow-wrap: anywhere; }
    #logsTable th { white-space: nowrap; }
    @media(max-width:575.98px) {
      #logsTable thead { display:none; }
      #logsTable, #logsTable tbody, #logsTable tr, #logsTable td { display:block; width:100%; }
      #logsTable tr { border-bottom:1px solid var(--bs-border-color,#dee2e6); padding: .5rem 0; }
      #logsTable td { border:0; padding:.35rem .5rem; }
      #logsTable td[data-label]::before { content:attr(data-label); display:inline-block; width:6rem; font-weight:600; }
      .logs-search { flex-basis:100%; }
    }
    .empty-state { text-align: center; padding: 32px 16px; color: var(--text-secondary); }
  </style>
</head>
<body class="mis-admin-page">
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <div class="app-page-head d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h5 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-clock-history text-success"></i> Activity History
          </h5>
          <p class="text-muted small mb-0">See recorded activities across user accounts and clinic services.</p>
        </div>
      </div>

      <p class="small text-muted">Times are shown in Philippine time (Asia/Manila).</p>
      <div class="logs-toolbar"><label for="logFrom" class="small">From</label><input id="logFrom" type="date" class="form-control w-auto"><label for="logTo" class="small">To</label><input id="logTo" type="date" class="form-control w-auto">
        <div class="input-group logs-search">
          <input aria-label="Search activity history" type="text" id="searchInput" class="form-control" placeholder="Search by user, role or activity...">
          <button class="btn btn-outline-primary" type="button" id="searchLogsButton" aria-label="Search activity"><i class="bi bi-search"></i></button>
        </div>
      </div>

      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="logsTable" style="width:100%">
            <thead><tr>
              <th>User</th><th>Role</th><th>Activity</th><th>Date &amp; Time</th>
            </tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="logs-pager">
          <small class="text-muted" id="logInfo" role="status" aria-live="polite"></small>
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-secondary" type="button" aria-label="Previous page" id="prevPage" disabled><i class="bi bi-chevron-left"></i></button>
            <button class="btn btn-sm btn-outline-secondary" type="button" aria-label="Next page" id="nextPage"><i class="bi bi-chevron-right"></i></button>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script src="<?= asset_url('js/admin-activity-logs.js') . '?v=' . filemtime(__DIR__ . '/../../assets/js/admin-activity-logs.js') ?>"></script>

</body>
</html>
