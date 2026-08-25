<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
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
        <h6 class="section-title mb-0"><i class="bi bi-gear-fill me-1"></i> Support Referrals</h6>
        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#referralModal"><i class="bi bi-plus-lg me-1"></i> New Referral</button>
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

<!-- New Referral Modal -->
<div class="modal fade" id="referralModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 shadow">
      <div class="modal-header border-0 bg-success text-white rounded-top-4">
        <h5 class="modal-title"><i class="bi bi-send-fill me-2"></i> New Support Referral</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="referralForm">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Student (Student No. or Name)</label>
            <input type="text" id="refStudentSearch" class="form-control" placeholder="Search…" autocomplete="off">
            <div id="refStudentResults" class="list-group mt-1"></div>
            <input type="hidden" name="student_id" id="refStudentId">
            <div id="refStudentChosen" class="small text-success mt-1 d-none"></div>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Referral Destination</label>
            <input type="text" class="form-control" name="referred_to" value="Guidance Office" required>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">Reason</label>
            <textarea class="form-control" name="reason" rows="2" required></textarea>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small fw-semibold">Priority</label>
              <select class="form-select" name="priority">
                <option value="routine">Routine</option>
                <option value="soon">Soon</option>
                <option value="urgent">Urgent</option>
              </select>
            </div>
            <div class="col-6 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="student_informed" id="si" value="1">
                <label class="form-check-label small" for="si">Student informed</label>
              </div>
            </div>
          </div>
          <div class="mb-2 mt-2">
            <label class="form-label small fw-semibold">Notes</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
          </div>
          <button class="btn btn-success w-100" type="submit"><i class="bi bi-send me-1"></i> Create Referral</button>
        </form>
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
const refPriorityColors = { routine: 'secondary', soon: 'warning text-dark', urgent: 'danger' };
let currentRefId = null;

function loadReferrals() {
  $.get(APP_URL + '/api/referrals.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    const rows = (res.data || []).map(r => [
      (r.first_name ? (r.first_name + ' ' + r.last_name) : 'Student #' + r.student_id) + '<div class="small text-muted">' + (r.student_number || '') + '</div>',
      r.referred_to,
      `<span class="badge bg-${refPriorityColors[r.priority] || 'secondary'} text-capitalize">${r.priority}</span>`,
      `<span class="badge bg-${refStatusColors[r.status] || 'secondary'}">${refStatusLabels[r.status] || r.status}</span>`,
      r.created_at ? r.created_at.replace(' ', ' · ') : '—',
      `<button class="btn btn-sm btn-outline-primary view-ref" data-id="${r.id}">View</button>`
    ]);
    if (window.__refDt) window.__refDt.destroy();
    window.__refDt = $('#refTable').DataTable({ data: rows, columns: [{},{},{},{},{},{}], destroy: true, pageLength: 10 });
  }, 'json');
}
loadReferrals();

// Student search for referral
$('#refStudentSearch').on('keyup', function () {
  const q = $(this).val();
  if (q.length < 2) { $('#refStudentResults').empty(); return; }
  $.get(APP_URL + '/api/students_api.php', { action: 'search', q }, function (res) {
    const $r = $('#refStudentResults').empty();
    (res.data || []).forEach(s => {
      $r.append(`<button type="button" class="list-group-item list-group-item-action" data-id="${s.id}" data-name="${s.first_name} ${s.last_name} (${s.student_number})">${s.first_name} ${s.last_name} — ${s.student_number}</button>`);
    });
  }, 'json');
});
$('#refStudentResults').on('click', 'button', function () {
  $('#refStudentId').val($(this).data('id'));
  $('#refStudentChosen').removeClass('d-none').text('Selected: ' + $(this).data('name'));
  $('#refStudentResults').empty();
  $('#refStudentSearch').val('');
});

$('#referralForm').on('submit', function (e) {
  e.preventDefault();
  if (!$('#refStudentId').val()) { toastError('Please select a student.'); return; }
  const data = $(this).serialize() + '&action=create&csrf_token=' + $('meta[name="csrf-token"]').attr('content');
  $.post(APP_URL + '/api/referrals.php', data, function (res) {
    if (res.success) { toastSuccess(res.message); $('#referralForm')[0].reset(); $('#refStudentChosen').addClass('d-none'); $('#refStudentId').val(''); bootstrap.Modal.getInstance(document.getElementById('referralModal')).hide(); loadReferrals(); }
    else toastError(res.message);
  }, 'json');
});

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
