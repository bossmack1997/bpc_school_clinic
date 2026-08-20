<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Clinic Announcements';
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
        <h6 class="section-title mb-0">Clinic Announcements</h6>
        <button class="btn btn-sm btn-success" id="addAnnouncementBtn">
          <i class="bi bi-plus-lg me-1"></i> Add Announcement
        </button>
      </div>
      <div class="card p-3">
        <div id="announcementsList"></div>
      </div>
    </div>
  </div>
</div>

<!-- Announcement Modal -->
<div class="modal fade" id="announcementModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="announcementModalTitle">Add Announcement</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="announcementForm">
          <input type="hidden" id="announcementId">
          <div class="mb-3">
            <label class="form-label">Title</label>
            <input type="text" class="form-control" id="announcementTitle" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Content</label>
            <textarea class="form-control" id="announcementContent" rows="4" required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Audience</label>
            <select class="form-select" id="announcementAudience">
              <option value="all">All</option>
              <option value="students">Students</option>
              <option value="nurses">Nurses</option>
            </select>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="announcementPinned">
            <label class="form-check-label" for="announcementPinned">Pin to top</label>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="saveAnnouncementBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');
const announcementModal = new bootstrap.Modal(document.getElementById('announcementModal'));

function loadAnnouncements() {
  $.get(APP_URL + '/api/announcements.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    const $list = $('#announcementsList').empty();
    if (res.data.length === 0) {
      $list.append('<p class="text-muted text-center py-4">No announcements yet.</p>');
      return;
    }
    res.data.forEach(function (a) {
      const pinned = a.is_pinned == 1 ? '<span class="badge bg-warning text-dark ms-1">Pinned</span>' : '';
      const audience = a.audience !== 'all' ? '<span class="badge bg-info ms-1">' + a.audience + '</span>' : '';
      $list.append(
        `<div class="border-bottom py-3">
          <div class="d-flex justify-content-between align-items-start">
            <div style="flex:1;">
              <strong>${$('<div>').text(a.title).html()}</strong> ${pinned} ${audience}
              <div class="text-muted small mt-1">${$('<div>').text(a.content).html()}</div>
              <div class="text-muted" style="font-size:.75rem">Posted by ${a.first_name || 'Clinic'} ${a.last_name || ''} · ${a.created_at}</div>
            </div>
            <div class="btn-group btn-group-sm ms-3">
              <button class="btn btn-outline-primary edit-btn" data-id="${a.id}" data-title="${$('<div>').text(a.title).html()}" data-content="${$('<div>').text(a.content).html()}" data-audience="${a.audience}" data-pinned="${a.is_pinned}">Edit</button>
              <button class="btn btn-outline-danger delete-btn" data-id="${a.id}">Delete</button>
              <button class="btn btn-outline-warning pin-btn" data-id="${a.id}" data-pinned="${a.is_pinned}">${a.is_pinned == 1 ? 'Unpin' : 'Pin'}</button>
            </div>
          </div>
        </div>`
      );
    });
  }, 'json');
}

$('#addAnnouncementBtn').on('click', function () {
  $('#announcementForm')[0].reset();
  $('#announcementId').val('');
  $('#announcementModalTitle').text('Add Announcement');
  announcementModal.show();
});

$('#saveAnnouncementBtn').on('click', function () {
  const id = $('#announcementId').val();
  const title = $('#announcementTitle').val().trim();
  const content = $('#announcementContent').val().trim();
  const audience = $('#announcementAudience').val();
  const is_pinned = $('#announcementPinned').is(':checked') ? 1 : 0;

  if (!title || !content) {
    toastError('Title and content are required.');
    return;
  }

  const action = id ? 'edit' : 'add';
  const data = { action: action, title: title, content: content, audience: audience, is_pinned: is_pinned, csrf_token: csrfToken };
  if (id) data.id = id;

  $.post(APP_URL + '/api/announcements.php', data, function (res) {
    if (res.success) {
      toastSuccess(res.message);
      announcementModal.hide();
      loadAnnouncements();
    } else {
      toastError(res.message);
    }
  }, 'json');
});

$('#announcementsList').on('click', '.edit-btn', function () {
  const id = $(this).data('id');
  const title = $(this).data('title');
  const content = $(this).data('content');
  const audience = $(this).data('audience');
  const pinned = $(this).data('pinned');

  $('#announcementId').val(id);
  $('#announcementTitle').val(title);
  $('#announcementContent').val(content);
  $('#announcementAudience').val(audience);
  $('#announcementPinned').prop('checked', pinned == 1);
  $('#announcementModalTitle').text('Edit Announcement');
  announcementModal.show();
});

$('#announcementsList').on('click', '.delete-btn', function () {
  const id = $(this).data('id');
  confirmAction('Delete this announcement?', function () {
    $.post(APP_URL + '/api/announcements.php', { action: 'delete', id, csrf_token: csrfToken }, function (res) {
      if (res.success) { toastSuccess(res.message); loadAnnouncements(); }
      else toastError(res.message);
    }, 'json');
  });
});

$('#announcementsList').on('click', '.pin-btn', function () {
  const id = $(this).data('id');
  $.post(APP_URL + '/api/announcements.php', { action: 'toggle_pin', id, csrf_token: csrfToken }, function (res) {
    if (res.success) { toastSuccess(res.message); loadAnnouncements(); }
    else toastError(res.message);
  }, 'json');
});

loadAnnouncements();
</script>
</body>
</html>
