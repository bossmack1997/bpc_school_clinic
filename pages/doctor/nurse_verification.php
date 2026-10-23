<?php
/**
 * pages/doctor/nurse_verification.php
 * ------------------------------------------------------------------
 * Doctor-Led Nurse Clinical Verification Module.
 * Dedicated module for reviewing, authorizing, requesting information,
 * rejecting, and revoking Nurse clinical permissions.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/doctor_page.php';
require_doctor();
require_once __DIR__ . '/../../includes/nurse_verification_service.php';

$db = getDB();
$doctor = current_user();
$service = new NurseVerificationService($db, $doctor);

$error = '';
$success = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = trim($_POST['action'] ?? '');
    $nurseId = (int)($_POST['nurse_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    try {
        if ($nurseId <= 0) {
            throw new InvalidArgumentException('Invalid nurse account specified.');
        }

        switch ($action) {
            case 'authorize':
                $service->authorize($nurseId, $notes);
                set_flash('success', 'Nurse clinical access successfully authorized. Clinical permissions granted.');
                break;

            case 'request_info':
                $service->requestInformation($nurseId, $notes);
                set_flash('info', 'Additional information requested from nurse. Instructions updated on their account.');
                break;

            case 'reject':
                $service->reject($nurseId, $notes);
                set_flash('warning', 'Nurse clinical authorization application rejected.');
                break;

            case 'revoke':
                $service->revoke($nurseId, $notes);
                set_flash('danger', 'Nurse clinical access has been revoked. All active sessions invalidated.');
                break;

            default:
                throw new InvalidArgumentException('Invalid action requested.');
        }

        redirect(APP_URL . '/pages/doctor/nurse_verification.php' . (!empty($_GET['tab']) ? '?tab=' . urlencode($_GET['tab']) : ''));
    } catch (Throwable $e) {
        error_log('Nurse verification error: ' . $e->getMessage());
        $error = $e->getMessage();
    }
}

$tab = trim($_GET['tab'] ?? 'pending');
if (!in_array($tab, ['pending', 'needs_information', 'authorized', 'rejected', 'revoked', 'all'], true)) {
    $tab = 'pending';
}

$search = trim($_GET['search'] ?? '');
$viewId = (int)($_GET['view'] ?? 0);

$counts = $service->summaryCounts();
$nurses = $service->listNurses($tab, $search, 100);

$selectedNurse = null;
if ($viewId > 0) {
    $selectedNurse = $service->getNurse($viewId);
}

$pageTitle = 'Nurse Clinical Verification';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../../includes/head.php'; ?>
  <style>
    .nurse-review-badge { font-size: 0.75rem; text-transform: uppercase; font-weight: 700; padding: 4px 8px; border-radius: 6px; }
    .badge-pending { background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
    .badge-needs-info { background-color: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
    .badge-authorized { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .badge-rejected { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .badge-revoked { background-color: #f3f4f6; color: #4b5563; border: 1px solid #e5e7eb; }
    .timeline-item { position: relative; padding-left: 24px; padding-bottom: 16px; border-left: 2px solid #e2e8f0; }
    .timeline-item:last-child { border-left-color: transparent; padding-bottom: 0; }
    .timeline-dot { position: absolute; left: -7px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: #0b6b3a; border: 2px solid #fff; }
    .nurse-doc-preview { max-height: 220px; object-fit: contain; border-radius: 8px; border: 1px solid #cbd5e1; background: #f8fafc; }
  </style>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <main id="mainContent" class="content-area">
      
      <div class="app-page-head d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h1 class="h4 mb-1">Nurse Clinical Verification</h1>
          <p class="small text-muted mb-0">Professional credential review and clinical authorization for school clinic operations.</p>
        </div>
        <div class="d-flex gap-2">
          <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Clinical Dashboard
          </a>
        </div>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($error) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Metrics Summary Cards -->
      <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
          <a href="?tab=pending" class="card p-3 border-0 shadow-sm text-decoration-none <?= $tab === 'pending' ? 'border-start border-4 border-warning' : '' ?>">
            <div class="small text-muted">Pending Verification</div>
            <div class="fs-4 fw-bold text-warning"><?= (int)$counts['pending'] ?></div>
          </a>
        </div>
        <div class="col-6 col-md-3">
          <a href="?tab=needs_information" class="card p-3 border-0 shadow-sm text-decoration-none <?= $tab === 'needs_information' ? 'border-start border-4 border-info' : '' ?>">
            <div class="small text-muted">Needs Information</div>
            <div class="fs-4 fw-bold text-info"><?= (int)$counts['needs_information'] ?></div>
          </a>
        </div>
        <div class="col-6 col-md-3">
          <a href="?tab=authorized" class="card p-3 border-0 shadow-sm text-decoration-none <?= $tab === 'authorized' ? 'border-start border-4 border-success' : '' ?>">
            <div class="small text-muted">Authorized Nurses</div>
            <div class="fs-4 fw-bold text-success"><?= (int)$counts['authorized'] ?></div>
          </a>
        </div>
        <div class="col-6 col-md-3">
          <a href="?tab=all" class="card p-3 border-0 shadow-sm text-decoration-none <?= $tab === 'all' ? 'border-start border-4 border-secondary' : '' ?>">
            <div class="small text-muted">Total Accounts</div>
            <div class="fs-4 fw-bold text-dark"><?= (int)$counts['total'] ?></div>
          </a>
        </div>
      </div>

      <!-- Detail View If Nurse Selected -->
      <?php if ($selectedNurse): ?>
        <section class="card border-0 shadow-sm mb-4" id="nurseDetailView">
          <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom">
            <div>
              <span class="small text-muted">Application Review</span>
              <h2 class="h5 mb-0 fw-bold"><?= e(trim($selectedNurse['first_name'] . ' ' . ($selectedNurse['middle_name'] ?? '') . ' ' . $selectedNurse['last_name'])) ?></h2>
            </div>
            <div class="d-flex align-items-center gap-2">
              <?php
                $badgeCls = match($selectedNurse['effective_status']) {
                    'authorized' => 'badge-authorized',
                    'needs_information' => 'badge-needs-info',
                    'rejected' => 'badge-rejected',
                    'revoked' => 'badge-revoked',
                    default => 'badge-pending'
                };
              ?>
              <span class="nurse-review-badge <?= $badgeCls ?>"><?= e(str_replace('_', ' ', $selectedNurse['effective_status'])) ?></span>
              <a href="?tab=<?= urlencode($tab) ?>" class="btn btn-sm btn-outline-secondary" aria-label="Close detail view">
                <i class="bi bi-x-lg"></i>
              </a>
            </div>
          </div>
          <div class="card-body p-4">
            
            <div class="row g-4">
              <!-- Left Column: Professional & Personal Details -->
              <div class="col-lg-7">
                <h3 class="h6 text-success fw-bold mb-3"><i class="bi bi-person-badge me-2"></i>Professional Credentials</h3>
                <div class="row g-2 mb-3">
                  <div class="col-sm-6">
                    <div class="border rounded p-2 bg-light">
                      <div class="small text-muted">PRC License Number</div>
                      <div class="fw-bold fs-6"><?= e($selectedNurse['license_number'] ?: 'Not provided') ?></div>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <div class="border rounded p-2 bg-light">
                      <div class="small text-muted">Employee ID</div>
                      <div class="fw-bold fs-6"><?= e($selectedNurse['employee_id'] ?: 'Not provided') ?></div>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <div class="border rounded p-2 bg-light">
                      <div class="small text-muted">Clinical Position</div>
                      <div class="fw-semibold"><?= e($selectedNurse['position'] ?: 'School Clinic Nurse') ?></div>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <div class="border rounded p-2 bg-light">
                      <div class="small text-muted">Specialization / Schedule</div>
                      <div class="fw-semibold"><?= e(($selectedNurse['specialization'] ?: 'General Clinic') . ' / ' . ($selectedNurse['shift_schedule'] ?: 'Regular')) ?></div>
                    </div>
                  </div>
                </div>

                <h3 class="h6 text-success fw-bold mb-3"><i class="bi bi-person-lines-fill me-2"></i>Personal & Contact Information</h3>
                <div class="row g-2 mb-3">
                  <div class="col-sm-6">
                    <div class="border rounded p-2">
                      <div class="small text-muted">Email Address</div>
                      <div class="fw-semibold"><?= e($selectedNurse['email']) ?></div>
                      <span class="badge bg-success-subtle text-success mt-1"><i class="bi bi-check-circle me-1"></i>Email Verified</span>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <div class="border rounded p-2">
                      <div class="small text-muted">Phone Number</div>
                      <div class="fw-semibold"><?= e($selectedNurse['phone'] ?: 'Not provided') ?></div>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <div class="border rounded p-2">
                      <div class="small text-muted">Emergency Contact</div>
                      <div class="fw-semibold"><?= e($selectedNurse['emergency_contact_name'] ?: 'None') ?> (<?= e($selectedNurse['emergency_contact_number'] ?: 'N/A') ?>)</div>
                    </div>
                  </div>
                  <div class="col-sm-6">
                    <div class="border rounded p-2">
                      <div class="small text-muted">Account Registered</div>
                      <div class="fw-semibold"><?= e($selectedNurse['registered_at']) ?></div>
                    </div>
                  </div>
                </div>

                <!-- Review Notes If Any -->
                <?php if (!empty($selectedNurse['clinical_review_notes'])): ?>
                  <div class="alert alert-info py-2 px-3 small mb-3">
                    <strong>Current Review Notes:</strong> <?= e($selectedNurse['clinical_review_notes']) ?>
                  </div>
                <?php endif; ?>

                <!-- Decision Action Form -->
                <div class="border rounded p-3 bg-light">
                  <h4 class="h6 fw-bold mb-2">Doctor Authorization Actions</h4>
                  
                  <div class="alert alert-warning py-2 px-3 small mb-3">
                    <i class="bi bi-shield-lock-fill me-1"></i>
                    <strong>Institutional Verification Mandate:</strong> The Doctor must verify legitimate employment or assignment to the school clinic using an authorized institutional source or offline clinic confirmation. Uploaded documents alone do not automatically grant clinical access.
                  </div>

                  <div class="d-flex flex-wrap gap-2">
                    <?php if ($selectedNurse['effective_status'] !== 'authorized'): ?>
                      <!-- Authorize Action -->
                      <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#authorizeModal">
                        <i class="bi bi-check-circle-fill me-1"></i>Authorize Clinical Access
                      </button>
                      
                      <!-- Request Info Action -->
                      <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#requestInfoModal">
                        <i class="bi bi-info-circle me-1"></i>Request Information
                      </button>

                      <!-- Reject Action -->
                      <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                        <i class="bi bi-x-circle me-1"></i>Reject Application
                      </button>
                    <?php else: ?>
                      <!-- Revoke Action -->
                      <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#revokeModal">
                        <i class="bi bi-shield-x me-1"></i>Revoke Clinical Access
                      </button>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- Right Column: Document Verification & History -->
              <div class="col-lg-5">
                <h3 class="h6 text-success fw-bold mb-3"><i class="bi bi-file-earmark-medical me-2"></i>Submitted Credential Documents</h3>
                
                <div class="border rounded p-3 mb-3 bg-white text-center">
                  <?php if (!empty($selectedNurse['valid_id_path'])): ?>
                    <p class="small text-muted mb-2">PRC / Institutional ID Document</p>
                    <div class="mb-3">
                      <a href="<?= e(APP_URL . '/api/nurse_documents.php?id=' . (int)$selectedNurse['user_id']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Inspect Document in Secure Viewer
                      </a>
                    </div>
                    <small class="text-muted d-block">Served via protected server endpoint with IDOR and path validation.</small>
                  <?php else: ?>
                    <p class="text-muted small mb-0 py-3"><i class="bi bi-file-earmark-x fs-3 d-block mb-1"></i>No credential document file uploaded during registration.</p>
                  <?php endif; ?>
                </div>

                <!-- Review Audit History Timeline -->
                <h3 class="h6 text-success fw-bold mb-3"><i class="bi bi-clock-history me-2"></i>Review History</h3>
                <div class="border rounded p-3 bg-white" style="max-height: 280px; overflow-y: auto;">
                  <?php if (!empty($selectedNurse['history'])): ?>
                    <?php foreach ($selectedNurse['history'] as $hist): ?>
                      <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <div class="small fw-bold text-dark">
                          <?= e(ucwords(str_replace('_', ' ', $hist['action'] ?? $hist['action'] ?? 'Review'))) ?>
                        </div>
                        <div class="small text-muted"><?= e($hist['created_at']) ?></div>
                        <?php if (!empty($hist['notes'])): ?>
                          <div class="small text-secondary mt-1 bg-light p-1 rounded"><?= e($hist['notes']) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <p class="text-muted small mb-0">No previous review actions recorded.</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>

          </div>
        </section>

        <!-- ACTION MODALS -->
        <!-- 1. Authorize Modal -->
        <div class="modal fade" id="authorizeModal" tabindex="-1" aria-labelledby="authModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <form method="post" class="modal-content">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="authorize">
              <input type="hidden" name="nurse_id" value="<?= (int)$selectedNurse['user_id'] ?>">
              <div class="modal-header bg-success text-white">
                <h5 class="modal-title h6" id="authModalLabel"><i class="bi bi-check-circle me-1"></i>Authorize Nurse Clinical Access</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p>You are about to authorize <strong><?= e($selectedNurse['first_name'] . ' ' . $selectedNurse['last_name']) ?></strong> for clinical staff permissions.</p>
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" id="confirmVerificationCheck" required>
                  <label class="form-check-label small" for="confirmVerificationCheck">
                    I confirm that I have verified this nurse's legitimate employment / clinic assignment via authorized school records or institutional confirmation.
                  </label>
                </div>
                <label for="authNotes" class="form-label small">Review Notes / Verification Remarks (Optional)</label>
                <textarea class="form-control form-control-sm" id="authNotes" name="notes" rows="2" placeholder="e.g. Verified PRC license against institutional registry"></textarea>
              </div>
              <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-success">Grant Clinical Access</button>
              </div>
            </form>
          </div>
        </div>

        <!-- 2. Request Info Modal -->
        <div class="modal fade" id="requestInfoModal" tabindex="-1" aria-labelledby="reqInfoModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <form method="post" class="modal-content">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="request_info">
              <input type="hidden" name="nurse_id" value="<?= (int)$selectedNurse['user_id'] ?>">
              <div class="modal-header bg-info text-white">
                <h5 class="modal-title h6" id="reqInfoModalLabel"><i class="bi bi-info-circle me-1"></i>Request Additional Information</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p class="small text-muted">Specify the additional documents, credentials, or clarifications the nurse must provide before clinical authorization.</p>
                <label for="infoInstructions" class="form-label small fw-semibold">Instructions for Nurse <span class="text-danger">*</span></label>
                <textarea class="form-control form-control-sm" id="infoInstructions" name="notes" rows="3" required placeholder="e.g. Please present updated PRC ID card or appointment letter to the clinic office."></textarea>
              </div>
              <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-info text-white">Submit Request</button>
              </div>
            </form>
          </div>
        </div>

        <!-- 3. Reject Modal -->
        <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <form method="post" class="modal-content">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="nurse_id" value="<?= (int)$selectedNurse['user_id'] ?>">
              <div class="modal-header bg-danger text-white">
                <h5 class="modal-title h6" id="rejectModalLabel"><i class="bi bi-x-circle me-1"></i>Reject Clinical Application</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p class="small text-muted">This will reject the clinical application. The nurse's basic personal account will remain active without clinical access.</p>
                <label for="rejectReason" class="form-label small fw-semibold">Rejection Reason <span class="text-danger">*</span></label>
                <textarea class="form-control form-control-sm" id="rejectReason" name="notes" rows="3" required placeholder="e.g. Unable to verify credentials with institutional records."></textarea>
              </div>
              <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-danger">Reject Application</button>
              </div>
            </form>
          </div>
        </div>

        <!-- 4. Revoke Modal -->
        <div class="modal fade" id="revokeModal" tabindex="-1" aria-labelledby="revokeModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <form method="post" class="modal-content">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="nurse_id" value="<?= (int)$selectedNurse['user_id'] ?>">
              <div class="modal-header bg-danger text-white">
                <h5 class="modal-title h6" id="revokeModalLabel"><i class="bi bi-shield-x me-1"></i>Revoke Clinical Access</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <div class="alert alert-danger py-2 px-3 small">
                  <strong>Warning:</strong> Revoking clinical access will immediately terminate any active sessions and block access to medical records, medicine dispensing, and queue operations.
                </div>
                <label for="revokeReason" class="form-label small fw-semibold">Reason for Revocation <span class="text-danger">*</span></label>
                <textarea class="form-control form-control-sm" id="revokeReason" name="notes" rows="3" required placeholder="e.g. End of clinic assignment or credential reassessment."></textarea>
              </div>
              <div class="modal-footer border-0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-danger">Confirm Revocation</button>
              </div>
            </form>
          </div>
        </div>

      <?php endif; ?>

      <!-- Filter Tabs & Search Bar -->
      <div class="card border-0 shadow-sm p-3 mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          
          <!-- Filter Navigation -->
          <ul class="nav nav-pills small" role="tablist">
            <li class="nav-item">
              <a class="nav-link <?= $tab === 'pending' ? 'active bg-warning text-dark' : 'text-muted' ?>" href="?tab=pending">
                Pending <span class="badge bg-light text-dark ms-1"><?= (int)$counts['pending'] ?></span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= $tab === 'needs_information' ? 'active bg-info text-white' : 'text-muted' ?>" href="?tab=needs_information">
                Needs Info <span class="badge bg-light text-dark ms-1"><?= (int)$counts['needs_information'] ?></span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= $tab === 'authorized' ? 'active bg-success text-white' : 'text-muted' ?>" href="?tab=authorized">
                Authorized <span class="badge bg-light text-dark ms-1"><?= (int)$counts['authorized'] ?></span>
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= $tab === 'rejected' ? 'active bg-danger text-white' : 'text-muted' ?>" href="?tab=rejected">
                Rejected
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= $tab === 'revoked' ? 'active bg-secondary text-white' : 'text-muted' ?>" href="?tab=revoked">
                Revoked
              </a>
            </li>
            <li class="nav-item">
              <a class="nav-link <?= $tab === 'all' ? 'active bg-dark text-white' : 'text-muted' ?>" href="?tab=all">
                All
              </a>
            </li>
          </ul>

          <!-- Search Form -->
          <form method="get" class="d-flex gap-2">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="search" name="search" class="form-control form-control-sm" placeholder="Search nurse or license..." value="<?= e($search) ?>" style="max-width: 220px;">
            <button type="submit" class="btn btn-sm btn-outline-secondary">Search</button>
            <?php if ($search): ?>
              <a href="?tab=<?= e($tab) ?>" class="btn btn-sm btn-link text-muted p-0 align-self-center">Clear</a>
            <?php endif; ?>
          </form>

        </div>
      </div>

      <!-- Nurse List Table / Responsive Cards -->
      <div class="card border-0 shadow-sm p-3">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light small">
              <tr>
                <th scope="col">Nurse</th>
                <th scope="col">PRC License</th>
                <th scope="col">Employee ID</th>
                <th scope="col">Email Status</th>
                <th scope="col">Clinical Authorization</th>
                <th scope="col">Registered</th>
                <th scope="col" class="text-end">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($nurses)): ?>
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                    No nurse applications found under this status.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($nurses as $nurse): ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <img src="<?= e(!empty($nurse['user_photo']) ? app_url($nurse['user_photo']) : asset_url('images/default-avatar.svg')) ?>" onerror="this.src='<?= e(asset_url('images/default-avatar.svg')) ?>'" class="rounded-circle border" width="34" height="34" style="object-fit:cover;" alt="">
                        <div>
                          <strong><?= e($nurse['first_name'] . ' ' . $nurse['last_name']) ?></strong>
                          <div class="small text-muted"><?= e($nurse['email']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td>
                      <span class="font-monospace small"><?= e($nurse['license_number'] ?: 'Not provided') ?></span>
                    </td>
                    <td>
                      <span class="small"><?= e($nurse['employee_id'] ?: 'Not provided') ?></span>
                    </td>
                    <td>
                      <?php if ($nurse['email_verified']): ?>
                        <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i>Verified</span>
                      <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning"><i class="bi bi-clock me-1"></i>Pending OTP</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php
                        $badgeCls = match($nurse['effective_status']) {
                            'authorized' => 'badge-authorized',
                            'needs_information' => 'badge-needs-info',
                            'rejected' => 'badge-rejected',
                            'revoked' => 'badge-revoked',
                            default => 'badge-pending'
                        };
                      ?>
                      <span class="nurse-review-badge <?= $badgeCls ?>"><?= e(str_replace('_', ' ', $nurse['effective_status'])) ?></span>
                    </td>
                    <td class="small text-muted">
                      <?= e(date('M d, Y', strtotime($nurse['registered_at']))) ?>
                    </td>
                    <td class="text-end">
                      <a href="?tab=<?= urlencode($tab) ?>&view=<?= (int)$nurse['user_id'] ?>#nurseDetailView" class="btn btn-sm btn-outline-primary">
                        Review Application
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>

