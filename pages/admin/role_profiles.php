<?php
/**
 * pages/admin/role_profiles.php
 * =====================================================================
 * Administrator Management — MIS / Doctor account permissions,
 * verified appointments, and succession history.
 * =====================================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/admin_profile_service.php';
require_primary_admin();
$db = getDB();
$u = current_user();
$pageTitle = 'Administrator Management';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    try {
        if (empty($_POST['confirmed'])) {
            throw new InvalidArgumentException('Confirm identity and official appointment verification.');
        }
        $s = new AdminProfileService($db);
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['target'] ?? 0);
        $ref = trim($_POST['reference'] ?? '');
        $pw = $_POST['current_password'] ?? '';
        if ($action === 'assign') {
            $s->assign((int)$u['id'], $id, $_POST['profile'] ?? '', $ref, trim($_POST['license'] ?? ''), $pw);
            $success = 'Verified administrative profile assigned. Affected account must sign in again.';
        } elseif ($action === 'succeed') {
            $s->succeed((int)$u['id'], $id, $ref, $pw);
            $success = 'MIS succession recorded. You have been demoted to Co-Administrator. Please sign in again.';
            redirect(APP_URL . '/auth/login.php');
        } elseif ($action === 'departure') {
            $s->departure((int)$u['id'], $id, $_POST['effective'] ?? '', $ref, $pw);
            $success = 'Authorized departure scheduled. Medical and account history are preserved.';
        } else {
            throw new InvalidArgumentException('Unsupported operation.');
        }
        set_flash('success', $success);
        redirect('role_profiles.php');
    } catch (PDOException $e) {
        error_log('Profile transition: ' . $e->getMessage());
        $error = 'The transition was not committed.';
    } catch (RuntimeException | InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

// Apply any due scheduled departures
try {
    (new AdminProfileService($db))->applyDue();
} catch (Throwable $e) {
    error_log('applyDue: ' . $e->getMessage());
}

$admins = $db->query("
    SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.status, u.admin_type,
           u.email_verified, u.last_login, u.created_at,
           COALESCE(p.profile, 'legacy') AS profile,
           p.appointment_reference, p.clinical_registration, p.verified_by, p.verified_at
    FROM users u
    LEFT JOIN admin_profiles p ON p.user_id = u.id
    WHERE u.role = 'admin'
    ORDER BY FIELD(u.admin_type, 'primary_admin', 'co_admin'), u.created_at ASC
")->fetchAll();

$history = $db->query('
    SELECT h.*, a.username AS actor_username, t.username AS target_username
    FROM admin_profile_history h
    JOIN users a ON a.id = h.actor_id
    JOIN users t ON t.id = h.user_id
    ORDER BY h.id DESC
    LIMIT 25
')->fetchAll();

// Resolve role labels for display
function adminRoleLabel(string $type, ?string $profile): string {
    if ($type === 'primary_admin') {
        return 'Primary Administrator';
    }
    if ($profile === 'mis') {
        return 'MIS / Technical Administrator';
    }
    if ($profile === 'doctor') {
        return 'Doctor / Clinical Co-Administrator';
    }
    return 'Co-Administrator';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
  <style>
    .admin-card { background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius-lg, 16px); box-shadow: var(--shadow-sm); overflow: hidden; }
    .admin-card__head { padding: 16px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .admin-card__title { font-size: 0.9rem; font-weight: 700; color: var(--text-primary); margin: 0; }
    .admin-card__body { padding: 16px 18px; min-width: 0; }
    .profile-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 0.82rem; font-weight: 600; }
    .profile-mis { background: #e7f7ee; color: #1e7a3a; border: 1px solid #bfe6cf; }
    .profile-doctor { background: #e3f2fd; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .profile-legacy { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    .profile-unknown { background: #fbeaea; color: #b3261e; border: 1px solid #f5c2c2; }
    .account-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
    .account-chip__avatar { width: 32px; height: 32px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: grid; place-items: center; font-size: 0.85rem; font-weight: 700; flex: 0 0 32px; }
    .empty-state { text-align: center; padding: 32px 16px; color: var(--text-secondary); }
    .succession-note { background: #fbeaea; border: 1px solid #f5c2c2; border-radius: 12px; padding: 14px 16px; margin-top: 16px; font-size: 0.85rem; color: #8a1f18; }
    @media (max-width: 575.98px) {
      .admin-card__head { flex-direction: column; align-items: flex-start; gap: 8px; }
    }
  </style>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <div class="app-page-head d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h5 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-shield-check text-success"></i> Administrator Management
          </h5>
          <p class="text-muted small mb-0">MIS / Doctor account permissions, verified appointments, and succession history. Verified school appointment required before administrative permissions are assigned.</p>
        </div>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-3" role="alert">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success py-2 small d-flex align-items-center gap-2 mb-3" role="alert">
          <i class="bi bi-check-circle-fill"></i>
          <span><?= e($success) ?></span>
        </div>
      <?php endif; ?>

      <!-- Administrator Roster -->
      <section class="admin-card mb-4">
        <div class="admin-card__head">
          <h6 class="admin-card__title"><i class="bi bi-people me-1"></i> Administrator Accounts</h6>
        </div>
        <div class="admin-card__body">
          <?php if (empty($admins)): ?>
            <div class="empty-state"><i class="bi bi-shield-lock fs-4 d-block mb-2"></i>No administrator accounts found.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                  <tr>
                    <th>Administrator</th>
                    <th>Role</th>
                    <th>Profile</th>
                    <th>Status</th>
                    <th>Verified Appointment</th>
                    <th>Last Sign-in</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($admins as $a): ?>
                  <tr>
                    <td>
                      <div class="fw-bold text-dark"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></div>
                      <div class="small text-muted">@<?= e($a['username']) ?> &middot; <?= e($a['email']) ?></div>
                    </td>
                    <td><?= adminRoleLabel($a['admin_type'], $a['profile']) ?></td>
                    <td>
                      <span class="profile-chip <?= $a['profile'] === 'mis' ? 'profile-mis' : ($a['profile'] === 'doctor' ? 'profile-doctor' : ($a['profile'] === 'legacy' ? 'profile-legacy' : 'profile-unknown')) ?>">
                        <?= ucfirst(e($a['profile'])) ?>
                      </span>
                    </td>
                    <td>
                      <span class="badge bg-<?= $a['status'] === 'active' ? 'success' : 'warning' ?>">
                        <?= ucfirst(e($a['status'])) ?>
                      </span>
                    </td>
                    <td>
                      <?php if (!empty($a['appointment_reference'])): ?>
                        <div class="small text-dark"><?= e($a['appointment_reference']) ?></div>
                        <div class="small text-muted">Verified <?= e($a['verified_at']) ?></div>
                      <?php else: ?>
                        <span class="text-muted small">Not verified</span>
                      <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                      <?= !empty($a['last_login']) ? e(format_datetime($a['last_login'])) : 'Never' ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <!-- Administrator Appointment / Assignment -->
      <section class="admin-card mb-4">
        <div class="admin-card__head">
          <h6 class="admin-card__title"><i class="bi bi-person-plus me-1"></i> Assign Verified Administrator Profile</h6>
        </div>
        <div class="admin-card__body">
          <p class="small text-muted mb-3">Assign a verified administrator profile (MIS / Doctor / Legacy) to an active Co-Administrator. Requires current MIS Primary password and official appointment reference.</p>
          <form method="post" class="card p-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign">
            <input type="hidden" name="confirmed" value="1">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Target Account ID <span class="text-danger">*</span></label>
                <input id="profile-target" name="target" type="number" min="1" required class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Verified Profile <span class="text-danger">*</span></label>
                <select id="profile-choice" name="profile" class="form-select form-select-sm" required>
                  <option value="mis">MIS / Technical Administrator</option>
                  <option value="doctor">Doctor / Clinical Co-Administrator</option>
                  <option value="legacy">Legacy / Standard Co-Admin</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Professional Registration (Doctor only)</label>
                <input id="profile-license" name="license" type="text" class="form-control" placeholder="PRC License / Registration">
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-semibold">Official Appointment / Authority Reference <span class="text-danger">*</span></label>
                <input id="profile-reference" name="reference" type="text" class="form-control" minlength="10" maxlength="255" required placeholder="School appointment authority">
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-semibold">Current MIS Primary Password <span class="text-danger">*</span></label>
                <input id="profile-password" name="current_password" type="password" class="form-control" required>
              </div>
              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="confirm_action" id="confirm-assign" required>
                  <label class="form-check-label small" for="confirm-assign">
                    I confirm the school appointment and identity of the target administrator.
                  </label>
                </div>
              </div>
              <div class="col-12">
                <button type="submit" class="btn btn-sm btn-success">Assign Verified Profile</button>
              </div>
            </div>
          </form>
        </div>
      </section>

      <!-- Authorized Departure -->
      <section class="admin-card mb-4">
        <div class="admin-card__head">
          <h6 class="admin-card__title"><i class="bi bi-person-dash me-1"></i> Authorized Administrator Departure</h6>
        </div>
        <div class="admin-card__body">
          <p class="small text-muted mb-3">Schedule the verified departure of a Co-Administrator (not Primary). The outgoing account is deactivated at the approved effective date. Medical and account history are preserved.</p>
          <form method="post" class="card p-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="departure">
            <input type="hidden" name="confirmed" value="1">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Target Account ID <span class="text-danger">*</span></label>
                <input id="departure-target" name="target" type="number" min="1" required class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Effective Date / Time <span class="text-danger">*</span></label>
                <input id="departure-effective" name="effective" type="datetime-local" required class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Authority Reference <span class="text-danger">*</span></label>
                <input id="departure-reference" name="reference" type="text" class="form-control" minlength="10" maxlength="255" required placeholder="School authorization reference">
              </div>
              <div class="col-md-6">
                <label class="form-label small fw-semibold">Current MIS Primary Password <span class="text-danger">*</span></label>
                <input id="departure-password" name="current_password" type="password" class="form-control" required>
              </div>
              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="confirm_action" id="confirm-departure" required>
                  <label class="form-check-label small" for="confirm-departure">
                    I confirm this authorized administrator departure.
                  </label>
                </div>
              </div>
              <div class="col-12">
                <button type="submit" class="btn btn-sm btn-outline-danger">Schedule Departure</button>
              </div>
            </div>
          </form>
        </div>
      </section>

      <!-- MIS Succession -->
      <section class="admin-card mb-4">
        <div class="admin-card__head">
          <h6 class="admin-card__title"><i class="bi bi-arrow-repeat me-1"></i> Verified MIS Succession</h6>
        </div>
        <div class="admin-card__body">
          <p class="small text-muted mb-3">Transfer Primary MIS responsibility to a separately verified MIS Co-Administrator. The Doctor is never promoted automatically. The requesting administrator cannot approve their own elevation.</p>
          <div class="succession-note">
            <strong>Requirements:</strong> authorized school appointment, verified identity, confirmation of incoming MIS personnel, approval independent of the requesting administrator, secure permission assignment, and audit logging.
          </div>
          <form method="post" class="card p-3 mt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="succeed">
            <input type="hidden" name="confirmed" value="1">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Successor Account ID <span class="text-danger">*</span></label>
                <input id="succession-target" name="target" type="number" min="1" required class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Official Appointment Reference <span class="text-danger">*</span></label>
                <input id="succession-reference" name="reference" type="text" class="form-control" minlength="10" maxlength="255" required placeholder="School succession authority">
              </div>
              <div class="col-md-4">
                <label class="form-label small fw-semibold">Current MIS Primary Password <span class="text-danger">*</span></label>
                <input id="succession-password" name="current_password" type="password" class="form-control" required>
              </div>
              <div class="col-12">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="confirm_action" id="confirm-succession" required>
                  <label class="form-check-label small" for="confirm-succession">
                    I confirm the successor is separately verified and assigned an active MIS profile.
                  </label>
                </div>
              </div>
              <div class="col-12">
                <button type="submit" class="btn btn-sm btn-primary">Transfer Primary MIS Responsibility</button>
              </div>
            </div>
          </form>
        </div>
      </section>

      <!-- Succession / Profile History -->
      <section class="admin-card">
        <div class="admin-card__head">
          <h6 class="admin-card__title"><i class="bi bi-clock-history me-1"></i> Administrator Succession History</h6>
        </div>
        <div class="admin-card__body">
          <?php if (empty($history)): ?>
            <div class="empty-state"><i class="bi bi-clock-history fs-4 d-block mb-2"></i>No administrator succession or profile transitions recorded.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                  <tr>
                    <th>Target Account</th>
                    <th>Previous Profile</th>
                    <th>New Profile</th>
                    <th>Authority Reference</th>
                    <th>Actor</th>
                    <th>Recorded At</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                  <tr>
                    <td>
                      <div class="fw-bold text-dark">@<?= e($h['target_username']) ?></div>
                      <div class="small text-muted">#<?= (int)$h['user_id'] ?></div>
                    </td>
                    <td><?= e($h['previous_profile']) ?></td>
                    <td><?= e($h['new_profile']) ?></td>
                    <td class="small text-muted"><?= e($h['reference']) ?></td>
                    <td><?= e($h['actor_username']) ?></td>
                    <td class="small text-muted"><?= e($h['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
