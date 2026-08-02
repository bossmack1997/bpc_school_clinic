<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$pageTitle = 'Activity Logs';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="section-title mb-0">System Activity Logs</h6>
        <div class="input-group" style="width:280px;">
          <input type="text" id="searchInput" class="form-control" placeholder="Search logs...">
          <button class="btn btn-outline-primary" onclick="searchLogs()"><i class="bi bi-search"></i></button>
        </div>
      </div>
      <div class="card p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="logsTable" style="width:100%">
            <thead><tr><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Details</th><th>IP Address</th><th>Date/Time</th></tr></thead>
            <tbody></tbody>
          </table>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-2">
          <small class="text-muted" id="logInfo"></small>
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-secondary" id="prevPage" disabled><i class="bi bi-chevron-left"></i></button>
            <button class="btn btn-sm btn-outline-secondary" id="nextPage"><i class="bi bi-chevron-right"></i></button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
let currentPage = 1, totalPages = 1;

function loadLogs(page) {
  const search = $('#searchInput').val();
  $.get(APP_URL + '/api/activity_logs.php', { action: 'list', page, search }, function (res) {
    if (!res.success) return;
    currentPage = res.page;
    totalPages = res.pages;
    $('#logInfo').text('Page ' + currentPage + ' of ' + totalPages + ' (' + res.total + ' total)');
    $('#prevPage').prop('disabled', currentPage <= 1);
    $('#nextPage').prop('disabled', currentPage >= totalPages);

const rows = res.data.map(l => [
      (l.first_name || 'System') + ' ' + (l.last_name || ''),
      l.role ? '<span class="badge bg-info">' + l.role + '</span>' : '—',
      l.action,
      l.module || '—',
      l.details || '—',
      l.ip_address || '—',
      l.created_at
    ]);
    // Populate table manually (server-side pagination by the API)
    const tbody = $('#logsTable tbody');
    tbody.empty();
    rows.forEach(function(r) {
      tbody.append('<tr>' + r.map(function(c) { return '<td>' + c + '</td>'; }).join('') + '</tr>');
    });
  }, 'json');
}
loadLogs(1);

$('#prevPage').on('click', function () { if (currentPage > 1) loadLogs(currentPage - 1); });
$('#nextPage').on('click', function () { if (currentPage < totalPages) loadLogs(currentPage + 1); });

function searchLogs() { loadLogs(1); }
$('#searchInput').on('keyup', function (e) { if (e.key === 'Enter') searchLogs(); });
</script>
</body>
</html>
