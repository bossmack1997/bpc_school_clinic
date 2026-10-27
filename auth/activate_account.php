<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/account_provisioning_service.php';
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
$token=is_string($_POST['token']??null)?$_POST['token']:'';$error='';$success=false;$invite=null;
try{
 $service=new AccountProvisioningService(getDB());$invite=$service->inspect($token);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify_or_die();
  if(($_POST['action']??'')==='activate'){
   if(!is_string($_POST['password']??null)||!is_string($_POST['confirmation']??null))throw new InvalidArgumentException('Enter and confirm your password.');
   $service->activate($token,$_POST['password'],$_POST['confirmation']);$success=true;
  }elseif(!$invite)$error='The activation link is invalid, expired or already used. Ask MIS for a new link.';
 }
}catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
catch(PDOException $e){http_response_code(503);$error='Account activation is temporarily unavailable. Contact MIS.';error_log('Account activation unavailable SQLSTATE='.$e->getCode());}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Activate your clinic account</title><link rel="stylesheet" href="<?=e(asset_url('css/bootstrap.min.css'))?>"></head><body class="bg-light"><main class="container py-5" style="max-width:560px"><section class="card p-4"><h1 class="h4">Activate your clinic account</h1>
<?php if($error): ?><p class="alert alert-danger" role="alert"><?=e($error)?></p><?php endif; ?>
<?php if($success): ?><p class="alert alert-success" role="status">Your account is active. Your private password was saved securely. Nurse clinical access still requires Doctor authorization.</p><a href="login.php" class="btn btn-success">Continue to sign in</a>
<?php elseif($invite): ?><p>Hello <?=e($invite['first_name'])?>. Choose your initial password to verify your email and activate your <?=e($invite['role'])?> account.</p><form method="post"><?=csrf_field()?><input type="hidden" name="token" value="<?=e($token)?>"><input type="hidden" name="action" value="activate"><label class="form-label" for="activation-password">New password</label><input class="form-control mb-3" id="activation-password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required><p class="small text-muted">Use at least 8 characters with uppercase, lowercase, a number and a special character.</p><label class="form-label" for="activation-confirmation">Confirm password</label><input class="form-control mb-3" id="activation-confirmation" name="confirmation" type="password" autocomplete="new-password" maxlength="72" required><button class="btn btn-success">Activate account</button></form>
<?php else: ?><p>Open the secure activation link sent by MIS. For an expired or missing email, ask MIS to resend your invitation.</p><a href="login.php">Return to sign in</a><?php endif; ?>
<noscript>JavaScript is required to open secure email activation links.</noscript></section></main>
<form method="post" id="activation-token-form" hidden><?=csrf_field()?><input type="hidden" name="token" value=""><input type="hidden" name="action" value="inspect"></form>
<script>(()=>{const t=new URLSearchParams(location.hash.slice(1)).get('token');if(!t)return;history.replaceState(null,'',location.pathname);const f=document.getElementById('activation-token-form');f.elements.token.value=/^[a-f0-9]{64}$/.test(t)?t:'';f.submit();})();</script></body></html>
