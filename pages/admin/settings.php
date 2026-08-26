<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$pageTitle = 'System Settings';
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
      <div class="row g-3">
        <div class="col-lg-8">
          <div class="card p-3">
<h6 class="section-title mb-3"><i class="bi bi-gear text-success me-1"></i> System Configuration</h6>
            <form id="settingsForm">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label small">School Name</label><input class="form-control" name="settings[school_name]" id="inp_school_name"></div>
                <div class="col-md-6"><label class="form-label small">Clinic Name</label><input class="form-control" name="settings[clinic_name]" id="inp_clinic_name"></div>
                <div class="col-md-6"><label class="form-label small">School Address</label><input class="form-control" name="settings[school_address]" id="inp_school_address"></div>
                <div class="col-md-6"><label class="form-label small">Clinic Contact Number</label><input class="form-control" name="settings[clinic_contact]" id="inp_clinic_contact"></div>
                <div class="col-md-6"><label class="form-label small">Theme Mode</label>
                  <select class="form-select" name="settings[theme_mode]" id="inp_theme_mode">
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                  </select>
                </div>
              </div>
<button class="btn btn-primary mt-3" type="submit"><i class="bi bi-floppy me-1"></i> Save Settings</button>
            </form>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card p-3">
<h6 class="section-title mb-3"><i class="bi bi-info-circle text-success me-1"></i> System Info</h6>
            <div class="small">
              <div class="mb-2"><strong>Application:</strong> <?= APP_NAME ?></div>
              <div class="mb-2"><strong>Version:</strong> 1.0.0</div>
              <div class="mb-2"><strong>Database:</strong> MySQL / MariaDB</div>
              <div class="mb-2"><strong>PHP Version:</strong> <?= phpversion() ?></div>
              <hr>
              <div class="text-muted">Manage your clinic system configuration here. Changes take effect immediately.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');

$.get(APP_URL + '/api/settings.php', { action: 'list' }, function (res) {
  if (!res.success) return;
  const s = res.data;
  $('#inp_school_name').val(s.school_name || '');
  $('#inp_clinic_name').val(s.clinic_name || '');
  $('#inp_school_address').val(s.school_address || '');
  $('#inp_clinic_contact').val(s.clinic_contact || '');
  $('#inp_theme_mode').val(s.theme_mode || 'light');
}, 'json');

$('#settingsForm').on('submit', function (e) {
  e.preventDefault();
  $.post(APP_URL + '/api/settings.php', $(this).serialize() + '&action=update&csrf_token=' + csrfToken, function (res) {
    res.success ? toastSuccess(res.message) : toastError(res.message);
  }, 'json');
});
</script>
</body>
</html>
