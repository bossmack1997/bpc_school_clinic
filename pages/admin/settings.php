<?php
/**
 * pages/admin/settings.php
 * =====================================================================
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 * System Settings & Administrator Management Console
 * =====================================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$u = current_user();
$pageTitle = 'System Settings & Administrators';

$activeTab = $_GET['tab'] ?? 'general';

// -------------------------------------------------------------
// POST Request Actions (Settings Update & Admin Permissions)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    // 1. Update General System Settings
    if ($action === 'update_general_settings') {
        require_admin_permission('manage_settings');
        $schoolName    = trim($_POST['school_name'] ?? '');
        $clinicName    = trim($_POST['clinic_name'] ?? '');
        $schoolAddress = trim($_POST['school_address'] ?? '');
        $clinicContact = trim($_POST['clinic_contact'] ?? '');
        $themeMode     = trim($_POST['theme_mode'] ?? 'light');

        $settings = [
            'school_name'    => $schoolName,
            'clinic_name'    => $clinicName,
            'school_address' => $schoolAddress,
            'clinic_contact' => $clinicContact,
            'theme_mode'     => $themeMode,
        ];

        foreach ($settings as $k => $v) {
            $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            $stmt->execute([$k, $v]);
        }

        log_activity($u['id'], 'Updated institutional system settings', 'settings');
        set_flash('success', 'System configuration updated successfully.');
        redirect('settings.php?tab=general');
    }

    // 2. Update Co-Admin Permissions
    if ($action === 'update_admin_permissions') {
        // Only Primary Admin or admins with manage_settings can modify admin permissions
        if (!is_primary_admin($u)) {
            require_admin_permission('manage_settings');
        }

        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $permissions = $_POST['permissions'] ?? [];

        if ($targetUserId > 0) {
            $tStmt = $db->prepare("SELECT id, username, first_name, last_name, admin_type FROM users WHERE id = ? AND role = 'admin'");
            $tStmt->execute([$targetUserId]);
            $targetAdmin = $tStmt->fetch(PDO::FETCH_ASSOC);

            if ($targetAdmin) {
                // Prevent altering primary admin via permissions table
                if ($targetAdmin['admin_type'] !== 'primary_admin') {
                    // Clear existing
                    $db->prepare("DELETE FROM admin_permissions WHERE user_id = ?")->execute([$targetUserId]);

                    // Insert new permissions
                    $validPerms = ['manage_users', 'manage_sections', 'view_reports', 'manage_security', 'manage_settings', 'view_audit_logs'];
                    $insStmt = $db->prepare("INSERT INTO admin_permissions (user_id, permission_key, granted_by, granted_at) VALUES (?, ?, ?, NOW())");
                    
                    $grantedList = [];
                    foreach ($permissions as $pKey) {
                        if (in_array($pKey, $validPerms, true)) {
                            $insStmt->execute([$targetUserId, $pKey, $u['id']]);
                            $grantedList[] = $pKey;
                        }
                    }

                    $permStr = !empty($grantedList) ? implode(', ', $grantedList) : 'None';
                    log_activity($u['id'], "Updated permissions for Co-Admin @{$targetAdmin['username']}: [{$permStr}]", 'admin');
                    set_flash('success', "Permissions updated for Co-Admin {$targetAdmin['first_name']} {$targetAdmin['last_name']}.");
                }
            }
        }
        redirect('settings.php?tab=admins');
    }

    // 3. Transfer Primary Administrator Role (Succession)
    if ($action === 'transfer_primary_admin') {
        if (!is_primary_admin($u)) {
            set_flash('danger', 'Only the current Primary Administrator can transfer primary leadership.');
            redirect('settings.php?tab=admins');
        }

        $newPrimaryId = (int)($_POST['new_primary_id'] ?? 0);
        if ($newPrimaryId > 0 && $newPrimaryId !== (int)$u['id']) {
            $stmt = $db->prepare("SELECT id, username, first_name, last_name FROM users WHERE id = ? AND role = 'admin' AND status = 'active'");
            $stmt->execute([$newPrimaryId]);
            $successor = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($successor) {
                $db->beginTransaction();
                try {
                    // Demote current user to co-admin
                    $db->prepare("UPDATE users SET admin_type = 'co_admin' WHERE id = ?")->execute([$u['id']]);
                    // Promote successor to primary_admin
                    $db->prepare("UPDATE users SET admin_type = 'primary_admin' WHERE id = ?")->execute([$newPrimaryId]);

                    // Grant all permissions to former primary admin so they retain full operational co-admin access
                    $allPerms = ['manage_users', 'manage_sections', 'view_reports', 'manage_security', 'manage_settings', 'view_audit_logs'];
                    $db->prepare("DELETE FROM admin_permissions WHERE user_id = ?")->execute([$u['id']]);
                    $ins = $db->prepare("INSERT INTO admin_permissions (user_id, permission_key, granted_by, granted_at) VALUES (?, ?, ?, NOW())");
                    foreach ($allPerms as $ap) {
                        $ins->execute([$u['id'], $ap, $newPrimaryId]);
                    }

                    $db->commit();

                    log_activity($u['id'], "Transferred Primary Administrator authority to @{$successor['username']} ({$successor['first_name']} {$successor['last_name']})", 'admin');
                    // Update session
                    $_SESSION['user']['admin_type'] = 'co_admin';

                    set_flash('success', "Primary Administrator authority successfully transferred to {$successor['first_name']} {$successor['last_name']}.");
                } catch (Exception $e) {
                    $db->rollBack();
                    set_flash('danger', safe_error_message(
                        $e,
                        'Failed to transfer administrator authority. Please try again.',
                        'settings/transfer_authority'
                    ));
                }
            }
        }
        redirect('settings.php?tab=admins');
    }
}

// -------------------------------------------------------------
// Data Fetching
// -------------------------------------------------------------
// 1. Current System Settings
$settingsRows = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$schoolName    = $settingsRows['school_name'] ?? 'Bulacan Polytechnic College';
$clinicName    = $settingsRows['clinic_name'] ?? 'BPC Healthcare Clinic';
$schoolAddress = $settingsRows['school_address'] ?? 'Bulihan, City of Malolos, Bulacan';
$clinicContact = $settingsRows['clinic_contact'] ?? '(044) 791-2345';
$themeMode     = $settingsRows['theme_mode'] ?? 'light';

// 2. Administrators List
$adminAccounts = $db->query("
    SELECT id, username, first_name, last_name, email, admin_type, status, last_login, created_at
    FROM users
    WHERE role = 'admin'
    ORDER BY FIELD(admin_type, 'primary_admin', 'co_admin'), created_at ASC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Permissions Map for each Admin
$adminPermsMap = [];
$permRows = $db->query("SELECT user_id, permission_key FROM admin_permissions")->fetchAll(PDO::FETCH_ASSOC);
foreach ($permRows as $pr) {
    $adminPermsMap[$pr['user_id']][] = $pr['permission_key'];
}
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

      <!-- Header -->
      <div class="app-page-head d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
          <h5 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-gear-wide-connected text-success"></i> System Settings &amp; Governance
          </h5>
          <p class="text-muted small mb-0">Configure institutional clinic metadata, manage co-administrator access privileges, and maintain leadership succession.</p>
        </div>
      </div>

      <!-- Settings Navigation Tabs -->
      <div class="d-flex gap-2 mb-3 border-bottom pb-2">
        <a href="?tab=general" class="app-filter <?= $activeTab === 'general' ? "is-active" : "" ?>">
          <i class="bi bi-sliders"></i> General Settings
        </a>
        <a href="?tab=admins" class="app-filter <?= $activeTab === 'admins' ? "is-active" : "" ?>">
          <i class="bi bi-person-badge-fill"></i> Administrators Management
          <span class="badge <?= $activeTab === 'admins' ? 'bg-white text-dark' : 'bg-secondary' ?> ms-1"><?= count($adminAccounts) ?></span>
        </a>
      </div>

      <!-- TAB 1: GENERAL SETTINGS -->
      <?php if ($activeTab === 'general'): ?>
        <div class="row g-3">
          <div class="col-lg-8">
            <div class="card p-4 shadow-sm border-0 rounded-3">
              <h6 class="fw-bold text-dark mb-3"><i class="bi bi-building text-success me-1"></i> Institutional Clinic Configuration</h6>
              <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_general_settings">

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label small fw-semibold">School Name</label>
                    <input type="text" class="form-control" name="school_name" value="<?= e($schoolName) ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small fw-semibold">Clinic / Healthcare Unit</label>
                    <input type="text" class="form-control" name="clinic_name" value="<?= e($clinicName) ?>" required>
                  </div>
                  <div class="col-12">
                    <label class="form-label small fw-semibold">Institutional Campus Address</label>
                    <input type="text" class="form-control" name="school_address" value="<?= e($schoolAddress) ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small fw-semibold">Clinic Contact Number / Hotline</label>
                    <input type="text" class="form-control" name="clinic_contact" value="<?= e($clinicContact) ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small fw-semibold">Interface Default Theme</label>
                    <select class="form-select" name="theme_mode">
                      <option value="light" <?= $themeMode === 'light' ? 'selected' : '' ?>>Light Theme (Standard)</option>
                      <option value="dark" <?= $themeMode === 'dark' ? 'selected' : '' ?>>Dark Theme</option>
                    </select>
                  </div>
                </div>

                <div class="mt-4 pt-3 border-top">
                  <button type="submit" class="btn btn-success"><i class="bi bi-check-circle me-1"></i> Save Configuration</button>
                </div>
              </form>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card p-3 shadow-sm border-0 rounded-3">
              <h6 class="fw-bold text-dark mb-2"><i class="bi bi-info-circle text-primary me-1"></i> System Environment</h6>
              <div class="small">
                <div class="d-flex justify-content-between py-1 border-bottom">
                  <span class="text-muted">Application:</span>
                  <span class="fw-semibold text-dark"><?= APP_NAME ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom">
                  <span class="text-muted">Database Engine:</span>
                  <span class="fw-semibold text-dark">MySQL / MariaDB</span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom">
                  <span class="text-muted">PHP Runtime:</span>
                  <span class="fw-semibold text-dark">PHP <?= phpversion() ?></span>
                </div>
                <div class="d-flex justify-content-between py-1 border-bottom">
                  <span class="text-muted">Environment:</span>
                  <span class="badge bg-success">Active Production / Localhost</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- TAB 2: ADMINISTRATORS MANAGEMENT -->
      <?php if ($activeTab === 'admins'): ?>
        <div class="card p-3 shadow-sm border-0 rounded-3 mb-4">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h6 class="fw-bold text-dark mb-1"><i class="bi bi-shield-shaded text-success me-1"></i> Active Administrator Accounts</h6>
              <p class="text-muted small mb-0">Manage Primary and Co-Administrator roles, permission matrices, and succession continuity.</p>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Administrator</th>
                  <th>Designation</th>
                  <th>Assigned Permissions</th>
                  <th>Status</th>
                  <th>Last Active</th>
                  <th class="text-end">Privileges</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$adminAccounts): ?>
                  <tr>
                    <td colspan="6">
                      <div class="empty-state">
                        <div class="empty-state__icon"><i class="bi bi-shield-lock" aria-hidden="true"></i></div>
                        <h6 class="empty-state__title">No administrator accounts found</h6>
                        <p class="empty-state__text">Administrator accounts will appear here once they are created.</p>
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
                <?php foreach ($adminAccounts as $adm): ?>
                  <tr>
                    <td>
                      <div class="fw-bold text-dark"><?= e($adm['first_name'] . ' ' . $adm['last_name']) ?></div>
                      <span class="text-muted small">@<?= e($adm['username']) ?> · <?= e($adm['email']) ?></span>
                    </td>
                    <td>
                      <?php if ($adm['admin_type'] === 'primary_admin'): ?>
                        <span class="badge bg-primary px-2 py-1"><i class="bi bi-star-fill me-1"></i> Primary Administrator</span>
                      <?php else: ?>
                        <span class="badge bg-secondary px-2 py-1">Co-Administrator</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($adm['admin_type'] === 'primary_admin'): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Full Root Governance</span>
                      <?php else: ?>
                        <?php 
                          $pList = $adminPermsMap[$adm['id']] ?? [];
                          if (empty($pList)):
                        ?>
                          <span class="text-muted small italic">Standard Overview</span>
                        <?php else: ?>
                          <?php foreach ($pList as $p): ?>
                            <span class="app-badge app-badge--primary"><?= str_replace('_', ' ', $p) ?></span>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="badge bg-<?= $adm['status'] === 'active' ? 'success' : 'warning' ?>">
                        <?= ucfirst($adm['status']) ?>
                      </span>
                    </td>
                    <td class="small text-muted">
                      <?= !empty($adm['last_login']) ? format_datetime($adm['last_login']) : 'Never' ?>
                    </td>
                    <td class="text-end">
                      <?php if ($adm['admin_type'] !== 'primary_admin'): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="openPermissionsModal(<?= $adm['id'] ?>, '<?= e(addslashes($adm['first_name'] . ' ' . $adm['last_name'])) ?>', <?= htmlspecialchars(json_encode($adminPermsMap[$adm['id']] ?? [])) ?>)">
                          <i class="bi bi-shield-check me-1"></i> Permissions
                        </button>
                      <?php else: ?>
                        <span class="text-muted small">Primary Account</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Primary Admin Succession Transfer (Only visible to Primary Admin) -->
        <?php if (is_primary_admin($u)): ?>
          <div class="card p-3 shadow-sm border-0 rounded-3 border-start border-primary border-4">
            <h6 class="fw-bold text-dark mb-1"><i class="bi bi-arrow-left-right text-primary me-1"></i> Primary Administrator Succession</h6>
            <p class="text-muted small mb-3">Designate an active Co-Administrator as the new Primary Administrator. Your account will automatically remain an authorized Co-Administrator with full delegated access.</p>
            
            <form method="POST" onsubmit="return confirm('Are you certain you wish to transfer the Primary Administrator role? This action is logged immediately.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="transfer_primary_admin">
              
              <div class="row g-2 align-items-center">
                <div class="col-md-6">
                  <select name="new_primary_id" class="form-select form-select-sm" required>
                    <option value="">Select Successor Co-Administrator...</option>
                    <?php foreach ($adminAccounts as $adm): ?>
                      <?php if ($adm['id'] != $u['id'] && $adm['status'] === 'active'): ?>
                        <option value="<?= $adm['id'] ?>"><?= e($adm['first_name'] . ' ' . $adm['last_name']) ?> (@<?= e($adm['username']) ?>)</option>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-auto">
                  <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-check2-circle me-1"></i> Transfer Primary Role
                  </button>
                </div>
              </div>
            </form>
          </div>
        <?php endif; ?>

      <?php endif; ?>

    </div>
  </div>
</div>

<!-- Modal: Co-Admin Permissions Management -->
<div class="modal fade" id="permissionsModal" tabindex="-1" aria-labelledby="permissionsModalTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_admin_permissions">
        <input type="hidden" name="target_user_id" id="permTargetUserId">

<div class="modal-header">
            <h6 class="modal-title" id="permissionsModalTitle"><?= bpc_icon('shield-lock') ?> Configure Co-Admin Permissions</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p class="app-muted mb-3">Set delegated governance permissions for <strong id="permTargetUserName">Admin</strong>:</p>

          <div class="form-check mb-2">
            <input class="form-check-input perm-chk" type="checkbox" name="permissions[]" value="manage_users" id="chk_manage_users">
            <label class="form-check-label small" for="chk_manage_users">
              <strong>User Management</strong> — Suspend, reactivate, and reset accounts
            </label>
          </div>

          <div class="form-check mb-2">
            <input class="form-check-input perm-chk" type="checkbox" name="permissions[]" value="manage_sections" id="chk_manage_sections">
            <label class="form-check-label small" for="chk_manage_sections">
              <strong>Sections &amp; Classes</strong> — Configure sections and class advisers
            </label>
          </div>

          <div class="form-check mb-2">
            <input class="form-check-input perm-chk" type="checkbox" name="permissions[]" value="manage_security" id="chk_manage_security">
            <label class="form-check-label small" for="chk_manage_security">
              <strong>Security Oversight</strong> — Manage IP lockouts and rate-limiting
            </label>
          </div>

          <div class="form-check mb-2">
            <input class="form-check-input perm-chk" type="checkbox" name="permissions[]" value="view_reports" id="chk_view_reports">
            <label class="form-check-label small" for="chk_view_reports">
              <strong>Reporting &amp; Analytics</strong> — Export institutional clinic reports
            </label>
          </div>

          <div class="form-check mb-2">
            <input class="form-check-input perm-chk" type="checkbox" name="permissions[]" value="manage_settings" id="chk_manage_settings">
            <label class="form-check-label small" for="chk_manage_settings">
              <strong>System Settings</strong> — Modify institutional parameters
            </label>
          </div>

          <div class="form-check mb-2">
            <input class="form-check-input perm-chk" type="checkbox" name="permissions[]" value="view_audit_logs" id="chk_view_audit_logs">
            <label class="form-check-label small" for="chk_view_audit_logs">
              <strong>Audit Logs</strong> — Inspect system audit trails
            </label>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-success">Save Permissions</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
function openPermissionsModal(id, name, permissions) {
  document.getElementById('permTargetUserId').value = id;
  document.getElementById('permTargetUserName').textContent = name;

  // Reset checkboxes
  document.querySelectorAll('.perm-chk').forEach(chk => {
    chk.checked = permissions.includes(chk.value);
  });

  BPCModal.show('permissionsModal');
}
</script>
</body>
</html>
