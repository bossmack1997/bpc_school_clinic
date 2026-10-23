<?php
require_once __DIR__ . '/../config/config.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
date_default_timezone_set('Asia/Manila');

// This public recipient flow neither requires nor changes an existing login.

require_once __DIR__.'/../includes/doctor_invitation_service.php';
$service = new DoctorInvitationService(getDB());
$rawToken = $_POST['token'] ?? $_GET['token'] ?? '';
$token = is_string($rawToken) ? trim($rawToken) : '';
$success = false;
try {$invitation = $service->inspect($token);$error = DoctorInvitationService::eligibilityError($invitation);}
catch(Throwable $e){http_response_code(503);$invitation=null;$error='Account setup is temporarily unavailable. Contact the MIS Administrator.';error_log('Doctor invitation lookup unavailable.');}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $error === '') {
    if (!is_string($_POST['csrf_token'] ?? null) || !csrf_verify($_POST['csrf_token'])) {
        http_response_code(403);$error = 'This form expired. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'load_invitation') {
        // Fragment bridge validates the link without consuming it.
    } elseif (!is_string($_POST['password'] ?? null) || !is_string($_POST['confirm_password'] ?? null)) {
        http_response_code(422);$error = 'Enter and confirm a valid password.';
    } else {
        try {$service->accept($token,$_POST['password'],$_POST['confirm_password']);$success=true;}
        catch (InvalidArgumentException|DomainException $e) {http_response_code(422);$error=$e->getMessage();$invitation=$service->inspect($token);}
        catch (Throwable $e) {http_response_code(503);error_log('Doctor setup failed; code='.(string)$e->getCode());$error='Account activation could not be completed. Contact the MIS Administrator.';}
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Activate your clinical account — BPC School Clinic</title>
  <link rel="stylesheet" href="<?= e(asset_url('css/bootstrap.min.css')) ?>">
  <link rel="icon" href="<?= e(asset_url('images/bpc_logo.png')) ?>">
  <script>
  // New invitation secrets arrive in a fragment, never in request URLs/referrers.
  (()=>{
    function verifyFragment(){
      const token=new URLSearchParams(location.hash.slice(1)).get('token');
      if(!token)return;
      history.replaceState(null,'',location.pathname);
      const form=document.getElementById('invitationTokenForm');
      form.elements.token.value=/^[a-f0-9]{64}$/.test(token)?token:'';
      form.action=location.pathname;form.submit();
    }
    addEventListener('DOMContentLoaded',verifyFragment,{once:true});
    addEventListener('hashchange',verifyFragment);
  })();
  </script>
  <style>
    body{background:linear-gradient(135deg,#eef8f1,#f8fafc);min-height:100vh}.setup-wrap{max-width:540px;margin:7vh auto;padding:16px}.setup-card{background:#fff;border:1px solid #dce8df;border-radius:18px;box-shadow:0 18px 45px rgba(15,80,45,.12);padding:28px;overflow-wrap:anywhere}.password-rules{font-size:.82rem;color:#64748b}.password-rules span{display:block}.password-rules .valid{color:#137333}.password-rules .valid:before{content:'✓ '}.field-error{color:#b42318;font-size:.82rem;min-height:1.25rem}.form-control{font-size:16px}.input-group>.form-control{min-width:0}@media(max-width:575px){.setup-wrap{margin:16px auto;padding:12px}.setup-card{padding:20px}}
  </style>
</head>
<body>
<noscript><p class="alert alert-warning">Enable JavaScript to open a new invitation securely.</p></noscript>
<main class="setup-wrap">
  <section class="setup-card" aria-labelledby="setupTitle">
    <div class="text-center mb-4"><img src="<?= e(APP_URL) ?>/assets/images/bpc_logo.png" alt="BPC" width="64"><h1 class="h4 mt-3" id="setupTitle">Clinical Co-Administrator Account Setup</h1></div>
    <?php if ($success): ?>
      <div class="alert alert-success" role="status">Your account is active. Your password was created privately and was not disclosed to MIS.</div>
      <a class="btn btn-success w-100" href="<?= e(APP_URL) ?>/auth/login.php">Continue to sign in</a>
    <?php elseif (DoctorInvitationService::eligibilityError($invitation) !== ''): ?>
      <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
      <a class="btn btn-outline-success w-100" href="<?= e(APP_URL) ?>/auth/login.php">Return to sign in</a>
    <?php else: ?>
      <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
      <p class="text-muted small">Hello <?= e(trim(($invitation['first_name'] ?? '') . ' ' . ($invitation['last_name'] ?? ''))) ?>. Create your own password to activate the verified Doctor profile.</p>
      <form method="post" id="setupAccountForm" novalidate><input type="hidden" name="action" value="activate">
        <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <label class="form-label fw-semibold" for="password">Password</label>
        <div class="input-group"><input class="form-control" id="password" name="password" type="password" maxlength="72" autocomplete="new-password" required aria-describedby="passwordRules passwordError"><button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Show password" aria-controls="password" aria-pressed="false">Show</button></div>
        <div class="password-rules mt-2" id="passwordRules"><span data-rule="length">At least 8 characters</span><span data-rule="upper">One uppercase letter</span><span data-rule="lower">One lowercase letter</span><span data-rule="number">One number</span><span data-rule="special">One special character</span></div>
        <label class="form-label fw-semibold mt-3" for="confirm_password">Confirm password</label>
        <input class="form-control" id="confirm_password" name="confirm_password" type="password" maxlength="72" autocomplete="new-password" required aria-describedby="passwordError">
        <div id="passwordError" class="field-error mt-1" role="alert" aria-live="polite"></div>
        <button class="btn btn-success w-100 mt-3" id="submitBtn" type="submit">Activate account securely</button>
      </form>
    <?php endif; ?>
  </section>
</main>
<form method="post" id="invitationTokenForm" hidden>
  <?= csrf_field() ?><input type="hidden" name="action" value="load_invitation"><input type="hidden" name="token" value="">
</form>
<script>
(() => {
  const form=document.getElementById('setupAccountForm'); if(!form)return;
  const pw=document.getElementById('password'), confirm=document.getElementById('confirm_password'), error=document.getElementById('passwordError');
  document.getElementById('togglePassword').addEventListener('click',event=>{const show=pw.type==='password';pw.type=show?'text':'password';const button=event.currentTarget;button.textContent=show?'Hide':'Show';button.setAttribute('aria-label',show?'Hide password':'Show password');button.setAttribute('aria-pressed',String(show));});
  const tests={length:v=>v.length>=8,upper:v=>/[A-Z]/.test(v),lower:v=>/[a-z]/.test(v),number:v=>/[0-9]/.test(v),special:v=>/[^A-Za-z0-9]/.test(v)};
  function validate(){const value=pw.value;Object.entries(tests).forEach(([name,test])=>document.querySelector(`[data-rule="${name}"]`).classList.toggle('valid',test(value)));const ok=Object.values(tests).every(test=>test(value));error.textContent=confirm.value&&value!==confirm.value?'Passwords do not match.':(!ok&&value?'Password does not yet meet every requirement.':'');return ok&&value===confirm.value;}
  pw.addEventListener('input',validate);confirm.addEventListener('input',validate);
  form.addEventListener('submit',event=>{if(!validate()){event.preventDefault();error.textContent=error.textContent||'Complete all password requirements.';pw.focus();return;}const button=document.getElementById('submitBtn');button.disabled=true;button.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Activating account...';});
})();
</script>
</body></html>
