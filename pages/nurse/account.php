<?php
/**
 * pages/nurse/account.php
 * ------------------------------------------------------------------
 * Functional Basic Nurse Dashboard.
 * Serves as the active personal workspace for self-registered nurses
 * while clinical authorization is reviewed by an authorized Doctor.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../config/config.php';
require_login();
$user = current_user();

if ($user['role'] !== 'nurse') {
    redirect(dashboard_url_for($user['role']));
    exit;
}

// If already authorized, route directly to clinical dashboard
if (empty($user['self_service_only'])) {
    redirect(APP_URL . '/pages/nurse/dashboard.php');
    exit;
}

require_once __DIR__ . '/../../includes/nurse_verification_service.php';
$db = getDB();
$service = new NurseVerificationService($db, $user);
$nurseDetail = $service->getNurse((int)$user['id']);

$effectiveStatus = $nurseDetail['effective_status'] ?? 'pending';
$reviewNotes = $nurseDetail['clinical_review_notes'] ?? '';
$nurseName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

$pageTitle = 'Nurse Account Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
  <style>
    .nurse-badge-pending { background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; font-weight: 700; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; }
    .nurse-badge-info { background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-weight: 700; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; }
    .nurse-badge-authorized { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; font-weight: 700; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; }
    .nurse-badge-rejected { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; font-weight: 700; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; }
    .nurse-badge-revoked { background-color: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; font-weight: 700; padding: 4px 10px; border-radius: 20px; font-size: 0.8rem; }
    .credential-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
  </style>
</head>
<body class="nurse-account-page">
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <main id="mainContent" class="content-area py-3">

      <!-- Welcome Banner -->
      <div class="card border-0 shadow-sm mb-3" style="background: linear-gradient(135deg, #0b6b3a 0%, #15803d 100%); color: #fff;">
        <div class="card-body p-4">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
              <span class="badge bg-white text-success px-2 py-1 mb-2 fw-semibold">School Clinic Staff Portal</span>
              <h1 class="h3 mb-1 fw-bold text-white">Welcome, <?= e($nurseName) ?></h1>
              <p class="mb-0 text-white-50"><?= (!empty($nurseDetail['email_verified']) && ($nurseDetail['account_status'] ?? '') === 'active') ? 'Your email is verified and your basic account is active.' : 'Your account needs attention before clinical review can proceed. See the checklist below.' ?></p>
            </div>
            <div class="d-flex gap-2">
              <a href="profile.php" class="btn btn-sm btn-light text-success fw-semibold">
                <i class="bi bi-person me-1"></i>My Profile
              </a>
              <a href="settings.php" class="btn btn-sm btn-outline-light">
                <i class="bi bi-gear me-1"></i>Settings
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- Clinical Authorization Status Banner -->
      <section class="card border-0 shadow-sm mb-3">
        <div class="card-body p-4">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom pb-3 mb-3">
            <div>
              <span class="small text-muted text-uppercase fw-bold">Clinical Access Assessment</span>
              <h2 class="h5 mb-0 fw-bold">Professional Staff Authorization Status</h2>
            </div>
            <div>
              <?php if ($effectiveStatus === 'pending'): ?>
                <span class="nurse-badge-pending"><i class="bi bi-clock me-1"></i>Pending Doctor Verification</span>
              <?php elseif ($effectiveStatus === 'needs_information'): ?>
                <span class="nurse-badge-info"><i class="bi bi-info-circle me-1"></i>Action Required: Needs Information</span>
              <?php elseif ($effectiveStatus === 'authorized'): ?>
                <span class="nurse-badge-authorized"><i class="bi bi-check-circle me-1"></i>Authorized Staff</span>
              <?php elseif ($effectiveStatus === 'rejected'): ?>
                <span class="nurse-badge-rejected"><i class="bi bi-x-circle me-1"></i>Authorization Rejected</span>
              <?php elseif ($effectiveStatus === 'revoked'): ?>
                <span class="nurse-badge-revoked"><i class="bi bi-shield-x me-1"></i>Access Revoked</span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($effectiveStatus === 'pending'): ?>
            <div class="alert alert-warning border-0 bg-warning-subtle text-dark py-3 px-3 mb-3 rounded-3">
              <div class="d-flex gap-2">
                <i class="bi bi-hourglass-split fs-4 text-warning"></i>
                <div>
                  <h3 class="h6 fw-bold mb-1">Your registration is in the clinical review queue</h3>
                  <p class="small mb-0">Confidential patient records, consultations, medicine dispensing, and clinical functions require trusted staff authorization. An authorized School Clinic Doctor will review and verify your submitted credentials against school records.</p>
                </div>
              </div>
            </div>
            <p class="small text-muted mb-0">You do not need to contact MIS Administration. Once verified by the Doctor, your clinical modules (Clinic Queue, Medicine Inventory, Medical Records, Emergency Response) will activate automatically.</p>

          <?php elseif ($effectiveStatus === 'needs_information'): ?>
            <div class="alert alert-info border-0 bg-info-subtle text-dark py-3 px-3 mb-3 rounded-3">
              <div class="d-flex gap-2">
                <i class="bi bi-chat-left-dots-fill fs-4 text-info"></i>
                <div>
                  <h3 class="h6 fw-bold mb-1">Additional Information Requested by Doctor</h3>
                  <p class="small mb-2">The reviewing Doctor requested the following details or document clarification:</p>
                  <div class="p-2 bg-white rounded border text-dark fw-semibold small">
                    <?= e($reviewNotes ?: 'Please present your updated professional PRC card or clinic assignment order.') ?>
                  </div>
                </div>
              </div>
            </div>
            <p class="small text-muted mb-0">Please present the requested items to the Clinic Head or updating your profile information.</p>

          <?php elseif ($effectiveStatus === 'rejected'): ?>
            <div class="alert alert-danger border-0 bg-danger-subtle text-dark py-3 px-3 mb-3 rounded-3">
              <div class="d-flex gap-2">
                <i class="bi bi-x-octagon-fill fs-4 text-danger"></i>
                <div>
                  <h3 class="h6 fw-bold mb-1">Nurse Authorization Not Approved</h3>
                  <p class="small mb-1">Reason: <?= e($reviewNotes ?: 'Credentials could not be verified with school clinic assignment.') ?></p>
                  <p class="small text-muted mb-0">Your basic personal account remains active for personal clinic services, but clinical staff permissions are not granted.</p>
                </div>
              </div>
            </div>

          <?php elseif ($effectiveStatus === 'revoked'): ?>
            <div class="alert alert-secondary border-0 bg-light text-dark py-3 px-3 mb-3 rounded-3">
              <div class="d-flex gap-2">
                <i class="bi bi-shield-x fs-4 text-secondary"></i>
                <div>
                  <h3 class="h6 fw-bold mb-1">Clinical Access Revoked</h3>
                  <p class="small mb-1">Reason: <?= e($reviewNotes ?: 'Staff clinic assignment ended or credential re-evaluation.') ?></p>
                  <p class="small text-muted mb-0">Clinical operations access is currently restricted.</p>
                </div>
              </div>
            </div>
          <?php endif; ?>

        </div>
      </section>

      <section class="card border-0 shadow-sm mb-3"><div class="card-body p-4"><h2 class="h6 fw-bold">Your clinical review checklist</h2><ul class="list-unstyled small mb-0"><?php foreach ($nurseDetail['eligibility']['checks'] as $chk): ?><li class="mb-1"><?= $chk['ok'] === true ? '&#10004;' : ($chk['ok'] === false ? '&#10008;' : '&#9203;') ?> <strong><?= e($chk['label']) ?>:</strong> <?= e($chk['detail']) ?></li><?php endforeach; ?></ul><p class="small text-muted mt-2 mb-0">Email verification is completed with your one-time code; it cannot be waived. Institutional verification is a separate decision made by the Doctor.</p></div></section>

      <div class="row g-3">
        <!-- Professional Credentials Column -->
        <div class="col-lg-6">
          <section class="card border-0 shadow-sm h-100 p-4">
            <h3 class="h6 text-success fw-bold mb-3">
              <i class="bi bi-briefcase me-2"></i>Professional Profile
            </h3>
            <div class="row g-2">
              <div class="col-sm-6">
                <div class="p-2 border rounded bg-light">
                  <div class="small text-muted">PRC License Number</div>
                  <div class="fw-bold"><?= e($nurseDetail['license_number'] ?: 'Not provided') ?></div>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="p-2 border rounded bg-light">
                  <div class="small text-muted">Employee ID</div>
                  <div class="fw-bold"><?= e($nurseDetail['employee_id'] ?: 'Not provided') ?></div>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="p-2 border rounded bg-light">
                  <div class="small text-muted">Designation / Position</div>
                  <div class="fw-semibold"><?= e($nurseDetail['position'] ?: 'Not provided') ?></div>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="p-2 border rounded bg-light">
                  <div class="small text-muted">Specialization</div>
                  <div class="fw-semibold"><?= e($nurseDetail['specialization'] ?? 'General Clinic') ?></div>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="p-2 border rounded bg-light">
                  <div class="small text-muted">Contact Number</div>
                  <div class="fw-semibold"><?= e($nurseDetail['phone'] ?? 'Not provided') ?></div>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="p-2 border rounded bg-light">
                  <div class="small text-muted">Emergency Contact</div>
                  <div class="fw-semibold"><?= e($nurseDetail['emergency_contact_name'] ?? 'None') ?></div>
                </div>
              </div>
            </div>
            <div class="mt-3">
              <a href="profile.php" class="btn btn-sm btn-outline-success">
                <i class="bi bi-pencil-square me-1"></i>View Complete Profile
              </a>
            </div>
          </section>
        </div>

        <!-- Submitted Documents Column -->
        <div class="col-lg-6">
          <section class="card border-0 shadow-sm h-100 p-4">
            <h3 class="h6 text-success fw-bold mb-3">
              <i class="bi bi-file-earmark-lock me-2"></i>Submitted Credential Documents
            </h3>
            
            <div class="border rounded p-3 mb-3 bg-light">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <strong>PRC ID / Professional Document</strong>
                <?php if (!empty($nurseDetail['valid_id_path'])): ?>
                  <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i>Uploaded</span>
                <?php else: ?>
                  <span class="badge bg-secondary-subtle text-secondary">None</span>
                <?php endif; ?>
              </div>
              
              <?php if (!empty($nurseDetail['valid_id_path'])): ?>
                <p class="small text-muted mb-2">Your document was recorded during registration and is securely stored in private storage.</p>
                <a href="<?= e(APP_URL . '/api/nurse_documents.php?id=' . (int)$user['id']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-eye me-1"></i>View Your Uploaded Document
                </a>
              <?php else: ?>
                <p class="small text-muted mb-0">No document was uploaded during registration. You can provide credentials directly to the Clinic Head.</p>
              <?php endif; ?>
            </div>

            <?php if ($effectiveStatus !== 'revoked'): ?>
            <div class="border rounded p-3 mb-3"><h3 class="h6">Correct or complete your professional credentials</h3><p class="small text-muted">Update your PRC number, Employee ID, position or document, save a draft, and submit for Doctor review.</p><a href="credentials.php" class="btn btn-success"><i class="bi bi-patch-check me-1"></i>Open Professional Credentials</a></div>
            <?php endif; ?>
            <div class="border rounded p-3 bg-light">
              <div class="d-flex align-items-center justify-content-between mb-1">
                <strong>Account Security Status</strong>
                <?php if (!empty($nurseDetail['email_verified'])): ?><span class="badge bg-success">Email Verified</span><?php else: ?><span class="badge bg-danger">Email Not Verified</span><?php endif; ?>
              </div>
              <p class="small text-muted mb-0">Account ID: #<?= (int)$user['id'] ?> &bull; Username: @<?= e($user['username']) ?></p>
            </div>

            <div class="mt-3 d-flex gap-2">
              <a href="settings.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-shield-lock me-1"></i>Password & Security
              </a>
              <a href="<?= e(APP_URL) ?>/auth/logout.php" class="btn btn-sm btn-outline-danger ms-auto">
                <i class="bi bi-box-arrow-right me-1"></i>Sign out
              </a>
            </div>
          </section>
        </div>
      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>