<?php
require_once __DIR__ . '/../../config/config.php';
require_role('guidance');
$pageTitle = 'Clinic Announcements';
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

      <div class="card p-3">
        <h6 class="section-title mb-3"><i class="bi bi-megaphone text-success me-1"></i> Clinic Announcements</h6>
        <div id="announcementsList">
          <div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-1"></span> Loading announcements...</div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$(document).ready(function () {
  $.get(APP_URL + '/api/announcements.php', { action: 'list' }, function (res) {
    if (!res.success) {
      $('#announcementsList').html('<p class="text-muted text-center py-4">Unable to load announcements.</p>');
      return;
    }
    const $list = $('#announcementsList').empty();
    if (!res.data || res.data.length === 0) {
      $list.append('<p class="text-muted text-center py-4"><i class="bi bi-info-circle fs-3 d-block mb-2"></i>No new clinic announcements.</p>');
      return;
    }
    res.data.forEach(function (a) {
      const pinned = a.is_pinned == 1 ? '<span class="badge bg-warning text-dark ms-1">Pinned</span>' : '';
      $list.append(
        `<div class="border-bottom py-3">
          <div>
            <strong class="text-dark">${$('<div>').text(a.title).html()}</strong> ${pinned}
            <div class="text-muted small mt-1">${$('<div>').text(a.content).html()}</div>
            <div class="text-muted mt-2" style="font-size:.78rem"><i class="bi bi-person me-1"></i>Posted by ${escapeHtml(a.first_name || 'Clinic')} ${escapeHtml(a.last_name || '')} · <i class="bi bi-clock me-1"></i>${a.created_at}</div>
          </div>
        </div>`
      );
    });
  }, 'json');
});
</script>
</body>
</html>