<?php
/**
 * pages/admin/security.php
 * =====================================================================
 * Security Center — Security monitoring, incident management, and
 * documented account security exceptions.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/account_security_exceptions.php';
require_once __DIR__ . '/../../includes/security_monitoring.php';
require_once __DIR__ . '/../../includes/security_incident_service.php';
require_admin_permission('manage_security');
$db = getDB();
$u = current_user();
$loadMisAdminStyles=true;
$pageTitle = 'Security';

$exceptionError = '';
$exceptionSuccess = '';

// -------------------------------------------------------------
// Open a new security exception
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    foreach (['action', 'user_id', 'exception_id', 'exception_type', 'reason', 'evidence', 'decision', 'notes'] as $key) {
        if (isset($_POST[$key]) && !is_scalar($_POST[$key])) {
            http_response_code(400);
            exit('Invalid exception input.');
        }
    }

    try {
        $service = new AccountSecurityExceptionService($db);
        $action = $_POST['action'] ?? '';

        if ($action === 'clear_ip_lockout') {
            $ip=trim((string)($_POST['ip_address']??''));if(!filter_var($ip,FILTER_VALIDATE_IP))throw new InvalidArgumentException('Select a valid IP address.');
            login_rate_limit_clear($ip);log_activity((int)$u['id'],'Cleared login lockout','security','IP '.$ip);$exceptionSuccess='Lockout released. Historical failed attempts are preserved.';
        } elseif ($action === 'clear_all_lockouts') {
            foreach((new SecurityMonitoringService($db))->lockouts() as $locked)login_rate_limit_clear($locked['ip_address']);
            log_activity((int)$u['id'],'Cleared active login lockouts','security');$exceptionSuccess='Active lockouts released; failed-attempt history is preserved.';
        } elseif ($action === 'incident') {
            (new SecurityIncidentService($db))->transition((int)$u['id'],(int)($_POST['event_id']??0),(string)($_POST['stage']??''),(string)($_POST['notes']??''));
            $exceptionSuccess='Security incident transition recorded.';
        } elseif ($action === 'open') {
            $service->open(
                (int)$u['id'],
                (int)($_POST['user_id'] ?? 0),
                trim($_POST['reason'] ?? ''),
                trim($_POST['evidence'] ?? '')
            );
            $exceptionSuccess = 'Security exception recorded with audit history.';
        } elseif ($action === 'resolve') {
            $service->resolve(
                (int)$u['id'],
                (int)($_POST['exception_id'] ?? 0),
                $_POST['decision'] ?? '',
                trim($_POST['notes'] ?? '')
            );
            $exceptionSuccess = 'Exception disposition recorded with audit history.';
        } else {
            throw new InvalidArgumentException('Unsupported exception action.');
        }
    } catch (PDOException $e) {
        error_log('Security exception: ' . $e->getMessage());
        $exceptionError = 'The exception could not be saved. No account change was committed.';
    } catch (RuntimeException | InvalidArgumentException $e) {
        $exceptionError = $e->getMessage();
    }
}

// -------------------------------------------------------------
// Data for Security Overview
// -------------------------------------------------------------
$totalFailed24h = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();

$lockedIps = (new SecurityMonitoringService($db))->lockouts();

$totalAdmins = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();

$openExceptions = (int)$db->query("SELECT COUNT(*) FROM account_security_exceptions WHERE status = 'open'")->fetchColumn();

$recentSuspicious = (new SecurityMonitoringService($db))->events();

// -------------------------------------------------------------
// Exception History
// -------------------------------------------------------------
$exceptionRows = $db->query("
    SELECT x.*, u.username AS account_username, u.role AS account_role,
           o.username AS opened_by_name
    FROM account_security_exceptions x
    JOIN users u ON u.id = x.user_id
    JOIN users o ON o.id = x.opened_by
    ORDER BY x.status = 'open' DESC, x.id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// Account selector list for the exception form (exclude primary admin and self)
$selectableAccounts = $db->query("
    SELECT id, username, email, role, status, admin_type
    FROM users
    WHERE status IN ('active', 'inactive', 'pending')
    ORDER BY username
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
  <style>
    .security-kpi { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; min-width: 0; }
    .security-kpi__card { background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius-lg, 16px); box-shadow: var(--shadow-sm); padding: 16px 18px; min-width: 0; }
    .security-kpi__label { display: block; font-size: 0.78rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-secondary); margin-bottom: 8px; }
    .security-kpi__value { display: block; font-size: 1.75rem; font-weight: 700; line-height: 1.2; color: var(--text-primary); }
    .security-kpi__sub { display: block; margin-top: 6px; font-size: 0.8rem; color: var(--text-secondary); }
    .security-kpi__sub a { color: var(--primary); text-decoration: underline; }
    .security-section { background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius-lg, 16px); box-shadow: var(--shadow-sm); margin-top: 20px; min-width: 0; overflow: hidden; }
    .security-section__head { padding: 16px 18px; border-bottom: 1px solid var(--border); background: var(--surface-light, #f8fafc); display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .security-section__title { font-size: 0.9rem; font-weight: 700; color: var(--text-primary); margin: 0; }
    .security-section__body { padding: 16px 18px; min-width: 0; }
    .status-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 0.82rem; font-weight: 600; }
    .status-open { background: #fdf3e3; color: #9a6210; border: 1px solid #f3d3a8; }
    .status-resolved { background: #e7f7ee; color: #1e7a3a; border: 1px solid #bfe6cf; }
    .account-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
    .account-chip__avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: grid; place-items: center; font-size: 0.85rem; font-weight: 700; flex: 0 0 32px; }
    .empty-state { text-align: center; padding: 32px 16px; color: var(--text-secondary); }
    @media (max-width: 575.98px) {
      .security-kpi { grid-template-columns: 1fr; }
      .security-section__head { flex-direction: column; align-items: flex-start; gap: 8px; }
    }
  </style>
</head>
<body class="mis-admin-page">
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <div class="app-page-head d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h5 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-shield-lock-fill text-success"></i> Security
          </h5>
          <p class="text-muted small mb-0">Security monitoring, authentication incidents, and documented account security exceptions.</p>
        </div>
      </div>

      <section class="card p-3 mb-3"><h2 class="h6">Security incident review</h2><p class="small text-muted">Routine activation and ordinary validation events remain informational. Repeated failures or unauthorized access may warrant an incident. Stages: Detected, Under Review, Action Taken, Resolved. Notes are appended to audit history.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="incident"><div class="row g-2"><div class="col-md-4"><label for="incident-event" class="form-label">Event</label><select id="incident-event" name="event_id" class="form-select" required><option value="">Select suspicious event</option><?php foreach($recentSuspicious as $event): if(!in_array($event['severity'],['medium','high','critical'],true))continue; ?><option value="<?=$event['id']?>"><?=e('#'.$event['id'].' '.$event['event_type'].' / '.$event['severity'])?></option><?php endforeach; ?></select></div><div class="col-md-3"><label for="incident-stage" class="form-label">Stage</label><select id="incident-stage" name="stage" class="form-select"><option value="detected">Detected</option><option value="under_review">Under Review</option><option value="action_taken">Action Taken</option><option value="resolved">Resolved</option></select></div><div class="col-md-5"><label for="incident-notes" class="form-label">Findings / outcome</label><input id="incident-notes" name="notes" class="form-control" minlength="15" maxlength="255" required></div></div><button class="btn btn-outline-primary mt-2">Record incident review</button></form><div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Event</th><th>Severity</th><th>Stage</th><th>Reviewer</th><th>Outcome</th><th>Updated</th></tr></thead><tbody><?php try {$incidents=$db->query('SELECT i.*,u.username reviewer FROM security_incidents i LEFT JOIN users u ON u.id=i.reviewer_id ORDER BY i.id DESC LIMIT 30')->fetchAll(); foreach($incidents as $incident): ?><tr><td>#<?=$incident['event_id']?></td><td><?=e($incident['severity'])?></td><td><?=e($incident['stage'])?></td><td><?=e($incident['reviewer'])?></td><td><?=e($incident['outcome'])?></td><td><?=e($incident['updated_at'])?></td></tr><?php endforeach; if(!$incidents)echo '<tr><td colspan="6">No incidents recorded.</td></tr>'; }catch(PDOException $e){echo '<tr><td colspan="6">Incident storage is unavailable. Apply the reviewed MIS audit migration before incident recording.</td></tr>';} ?></tbody></table></div></section>
      <?php if ($exceptionError): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span><?= e($exceptionError) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($exceptionSuccess): ?>
        <div class="alert alert-success py-2 small d-flex align-items-center gap-2 mb-3" role="alert">
          <i class="bi bi-check-circle-fill"></i>
          <span><?= e($exceptionSuccess) ?></span>
        </div>
      <?php endif; ?>

      <!-- Security Overview KPIs -->
      <div class="security-kpi mb-4">
        <div class="security-kpi__card">
          <span class="security-kpi__label">Failed Logins (24h)</span>
          <span class="security-kpi__value"><?= $totalFailed24h ?></span>
          <span class="security-kpi__sub">Authentication attempts failed in the last 24 hours</span>
        </div>
        <div class="security-kpi__card">
          <span class="security-kpi__label">Active Account / IP Lockouts</span>
          <span class="security-kpi__value text-<?= count($lockedIps) > 0 ? 'danger' : 'dark' ?>"><?= count($lockedIps) ?></span>
          <span class="security-kpi__sub"><?= count($lockedIps) > 0 ? 'Exceeded threshold in 15 min' : 'No active IP lockouts' ?></span>
        </div>
        <div class="security-kpi__card">
          <span class="security-kpi__label">Active Administrators</span>
          <span class="security-kpi__value"><?= $totalAdmins ?></span>
          <span class="security-kpi__sub"><a href="role_profiles.php" class="text-success text-decoration-none">Manage Privileges &rarr;</a></span>
        </div>
        <div class="security-kpi__card">
          <span class="security-kpi__label">Unresolved Security Exceptions</span>
          <span class="security-kpi__value text-<?= $openExceptions > 0 ? 'danger' : 'dark' ?>"><?= $openExceptions ?></span>
          <span class="security-kpi__sub">Open documented account security cases</span>
        </div>
      </div>

      <!-- Authentication Security -->
      <section class="security-section">
        <div class="security-section__head">
          <h6 class="security-section__title"><i class="bi bi-person-badge me-1"></i> Authentication Security</h6>
        </div>
        <div class="security-section__body">
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <div class="card p-3 shadow-sm border-0 rounded-3">
                <h6 class="fw-bold text-dark mb-2">Failed Login History (24h)</h6>
                <?php $recentAttempts = $db->query("
                    SELECT ip_address, username, attempted_at
                    FROM login_attempts
                    ORDER BY attempted_at DESC
                    LIMIT 10
                ")->fetchAll(PDO::FETCH_ASSOC); ?>
                <?php if (!$recentAttempts): ?>
                  <div class="empty-state"><i class="bi bi-shield-check fs-4 text-success d-block mb-2"></i>No failed login attempts recorded in the last 24 hours.</div>
                <?php else: ?>
                  <table class="table table-hover align-middle mb-0 small">
                    <thead><tr><th>IP Address</th><th>Targeted Account</th><th>Attempted At</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentAttempts as $attempt): ?>
                      <tr>
                        <td><code><?= e($attempt['ip_address']) ?></code></td>
                        <td><?= e($attempt['username']) ?></td>
                        <td class="text-muted"><?= e(format_datetime($attempt['attempted_at'])) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </div>
            <div class="col-md-6">
              <div class="card p-3 shadow-sm border-0 rounded-3">
                <h6 class="fw-bold text-dark mb-2">Active Account / IP Lockouts</h6>
                <?php if (empty($lockedIps)): ?>
                  <div class="empty-state"><i class="bi bi-shield-check fs-4 text-success d-block mb-2"></i>No IP addresses are currently locked out.</div>
                <?php else: ?>
                  <table class="table table-hover align-middle mb-0 small">
                    <thead><tr><th>IP Address</th><th>Attempts</th><th>Targeted Accounts</th><th>Last Attempt</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($lockedIps as $lip): ?>
                      <tr>
                        <td><code><?= e($lip['ip_address']) ?></code></td>
                        <td><span class="badge bg-danger"><?= $lip['attempt_count'] ?> failures</span></td>
                        <td class="small text-muted"><?= e($lip['targeted_accounts'] ?: 'N/A') ?></td>
                        <td class="small text-muted"><?= e(format_datetime($lip['last_attempt'])) ?></td>
                        <td class="text-end">
                          <form method="POST" action="security.php" class="d-inline">
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
                  <div class="mt-2">
                    <form method="POST" action="security.php" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="clear_all_lockouts">
                      <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Clear all active IP lockouts?');">
                        <i class="bi bi-trash me-1"></i> Clear All Lockouts
                      </button>
                    </form>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <?php if (!empty($recentSuspicious)): ?>
          <div class="card p-3 shadow-sm border-0 rounded-3 mt-3">
            <h6 class="fw-bold text-dark mb-2">Recent Security Events (24h)</h6>
            <table class="table table-hover align-middle mb-0 small">
              <thead><tr><th>Event Type / Severity</th><th>Subject</th><th>Details</th><th>Occurred At</th></tr></thead>
              <tbody>
              <?php foreach ($recentSuspicious as $evt): ?>
                <tr>
                  <td><?= e(str_replace('_', ' ', $evt['event_type'])) ?><div class="small"><?=e(ucfirst($evt['severity']))?> &middot; <?=(int)$evt['repetitions']?> event(s)</div></td>
                  <td><?= e($evt['user_id'] ? '#' . $evt['user_id'] : $evt['subject_key']) ?></td>
                  <td class="small text-muted"><?= e($evt['details']) ?></td>
                  <td class="small text-muted"><?= e(format_datetime($evt['occurred_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Account Security Exceptions -->
      <section class="security-section">
        <div class="security-section__head">
          <h6 class="security-section__title"><i class="bi bi-shield-exclamation me-1"></i> Account Security Exceptions</h6>
        </div>
        <div class="security-section__body">
          <p class="small text-muted mb-3">Documented identity review, suspension, or recovery. Record an evidence/case reference; do not paste passwords, OTPs, or confidential medical information.</p>

          <form method="post" class="card p-3 mb-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="open">
            <h6 class="fw-bold text-dark mb-3">Open a Documented Exception</h6>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Select Account <span class="text-danger">*</span></label>
                <select id="exception-account" name="user_id" class="form-select form-select-sm" required>
                  <option value="">Choose an account...</option>
                  <?php foreach ($selectableAccounts as $acct): ?>
                    <?php if ($acct['id'] === (int)$u['id']) continue; ?>
                    <option value="<?= (int)$acct['id'] ?>" data-role="<?= e($acct['role']) ?>" data-status="<?= e($acct['status']) ?>">
                      @<?= e($acct['username']) ?> (<?= e($acct['role']) ?>) &middot; <?= e($acct['status']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text small">Primary administrators and your own account are protected.</div>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Exception Type <span class="text-danger">*</span></label>
                <select id="exception-type" name="exception_type" class="form-select form-select-sm" required>
                  <option value="identity_review">Identity Review</option>
                  <option value="security_suspension">Security Suspension</option>
                  <option value="account_recovery">Account Recovery</option>
                  <option value="otp_retry">Restart Locked OTP</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Reason <span class="text-danger">*</span></label>
                <textarea id="exception-reason" name="reason" class="form-control" minlength="15" maxlength="2000" required rows="3"></textarea>
                <div class="form-text small">15–2000 characters. Describe the specific security concern.</div>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Evidence / Case Reference <span class="text-danger">*</span></label>
                <input id="exception-evidence" name="evidence" class="form-control" minlength="5" maxlength="255" required>
                <div class="form-text small">5–255 characters. Case ticket or evidence reference only.</div>
              </div>
              <div class="col-12">
                <button type="submit" class="btn btn-sm btn-primary align-self-start">Record Exception</button>
              </div>
            </div>
          </form>

          <!-- Exception History -->
          <h6 class="fw-bold text-dark mb-2 mt-4">Exception History</h6>
          <p class="small text-muted mb-3">Workflow: Open &rarr; Under Review &rarr; Resolved. All status changes are audit-logged.</p>
          <?php if (empty($exceptionRows)): ?>
            <div class="empty-state"><i class="bi bi-shield-check fs-4 text-success d-block mb-2"></i>No documented security exceptions.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                  <tr>
                    <th>Case Reference</th>
                    <th>Account</th>
                    <th>Exception Type</th>
                    <th>Status</th>
                    <th>Opened By</th>
                    <th>Opened At</th>
                    <th class="text-end">Disposition</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($exceptionRows as $x): ?>
                  <tr>
                    <td><span class="badge bg-secondary">#<?= (int)$x['id'] ?></span></td>
                    <td>
                      <div class="fw-bold text-dark">@<?= e($x['account_username']) ?></div>
                      <div class="small text-muted">#<?= (int)$x['user_id'] ?> &middot; <?= e($x['account_role']) ?></div>
                    </td>
                    <td><?= e(str_replace('_', ' ', $x['reason'] ?? '')) ?></td>
                    <td><span class="status-chip <?= $x['status'] === 'open' ? 'status-open' : 'status-resolved' ?>">
                      <?= ucfirst(e($x['status'])) ?>
                    </span></td>
                    <td><?= e($x['opened_by_name'] ?? '—') ?></td>
                    <td class="small text-muted"><?= e($x['opened_at']) ?></td>
                    <td class="text-end">
                      <?php if ($x['status'] === 'open'): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary resolve-btn" data-id="<?= (int)$x['id'] ?>">
                          Resolve
                        </button>
                      <?php else: ?>
                        <span class="badge bg-success-subtle text-success small">
                          <?= e($x['disposition'] ?? 'resolved') ?>
                        </span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <!-- Resolve modal -->
          <div class="modal fade" id="resolveExceptionModal" tabindex="-1" aria-labelledby="resolveExceptionModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content border-0 shadow">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="resolve">
                  <input type="hidden" name="exception_id" id="resolveExceptionId">
                  <div class="modal-header border-0">
                    <h6 class="modal-title fw-semibold" id="resolveExceptionModalLabel">Resolve Exception</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-3">
                      <label class="form-label small fw-semibold">Disposition <span class="text-danger">*</span></label>
                      <select name="decision" class="form-select form-select-sm" required>
                        <option value="">Select disposition</option>
                        <option value="no_change">Close without access change</option>
                        <option value="suspend">Security Suspension</option>
                        <option value="restore">Restore Verified Suspended Account</option>
                        <option value="verify">Identity and Role Eligibility Verified</option>
                        <option value="retry_verification">Restart Locked OTP after Security Review</option>
                        <option value="reject">Identity Review Failed</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label small fw-semibold">Verification Findings / Resolution Notes <span class="text-danger">*</span></label>
                      <textarea name="notes" class="form-control" minlength="15" maxlength="2000" required rows="4"></textarea>
                    </div>
                  </div>
                  <div class="modal-footer border-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Confirm Disposition</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
(function() {
  var resolveBtns = document.querySelectorAll('.resolve-btn');
  var modal = new bootstrap.Modal(document.getElementById('resolveExceptionModal'));
  var resolveId = document.getElementById('resolveExceptionId');
  resolveBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      resolveId.value = btn.dataset.id;
      modal.show();
    });
  });
})();
</script>
</body>
</html>
