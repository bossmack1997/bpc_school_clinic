<?php
/**
 * pages/admin/admin_accounts.php
 * =====================================================================
 * Admin Accounts Management
 * Create and manage administrator accounts from the Admin Dashboard only.
 * Only Primary Administrators can create/modify admin accounts.
 * Public registration never exposes the Admin role.
 * =====================================================================
 */
require_once __DIR__ . '/../../config/config.php';
require_primary_admin();
require_login();
if(permission_profile()!=='mis' || !has_permission('manage_users'))admin_access_denied('MIS account-provisioning permission required.');

$db = getDB();
$u = current_user();

// Permission gate: only Primary Administrator can manage admin accounts
if (!is_primary_admin($u)) {
    set_flash('danger', 'Access denied: Only the Primary Administrator can manage admin accounts.');
    redirect(APP_URL . '/pages/admin/dashboard.php');
}

$pageTitle = 'Admin Accounts';

$errors = [];
$success = '';
function doctor_old(string $key): string {return isset($_POST[$key])&&is_scalar($_POST[$key])?e(trim((string)$_POST[$key])):'';}

require_once __DIR__.'/../../includes/doctor_invitation_service.php';
$loadMisAdminStyles=true;
$isInvitationAjax=strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest';
$_SESSION['doctor_invite_nonce']=$_SESSION['doctor_invite_nonce']??bin2hex(random_bytes(32));
if($_SERVER['REQUEST_METHOD']==='POST') {
    $statusCode=422;
    if(!is_string($_POST['csrf_token']??null) || !csrf_verify($_POST['csrf_token'])) {$errors[]='This form expired. Refresh the page and try again.';$statusCode=403;}
    else {
        try {
            if(!is_string($_POST['request_nonce']??null) || !hash_equals($_SESSION['doctor_invite_nonce'],$_POST['request_nonce'])) {$statusCode=409;throw new DomainException('This form was already submitted or expired. Refresh the page before retrying.');}
            $service=new DoctorInvitationService($db,null,static function(): void {
                // Persist replay protection before SMTP and release the session lock.
                $_SESSION['doctor_invite_nonce']=bin2hex(random_bytes(32));
                session_write_close();
            });
            $action=$_POST['action']??'';
            if($action==='invite_doctor')$result=$service->create((int)$u['id'],$_POST);
            elseif($action==='resend_invitation' && is_scalar($_POST['user_id']??null))$result=$service->resend((int)$u['id'],(int)$_POST['user_id']);
            else throw new InvalidArgumentException('Unsupported invitation action.');
            if(session_status()!==PHP_SESSION_ACTIVE)session_start();
            unset($_SESSION['manual_doctor_invitation']);
            $message=$result['delivery_status']==='sent'?'Invitation accepted by the mail service. Ask the appointee to check their inbox.':'The account remains pending. Email delivery was not confirmed; use Resend invitation after checking mail availability.';
            set_flash($result['delivery_status']==='sent'?'success':'warning',$message);
            if($isInvitationAjax){http_response_code($result['delivery_status']==='sent'?200:202);json_response(true,['redirect'=>'admin_accounts.php','delivery_status'=>$result['delivery_status']],$message);}
            redirect('admin_accounts.php');
        }catch(InvalidArgumentException $e){$statusCode=422;$errors[]=$e->getMessage();}
        catch(DomainException $e){$statusCode=409;$errors[]=$e->getMessage();}
        catch(PDOException $e){error_log('Doctor invitation database request failed; code='.(string)$e->getCode());$statusCode=$e->getCode()==='23000'?409:503;$errors[]=$statusCode===409?'An account already uses this identity, or its invitation is no longer eligible. Refresh the account list.':'Invitation storage is unavailable. Refresh the account list before retrying.';}
        catch(Throwable $e){error_log('Doctor invitation request failed; code='.(string)$e->getCode());$statusCode=503;$errors[]='The invitation request could not be completed. Check the current account status before retrying.';}
    }
    http_response_code($statusCode);
    if($isInvitationAjax)json_response(false,[],implode(' ',$errors));
}
// Fetch all admin accounts
try {
$stmt = $db->query("
    SELECT u.id, u.role, u.admin_type, u.username, u.email, u.first_name, u.middle_name, u.last_name, u.phone,
           u.status, u.created_at, u.last_login, p.profile, p.official_designation, p.appointment_reference,
           (SELECT ai.delivery_status FROM admin_invitations ai WHERE ai.user_id=u.id ORDER BY ai.id DESC LIMIT 1) invitation_status
    FROM users u
    LEFT JOIN admin_profiles p ON p.user_id=u.id
    WHERE u.role = 'admin'
    ORDER BY u.admin_type DESC, u.created_at DESC
    LIMIT 500
");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e){http_response_code(503);$admins=[];$errors[]='Doctor invitation storage is unavailable. Ask the operator to check the Phase 1 schema before sending invitations.';error_log('Doctor invitation list unavailable; code='.(string)$e->getCode());}

unset($_SESSION['manual_doctor_invitation']);
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
    <div class="content-area page-enter fade-in">

      <!-- Flash messages -->
      <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?> py-2 mb-3 d-flex align-items-center gap-2">
          <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>
          <span><?= e($flash['message']) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="alert alert-danger py-2 mb-3 d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
              <li><?= e($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- Hero / Header -->
      <div class="app-page-head">
        <div class="app-page-head__text">
          <div class="app-page-head__eyebrow">System</div>
          <h2 class="app-page-head__title">Administrator Management</h2>
          <p class="app-page-head__sub">Manage MIS continuity and verified Doctor clinical appointments without sharing unrestricted privileges.</p>
        </div>
        <div class="app-page-head__aside">
          <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAdminModal">
            <i class="bi bi-plus-lg me-1"></i> Add Doctor / Clinical Co-Administrator
          </button>
        </div>
      </div>

      <!-- Summary Stats -->
      <div class="app-stats app-stats--4 mb-4">
        <div class="dbg-stat dbg-stat--green">
          <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-shield-lock"></i></div></div>
          <div class="dbg-stat__value"><?= count($admins) ?></div>
          <div class="dbg-stat__label">Total Admins</div>
        </div>
        <div class="dbg-stat dbg-stat--teal">
          <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-person-check"></i></div></div>
          <div class="dbg-stat__value"><?= count(array_filter($admins, fn($a) => $a['status'] === 'active')) ?></div>
          <div class="dbg-stat__label">Active</div>
        </div>
        <div class="dbg-stat dbg-stat--orange">
          <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-person-x"></i></div></div>
          <div class="dbg-stat__value"><?= count(array_filter($admins, fn($a) => $a['status'] === 'inactive')) ?></div>
          <div class="dbg-stat__label">Inactive</div>
        </div>
        <div class="dbg-stat dbg-stat--blue">
          <div class="dbg-stat__top"><div class="dbg-stat__icon"><i class="bi bi-star"></i></div></div>
          <div class="dbg-stat__value"><?= count(array_filter($admins, fn($a) => $a['admin_type'] === 'primary_admin')) ?></div>
          <div class="dbg-stat__label">Primary Admin</div>
        </div>
      </div>

      <!-- Admin Accounts Table -->
      <div class="app-card">
        <div class="app-card__body app-card__body--flush">
          <div class="table-responsive">
            <table class="table mb-0" id="adminAccountsTable">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email / Username</th>
                  <th>Designation / Role</th>
                  <th>Verification</th>
                  <th>Status</th>
                    <th>Last Login</th>
                    <th class="text-end">Actions</th>
                  </tr>
            </thead>
            <tbody>
              <?php if (empty($admins)): ?>
                <tr>
                  <td colspan="7">
                    <div class="empty-state">
                      <div class="empty-state__icon"><i class="bi bi-shield-lock"></i></div>
                      <h6 class="empty-state__title">No administrator accounts found</h6>
                      <p class="empty-state__text">Administrative appointments will appear here.</p>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($admins as $admin): ?>
                <tr>
                  <td>
                    <div class="fw-medium"><?= e($admin['first_name'] . ' ' . ($admin['middle_name'] ? $admin['middle_name'] . ' ' : '') . $admin['last_name']) ?></div>
                  </td>
                  <td>
                    <div class="small"><?= e($admin['email']) ?></div>
                    <div class="text-muted" style="font-size:0.72rem;">@<?= e($admin['username']) ?></div>
                  </td>
                  <td>
                    <?php if ($admin['admin_type'] === 'primary_admin'): ?>
                      <div class="small fw-semibold"><?= e($admin['official_designation'] ?: 'MIS / IT Personnel') ?></div><span class="app-badge app-badge--primary"><i class="bi bi-star-fill me-1"></i> MIS Main Administrator</span>
                    <?php elseif (($admin['profile'] ?? '') === 'doctor'): ?>
                      <div class="small fw-semibold"><?= e($admin['official_designation'] ?: 'Doctor') ?></div><span class="app-badge app-badge--info"><i class="bi bi-heart-pulse me-1"></i> Clinical Co-Administrator</span>
                    <?php else: ?>
                      <span class="app-badge app-badge--neutral"><i class="bi bi-person-badge me-1"></i> Legacy Administrator</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="app-badge app-badge--<?= ($admin['profile'] ?? '') === 'doctor' && $admin['invitation_status'] === 'sent' ? 'info' : (($admin['profile'] ?? '') === 'mis' ? 'success' : 'neutral') ?>"><?= e(($admin['profile'] ?? '') === 'mis' ? 'Verified' : ucfirst($admin['invitation_status'] ?: 'Recorded')) ?></span></td>
                  <td>
                    <span class="app-badge app-badge--<?= $admin['status'] === 'active' ? 'success' : 'neutral' ?>"><?= e(ucfirst($admin['status'])) ?></span>
                  </td>
                  <td>
                    <div class="small"><?= $admin['last_login'] ? e(format_date($admin['last_login'], true)) : '<span class="text-muted">Never</span>' ?></div>
                  </td>
                  <td class="text-end pe-2">
                    <?php if ($admin['admin_type'] !== 'primary_admin' && (int)$admin['id'] !== (int)$u['id']): ?>
                      <a class="btn btn-sm btn-outline-secondary" href="account_exceptions.php">Security exception</a>
                      <?php if(($admin['profile']??'')==='doctor' && $admin['status']==='pending'): ?>
                      <form method="post" class="doctor-resend-form d-inline"><input type="hidden" name="action" value="resend_invitation"><input type="hidden" name="user_id" value="<?=(int)$admin['id']?>"><input type="hidden" name="request_nonce" value="<?=e($_SESSION['doctor_invite_nonce'])?>"><?=csrf_field()?><button class="btn btn-sm btn-outline-primary" type="submit">Resend invitation</button></form>
                      <?php endif; ?>
                    <?php elseif ($admin['admin_type'] === 'primary_admin'): ?>
                      <span class="text-muted small"><i class="bi bi-lock me-1"></i> Protected</span>
                    <?php else: ?>
                      <span class="text-muted small"><i class="bi bi-person-check me-1"></i> Current Session</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Add Admin Modal -->
<div class="modal fade" id="addAdminModal" tabindex="-1" aria-hidden="true" aria-labelledby="addAdminModalTitle" data-reopen="<?=$errors?'true':'false'?>">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <form method="POST" action="<?=e(app_url('pages/admin/admin_accounts.php'))?>" id="addAdminForm" class="modal-content border-0 shadow">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="invite_doctor"><input type="hidden" name="request_nonce" value="<?=e($_SESSION['doctor_invite_nonce'])?>">
        <div class="modal-header border-0" style="background: linear-gradient(135deg, rgba(11, 107, 58, 0.05), rgba(11, 107, 58, 0.03));">
          <h6 class="modal-title fw-semibold" id="addAdminModalTitle"><i class="bi bi-person-plus-fill me-1 text-primary"></i> Add Doctor / Clinical Co-Administrator</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close invitation form"></button>
        </div>
        <div class="modal-body"><div id="doctorInvitationFeedback" class="alert alert-danger small" role="alert" aria-live="polite" hidden></div>
          <p class="text-muted small mb-3">Provision a clinical-only account. The Doctor sets their own password through an expiring, single-use invitation; MIS never sees it.</p>

          <div class="row g-2">
            <div class="col-12"><h3 class="h6 mb-1">Personal information</h3></div>
            <div class="col-md-6">
              <label for="doctor-first_name" class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
              <input type="text" id="doctor-first_name" name="first_name" value="<?=doctor_old('first_name')?>" class="form-control form-control-sm" required maxlength="80" placeholder="First name">
            </div>
            <div class="col-md-6">
              <label for="doctor-last_name" class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
              <input type="text" id="doctor-last_name" name="last_name" value="<?=doctor_old('last_name')?>" class="form-control form-control-sm" required maxlength="80" placeholder="Last name">
            </div>
            <div class="col-12">
              <label for="doctor-middle_name" class="form-label small fw-semibold">Middle Name</label>
              <input type="text" id="doctor-middle_name" name="middle_name" value="<?=doctor_old('middle_name')?>" class="form-control form-control-sm" maxlength="80" placeholder="Optional">
            </div>
            <div class="col-12">
              <label for="doctor-email" class="form-label small fw-semibold">Email Address <span class="text-danger">*</span></label>
              <input type="email" id="doctor-email" name="email" maxlength="120" value="<?=doctor_old('email')?>" class="form-control form-control-sm" required placeholder="admin@bpc.edu.ph">
            </div>
            <div class="col-12">
              <label for="doctor-username" class="form-label small fw-semibold">Username <span class="text-danger">*</span></label>
              <input type="text" id="doctor-username" name="username" value="<?=doctor_old('username')?>" class="form-control form-control-sm" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_]+" placeholder="admin_username">
              <div class="form-text small">Letters, numbers, underscores (3-50 chars).</div>
            </div>
            <div class="col-12">
              <label for="doctor-contact_number" class="form-label small fw-semibold">Contact Number</label>
              <input type="text" id="doctor-contact_number" name="contact_number" value="<?=doctor_old('contact_number')?>" class="form-control form-control-sm" maxlength="30" placeholder="e.g. 09123456789">
            </div>
            <div class="col-12"><h3 class="h6 mt-2 mb-1">Professional appointment</h3></div>
            <div class="col-md-6">
              <label for="doctor-official_designation" class="form-label small fw-semibold">Professional Designation <span class="text-danger">*</span></label>
              <input type="text" id="doctor-official_designation" name="official_designation" value="<?=doctor_old('official_designation')?>" class="form-control form-control-sm" required maxlength="150" placeholder="School Physician">
            </div>
            <div class="col-md-6">
              <label for="doctor-professional_license" class="form-label small fw-semibold">Professional License Information</label>
              <input type="text" id="doctor-professional_license" name="professional_license" value="<?=doctor_old('professional_license')?>" class="form-control form-control-sm" maxlength="150" placeholder="License / registration reference">
            </div>
            <div class="col-md-8">
              <label for="doctor-appointment_reference" class="form-label small fw-semibold">Appointment Reference <span class="text-danger">*</span></label>
              <input type="text" id="doctor-appointment_reference" name="appointment_reference" value="<?=doctor_old('appointment_reference')?>" class="form-control form-control-sm" required maxlength="255" placeholder="Approved memorandum / board reference">
            </div>
            <div class="col-md-4">
              <label for="doctor-verification_status" class="form-label small fw-semibold">Verification Status</label>
              <select id="doctor-verification_status" name="verification_status" class="form-select form-select-sm" required>
                <option value="" <?=($_POST['verification_status']??'pending')==='pending'?'selected':''?>>Not verified</option>
                <option value="verified" <?=($_POST['verification_status']??'')==='verified'?'selected':''?>>Verified appointment</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-success">Send Invitation</button>
        </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../components/footer.php'; ?>
<script src="<?=asset_url('js/mis-admin.js',true)?>"></script>
</body>
</html>
