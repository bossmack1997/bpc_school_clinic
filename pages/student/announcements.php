<?php
require_once __DIR__ . '/../../config/config.php';
require_role('student');
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
      <div class="card p-3">
        <h6 class="section-title mb-3"><i class="bi bi-megaphone text-success me-1"></i> Clinic Announcements</h6>
        <div id="announcementsList"></div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
$.get(APP_URL + '/api/announcements.php', { action: 'list' }, function (res) {
  if (!res.success) return;
  const $list = $('#announcementsList').empty();
  if (res.data.length === 0) {
    $list.append('<p class="text-muted text-center py-4">No announcements yet.</p>');
    return;
  }
  res.data.forEach(function (a) {
    const pinned = a.is_pinned == 1 ? '<span class="badge bg-warning text-dark ms-1">Pinned</span>' : '';
    $list.append(
      `<div class="border-bottom py-3">
        <div>
          <strong>${$('<div>').text(a.title).html()}</strong> ${pinned}
          <div class="text-muted small mt-1">${$('<div>').text(a.content).html()}</div>
          <div class="text-muted" style="font-size:.75rem">Posted by ${a.first_name || 'Clinic'} ${a.last_name || ''} · ${a.created_at}</div>
        </div>
      </div>`
    );
  });
}, 'json');
</script>
</body>
</html>
