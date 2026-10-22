<?php
require_once __DIR__ . '/../../config/config.php';
require_admin_permission('view_emergencies');
$u=current_user();
$pageTitle='Emergency Oversight Center';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../../includes/head.php'; ?>
<link rel="stylesheet" href="<?= asset_url('css/emergency-oversight.css') ?>?v=<?= filemtime(__DIR__.'/../../assets/css/emergency-oversight.css') ?>">
</head>
<body>
<div class="app-wrapper">
<?php include __DIR__ . '/../../components/sidebar.php'; ?>
<div class="main-content">
<?php include __DIR__ . '/../../components/navbar.php'; ?>
<main class="content-area eo-page" id="emergencyOversight" data-endpoint="<?= e(APP_URL.'/api/emergencies.php') ?>">
  <header class="eo-heading">
    <div><div class="eo-eyebrow">ADMINISTRATION / EMERGENCY MONITORING</div><h1>Emergency Oversight Center</h1><p>Monitor incoming reports, review nurse response progress, and inspect emergency history.</p></div>
    <div class="eo-refresh"><span id="eoUpdated" class="small text-muted">Connecting to clinic records</span><button type="button" class="btn btn-outline-secondary" id="eoRefresh"><i class="bi bi-arrow-clockwise me-2" aria-hidden="true"></i>Refresh</button></div>
  </header>
  <section class="row g-3 eo-summary" aria-label="Emergency summary, all records">
    <?php foreach([
        ['active','Active Emergencies','Awaiting response or acknowledged','exclamation-triangle','red'],
        ['responding','Responding','Currently being handled','activity','orange'],
        ['resolved','Resolved','All-time resolved reports','check-circle','green'],
        ['total','Total Emergency Reports','All-time, including closed reports','clipboard-data','neutral'],
    ] as [$key,$label,$hint,$icon,$tone]): ?>
    <div class="col-12 col-md-6 col-xl-3"><article class="eo-stat eo-stat--<?= $tone ?>"><div class="eo-stat-top"><h2><?= e($label) ?></h2><span class="eo-stat-icon"><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i></span></div><div class="eo-stat-value" data-stat="<?= $key ?>">&mdash;</div><p><?= e($hint) ?></p></article></div>
    <?php endforeach; ?>
  </section>
  <div id="eoError" class="alert alert-danger d-none mt-3" role="alert"></div>
  <section class="eo-panel" aria-label="Emergency reports">
    <div class="eo-panel-heading"><div><h2>Emergency reports</h2><p>Live clinic records · newest reports first</p></div><span class="eo-live-label"><span aria-hidden="true"></span>Updates every 15 seconds</span></div>
    <div class="eo-tabs" role="tablist" aria-label="Emergency report categories">
      <button class="eo-tab active" type="button" role="tab" aria-selected="true" aria-controls="eoResults" id="eoTabActive" data-tab="active">Active Emergencies <span data-count="active">&mdash;</span></button>
      <button class="eo-tab" type="button" role="tab" aria-selected="false" aria-controls="eoResults" id="eoTabResponding" tabindex="-1" data-tab="responding">Responding <span data-count="responding">&mdash;</span></button>
      <button class="eo-tab" type="button" role="tab" aria-selected="false" aria-controls="eoResults" id="eoTabHistory" tabindex="-1" data-tab="history">Emergency History <span data-count="history">&mdash;</span></button>
    </div>
    <form id="eoFilters" class="eo-filters" role="search" aria-label="Filter emergency reports">
      <div class="eo-search"><label for="eoSearch">Search reports</label><div class="eo-search-input"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" type="search" id="eoSearch" maxlength="120" placeholder="Patient, reporter, ID, or emergency type" autocomplete="off"></div></div>
      <div><label for="eoStatus">Status</label><select class="form-select" id="eoStatus"><option value="">All statuses</option></select></div>
      <div><label for="eoRole">Reporter role</label><select class="form-select" id="eoRole"><option value="">All roles</option><option value="student">Student</option><option value="teacher">Teacher</option><option value="nurse">Nurse</option><option value="admin">Administration</option></select></div>
      <div><label for="eoFrom">Reported from</label><input class="form-control" type="date" id="eoFrom"></div>
      <div><label for="eoTo">Reported through</label><input class="form-control" type="date" id="eoTo"></div>
      <div class="eo-filter-buttons"><button class="btn btn-success" type="submit">Apply filters</button><button class="btn btn-outline-secondary" type="button" id="eoClear">Clear</button></div>
    </form>
    <div class="eo-results-heading"><p id="eoResultsLabel" class="mb-0" aria-live="polite">Loading active emergencies…</p><div><label for="eoPageSize">Rows per page</label><select id="eoPageSize" class="form-select form-select-sm"><option>10</option><option>25</option><option>50</option></select></div></div>
    <div id="eoResults" role="tabpanel" aria-labelledby="eoTabActive" aria-busy="true" tabindex="0">
      <div class="eo-table-wrap"><table class="table eo-table"><caption class="visually-hidden">Emergency reports, sorted by most recent first</caption><thead><tr><th scope="col">Patient / Reporter</th><th scope="col">Emergency Type</th><th scope="col">Location</th><th scope="col">Status</th><th scope="col">Reported At</th><th scope="col">Actions</th></tr></thead><tbody id="eoRows"></tbody></table></div>
      <div id="eoMobileCards" class="eo-mobile-cards"></div>
      <div id="eoEmpty" class="eo-empty" hidden><i class="bi bi-shield-check" aria-hidden="true"></i><h3 id="eoEmptyTitle">No active emergencies</h3><p id="eoEmptyText">New emergency reports will appear here automatically.</p></div>
    </div>
    <footer class="eo-pagination"><p id="eoRange" class="mb-0">Loading reports…</p><nav aria-label="Emergency report pages"><button type="button" class="btn btn-outline-secondary btn-sm" id="eoPrevious" disabled>Previous</button><span id="eoPageLabel">Page 1</span><button type="button" class="btn btn-outline-secondary btn-sm" id="eoNext" disabled>Next</button></nav></footer>
  </section>
</main>
</div>
</div>
<div class="modal fade eo-detail-modal" id="eoDetailModal" tabindex="-1" aria-labelledby="eoDetailTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><div><div class="eo-eyebrow" id="eoReference">EMERGENCY REPORT</div><h2 class="modal-title fs-5" id="eoDetailTitle">Emergency details</h2></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close emergency details"></button></div><div class="modal-body" id="eoDetailBody"></div><div class="modal-footer"><div id="eoDetailActions" class="d-flex flex-wrap gap-2 me-auto"></div><button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Close</button></div></div></div></div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script src="<?= asset_url('js/emergency-oversight.js') ?>?v=<?= filemtime(__DIR__.'/../../assets/js/emergency-oversight.js') ?>"></script>
</body>
</html>
