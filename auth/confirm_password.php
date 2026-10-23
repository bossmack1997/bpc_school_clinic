<?php
require_once __DIR__.'/../config/config.php';require_login();
header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');
$confirmationUser=current_user();$confirmationError='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    if(!csrf_verify($_POST['csrf_token']??null)){http_response_code(403);$confirmationError='This form expired. Reload this page and try again.';}
    else {
        $confirmationIp=$_SERVER['REMOTE_ADDR']??'unknown';
        $confirmationKey='password_confirmation_'.(int)$confirmationUser['id'];
        if(login_rate_limit_check($confirmationIp,$confirmationKey)>0){http_response_code(429);$confirmationError='Too many attempts. Wait before trying again.';}
        else {
            $q=getDB()->prepare('SELECT * FROM users WHERE id=?');$q->execute([$confirmationUser['id']]);$confirmationFresh=$q->fetch();
            $password=$_POST['password']??null;
            if(!$confirmationFresh||$confirmationFresh['status']!=='active'||(int)$confirmationFresh['credential_version']!==(int)($_SESSION['credential_version']??-1)||!is_string($password)||!password_verify($password,$confirmationFresh['password_hash'])) {
                login_rate_limit_record_failure($confirmationIp,$confirmationKey);http_response_code(422);$confirmationError='Password confirmation failed. Enter your current password.';
            } else {
                login_rate_limit_clear($confirmationIp,$confirmationKey);
                $_SESSION['password_confirmed_at']=time();$_SESSION['password_confirmed_user_id']=(int)$confirmationUser['id'];
                secure_session_regenerate_id();csrf_regenerate();
                // Only a server-selected, role-authorized destination may be resumed.
                $destination=$_SESSION['password_confirmation_return']??'';unset($_SESSION['password_confirmation_return']);
                if($destination==='pages/admin/admin_accounts.php'&&is_primary_admin($confirmationUser)&&permission_profile($confirmationUser)==='mis')$destination=app_url($destination);
                else $destination=dashboard_url_for($confirmationUser['role']);
                log_activity($confirmationUser['id'],'Confirmed password for sensitive action','auth');
                header('Location: '.$destination,true,303);exit;
            }
        }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Confirm your password — BPC School Clinic</title><link rel="stylesheet" href="<?=e(asset_url('css/bootstrap.min.css'))?>"><style>body{background:#f3f8f5}.confirmation-card{max-width:440px;margin:8vh auto;padding:24px;background:white;border:1px solid #d6e5dc;border-radius:16px}input.form-control{font-size:16px}@media(max-width:480px){.confirmation-card{margin:16px 12px}}</style></head><body><main class="confirmation-card"><h1 class="h4">Confirm your password</h1><p>Confirm your identity before managing physician invitations. You will review and submit the action after confirmation.</p><?php if($confirmationError): ?><p class="alert alert-danger" role="alert"><?=e($confirmationError)?></p><?php endif; ?><form method="post"><?=csrf_field()?><label class="form-label d-block">Current password<input type="password" class="form-control mt-1" name="password" autocomplete="current-password" maxlength="1024" required autofocus></label><button class="btn btn-success mt-2" type="submit">Confirm and continue</button></form><a class="d-inline-block mt-3" href="<?=e(dashboard_url_for($confirmationUser['role']))?>">Return to dashboard</a></main></body></html>
