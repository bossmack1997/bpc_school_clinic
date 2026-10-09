<?php
/**
 * pages/admin/emergencies.php
 * =====================================================================
 * Admin Emergency Oversight — Full Crisis Center for Active Emergencies
 * Shows all active/ongoing SOS emergencies with live tracking, status updates,
 * and full resolution workflow for system administrators.
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$u = current_user();
$pageTitle = 'Emergency Oversight Center';
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
    <div class="content-area page-enter">

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h6 class="section-title mb-0"><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i> Emergency Oversight Center</h6>
        <button class="btn btn-sm btn-outline-secondary" onclick="loadEmergencies()">Refresh</button>
      </div>

      <div class="mb-3">
        <div class="row g-3">
          <div class="col-sm-3">
            <div class="card p-3 text-center border-0 bg-white">
              <div class="fw-bold fs-4 text-danger" id="statActive">—</div>
              <div class="small text-muted">Active Emergencies</div>
            </div>
          </div>
          <div class="col-sm-3">
            <div class="card p-3 text-center border-0 bg-white">
              <div class="fw-bold fs-4 text-warning" id="statResponding">—</div>
              <div class="small text-muted">Responding</div>
            </div>
          </div>
          <div class="col-sm-3">
            <div class="card p-3 text-center border-0 bg-white">
              <div class="fw-bold fs-4 text-success" id="statResolved">—div>
              <div class="small text-muted">Resolved (24h)</div>
            </div>
          </div>
          <div class="col-sm-3">
            <div class="card p-3 text-center border-0 bg-white">
              <div class="fw-bold fs-4 text-secondary" id="statTotal">—</div>
              <div class="small text-muted">Total (24h)</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm border-0 rounded-3 bg-white">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="emgTable">
              <thead class="table-light">
                <tr>
                  <th>Student</th>
                  <th>Type</th>
                  <th>Location</th>
                  <th>Source</th>
                  <th>Status</th>
                  <th>Reported</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr><td colspan="7" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Loading emergencies...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Resolve Modal -->
<div class="modal fade" id="resolveModal" tabindex="-1" aria-hidden="true" aria-labelledby="resolveModalTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="emergency_id" id="resolveEmgId">
        <input type="hidden" name="op" value="resolve">
<div class="modal-header">
            <h6 class="modal-title" id="resolveModalTitle"><?= bpc_icon('check-circle') ?> Resolve Emergency</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p class="app-muted mb-2">Mark emergency <strong id="resolveEmgTitle">&mdash;</strong> as resolved.</p>
            <label class="form-label" for="resolutionNotes">Resolution Notes</label>
            <textarea name="resolution_notes" id="resolutionNotes" class="form-control" rows="3" placeholder="e.g. Student arrived at clinic, condition stable."></textarea>
          </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-success">Confirm Resolution</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');
/* Emergency severity maps onto the shared status palette. Severity is also
   carried by the label text, never by colour alone. */
const emgStatusTone = {
  new: 'danger',
  active: 'danger',
  acknowledged: 'warning',
  responding: 'info',
  responded: 'warning',
  resolved: 'success',
  cancelled: 'neutral',
  false_alarm: 'neutral'
};
const emgStatusLabels = {
  new: 'New', active: 'New', acknowledged: 'Acknowledged', responding: 'Responding',
  responded: 'Responded', resolved: 'Resolved', cancelled: 'Cancelled', false_alarm: 'False Alarm'
};

function loadEmergencies() {
  $.get(APP_URL + '/api/emergencies.php', { action: 'list' }, function (res) {
    if (!res.success) {
      BPCState.tableError('#emgTable tbody', 7, 'Unable to load emergencies. Please try again.', loadEmergencies);
      return;
    }
    const data = res.data || [];
    let active = 0, responding = 0, resolved = 0;

    const rows = data.map(e => {
      const status = e.status || 'new';
      if (['new', 'active', 'acknowledged', 'responding', 'responded'].includes(status)) {
        active++;
        if (status === 'responding' || status === 'responded') responding++;
      }
      if (status === 'resolved') {
        resolved++;
        active--;
      }

      const studentName = e.first_name ? (e.first_name + ' ' + e.last_name) : ('Student #' + (e.student_id || '—'));
      const badge = `<span class="app-badge app-badge--${emgStatusTone[status] || 'neutral'}"><span class="spinner-border spinner-border-sm d-none" id="spinner-${e.id}"></span>${emgStatusLabels[status] || status}</span>`;
      const location = e.location || (e.latitude ? 'GPS: ' + e.latitude + ', ' + e.longitude : '—');
      const source = e.source === 'sos' ? 'Student SOS' : (e.source === 'adviser' ? 'Adviser Report' : e.source || '—');
      const reported = e.created_at ? new Date(e.created_at).toLocaleString() : '—';

      // Actions
      let actions = '';
      if (['new', 'active'].includes(status)) {
        actions = `<button class="btn btn-sm btn-outline-warning" onclick="updateStatus(${e.id}, 'acknowledge')"><i class="bi bi-flag"></i> Acknowledge</button>`;
      } else if (status === 'acknowledged') {
        actions = `<button class="btn btn-sm btn-outline-info" onclick="updateStatus(${e.id}, 'respond')"><i class="bi bi-ambulance"></i> Respond</button>`;
      } else if (['responding', 'responded'].includes(status)) {
        actions = `<button class="btn btn-sm btn-outline-success" onclick="openResolveModal(${e.id}, '${studentName}')"><i class="bi bi-check-lg"></i> Resolve</button>`;
      }
      if (!['resolved', 'cancelled', 'false_alarm'].includes(status)) {
        if (actions) actions += ' ';
        actions += `<button class="btn btn-sm btn-outline-secondary" onclick="updateStatus(${e.id}, 'cancel')" title="Cancel"><i class="bi bi-x-lg"></i></button>`;
      }

      return [badge, studentName, e.emergency_type || 'Emergency', `<span class="app-mono">${location}</span>`, source, `<span class="app-card__meta">${reported}</span>`, actions];
    });

    $('#statActive').text(active);
    $('#statResponding').text(responding);
    $('#statResolved').text(resolved);
    $('#statTotal').text(data.length);

    const tbody = $('#emgTable tbody');
    tbody.empty();
    if (rows.length === 0) {
      BPCState.tableEmpty('#emgTable tbody', 7, { icon: 'shield-check', title: 'No active emergencies', desc: 'Nothing needs attention right now. New SOS reports appear here the moment a student submits one.' });
    } else {
      rows.forEach(r => {
        tbody.append('<tr>' + r.map(function(c) { return '<td>' + c + '</td>'; }).join('') + '</tr>');
      });
    }
  }, 'json').fail(function () {
    BPCState.tableError('#emgTable tbody', 7, 'Unable to reach the server. Please try again.', loadEmergencies);
  });
}

function updateStatus(id, op) {
  $.post(APP_URL + '/api/emergencies.php', { action: 'update_status', emergency_id: id, op: op, csrf_token: csrfToken }, function (res) {
    if (res.success) {
      toastSuccess(res.message);
      loadEmergencies();
    } else {
      toastError(res.message);
    }
  }, 'json');
}

function openResolveModal(id, title) {
  document.getElementById('resolveEmgId').value = id;
  document.getElementById('resolveEmgTitle').textContent = title;
  BPCModal.show('resolveModal');
}

$('#resolveModal form').on('submit', function (e) {
  e.preventDefault();
  const id = document.getElementById('resolveEmgId').value;
  const btn = $(this).find('button[type="submit"]');
  btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Resolving...');

  $.post(APP_URL + '/api/emergencies.php', $(this).serialize() + '&csrf_token=' + csrfToken, function (res) {
    if (res.success) {
      toastSuccess(res.message);
      BPCModal.hide('resolveModal');
      loadEmergencies();
    } else {
      toastError(res.message);
    }
    btn.prop('disabled', false).html('Confirm Resolution');
  }, 'json').fail(function () {
    toastError('Connection error.');
    btn.prop('disabled', false).html('Confirm Resolution');
  });
});

loadEmergencies();
setInterval(loadEmergencies, 10000);
</script>
</body>
</html>
