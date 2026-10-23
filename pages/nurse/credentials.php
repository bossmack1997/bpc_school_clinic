<?php
/**
 * pages/nurse/credentials.php
 * Professional Credentials: correct, save as draft, and resubmit for Doctor review.
 * Never grants clinical access; the Doctor decides independently.
 */
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
require_once __DIR__ . '/../../includes/nurse_credentials_service.php';

$db = getDB();
$user = current_user();
$service = new NurseCredentialsService($db, $user);
$errors = [];
$formError = '';
$posted = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $intent = ($_POST['intent'] ?? '') === 'submit' ? 'submit' : 'draft';
    $posted = [
        'license_number' => is_string($_POST['license_number'] ?? null) ? $_POST['license_number'] : '',
        'employee_id'    => is_string($_POST['employee_id'] ?? null) ? $_POST['employee_id'] : '',
        'position'       => is_string($_POST['position'] ?? null) ? $_POST['position'] : '',
    ];
    $stored = null;
    try {
        $state = $service->ready() ? $service->load() : ['live' => []];
        $errors = NurseCredentialsService::validateFields($posted, $state['live'], $intent === 'submit');
        $file = $_FILES['credential_document'] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try { NurseCredentialsService::inspectUpload($file); }
            catch (InvalidArgumentException $e) { $errors['credential_document'] = $e->getMessage(); }
        }
        if ($errors) throw new InvalidArgumentException('Please correct the highlighted fields. Nothing was saved or submitted.');
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) $stored = NurseCredentialsService::storeUpload($file);
        if ($intent === 'submit') {
            $result = $service->submit($posted, $stored);
            set_flash('success', $result['demoted']
                ? 'Credentials submitted. Because you changed professional details, clinical access is paused until a Doctor re-verifies them.'
                : 'Credentials submitted for Doctor review. This does not grant clinical access by itself.');
            redirect(APP_URL . '/pages/nurse/account.php');
            exit;
        }
        $service->saveDraft($posted, $stored);
        set_flash('success', 'Draft saved. It has not been submitted and does not change your status or access.');
        redirect(APP_URL . '/pages/nurse/credentials.php');
        exit;
    } catch (Throwable $e) {
        if ($stored && is_file(BASE_PATH . '/' . $stored)) @unlink(BASE_PATH . '/' . $stored); // only the just-stored, unreferenced file
        $formError = $e instanceof PDOException ? 'Unable to save. Nothing was changed. Please retry.' : $e->getMessage();
        if ($e instanceof PDOException) clinical_database_failure('nurse.credentials', $e);
    }
}

$state = $service->ready() ? $service->load() : ['live' => [], 'draft' => null, 'last_submission' => null, 'eligibility' => null];
$live = $state['live']; $draft = $state['draft'];
$val = static function (string $f) use ($posted, $draft, $live) {
    if ($posted !== null) return $posted[$f] ?? '';
    if ($draft && $draft[$f] !== null && $draft[$f] !== '') return $draft[$f];
    return $live[$f] ?? '';
};
$status = (string)($live['clinical_authorization_status'] ?? 'pending');
$authorized = $status === 'authorized';
$blockedReason = '';
if (!$service->ready()) $blockedReason = 'Credential correction is unavailable until the reviewed database migration is applied. Contact the system administrator.';
elseif (($live['account_status'] ?? '') !== 'active') $blockedReason = 'Your account is ' . e($live['account_status'] ?? 'unknown') . '. Credentials can be submitted only on an active account. Contact the Clinic Head.';
elseif (empty($live['email_verified'])) $blockedReason = 'Verify your email address with your one-time code before submitting credentials. Drafts can still be saved.';
elseif ($status === 'revoked') $blockedReason = 'Clinical access was revoked by a Doctor. Contact the Clinic Head; credentials cannot be resubmitted here.';
$canSubmit = $blockedReason === '';
$canDraft = $service->ready() && $status !== 'revoked';
$hint = NurseCredentialsService::employeeIdHint();
$empPattern = NurseCredentialsService::employeeIdPattern();
$docUrl = APP_URL . '/api/nurse_documents.php?id=' . (int)$user['id'];
$pageTitle = 'Professional Credentials';
$field = static fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block" role="alert">' . e($errors[$k]) . '</div>' : '';
$cls = static fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <main id="mainContent" class="content-area">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
          <h1 class="h4 mb-1"><i class="bi bi-patch-check text-success me-2"></i>Professional Credentials</h1>
          <p class="small text-muted mb-0">Correct your registration details and supporting documents. A Doctor reviews every submission; submitting never grants access by itself.</p>
        </div>
        <a href="profile.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>My Profile</a>
      </div>

      <?php if ($formError): ?><div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= e($formError) ?></div><?php endif; ?>
      <?php if ($blockedReason): ?><div class="alert alert-warning" role="alert"><?= $blockedReason ?></div><?php endif; ?>

      <div class="row g-3">
        <div class="col-12 col-xl-7">
          <section class="card border-0 shadow-sm p-3 p-md-4" aria-labelledby="credHeading">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
              <h2 id="credHeading" class="h6 fw-bold mb-0">Registration details</h2>
              <span class="badge text-bg-light border"><?= e(ucwords(str_replace('_', ' ', $status))) ?></span>
            </div>

            <?php if ($status === 'needs_information' && !empty($live['clinical_review_notes'])): ?>
              <div class="alert alert-info" role="status"><strong>Doctor's request:</strong> <?= e($live['clinical_review_notes']) ?></div>
            <?php elseif ($status === 'rejected' && !empty($live['clinical_review_notes'])): ?>
              <div class="alert alert-danger" role="status"><strong>Previous decision:</strong> <?= e($live['clinical_review_notes']) ?></div>
            <?php endif; ?>
            <?php if ($authorized): ?>
              <div class="alert alert-warning" role="alert"><i class="bi bi-shield-exclamation me-1"></i>You are currently authorized. Changing your PRC number, Employee ID, position or document will <strong>pause clinical access until a Doctor re-verifies</strong> the new details.</div>
            <?php endif; ?>
            <?php if ($draft): ?><div class="alert alert-secondary py-2 small" role="status"><i class="bi bi-pencil me-1"></i>Showing your saved draft (last saved <?= e($draft['updated_at']) ?>). It has <strong>not</strong> been submitted.</div><?php endif; ?>

            <form method="post" enctype="multipart/form-data" novalidate id="credForm" autocomplete="off">
              <?= csrf_field() ?>
              <div class="mb-3">
                <label for="license_number" class="form-label fw-semibold">PRC license number <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(required)</span></label>
                <input id="license_number" name="license_number" class="form-control<?= $cls('license_number') ?>" maxlength="30" inputmode="text" value="<?= e($val('license_number')) ?>" aria-describedby="licHelp" <?= $canDraft ? '' : 'readonly' ?>>
                <?= $field('license_number') ?>
                <div id="licHelp" class="form-text">Enter the registration number exactly as printed on your PRC license or ID. PRC numbers differ by profession and issue date, so no fixed length is assumed. Use letters, numbers, hyphens or slashes; at least one digit; up to 30 characters.</div>
              </div>
              <div class="mb-3">
                <label for="employee_id" class="form-label fw-semibold">Employee ID <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(required)</span></label>
                <input id="employee_id" name="employee_id" class="form-control<?= $cls('employee_id') ?>" maxlength="50" value="<?= e($val('employee_id')) ?>" aria-describedby="empHelp" <?= $empPattern ? 'data-pattern="' . e($empPattern) . '"' : '' ?> <?= $canDraft ? '' : 'readonly' ?>>
                <?= $field('employee_id') ?>
                <div id="empHelp" class="form-text"><?= e($hint) ?></div>
              </div>
              <div class="mb-3">
                <label for="position" class="form-label fw-semibold">Official appointment position <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(required)</span></label>
                <input id="position" name="position" class="form-control<?= $cls('position') ?>" maxlength="120" value="<?= e($val('position')) ?>" aria-describedby="posHelp" <?= $canDraft ? '' : 'readonly' ?>>
                <?= $field('position') ?>
                <div id="posHelp" class="form-text">The position stated on your school appointment or assignment order. The Doctor verifies it against institutional records.</div>
              </div>
              <div class="mb-3">
                <label for="credential_document" class="form-label fw-semibold">Supporting professional credential <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(required)</span></label>
                <input id="credential_document" name="credential_document" type="file" class="form-control<?= $cls('credential_document') ?>" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" aria-describedby="docHelp" <?= $canDraft ? '' : 'disabled' ?>>
                <?= $field('credential_document') ?>
                <div id="docHelp" class="form-text">PDF, JPG or PNG, up to 5 MB (for example a clear scan of your PRC ID). Your current document is kept until a replacement is accepted.</div>
                <div id="fileStatus" class="small mt-1" aria-live="polite"></div>
              </div>
              <div class="d-flex flex-column flex-sm-row gap-2">
                <button type="submit" name="intent" value="draft" class="btn btn-outline-success" <?= $canDraft ? '' : 'disabled' ?>><i class="bi bi-save me-1"></i>Save draft</button>
                <button type="submit" name="intent" value="submit" class="btn btn-success" <?= $canSubmit ? '' : 'disabled aria-disabled="true"' ?>><i class="bi bi-send-check me-1"></i>Submit for review</button>
              </div>
              <p class="small text-muted mt-2 mb-0">Save draft stores your entries privately and does not submit them. Submit for review sends them to a Doctor, who decides independently.</p>
            </form>
          </section>
        </div>

        <div class="col-12 col-xl-5">
          <section class="card border-0 shadow-sm p-3 p-md-4 mb-3" aria-labelledby="docStatusHeading">
            <h2 id="docStatusHeading" class="h6 fw-bold mb-3">Document status</h2>
            <ul class="list-unstyled small mb-0">
              <li class="mb-2"><strong>Submitted document:</strong>
                <?php if (!empty($live['valid_id_path']) && NurseCredentialsService::documentExists($live['valid_id_path'])): ?>
                  <span class="badge bg-success-subtle text-success">On file</span> <a href="<?= e($docUrl) ?>" target="_blank" rel="noopener">View</a>
                <?php elseif (!empty($live['valid_id_path'])): ?><span class="badge bg-danger-subtle text-danger">Record exists, file not found &ndash; upload again</span>
                <?php else: ?><span class="badge bg-danger-subtle text-danger">Missing</span><?php endif; ?></li>
              <li class="mb-2"><strong>Draft document:</strong>
                <?php if ($draft && !empty($draft['pending_document_path'])): ?><span class="badge bg-info-subtle text-info">Saved, not submitted</span> <a href="<?= e($docUrl . '&draft=1') ?>" target="_blank" rel="noopener">View</a>
                <?php else: ?><span class="text-muted">None</span><?php endif; ?></li>
              <?php if (!empty($state['last_submission'])): ?><li><strong>Last submitted:</strong> <?= e($state['last_submission']['created_at']) ?></li><?php endif; ?>
            </ul>
          </section>
          <?php if ($state['eligibility']): ?>
          <section class="card border-0 shadow-sm p-3 p-md-4" aria-labelledby="chkHeading">
            <h2 id="chkHeading" class="h6 fw-bold mb-3">What the Doctor will check</h2>
            <ul class="list-unstyled small mb-0">
              <?php foreach ($state['eligibility']['checks'] as $chk): ?>
                <li class="mb-1"><?= $chk['ok'] === true ? '&#10004;' : ($chk['ok'] === false ? '&#10008;' : '&#9203;') ?> <strong><?= e($chk['label']) ?>:</strong> <?= e($chk['detail']) ?></li>
              <?php endforeach; ?>
            </ul>
          </section>
          <?php endif; ?>
        </div>
      </div>
    </main>
  </div>
</div>
<script>
(function () {
  var form = document.getElementById('credForm'); if (!form) return;
  var emp = document.getElementById('employee_id'), file = document.getElementById('credential_document'), status = document.getElementById('fileStatus');
  function msg(el, text) { var n = el.parentNode.querySelector('.js-msg'); if (!n) { n = document.createElement('div'); n.className = 'invalid-feedback d-block js-msg'; n.setAttribute('role', 'alert'); el.parentNode.appendChild(n); } n.textContent = text; el.classList.toggle('is-invalid', !!text); }
  emp.addEventListener('blur', function () {
    var p = emp.getAttribute('data-pattern'), v = emp.value.trim();
    if (v && p) { try { msg(emp, new RegExp('^(?:' + p + ')$').test(v) ? '' : 'Employee ID does not match the institution format.'); } catch (e) {} } else msg(emp, '');
  });
  var lic = document.getElementById('license_number');
  lic.addEventListener('blur', function () {
    var v = lic.value.trim();
    msg(lic, !v ? '' : (!/^[A-Za-z0-9][A-Za-z0-9\-\/ ]*$/.test(v) ? 'Use only letters, numbers, hyphens, slashes and spaces.' : (!/\d/.test(v) ? 'Must contain at least one digit.' : '')));
  });
  file.addEventListener('change', function () {
    var f = file.files[0]; if (!f) { status.textContent = ''; msg(file, ''); return; }
    var okType = ['application/pdf', 'image/jpeg', 'image/png'].indexOf(f.type) >= 0;
    if (!okType) { msg(file, 'Only PDF, JPG and PNG are accepted.'); status.textContent = ''; }
    else if (f.size > 5 * 1024 * 1024) { msg(file, 'File exceeds the 5 MB limit.'); status.textContent = ''; }
    else { msg(file, ''); status.textContent = 'Selected: ' + f.name + ' (' + Math.round(f.size / 1024) + ' KB). It is verified on the server when you save or submit.'; }
  });
})();
</script>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
