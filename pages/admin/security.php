<?php
/**
 * pages/admin/security.php
 * =====================================================================
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 * System Administrator Security Oversight Console
 * =====================================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$u = current_user();
$pageTitle = 'Security Oversight Console';

// -------------------------------------------------------------
// POST Request Actions (Security Reset & Clear)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'clear_ip_lockout') {
        $ip = trim($_POST['ip_address'] ?? '');
        if ($ip !== '') {
            login_rate_limit_clear($ip);
            log_activity($u['id'], "Cleared login rate-limit lockout for IP: {$ip}", 'security');
            set_flash('success', "Rate-limit lockout for IP {$ip} has been cleared.");
        }
        redirect('security.php');
    }

    if ($action === 'clear_all_lockouts') {
        $db->query("DELETE FROM login_attempts WHERE UNIX_TIMESTAMP(attempted_at) >= (UNIX_TIMESTAMP() - 900)");
        log_activity($u['id'], "Cleared all active IP rate-limit lockouts", 'security');
        set_flash('success', "All active IP rate-limit lockouts cleared.");
        redirect('security.php');
    }
}

// -------------------------------------------------------------
// Security Metrics & Telemetry
// -------------------------------------------------------------
$totalFailed24h = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
$recentAttempts = $db->query("
    SELECT ip_address, username, attempted_at
    FROM login_attempts
    ORDER BY attempted_at DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// Rate-Limited IPs (>= 5 failed attempts in last 15 min)
$lockedIps = $db->query("
    SELECT ip_address, COUNT(*) AS attempt_count, MAX(attempted_at) AS last_attempt,
           GROUP_CONCAT(DISTINCT username SEPARATOR ', ') AS targeted_accounts
    FROM login_attempts
    WHERE UNIX_TIMESTAMP(attempted_at) >= (UNIX_TIMESTAMP() - 900)
    GROUP BY ip_address
    HAVING COUNT(*) >= 5
    ORDER BY attempt_count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Security & Authentication Activity Logs
$securityLogs = $db->query("
    SELECT al.*, u.first_name, u.last_name, u.role
    FROM activity_logs al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE al.module IN ('auth', 'security', 'admin')
    ORDER BY al.created_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

$totalAdmins = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
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
            <form method="POST" class="d-inline">
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
                      <form method="POST" class="d-inline">
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

      <!-- Recent Security & Authentication Activity Logs -->
      <div class="card p-3 shadow-sm border-0 rounded-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="fw-bold text-dark fs-6 d-flex align-items-center gap-2">
            <i class="bi bi-clock-history text-primary"></i> Security &amp; Access Activity Trail
          </div>
          <a href="activity_logs.php" class="btn btn-sm btn-outline-secondary">View All Audit Logs <i class="bi bi-arrow-right ms-1"></i></a>
        </div>

        <?php if (empty($securityLogs)): ?>
          <div class="text-center text-muted py-3 small">No security events recorded.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Timestamp</th>
                  <th>User / Actor</th>
                  <th>Security Action</th>
                  <th>Details</th>
                  <th>IP Address</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($securityLogs as $sLog): ?>
                  <tr>
                    <td class="small text-muted text-nowrap"><?= format_datetime($sLog['created_at']) ?></td>
                    <td>
                      <div class="fw-semibold text-dark small">
                        <?= !empty($sLog['first_name']) ? e($sLog['first_name'] . ' ' . $sLog['last_name']) : 'System / Guest' ?>
                      </div>
                      <span class="badge bg-secondary" style="font-size:0.65rem;"><?= e($sLog['role'] ?? 'system') ?></span>
                    </td>
                    <td><span class="badge bg-light text-dark border"><?= e($sLog['action']) ?></span></td>
                    <td class="small text-muted"><?= e($sLog['details'] ?: '—') ?></td>
                    <td><code class="small"><?= e($sLog['ip_address'] ?? '—') ?></code></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>

