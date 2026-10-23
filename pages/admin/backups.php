<?php
require_once __DIR__ . '/../../config/config.php';
require_admin_permission('backups_manage');
$loadMisAdminStyles=true;
$pageTitle = 'Backup & Recovery';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body class="mis-admin-page">
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <main class="content-area page-enter" id="mainContent">
      <div class="app-page-head d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div><h1 class="h4 mb-1"><i class="bi bi-database-check text-success me-2"></i>Backup &amp; Recovery</h1><p class="text-muted small mb-0">Create, verify, and securely download database backups.</p></div>
        <button class="btn btn-success" type="button" id="createBackup"><i class="bi bi-database-add me-1"></i>Create backup</button>
      </div>

      <div id="backupAlert" class="alert d-none" role="status" aria-live="polite"></div>
      <section class="card border-0 shadow-sm">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h2 class="h6 mb-0">Backup history</h2><button class="btn btn-sm btn-outline-secondary" id="refreshBackups" type="button"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button></div>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead><tr><th>Created</th><th>Status</th><th>Requested by</th><th>File</th><th>Actions</th></tr></thead>
              <tbody id="backupRows"><tr><td colspan="5" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading backup history…</td></tr></tbody>
            </table>
          </div>
        </div>
      </section>
      <section class="card p-3 mt-3"><h2 class="h6">Recover into a separate database</h2><p class="small text-muted">An operator must create an empty recovery database first. This workflow verifies a v2 backup and restores there. It refuses to overwrite the active database or any non-empty target. Review recovered records before any separately authorized production cutover.</p><form id="recoveryForm" method="post" action="<?=APP_URL?>/api/system_backups.php"><?=csrf_field()?><input type="hidden" name="action" value="restore"><div class="row g-3"><div class="col-md-6"><label for="recovery-id" class="form-label">Completed backup ID</label><input id="recovery-id" name="id" type="number" min="1" required class="form-control"></div><div class="col-md-6"><label for="recovery-target" class="form-label">Empty recovery database</label><input id="recovery-target" name="target_database" required pattern="[A-Za-z0-9_]{1,64}" maxlength="64" class="form-control"></div><div class="col-md-6"><label for="recovery-confirm" class="form-label">Type the recovery database name to confirm</label><input id="recovery-confirm" name="confirmation" required class="form-control"></div><div class="col-md-6"><label for="recovery-password" class="form-label">Current MIS password</label><input id="recovery-password" name="current_password" type="password" required autocomplete="current-password" class="form-control"></div></div><button class="btn btn-outline-danger mt-3" type="submit">Verify and recover into separate database</button></form></section>
    </main>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
(function () {
  'use strict';
  const endpoint = APP_URL + '/api/system_backups.php';
  const rows = document.getElementById('backupRows');
  const alertBox = document.getElementById('backupAlert');
  const createButton = document.getElementById('createBackup');
  const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
  function notify(message, type) { alertBox.className = 'alert alert-' + type; alertBox.textContent = message; }
  async function request(url, options) {
    const response = await fetch(url, Object.assign({signal:AbortSignal.timeout(180000), credentials:'same-origin', headers:{'Accept':'application/json'}}, options || {}));
    const text = await response.text();
    let result; try { result = JSON.parse(text); } catch (_) { throw new Error('The server returned an invalid response.'); }
    if (!response.ok || !result.success) throw new Error(result.message || 'The backup request failed.');
    return result;
  }
  async function load() {
    rows.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading backup history…</td></tr>';
    try {
      const result = await request(endpoint + '?action=list');
      if (!Array.isArray(result.backups)) throw new Error('Backup history response was incomplete.');
      if (!result.backups.length) { rows.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No backups have been created.</td></tr>'; return; }
      rows.innerHTML = result.backups.map(item => {
        const statusClass = item.status === 'completed' ? 'success' : (item.status === 'failed' ? 'danger' : 'warning');
        const actions = item.status === 'completed' ? '<div class="d-flex flex-wrap gap-1"><a class="btn btn-sm btn-outline-primary" href="' + endpoint + '?action=download&id=' + Number(item.id) + '"><i class="bi bi-download"></i><span class="visually-hidden"> Download</span></a><button class="btn btn-sm btn-outline-success verify-backup" data-id="' + Number(item.id) + '" type="button"><i class="bi bi-shield-check"></i><span class="visually-hidden"> Verify</span></button></div>' : '';
        return '<tr><td>' + escapeHtml(item.created_at) + '</td><td><span class="badge text-bg-' + statusClass + '">' + escapeHtml(item.status) + '</span></td><td>' + escapeHtml(item.requested_by_name || 'System administrator') + '</td><td class="small">' + escapeHtml(item.filename || '—') + '</td><td>' + actions + '</td></tr>';
      }).join('');
    } catch (error) { rows.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">' + escapeHtml(error.message) + '</td></tr>'; notify(error.message, 'danger'); }
  }
  async function post(action, id) {
    const body = new FormData(); body.append('action', action); body.append('csrf_token', token); if (id) body.append('id', id);
    return request(endpoint, {method:'POST', body});
  }
  createButton.addEventListener('click', async function () {
    if (!confirm('Create a new database backup now?')) return;
    createButton.disabled = true; createButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating…';
    try { const result = await post('create'); notify(result.message, 'success'); await load(); }
    catch (error) { notify(error.message, 'danger'); }
    finally { createButton.disabled = false; createButton.innerHTML = '<i class="bi bi-database-add me-1"></i>Create backup'; }
  });
  document.getElementById('refreshBackups').addEventListener('click', load);
  rows.addEventListener('click', async function (event) {
    const button = event.target.closest('.verify-backup'); if (!button) return;
    button.disabled = true;
    try { const result = await post('verify', button.dataset.id); notify(result.message, 'success'); }
    catch (error) { notify(error.message, 'danger'); }
    finally { button.disabled = false; }
  });
  document.getElementById('recoveryForm').addEventListener('submit',async function(event){
    event.preventDefault();const form=event.currentTarget,button=form.querySelector('button[type="submit"]');
    if(!confirm('Recover this backup into the named separate database? The active database cannot be overwritten.'))return;
    button.disabled=true;const body=new FormData(form);
    try {const result=await request(endpoint,{method:'POST',body});notify(result.message,'success');}
    catch(error){notify(error.message,'danger');}finally{form.elements.current_password.value='';button.disabled=false;}
  });
  load();
})();
</script>
</body></html>
