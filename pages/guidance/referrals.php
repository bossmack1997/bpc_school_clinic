<?php
require_once __DIR__ . '/../../config/config.php';
require_role('guidance');
$db = getDB();
$u = current_user();
$pageTitle = 'Support Referrals';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h6 class="section-title mb-0"><i class="bi bi-send-fill text-success me-1"></i> Support Referrals</h6>
      </div>

      <div class="alert alert-light border d-flex align-items-center gap-2 mb-3" role="alert">
        <i class="bi bi-shield-lock-fill text-primary"></i>
        <div class="small">You only see referrals assigned to Guidance/Support. You may update a referral's status as the student progresses through support.</div>
      </div>

      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="refTable" style="width:100%">
              <thead><tr><th>Student</th><th>Referred To</th><th>Priority</th><th>Status</th><th>Created</th><th></th></tr></thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal fade" id="refDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header border-0 bg-success text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-send-fill me-2"></i> Referral Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="refDetailBody"></div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
        <select id="refStatusUpdate" class="form-select form-select-sm" style="width:auto;">
          <option value="pending">Pending</option>
          <option value="accepted">Accepted</option>
          <option value="in_support">In Support</option>
          <option value="follow_up_required">Follow-up Required</option>
          <option value="closed">Closed</option>
        </select>
        <button class="btn btn-primary btn-sm" id="refStatusBtn"><i class="bi bi-check-lg me-1"></i> Update</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const refStatusLabels = {
  pending: 'Pending', accepted: 'Accepted', in_support: 'In Support',
  follow_up_required: 'Follow-up Required', closed: 'Closed'
};
const refStatusColors = {
  pending: 'secondary', accepted: 'info', in_support: 'primary',
  follow_up_required: 'warning text-dark', closed: 'success'
};
let currentRefId = null;

function loadReferrals() {
  $.get(APP_URL + '/api/referrals.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    const rows = (res.data || []).map(r => [
      (r.first_name ? (r.first_name + ' ' + r.last_name) : 'Student #' + r.student_id) + '<div class="small text-muted">' + (r.student_number || '') + '</div>',
      r.referred_to,
      `<span class="badge bg-${r.priority === 'urgent' ? 'danger' : r.priority === 'soon' ? 'warning text-dark' : 'secondary'} text-capitalize">${r.priority}</span>`,
      `<span class="badge bg-${refStatusColors[r.status] || 'secondary'}">${refStatusLabels[r.status] || r.status}</span>`,
      r.created_at ? r.created_at.replace(' ', ' · ') : '—',
      `<button class="btn btn-sm btn-outline-primary view-ref" data-id="${r.id}">View</button>`
    ]);
    if (window.__refDt) window.__refDt.destroy();
    window.__refDt = $('#refTable').DataTable({ data: rows, columns: [{},{},{},{},{},{}], destroy: true, pageLength: 10 });
  }, 'json');
}
loadReferrals();

$(document).on('click', '.view-ref', function () {
  const id = $(this).data('id');
  $.get(APP_URL + '/api/referrals.php', { action: 'list' }, function (res) {
    const r = (res.data || []).find(x => x.id == id);
    if (!r) return;
    currentRefId = r.id;
    $('#refStatusUpdate').val(r.status);
    $('#refDetailBody').html(
      `<div class="row g-2 small">
        <div class="col-6"><div class="text-muted">Student</div><div class="fw-semibold">${r.first_name} ${r.last_name}</div></div>
        <div class="col-6"><div class="text-muted">Referred To</div><div class="fw-semibold">${$('<div>').text(r.referred_to).html()}</div></div>
        <div class="col-6"><div class="text-muted">Priority</div><div class="fw-semibold text-capitalize">${r.priority}</div></div>
        <div class="col-6"><div class="text-muted">Student Informed</div><div class="fw-semibold">${r.student_informed ? 'Yes' : 'No'}</div></div>
        <div class="col-12"><div class="text-muted">Reason</div><div class="fw-semibold">${$('<div>').text(r.reason || '—').html()}</div></div>
        <div class="col-12"><div class="text-muted">Notes</div><div class="fw-semibold">${$('<div>').text(r.notes || '—').html()}</div></div>
        <div class="col-12"><div class="text-muted">Created</div><div class="fw-semibold">${r.created_at}</div></div>
      </div>`
    );
    new bootstrap.Modal(document.getElementById('refDetailModal')).show();
  }, 'json');
});

$('#refStatusBtn').on('click', function () {
  if (!currentRefId) return;
  const data = { action: 'update_status', id: currentRefId, status: $('#refStatusUpdate').val(), csrf_token: $('meta[name="csrf-token"]').attr('content') };
  $.post(APP_URL + '/api/referrals.php', data, function (res) {
    if (res.success) { toastSuccess(res.message); bootstrap.Modal.getInstance(document.getElementById('refDetailModal')).hide(); loadReferrals(); }
    else toastError(res.message);
  }, 'json');
});
</script>
</body>
</html>
