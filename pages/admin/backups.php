<?php
require_once __DIR__ . '/../../config/config.php';
require_admin_permission('backups_manage');
$pageTitle = 'Backup & Recovery';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
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
      <div class="alert alert-info mt-3 small"><i class="bi bi-shield-lock me-1"></i>Production restore is intentionally unavailable from this page. Restore testing must use an isolated database and authorized server tooling.</div>
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
    const response = await fetch(url, Object.assign({credentials:'same-origin', headers:{'Accept':'application/json'}}, options || {}));
    const text = await response.text();
    let result; try { result = JSON.parse(text); } catch (_) { throw new Error('The server returned an invalid response.'); }
    if (!response.ok || !result.success) throw new Error(result.message || 'The backup request failed.');
    return result;
  }
  async function load() {
    rows.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading backup history…</td></tr>';
    try {
      const result = await request(endpoint + '?action=list');
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
  load();
})();
</script>
</body></html>
