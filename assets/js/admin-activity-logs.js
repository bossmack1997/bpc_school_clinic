(function () {
'use strict';

let currentPage = 1, totalPages = 1;
let loaded = false;
let request = null;
const params = new URLSearchParams(window.location.search);
const initialPage = Math.max(1, parseInt(params.get('page'), 10) || 1);
if (params.has('search')) $('#searchInput').val(params.get('search'));
function escapeLog(value) { return $('<span>').text(String(value ?? '')).html(); }

function loadLogs(page) {
  const search = $('#searchInput').val();
  $('#logInfo').text('Loading activities...');
  if (request) request.abort();
  $('#prevPage, #nextPage').prop('disabled', true);
  $('#logsTable tbody').empty();
  request = $.get(APP_URL + '/api/activity_logs.php', { action: 'list', page, search, from:$('#logFrom').val()||'', to:$('#logTo').val()||'' }, function (res) {
    if (!res.success) {
      $('#logInfo').text(res.message || 'Unable to load activities. Select Search to retry.');
      return;
    }
    currentPage = res.page;
    totalPages = Math.max(1, Number(res.pages) || 1);
    loaded = true;
    $('#logInfo').text('Page ' + currentPage + ' of ' + totalPages + ' (' + res.total + ' total)');
    $('#prevPage').prop('disabled', currentPage <= 1);
    $('#nextPage').prop('disabled', currentPage >= totalPages);

const rows = res.data.map(l => [
      escapeLog((l.first_name || 'System') + ' ' + (l.last_name || '')),
      l.role ? '<span class="badge bg-info">' + escapeLog(l.role.charAt(0).toUpperCase() + l.role.slice(1)) + '</span>' : 'System',
      escapeLog(l.activity),
      escapeLog(l.created_at)
    ]);
    const tbody = $('#logsTable tbody');
    tbody.empty();
    if (!rows || rows.length === 0) {
      tbody.append('<tr><td colspan="4" class="text-center text-muted py-4"><i class="bi bi-clock-history fs-3 d-block mb-1"></i>No activities match your search.</td></tr>');
    } else {
      rows.forEach(function(r) {
        tbody.append('<tr>' + r.map(function(c, i) { return '<td data-label="' + ['User','Role','Activity','Date &amp; Time'][i] + '">' + c + '</td>'; }).join('') + '</tr>');
      });
    }
  }, 'json').fail(function (xhr, status) {
    if (status !== 'abort') $('#logInfo').text('Unable to load activities. Select Search to retry.');
  });
}
const panel = document.getElementById('settingsPanel-activity-logs');
const section = panel ? panel.closest('details') : null;
if (section) {
  section.addEventListener('toggle', function () { if (section.open && !loaded) loadLogs(initialPage); });
  if (section.open) loadLogs(initialPage);
} else {
  // Standalone Activity & Audit Logs page.
  loadLogs(initialPage);
}

$('#prevPage').on('click', function () { if (currentPage > 1) loadLogs(currentPage - 1); });
$('#nextPage').on('click', function () { if (currentPage < totalPages) loadLogs(currentPage + 1); });

function searchLogs() { loadLogs(1); }
$('#searchInput').on('keyup', function (e) { if (e.key === 'Enter') searchLogs(); });

$('#searchLogsButton').on('click', searchLogs);
$('#logFrom, #logTo').on('change',searchLogs);

})();
