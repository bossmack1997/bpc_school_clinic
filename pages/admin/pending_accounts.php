<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$pageTitle = 'Pending Accounts & AI Review Queue';

$pendingUsers = $db->query("
  SELECT u.*, 
         COALESCE(s.student_number, n.employee_id, t.employee_id) AS identifier,
         s.course, s.year_level, s.section,
         n.license_number,
         t.department AS teacher_department,
         COALESCE(s.valid_id_path, n.valid_id_path) AS valid_id_path
  FROM users u
  LEFT JOIN students s ON s.user_id = u.id
  LEFT JOIN nurses n ON n.user_id = u.id
  LEFT JOIN teachers t ON t.user_id = u.id
  WHERE u.status = 'pending'
  ORDER BY u.created_at DESC
")->fetchAll();
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
      <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
          <h6 class="section-title mb-1"><i class="bi bi-robot text-primary me-1"></i> AI-Assisted Registration Review Queue</h6>
          <p class="text-muted small mb-0">Review pending account applications evaluated by the MICA Intelligent Registration Validator.</p>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-success" id="bulkApproveBtn" disabled><i class="bi bi-check-all me-1"></i> Approve Selected</button>
        </div>
      </div>

      <div class="card p-3 shadow-sm border-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="pendingTable" style="width:100%">
            <thead class="table-light">
              <tr>
                <th style="width: 30px;"><input type="checkbox" id="masterCheckbox"></th>
                <th>Applicant</th>
                <th>Role</th>
                <th>Identifier &amp; Details</th>
                <th>MICA AI Assessment</th>
                <th>Registered</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pendingUsers as $u): ?>
              <tr>
                <td><input type="checkbox" class="user-checkbox" value="<?= $u['id'] ?>"></td>
                <td>
                  <strong><?= e($u['first_name'] . ' ' . $u['last_name']) ?></strong>
                  <div class="small text-muted"><?= e($u['email']) ?></div>
                  <?php if (!empty($u['phone'])): ?>
                    <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= e($u['phone']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php 
                    $badgeClass = match($u['role']) {
                      'admin' => 'bg-dark',
                      'doctor' => 'bg-primary',
                      'nurse' => 'bg-info text-dark',
                      'teacher' => 'bg-primary text-white',
                      default => 'bg-secondary'
                    };
                  ?>
                  <span class="badge <?= $badgeClass ?>"><?= e(get_role_display_name($u['role'])) ?></span>
                </td>
                <td>
                  <div class="small">
                    <strong>ID:</strong> <?= e($u['identifier'] ?? '—') ?>
                    <?php if (!empty($u['license_number'])): ?>
                      <br><span class="text-info fw-semibold">PRC: <?= e($u['license_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($u['course'])): ?>
                      <br><span class="text-muted">Course: <?= e($u['course']) ?> <?= e($u['year_level'] ?? '') ?></span>
                    <?php endif; ?>
                    <?php if (!empty($u['teacher_department'])): ?>
                      <br><span class="text-muted">Dept: <?= e($u['teacher_department']) ?></span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <?php 
                    $conf = !empty($u['validation_confidence']) ? round((float)$u['validation_confidence'] * 100) : 50;
                    $confBadge = $conf >= 75 ? 'bg-success' : ($conf >= 50 ? 'bg-warning text-dark' : 'bg-danger');
                  ?>
                  <div class="d-flex align-items-center gap-1 mb-1">
                    <span class="badge <?= $confBadge ?>"><i class="bi bi-shield-check me-1"></i><?= $conf ?>% Confidence</span>
                  </div>
                  <div class="small text-muted" style="max-width:280px;line-height:1.3;">
                    <?= e($u['validation_reason'] ?? 'Pending administrative verification.') ?>
                  </div>
                </td>
                <td>
                  <div class="small text-muted"><?= format_date($u['created_at']) ?></div>
                </td>
                <td>
                  <div class="btn-group btn-group-sm">
                    <button class="btn btn-success approve-btn" data-id="<?= $u['id'] ?>" data-name="<?= e($u['first_name'] . ' ' . $u['last_name']) ?>" title="Approve Account">
                      <i class="bi bi-check-lg me-1"></i> Approve
                    </button>
                    <button class="btn btn-outline-danger reject-btn" data-id="<?= $u['id'] ?>" data-name="<?= e($u['first_name'] . ' ' . $u['last_name']) ?>" title="Reject Account">
                      <i class="bi bi-x-lg me-1"></i> Reject
                    </button>
                    <?php if (!empty($u['valid_id_path']) || !empty($u['profile_picture'])): ?>
                      <button class="btn btn-outline-secondary view-doc-btn" 
                              data-id="<?= $u['id'] ?>" 
                              data-name="<?= e($u['first_name'] . ' ' . $u['last_name']) ?>" 
                              data-pic="<?= e($u['profile_picture'] ?? '') ?>" 
                              data-doc="<?= e($u['valid_id_path'] ?? '') ?>" 
                              title="View Uploaded ID">
                        <i class="bi bi-card-image"></i>
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$pendingUsers): ?>
              <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-check2-circle text-success fs-4 d-block mb-1"></i> No pending accounts awaiting review.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: View Verification Documents -->
<div class="modal fade" id="viewDocsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light">
        <h5 class="modal-title" id="docModalTitle"><i class="bi bi-person-badge me-2"></i>Verification Documents</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center p-4">
        <div class="row g-3">
          <div class="col-md-6" id="profilePicContainer">
            <h6 class="text-muted small fw-semibold mb-2">Profile Photo</h6>
            <img id="modalProfilePic" src="" alt="Profile Photo" class="img-fluid rounded border shadow-sm" style="max-height:260px;object-fit:cover;">
          </div>
          <div class="col-md-6" id="validIdContainer">
            <h6 class="text-muted small fw-semibold mb-2">Attached Valid / Student ID</h6>
            <div id="validIdContent"></div>
          </div>
        </div>
      </div>
      <div class="modal-footer bg-light">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Reject Account with Reason -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Account Application</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <p>You are rejecting the registration application for <strong id="rejectUserName"></strong>.</p>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Rejection Reason</label>
          <textarea id="rejectionReasonInput" class="form-control" rows="3" placeholder="Provide a reason (e.g. Invalid Student ID, unverifiable PRC license, duplicate registration...)"></textarea>
        </div>
        <input type="hidden" id="rejectUserId" value="">
      </div>
      <div class="modal-footer bg-light">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmRejectBtn">Confirm Rejection</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');

// Checkbox selection logic
function updateBulkButton() {
  const checkedCount = $('.user-checkbox:checked').length;
  $('#bulkApproveBtn').prop('disabled', checkedCount === 0);
  $('#bulkApproveBtn').html(`<i class="bi bi-check-all me-1"></i> Approve Selected (${checkedCount})`);
}

$('#masterCheckbox').on('change', function () {
  $('.user-checkbox').prop('checked', $(this).prop('checked'));
  updateBulkButton();
});

$(document).on('change', '.user-checkbox', function () {
  updateBulkButton();
});

// 1-Click Approve
$('.approve-btn').on('click', function () {
  const id = $(this).data('id');
  const name = $(this).data('name') || 'this account';
  confirmAction(`Approve ${name}? The user will be activated and allowed to sign in.`, function () {
    $.post(APP_URL + '/api/users.php', { action: 'approve', id, csrf_token: csrfToken }, function (res) {
      if (res.success) { toastSuccess(res.message); setTimeout(() => location.reload(), 600); }
      else toastError(res.message);
    }, 'json');
  });
});

// Bulk Approve
$('#bulkApproveBtn').on('click', function () {
  const ids = $('.user-checkbox:checked').map(function () { return $(this).val(); }).get();
  if (!ids.length) return;
  confirmAction(`Approve ${ids.length} selected account(s)?`, function () {
    $.post(APP_URL + '/api/users.php', { action: 'bulk_approve', ids, csrf_token: csrfToken }, function (res) {
      if (res.success) { toastSuccess(res.message); setTimeout(() => location.reload(), 600); }
      else toastError(res.message);
    }, 'json');
  });
});

// Reject with Modal Reason
$('.reject-btn').on('click', function () {
  const id = $(this).data('id');
  const name = $(this).data('name') || 'this applicant';
  $('#rejectUserId').val(id);
  $('#rejectUserName').text(name);
  $('#rejectionReasonInput').val('');
  $('#rejectModal').modal('show');
});

$('#confirmRejectBtn').on('click', function () {
  const id = $('#rejectUserId').val();
  const reason = $('#rejectionReasonInput').val().trim() || 'Rejected during administrative review.';
  $('#rejectModal').modal('hide');
  $.post(APP_URL + '/api/users.php', { action: 'reject', id, reason, csrf_token: csrfToken }, function (res) {
    if (res.success) { toastSuccess(res.message); setTimeout(() => location.reload(), 600); }
    else toastError(res.message);
  }, 'json');
});

// View attached ID / Profile Doc Modal
$('.view-doc-btn').on('click', function () {
  const name = $(this).data('name');
  const pic = $(this).data('pic');
  const doc = $(this).data('doc');

  $('#docModalTitle').html('<i class="bi bi-person-badge me-2"></i>Documents for ' + name);

  if (pic) {
    $('#modalProfilePic').attr('src', APP_URL + '/' + pic);
    $('#profilePicContainer').show();
  } else {
    $('#profilePicContainer').hide();
  }

  if (doc) {
    const isPdf = doc.toLowerCase().endsWith('.pdf');
    if (isPdf) {
      $('#validIdContent').html('<a href="' + APP_URL + '/' + doc + '" target="_blank" class="btn btn-outline-primary mt-4"><i class="bi bi-file-earmark-pdf me-1"></i> Open Attached PDF Document</a>');
    } else {
      $('#validIdContent').html('<img src="' + APP_URL + '/' + doc + '" alt="Valid ID" class="img-fluid rounded border shadow-sm" style="max-height:260px;object-fit:contain;">');
    }
    $('#validIdContainer').show();
  } else {
    $('#validIdContent').html('<p class="text-muted small my-4">No ID document attached.</p>');
  }

  $('#viewDocsModal').modal('show');
});
</script>
</body>
</html>
