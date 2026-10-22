<?php
// Included only after the authenticated admin settings entry point.
if (!defined('APP_URL')) {
    http_response_code(404);
    exit;
}
// Authorization runs in settings.php and admin_security.php before output.
// A rendering partial must not re-run require_login(), which sends headers.

?>
      <!-- Header Section -->
      <div class="app-page-head d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h5 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-shield-lock-fill text-success"></i> Security &amp; Access Oversight Console
          </h5>
          <p class="text-muted small mb-0">Monitor login telemetry, rate-limiting lockouts, security event audits, and administrative session controls.</p>
        </div>
        <div>
          <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 small">
            <i class="bi bi-shield-check me-1"></i> Rate Limiting &amp; CSP Active
          </span>
        </div>
      </div>

      <!-- Security KPI Cards -->
      <div class="row g-3 mb-4">
        <!-- 1. Failed Logins (24h) -->
        <div class="col-sm-6 col-xl-3">
          <div class="dbg-stat">
            <div class="text-muted small fw-semibold text-uppercase">Failed Logins (24h)</div>
            <h3 class="fw-bold text-dark mt-2 mb-1"><?= $totalFailed24h ?></h3>
            <div class="small text-muted">Authentication attempts failed</div>
          </div>
        </div>

        <!-- 2. Rate-Limited IPs -->
        <div class="col-sm-6 col-xl-3">
          <div class="dbg-stat">
            <div class="text-muted small fw-semibold text-uppercase">Active IP Lockouts</div>
            <h3 class="fw-bold text-<?= count($lockedIps) > 0 ? 'danger' : 'dark' ?> mt-2 mb-1"><?= count($lockedIps) ?></h3>
            <div class="small text-muted"><?= count($lockedIps) > 0 ? 'Exceeded 5 attempts in 15m' : 'No active IP lockouts' ?></div>
          </div>
        </div>

        <!-- 3. Active Admins -->
        <div class="col-sm-6 col-xl-3">
          <div class="dbg-stat">
            <div class="text-muted small fw-semibold text-uppercase">Active Administrators</div>
            <h3 class="fw-bold text-dark mt-2 mb-1"><?= $totalAdmins ?></h3>
            <div class="small text-muted"><a href="settings.php?tab=admins" class="text-success text-decoration-none">Manage Privileges &rarr;</a></div>
          </div>
        </div>

        <!-- 4. Security Status -->
        <div class="col-sm-6 col-xl-3">
          <div class="dbg-stat">
            <div class="text-muted small fw-semibold text-uppercase">Session Timeout</div>
            <h3 class="fw-bold text-success mt-2 mb-1">60 Min</h3>
            <div class="small text-muted">Strict session expiry &amp; regeneration</div>
          </div>
        </div>
      </div>

      <!-- Active Rate-Limited IP Lockouts -->
      <div class="card p-3 shadow-sm border-0 mb-4 rounded-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="fw-bold text-dark fs-6 d-flex align-items-center gap-2">
            <i class="bi bi-slash-circle text-danger"></i> Rate-Limited IP Addresses (<?= count($lockedIps) ?>)
          </div>
          <?php if (!empty($lockedIps)): ?>
            <form method="POST" action="settings.php?tab=security" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="clear_all_lockouts">
              <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Clear all active IP lockouts?');">
                <i class="bi bi-trash me-1"></i> Clear All Lockouts
              </button>
            </form>
          <?php endif; ?>
        </div>

        <?php if (empty($lockedIps)): ?>
          <div class="text-center text-muted py-3 small">
            <i class="bi bi-shield-check fs-4 text-success d-block mb-1"></i>
            No IP addresses are currently locked out by the brute-force mitigation engine.
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>IP Address</th>
                  <th>Failed Attempts</th>
                  <th>Targeted Username(s)</th>
                  <th>Most Recent Attempt</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($lockedIps as $lip): ?>
                  <tr>
                    <td><code><?= e($lip['ip_address']) ?></code></td>
                    <td><span class="badge bg-danger"><?= $lip['attempt_count'] ?> failures</span></td>
                    <td class="small text-muted"><?= e($lip['targeted_accounts'] ?: 'N/A') ?></td>
                    <td class="small text-muted"><?= format_datetime($lip['last_attempt']) ?></td>
                    <td class="text-end">
                      <form method="POST" action="settings.php?tab=security" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear_ip_lockout">
                        <input type="hidden" name="ip_address" value="<?= e($lip['ip_address']) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-success">
                          <i class="bi bi-unlock-fill me-1"></i> Unlock IP
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
