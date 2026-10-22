<?php
require_once __DIR__ . '/../config/config.php';

if (is_logged_in()) redirect(dashboard_url_for(current_user()['role']));

$db = getDB();
$token = trim((string)($_POST['token'] ?? $_GET['token'] ?? ''));
$tokenHash = preg_match('/^[a-f0-9]{64}$/D', $token) ? hash('sha256', $token) : '';
$error = '';
$success = false;
$invitation = null;

if ($tokenHash !== '') {
    $q = $db->prepare("SELECT ai.id,ai.user_id,ai.expires_at,ai.used_at,u.email,u.first_name,u.last_name,u.registration_status,p.profile
        FROM admin_invitations ai
        JOIN users u ON u.id=ai.user_id
        JOIN admin_profiles p ON p.user_id=u.id
        WHERE ai.token_hash=? LIMIT 1");
    $q->execute([$tokenHash]);
    $invitation = $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$invitation || $invitation['profile'] !== 'doctor') {
    $error = 'This account setup invitation is invalid.';
} elseif ($invitation['used_at']) {
    $error = 'This account setup invitation has already been used.';
} elseif (strtotime($invitation['expires_at']) <= time()) {
    $error = 'This account setup invitation has expired. Contact the MIS Administrator for a new invitation.';
} elseif (($invitation['registration_status'] ?? '') !== 'approved') {
    $error = 'The clinical appointment is still awaiting authorized verification. Account activation is not yet available.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $error === '') {
    csrf_verify_or_die();
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $passwordErrors = validate_password_strength($password);
    if ($password !== $confirm) $passwordErrors[] = 'Passwords do not match.';

    if ($passwordErrors) {
        $error = implode(' ', array_unique($passwordErrors));
    } else {
        try {
            $db->beginTransaction();
            $q = $db->prepare("SELECT ai.id,ai.user_id,ai.expires_at,ai.used_at,u.registration_status,p.profile
                FROM admin_invitations ai JOIN users u ON u.id=ai.user_id JOIN admin_profiles p ON p.user_id=u.id
                WHERE ai.token_hash=? FOR UPDATE");
            $q->execute([$tokenHash]);
            $locked = $q->fetch(PDO::FETCH_ASSOC);
            if (!$locked || $locked['profile'] !== 'doctor' || $locked['used_at'] || strtotime($locked['expires_at']) <= time() || $locked['registration_status'] !== 'approved') {
                throw new RuntimeException('The invitation is no longer eligible for activation.');
            }
            $db->prepare("UPDATE users SET password_hash=?,status='active',email_verified=1,credential_version=COALESCE(credential_version,0)+1 WHERE id=?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $locked['user_id']]);
            $db->prepare('UPDATE admin_invitations SET used_at=NOW() WHERE id=? AND used_at IS NULL')->execute([$locked['id']]);
            $db->prepare('UPDATE admin_invitations SET used_at=COALESCE(used_at,NOW()) WHERE user_id=? AND id<>? AND used_at IS NULL')->execute([$locked['user_id'], $locked['id']]);
            $db->prepare("INSERT INTO activity_logs(user_id,action,module,details,ip_address) VALUES (?,'Doctor account activated','admin_profiles','Password created by appointee through a single-use invitation; password contents not logged.',?)")
                ->execute([$locked['user_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
            $db->commit();
            $success = true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Doctor setup failed: ' . $e->getMessage());
            $error = 'Account activation could not be completed. Request a new invitation from the MIS Administrator.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../includes/head.php'; ?>
  <style>
    body{background:linear-gradient(135deg,#eef8f1,#f8fafc);min-height:100vh}.setup-wrap{max-width:540px;margin:7vh auto;padding:16px}.setup-card{background:#fff;border:1px solid #dce8df;border-radius:18px;box-shadow:0 18px 45px rgba(15,80,45,.12);padding:28px}.password-rules{font-size:.82rem;color:#64748b}.password-rules span{display:block}.password-rules .valid{color:#137333}.password-rules .valid:before{content:'✓ '}.field-error{color:#b42318;font-size:.82rem;min-height:1.25rem}
  </style>
</head>
<body>
<main class="setup-wrap">
  <section class="setup-card" aria-labelledby="setupTitle">
    <div class="text-center mb-4"><img src="<?= e(APP_URL) ?>/assets/images/bpc_logo.png" alt="BPC" width="64"><h1 class="h4 mt-3" id="setupTitle">Clinical Co-Administrator Account Setup</h1></div>
    <?php if ($success): ?>
      <div class="alert alert-success" role="status">Your account is active. Your password was created privately and was not disclosed to MIS.</div>
      <a class="btn btn-success w-100" href="<?= e(APP_URL) ?>/auth/login.php">Continue to sign in</a>
    <?php elseif ($error !== '' && (!$invitation || $invitation['used_at'] || strtotime($invitation['expires_at'] ?? 'now') <= time() || ($invitation['registration_status'] ?? '') !== 'approved')): ?>
      <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
      <a class="btn btn-outline-success w-100" href="<?= e(APP_URL) ?>/auth/login.php">Return to sign in</a>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
      <p class="text-muted small">Hello <?= e(trim(($invitation['first_name'] ?? '') . ' ' . ($invitation['last_name'] ?? ''))) ?>. Create your own password to activate the verified Doctor profile.</p>
      <form method="post" id="setupAccountForm" novalidate>
        <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <label class="form-label fw-semibold" for="password">Password</label>
        <div class="input-group"><input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required aria-describedby="passwordRules passwordError"><button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Show password" aria-controls="password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
        <div class="password-rules mt-2" id="passwordRules"><span data-rule="length">At least 8 characters</span><span data-rule="upper">One uppercase letter</span><span data-rule="lower">One lowercase letter</span><span data-rule="number">One number</span><span data-rule="special">One special character</span></div>
        <label class="form-label fw-semibold mt-3" for="confirm_password">Confirm password</label>
        <input class="form-control" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required aria-describedby="passwordError">
        <div id="passwordError" class="field-error mt-1" role="alert" aria-live="polite"></div>
        <button class="btn btn-success w-100 mt-3" id="submitBtn" type="submit">Activate account securely</button>
      </form>
    <?php endif; ?>
  </section>
</main>
<script>
(() => {
  const form=document.getElementById('setupAccountForm'); if(!form)return;
  const pw=document.getElementById('password'), confirm=document.getElementById('confirm_password'), error=document.getElementById('passwordError'), toggle=document.getElementById('togglePassword');
  const tests={length:v=>v.length>=8,upper:v=>/[A-Z]/.test(v),lower:v=>/[a-z]/.test(v),number:v=>/[0-9]/.test(v),special:v=>/[^A-Za-z0-9]/.test(v)};
  function validate(){const value=pw.value;Object.entries(tests).forEach(([name,test])=>document.querySelector(`[data-rule="${name}"]`).classList.toggle('valid',test(value)));const ok=Object.values(tests).every(test=>test(value));error.textContent=confirm.value&&value!==confirm.value?'Passwords do not match.':(!ok&&value?'Password does not yet meet every requirement.':'');return ok&&value===confirm.value;}
  pw.addEventListener('input',validate);confirm.addEventListener('input',validate);
  toggle.addEventListener('click',()=>{const show=pw.type==='password';pw.type=show?'text':'password';toggle.setAttribute('aria-label',show?'Hide password':'Show password');toggle.setAttribute('aria-pressed',String(show));toggle.querySelector('i').className=show?'bi bi-eye-slash':'bi bi-eye';});
  form.addEventListener('submit',event=>{if(!validate()){event.preventDefault();error.textContent=error.textContent||'Complete all password requirements.';pw.focus();return;}const button=document.getElementById('submitBtn');button.disabled=true;button.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Activating account...';});
})();
</script>
<?php include __DIR__ . '/../components/footer.php'; ?>
</body></html>
