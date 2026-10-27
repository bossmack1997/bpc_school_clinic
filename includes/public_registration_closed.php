<?php
header('Cache-Control: no-store, private');http_response_code(410);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'||strtolower($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='xmlhttprequest'){
 header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'message'=>'Public registration is closed. Contact MIS for a provisioned account and secure activation email.']);exit;
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account provisioning</title><link rel="stylesheet" href="<?=e(asset_url('css/bootstrap.min.css'))?>"></head><body><main class="container py-5" style="max-width:640px"><h1 class="h4">Accounts are provisioned by MIS</h1><p>Contact the MIS Administrator with your official identity and email. MIS will send a secure invitation so you can verify your email and choose your private password.</p><p>Nurse clinical access requires separate Doctor authorization.</p><a class="btn btn-success" href="<?=e(app_url('auth/login.php'))?>">Return to sign in</a></main></body></html><?php exit;
