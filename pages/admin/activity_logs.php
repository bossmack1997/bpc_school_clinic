<?php
/**
 * pages/admin/activity_logs.php
 * =====================================================================
 * Activity & Audit Logs — Central historical system events.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_admin_permission('view_audit_logs');
$db = getDB();
$u = current_user();
$loadMisAdminStyles=true;
$pageTitle = 'Activity & Audit Logs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
  <style>
    .logs-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .logs-search { flex: 1 1 280px; min-width: 0; }
    .logs-pager { display: flex; align-items: center; gap: 6px; margin-top: 10px; }
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
            <i class="bi bi-clock-history text-success"></i> Activity &amp; Audit Logs
          </h5>
          <p class="text-muted small mb-0">Central historical record of administrative actions, account events, and security operations.</p>
        </div>
      </div>

      <p class="small text-muted">Technical administrative events only. Times use Asia/Manila. Clinical audit records remain preserved in the database.</p>
      <div class="logs-toolbar"><label class="visually-hidden" for="logModule">Module filter</label><select id="logModule" class="form-select w-auto"><option value="">All modules</option><?php foreach(['auth','security','admin','account_security','admin_profiles','backups','settings'] as $module): ?><option><?=e($module)?></option><?php endforeach; ?></select><label for="logFrom" class="small">From</label><input id="logFrom" type="date" class="form-control w-auto"><label for="logTo" class="small">To</label><input id="logTo" type="date" class="form-control w-auto">
        <div class="input-group logs-search">
          <input type="text" id="searchInput" class="form-control" placeholder="Search logs by user, action, module, or details...">
          <button class="btn btn-outline-primary" type="button" id="searchLogsButton" aria-label="Search logs"><i class="bi bi-search"></i></button>
        </div>
      </div>

      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="logsTable" style="width:100%">
            <thead><tr>
              <th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Details</th><th>IP Address</th><th>Date/Time</th>
            </tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="logs-pager">
          <small class="text-muted" id="logInfo"></small>
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
<script>
(function() {
  const params = new URLSearchParams(window.location.search);
  if (params.has('search')) {
    setTimeout(() => {
      const el = document.getElementById('searchInput');
      if (el) el.value = params.get('search');
    }, 100);
  }
})();
</script>
</body>
</html>
