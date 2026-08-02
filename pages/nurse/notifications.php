<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'Notifications';
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
        <h6 class="section-title mb-0">My Notifications</h6>
        <button class="btn btn-sm btn-outline-primary" id="markAllRead"><i class="bi bi-check-all me-1"></i> Mark All Read</button>
      </div>
      <div class="card p-3">
        <div id="notifList"></div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');

function loadNotifs() {
  $.get(APP_URL + '/api/notifications.php', { action: 'list' }, function (res) {
    if (!res.success) return;
    const $list = $('#notifList').empty();
    if (res.data.length === 0) {
      $list.append('<p class="text-center text-muted py-4">No notifications yet.</p>');
      return;
    }
    res.data.forEach(function (n) {
      const readClass = n.is_read ? '' : 'fw-semibold bg-light';
      $list.append(
        `<div class="border-bottom py-2 px-2 ${readClass} d-flex justify-content-between align-items-center">
          <div>
            <div class="small">${n.title}</div>
            <div class="text-muted" style="font-size:.8rem">${n.message}</div>
            <div class="text-muted" style="font-size:.7rem">${n.created_at}</div>
          </div>
          <div class="d-flex gap-1">
            ${!n.is_read ? `<button class="btn btn-sm btn-outline-success mark-btn" data-id="${n.id}"><i class="bi bi-check-lg"></i></button>` : ''}
            ${n.link ? `<a href="${n.link}" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i></a>` : ''}
          </div>
        </div>`
      );
    });
  }, 'json');
}
loadNotifs();

$(document).on('click', '.mark-btn', function () {
  const id = $(this).data('id');
  $.post(APP_URL + '/api/notifications.php', { action: 'mark_read', id, csrf_token: csrfToken }, function (res) {
    if (res.success) loadNotifs();
  }, 'json');
});

$('#markAllRead').on('click', function () {
  $.post(APP_URL + '/api/notifications.php', { action: 'mark_all_read', csrf_token: csrfToken }, function (res) {
    if (res.success) { toastSuccess('All marked as read'); loadNotifs(); }
  }, 'json');
});
</script>
</body>
</html>
