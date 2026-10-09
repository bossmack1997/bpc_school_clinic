<?php
/**
 * pages/admin/users.php
 * =====================================================================
 * ADMIN People Management (Consolidated Users + Approvals)
 * Unified interface for managing all system accounts with tabs:
 *   All | Pending | Active | Inactive
 *   - Role-based filtering
 *   - Bulk actions for pending accounts
 *   - Quick status toggle
 *   - QR status visibility
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$db = getDB();
$u = current_user();
$canViewUsers = is_primary_admin($u)
    || has_admin_permission('manage_users', $u, $db)
    || has_admin_permission('approve_users', $u, $db);
if (!$canViewUsers) {
    set_flash('danger', 'Access denied: you do not have permission to view user accounts.');
    redirect(APP_URL . '/pages/admin/dashboard.php');
}
$pageTitle = 'People Management';

// Get active tab from URL
$activeTab = $_GET['tab'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';

// Handle POST actions (approve/reject/bulk/toggle)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    // This page performs several mutations directly instead of calling the
    // users API, so enforce the same co-administrator permissions here.
    $requiredPermission = match ($action) {
        'approve_account', 'reject_account', 'bulk_approve' => 'approve_users',
        'toggle_status' => 'manage_users',
        default => null,
    };
    if ($requiredPermission !== null && !is_primary_admin($u)) {
        $allowed = has_admin_permission($requiredPermission, $u, $db);
        if (!$allowed && in_array($action, ['approve_account', 'reject_account', 'bulk_approve'], true)) {
            $allowed = has_admin_permission('manage_users', $u, $db);
        }
        if (!$allowed) {
            set_flash('danger', 'Access denied: you do not have permission to manage user accounts.');
            redirect('users.php');
        }
    }

    if ($action === 'approve_account') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $stmt = $db->prepare("SELECT username, first_name, last_name, role, status FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($targetUser) {
                if ($targetUser['status'] !== 'pending') {
                    set_flash('warning', 'Only pending accounts can be approved.');
                    redirect('users.php?tab=pending');
                }
                if ($targetUser['role'] === 'admin' && !is_primary_admin($u)
                    && !has_admin_permission('approve_admins', $u, $db)) {
                    set_flash('danger', 'Access denied: you do not have permission to approve administrator accounts.');
                    redirect('users.php?tab=pending');
                }
                $db->prepare("UPDATE users SET status = 'active', registration_status = 'approved', validation_reason = 'Approved by Administrator' WHERE id = ?")->execute([$userId]);
                
                // If student, ensure QR is generated
                if ($targetUser['role'] === 'student') {
                    $stId = $db->query("SELECT id FROM students WHERE user_id = {$userId}")->fetchColumn();
                    if ($stId) {
                        ensure_student_qr($db, (int)$stId, $userId);
                    }
                }

                log_activity($u['id'], "Approved pending account for {$targetUser['first_name']} {$targetUser['last_name']} (@{$targetUser['username']})", 'admin');
                set_flash('success', "Account for {$targetUser['first_name']} {$targetUser['last_name']} successfully approved and activated.");
            }
        }
        redirect('users.php?tab=pending');
    }

    if ($action === 'reject_account') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? 'Did not meet institutional verification criteria.');
        if ($userId > 0) {
            $stmt = $db->prepare("SELECT username, first_name, last_name, role, status FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($targetUser) {
                if ($targetUser['status'] !== 'pending') {
                    set_flash('warning', 'Only pending accounts can be rejected.');
                    redirect('users.php?tab=pending');
                }
                if ($targetUser['role'] === 'admin' && !is_primary_admin($u)
                    && !has_admin_permission('approve_admins', $u, $db)) {
                    set_flash('danger', 'Access denied: you do not have permission to reject administrator accounts.');
                    redirect('users.php?tab=pending');
                }
                $db->prepare("UPDATE users SET status = 'rejected', registration_status = 'rejected', rejection_reason = ? WHERE id = ?")->execute([$reason, $userId]);
                log_activity($u['id'], "Rejected registration for {$targetUser['first_name']} {$targetUser['last_name']} (@{$targetUser['username']}). Reason: {$reason}", 'admin');
                set_flash('info', "Registration for {$targetUser['first_name']} {$targetUser['last_name']} has been rejected.");
            }
        }
        redirect('users.php?tab=pending');
    }

    if ($action === 'bulk_approve') {
        $userIds = $_POST['user_ids'] ?? [];
        if (is_array($userIds) && !empty($userIds)) {
            $count = 0;
            foreach ($userIds as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) {
                    $targetStmt = $db->prepare("SELECT role, admin_type FROM users WHERE id = ? AND status = 'pending'");
                    $targetStmt->execute([$uid]);
                    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$target || ($target['role'] === 'admin' && !is_primary_admin($u)
                        && !has_admin_permission('approve_admins', $u, $db))) {
                        continue;
                    }
                    $approveStmt = $db->prepare("UPDATE users SET status = 'active', registration_status = 'approved' WHERE id = ? AND status = 'pending'");
                    $approveStmt->execute([$uid]);
                    if ($approveStmt->rowCount() !== 1) {
                        continue;
                    }
                    $studentStmt = $db->prepare("SELECT id FROM students WHERE user_id = ?");
                    $studentStmt->execute([$uid]);
                    $stId = $studentStmt->fetchColumn();
                    if ($stId) {
                        ensure_student_qr($db, (int)$stId, $uid);
                    }
                    $count++;
                }
            }
            log_activity($u['id'], "Bulk approved {$count} pending user registrations", 'admin');
            set_flash('success', "Successfully approved {$count} account(s).");
        }
        redirect('users.php?tab=pending');
    }

    if ($action === 'toggle_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($userId > 0 && in_array($newStatus, ['active', 'inactive'], true)) {
            $targetStmt = $db->prepare("SELECT role, admin_type FROM users WHERE id = ?");
            $targetStmt->execute([$userId]);
            $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
            if ($target && $userId !== (int)$u['id']
                && ($target['role'] !== 'admin' || (is_primary_admin($u) && $target['admin_type'] !== 'primary_admin'))
                && ($target['role'] !== 'admin' || has_admin_permission('approve_admins', $u, $db))) {
                $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $userId]);
                log_activity($u['id'], "Changed user status to {$newStatus}", 'admin');
                set_flash('success', "User status updated to {$newStatus}.");
            } else {
                set_flash('danger', 'This account cannot be modified with your current permissions.');
            }
        }
        redirect('users.php?tab=' . $activeTab);
    }
}

// Build query with filters
$where = ['1=1'];
$params = [];

if ($activeTab === 'pending') {
    $where[] = "u.status = 'pending'";
} elseif ($activeTab === 'active') {
    $where[] = "u.status = 'active'";
} elseif ($activeTab === 'inactive') {
    $where[] = "u.status IN ('inactive', 'rejected')";
// 'all' = no status filter
}

if ($roleFilter !== '') {
    $where[] = "u.role = ?";
    $params[] = $roleFilter;
}

if ($search !== '') {
    $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR COALESCE(s.student_number, n.employee_id, t.employee_id) LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

$sql = "
    SELECT u.*, 
           COALESCE(s.student_number, n.employee_id, t.employee_id) AS identifier,
           s.course, s.year_level, s.section,
           n.license_number,
           t.department AS teacher_department,
           sq.qr_token IS NOT NULL AS has_qr
    FROM users u
    LEFT JOIN students s ON s.user_id = u.id
    LEFT JOIN nurses n ON n.user_id = u.id
    LEFT JOIN teachers t ON t.user_id = u.id
    LEFT JOIN student_qrs sq ON sq.student_id = s.id AND sq.status IN ('active', 'regenerated')
    WHERE " . implode(' AND ', $where) . "
    ORDER BY FIELD(u.role, 'student', 'teacher', 'nurse', 'admin'), u.created_at DESC
";

// Cap the rendered set. The full list is still searched and filtered in
// the browser, but an unbounded fetchAll would render every account in the
// system into the DOM at once, which gets slow and unwieldy as the school
// grows. The admin accounts page uses server-side paging for the same reason.
$stmt = $db->prepare($sql . ' LIMIT 1000');
$stmt->execute($params);
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats for tab badges
$stats = $db->query("
    SELECT 
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status IN ('inactive', 'rejected') THEN 1 ELSE 0 END) AS inactive,
        COUNT(*) AS total
    FROM users
")->fetch(PDO::FETCH_ASSOC);
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
      
      <!-- 1. HERO BANNER -->
      <div class="app-page-head d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
          <h5 class="fw-bold text-dark mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-people-fill text-success"></i> People Management
          </h5>
          <p class="text-muted small mb-0">
            Unified oversight of all system accounts. Students are auto-approved post-OTP; staff accounts require admin review.
          </p>
        </div>
      </div>

      <!-- 2. NAVIGATION TABS (All | Pending | Active | Inactive) -->
      <div class="d-flex flex-wrap gap-2 mb-3 border-bottom pb-2">
        <a href="?tab=all" class="app-filter <?= $activeTab === 'all' ? "is-active" : "" ?>">
          All <span class="badge <?= $activeTab === 'all' ? 'bg-white text-dark' : 'bg-secondary' ?> ms-1"><?= $stats['total'] ?></span>
        </a>
        <a href="?tab=pending" class="app-filter <?= $activeTab === 'pending' ? "is-active" : "" ?>">
          <i class="bi bi-clock-history"></i> Pending <span class="badge <?= $activeTab === 'pending' ? 'bg-white text-dark' : 'bg-warning text-dark' ?> ms-1"><?= $stats['pending'] ?></span>
        </a>
        <a href="?tab=active" class="app-filter <?= $activeTab === 'active' ? "is-active" : "" ?>">
          <i class="bi bi-check-circle-fill"></i> Active <span class="badge <?= $activeTab === 'active' ? 'bg-white text-dark' : 'bg-success' ?> ms-1"><?= $stats['active'] ?></span>
        </a>
        <a href="?tab=inactive" class="app-filter <?= $activeTab === 'inactive' ? "is-active" : "" ?>">
          <i class="bi bi-x-circle-fill"></i> Inactive <span class="badge <?= $activeTab === 'inactive' ? 'bg-white text-dark' : 'bg-secondary' ?> ms-1"><?= $stats['inactive'] ?></span>
        </a>
      </div>

      <!-- 3. ROLE FILTERS & SEARCH -->
      <div class="d-flex flex-wrap gap-2 mb-3 align-items-center justify-content-between">
        <div class="d-flex flex-wrap gap-1">
          <a href="?tab=<?= $activeTab ?>" class="app-filter <?= $roleFilter === '' ? "is-active" : "" ?>">
            All Roles <span class="badge <?= $roleFilter === '' ? 'bg-white text-dark' : 'bg-secondary' ?> ms-1"><?= $stats['total'] ?></span>
          </a>
          <a href="?tab=<?= $activeTab ?>&role=student" class="app-filter <?= $roleFilter === 'student' ? "is-active" : "" ?>">
            Students <span class="badge <?= $roleFilter === 'student' ? 'bg-white text-dark' : 'bg-secondary' ?> ms-1"><?= $db->query("SELECT COUNT(*) FROM users WHERE role='student'")->fetchColumn() ?></span>
          </a>
          <a href="?tab=<?= $activeTab ?>&role=teacher" class="app-filter <?= $roleFilter === 'teacher' ? "is-active" : "" ?>">
            Teachers <span class="badge <?= $roleFilter === 'teacher' ? 'bg-white text-dark' : 'bg-secondary' ?> ms-1"><?= $db->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher','adviser')")->fetchColumn() ?></span>
          </a>
          <a href="?tab=<?= $activeTab ?>&role=nurse" class="app-filter <?= $roleFilter === 'nurse' ? "is-active" : "" ?>">
            Nurses <span class="badge <?= $roleFilter === 'nurse' ? 'bg-white text-dark' : 'bg-secondary' ?> ms-1"><?= $db->query("SELECT COUNT(*) FROM users WHERE role='nurse'")->fetchColumn() ?></span>
          </a>
          <a href="?tab=<?= $activeTab ?>&role=admin" class="app-filter <?= $roleFilter === 'admin' ? "is-active" : "" ?>">
            Admins <span class="badge <?= $roleFilter === 'admin' ? 'bg-white text-dark' : 'bg-secondary' ?> ms-1"><?= $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() ?></span>
          </a>
        </div>

        <!-- Search Form -->
        <form method="GET" class="d-flex gap-2 align-items-center">
          <?php if ($activeTab !== 'all'): ?>
            <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
          <?php endif; ?>
          <?php if ($roleFilter !== ''): ?>
            <input type="hidden" name="role" value="<?= e($roleFilter) ?>">
          <?php endif; ?>
          <div class="input-group input-group-sm" style="width:280px;">
            <input type="text" name="search" class="form-control" placeholder="Search name, username, email, ID..." value="<?= e($search) ?>">
            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($search !== ''): ?>
              <a href="?tab=<?= $activeTab ?><?= $roleFilter ? '&role=' . $roleFilter : '' ?>" class="btn btn-outline-danger" title="Clear search"><i class="bi bi-x"></i></a>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- 4. BULK APPROVE FORM (only on Pending tab) -->
      <?php if ($activeTab === 'pending' && !empty($allUsers)): ?>
      <form method="POST" id="bulkApproveForm" class="mb-3">
        <input type="hidden" name="action" value="bulk_approve">
        <?= csrf_field() ?>
        <div class="d-flex justify-content-end">
          <button type="submit" class="btn btn-sm btn-success" id="bulkApproveBtn" disabled onclick="return confirm('Approve all selected accounts?');">
            <i class="bi bi-check-all me-1"></i> Approve Selected
          </button>
        </div>
      </form>
      <?php endif; ?>

      <!-- 5. PEOPLE TABLE -->
      <div class="card p-3 shadow-sm border-0 rounded-3 bg-white">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" id="usersTable" style="width:100%">
            <thead class="table-light">
              <tr>
                <?php if ($activeTab === 'pending'): ?>
                <th style="width:30px;"><input type="checkbox" id="selectAllCheckbox"></th>
                <?php else: ?>
                <th style="width:30px;"></th>
                <?php endif; ?>
                <th>User</th>
                <th>Role</th>
                <th>Identifier</th>
                <th>Institutional Email</th>
                <th>QR Status</th>
                <th>Status</th>
                <th>Registered</th>
                <th class="text-end pe-2">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allUsers as $usr): ?>
              <tr>
                <?php if ($activeTab === 'pending'): ?>
                <td><input type="checkbox" name="user_ids[]" value="<?= $usr['id'] ?>" form="bulkApproveForm" class="user-chk"></td>
                <?php else: ?>
                <td></td>
                <?php endif; ?>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <?php 
                      $avatar = (!empty($usr['profile_picture']) && $usr['profile_picture'] !== 'assets/images/default-avatar.png')
                        ? APP_URL . '/' . e($usr['profile_picture'])
                        : APP_URL . '/assets/images/default-avatar.png';
                    ?>
                    <img src="<?= $avatar ?>" alt="Avatar" class="app-avatar-sm flex-shrink-0">
                    <div>
                      <strong class="text-dark d-block"><?= e($usr['first_name'] . ' ' . $usr['last_name']) ?></strong>
                      <span class="text-muted small">@<?= e($usr['username']) ?></span>
                    </div>
                  </div>
                </td>
                <td>
                  <?php 
                    $badgeClass = match($usr['role']) {
                      'admin' => 'bg-dark',
                      'nurse' => 'bg-info text-dark',
                      'teacher', 'adviser' => 'bg-primary text-white',
                      default => 'bg-secondary'
                    };
                    $roleLabel = get_role_display_name($usr['role']);
                    if ($usr['role'] === 'admin') {
                      $roleLabel = ($usr['admin_type'] === 'primary_admin') ? 'Primary Admin' : 'Co-Admin';
                    }
                  ?>
                  <span class="badge <?= $badgeClass ?>"><?= e($roleLabel) ?></span>
                </td>
                <td>
                  <div class="small">
                    <strong><?= e($usr['identifier'] ?? '—') ?></strong>
                    <?php if (!empty($usr['course'])): ?>
                      <div class="text-muted" style="font-size:0.75rem;"><?= e($usr['course']) ?> <?= e($usr['year_level'] ?? '') ?></div>
                    <?php elseif (!empty($usr['teacher_department'])): ?>
                      <div class="text-muted" style="font-size:0.75rem;"><?= e($usr['teacher_department']) ?></div>
                    <?php elseif (!empty($usr['license_number'])): ?>
                      <div class="text-info fw-semibold" style="font-size:0.75rem;">PRC: <?= e($usr['license_number']) ?></div>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="small">
                    <span class="text-dark"><?= e($usr['email']) ?></span>
                    <?php if (!empty($usr['phone'])): ?>
                      <div class="text-muted" style="font-size:0.75rem;"><?= e($usr['phone']) ?></div>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <?php if ($usr['role'] === 'student'): ?>
                    <?php if ($usr['has_qr']): ?>
                      <span class="app-chip app-chip--ok"><i class="bi bi-qr-code-check"></i> QR Ready</span>
                    <?php else: ?>
                      <span class="app-chip app-chip--off"><i class="bi bi-qr-code-slash"></i> No QR</span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($usr['status'] === 'active'): ?>
                    <span class="badge bg-success">Active</span>
                  <?php elseif ($usr['status'] === 'pending'): ?>
                    <span class="badge bg-warning text-dark">Pending</span>
                  <?php elseif ($usr['status'] === 'rejected'): ?>
                    <span class="badge bg-danger">Rejected</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Inactive</span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted"><?= format_date($usr['created_at']) ?></td>
                <td class="text-end pe-2">
                  <div class="dropdown d-inline-block">
                    <button class="app-menu-btn" data-bs-toggle="dropdown" aria-expanded="false" title="Account Actions">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end app-menu">
                      <!-- Approve/Reject for pending -->
                      <?php if ($usr['status'] === 'pending'): ?>
                        <li>
                          <form method="POST" class="d-inline" onsubmit="return confirm('Approve this account?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="approve_account">
                            <input type="hidden" name="user_id" value="<?= $usr['id'] ?>">
                            <button type="submit" class="dropdown-item text-success fw-semibold">
                              <i class="bi bi-check-lg text-success"></i> Approve
                            </button>
                          </form>
                        </li>
                        <li>
                          <button type="button" class="dropdown-item text-danger fw-semibold" onclick="openRejectModal(<?= $usr['id'] ?>, '<?= e(addslashes($usr['first_name'] . ' ' . $usr['last_name'])) ?>')">
                            <i class="bi bi-x-lg text-danger"></i> Reject
                          </button>
                        </li>
                        <li><hr class="dropdown-divider my-1"></li>
                      <?php endif; ?>

                      <!-- View Details -->
                      <li>
                        <a class="dropdown-item view-user-btn" href="#" data-id="<?= $usr['id'] ?>">
                          <i class="bi bi-person-lines-fill text-primary"></i> View Details
                        </a>
                      </li>

                      <!-- View Activity -->
                      <li>
                        <a class="dropdown-item" href="activity_logs.php?search=<?= urlencode($usr['username']) ?>">
                          <i class="bi bi-clock-history text-secondary"></i> View Activity
                        </a>
                      </li>

                      <!-- Status Toggle (not for primary admin or self) -->
                      <?php if (!($usr['role'] === 'admin' && ($usr['admin_type'] ?? '') === 'primary_admin') && $usr['id'] != $u['id']): ?>
                        <li><hr class="dropdown-divider my-1"></li>
                        <?php if ($usr['status'] === 'active'): ?>
                          <li>
                            <form method="POST" class="d-inline">
                              <?= csrf_field() ?>
                              <input type="hidden" name="action" value="toggle_status">
                              <input type="hidden" name="user_id" value="<?= $usr['id'] ?>">
                              <input type="hidden" name="new_status" value="inactive">
                              <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Suspend this account?')">
                                <i class="bi bi-person-x"></i> Suspend
                              </button>
                            </form>
                          </li>
                        <?php elseif ($usr['status'] === 'inactive' || $usr['status'] === 'rejected'): ?>
                          <li>
                            <form method="POST" class="d-inline">
                              <?= csrf_field() ?>
                              <input type="hidden" name="action" value="toggle_status">
                              <input type="hidden" name="user_id" value="<?= $usr['id'] ?>">
                              <input type="hidden" name="new_status" value="active">
                              <button type="submit" class="dropdown-item text-success">
                                <i class="bi bi-person-check"></i> Reactivate
                              </button>
                            </form>
                          </li>
                        <?php endif; ?>
                      <?php endif; ?>
                    </ul>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($allUsers)): ?>
              <tr>
                <td colspan="<?= $activeTab === 'pending' ? '9' : '8' ?>" class="text-center text-muted py-5">
                  <i class="bi bi-people fs-2 text-muted d-block mb-2"></i>
                  <h6 class="fw-semibold text-dark mb-1">No accounts found.</h6>
                  <p class="text-muted small mb-0">No registered accounts match the selected filters or search query.</p>
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalTitle">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject_account">
        <input type="hidden" name="user_id" id="rejectUserId">
<div class="modal-header">
            <h6 class="modal-title" id="rejectModalTitle"><?= bpc_icon('user-x') ?> Reject Registration</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p class="app-muted mb-2">You are rejecting the registration application for <strong id="rejectUserName">User</strong>.</p>
            <label class="form-label" for="rejectionReason">Reason for Rejection</label>
            <textarea name="rejection_reason" id="rejectionReason" class="form-control" rows="3" required placeholder="Specify institutional discrepancy or reason..."></textarea>
          </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-danger">Confirm Rejection</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
const csrfToken = $('meta[name="csrf-token"]').attr('content');

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

// Bulk approve checkbox handling
document.addEventListener('DOMContentLoaded', function () {
  const masterChk = document.getElementById('selectAllCheckbox');
  const userChks = document.querySelectorAll('.user-chk');
  const bulkBtn = document.getElementById('bulkApproveBtn');

  function updateBulkBtn() {
    if (!bulkBtn) return;
    const anyChecked = Array.from(userChks).some(c => c.checked);
    bulkBtn.disabled = !anyChecked;
  }

  if (masterChk) {
    masterChk.addEventListener('change', function () {
      userChks.forEach(c => c.checked = masterChk.checked);
      updateBulkBtn();
    });
  }

  userChks.forEach(c => {
    c.addEventListener('change', updateBulkBtn);
  });
});

function openRejectModal(id, name) {
  document.getElementById('rejectUserId').value = id;
  document.getElementById('rejectUserName').textContent = name;
  BPCModal.show('rejectModal');
}

// View User Modal Handler
$(document).on('click', '.view-user-btn', function (e) {
  e.preventDefault();
  const id = $(this).data('id');
  BPCModal.show('viewUserModal');

  $('#viewUserModalBody').html('<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Loading user details...</div>');
  $('#viewModalFooterNote').empty();

  $.get(APP_URL + '/api/users.php', { action: 'get_user', id: id }, function (res) {
    if (!res.success || !res.data) {
      $('#viewUserModalBody').html('<div class="alert alert-danger">Failed to load user details: ' + escapeHtml(res.message || 'Unknown error') + '</div>');
      return;
    }

    const usr = res.data;
    const appUrl = APP_URL || window.APP_URL || '';
    const avatar = (usr.profile_picture && usr.profile_picture !== 'assets/images/default-avatar.png')
      ? appUrl + '/' + escapeHtml(usr.profile_picture)
      : appUrl + '/assets/images/default-avatar.png';

    const validIdDoc = usr.student_id_path || usr.nurse_id_path || usr.valid_id_path;

    let roleDisplay = usr.role.toUpperCase();
    if (usr.role === 'admin') {
      roleDisplay = (usr.admin_type === 'primary_admin') ? 'PRIMARY ADMINISTRATOR' : 'CO-ADMINISTRATOR';
    }

    let statusHtml = '';
    if (usr.status === 'active') {
      statusHtml = '<div class="alert alert-success d-flex align-items-center gap-2 py-2 px-3 mb-3 small"><i class="bi bi-check-circle-fill fs-5"></i><div><strong>System Status: Active</strong><br><span class="text-muted">Account is verified and in normal operating status. No administrative action required.</span></div></div>';
    } else if (usr.status === 'pending') {
      statusHtml = `<div class="alert alert-warning d-flex align-items-center gap-2 py-2 px-3 mb-3 small">
        <i class="bi bi-exclamation-octagon-fill fs-5"></i>
        <div>
          <strong>System Status: Needs Review</strong><br>
          <span>Flagged reason: ${escapeHtml(usr.validation_reason || 'Administrative verification required')}</span>
        </div>
      </div>`;
    } else {
      statusHtml = '<div class="alert alert-secondary d-flex align-items-center gap-2 py-2 px-3 mb-3 small"><i class="bi bi-pause-circle-fill fs-5"></i><div><strong>System Status: Inactive / Suspended</strong><br><span class="text-muted">User cannot sign in or perform transactions.</span></div></div>';
    }

    let html = `
      ${statusHtml}
      <div class="row g-3">
        <div class="col-md-4 text-center border-end pe-3">
          <img src="${avatar}" class="rounded-circle border mb-2 shadow-sm" style="width:100px;height:100px;object-fit:cover;">
          <h6 class="fw-bold mb-0 text-dark">${escapeHtml(usr.first_name + ' ' + (usr.middle_name ? usr.middle_name + ' ' : '') + usr.last_name)}</h6>
          <span class="text-muted small">@${escapeHtml(usr.username)}</span>
          <div class="mt-2">
            <span class="badge bg-dark">${escapeHtml(roleDisplay)}</span>
            <span class="badge ${usr.status === 'active' ? 'bg-success' : (usr.status === 'pending' ? 'bg-warning text-dark' : 'bg-secondary')} ms-1">${escapeHtml(usr.status.toUpperCase())}</span>
          </div>
        </div>
        <div class="col-md-8 ps-3">
          <h6 class="fw-semibold text-primary border-bottom pb-1 mb-2"><i class="bi bi-person-lines-fill me-1"></i> Account & Contact Details</h6>
          <div class="row g-2 small mb-3">
            <div class="col-6"><strong>Email:</strong> ${escapeHtml(usr.email)}</div>
            <div class="col-6"><strong>Phone:</strong> ${escapeHtml(usr.phone || 'N/A')}</div>
            <div class="col-6"><strong>Gender:</strong> ${escapeHtml(usr.gender || 'N/A')}</div>
            <div class="col-6"><strong>Date of Birth:</strong> ${escapeHtml(usr.date_of_birth || 'N/A')}</div>
            <div class="col-6"><strong>Registered:</strong> ${escapeHtml(usr.created_at || 'N/A')}</div>
            <div class="col-6"><strong>Last Login:</strong> ${escapeHtml(usr.last_login || 'Never')}</div>
          </div>

          <h6 class="fw-semibold text-primary border-bottom pb-1 mb-2"><i class="bi bi-building me-1"></i> Institutional Credentials</h6>
          <div class="row g-2 small mb-3">
            ${usr.student_number ? `<div class="col-6"><strong>Student No:</strong> ${escapeHtml(usr.student_number)}</div>` : ''}
            ${usr.course ? `<div class="col-6"><strong>Course & Year:</strong> ${escapeHtml(usr.course)} ${escapeHtml(usr.year_level || '')}</div>` : ''}
            ${usr.section ? `<div class="col-6"><strong>Section:</strong> ${escapeHtml(usr.section)}</div>` : ''}
            ${usr.teacher_employee_id ? `<div class="col-6"><strong>Teacher Employee ID:</strong> ${escapeHtml(usr.teacher_employee_id)}</div>` : ''}
            ${usr.teacher_department ? `<div class="col-6"><strong>Department:</strong> ${escapeHtml(usr.teacher_department)}</div>` : ''}
            ${usr.nurse_employee_id ? `<div class="col-6"><strong>Nurse Employee ID:</strong> ${escapeHtml(usr.nurse_employee_id)}</div>` : ''}
            ${usr.nurse_license_number ? `<div class="col-6"><strong>PRC License:</strong> ${escapeHtml(usr.nurse_license_number)}</div>` : ''}
          </div>

          ${validIdDoc ? `
            <h6 class="fw-semibold text-primary border-bottom pb-1 mb-2"><i class="bi bi-card-image me-1"></i> Verification Document</h6>
            <a href="${appUrl}/${escapeHtml(validIdDoc)}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-arrow-up me-1"></i> View Uploaded ID / Document</a>
          ` : '<div class="small text-muted">No uploaded verification document on file.</div>'}
        </div>
      </div>
    `;

    $('#viewUserModalBody').html(html);
  }).fail(function () {
    $('#viewUserModalBody').html('<div class="alert alert-danger">Failed to connect to server.</div>');
  });
});
</script>
</body>
</html>
