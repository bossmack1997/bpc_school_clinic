<?php
require_once __DIR__.'/../../includes/doctor_page.php';
require_permission('nurse_accounts_manage');
require_once __DIR__.'/../../includes/account_provisioning_service.php';
require_once __DIR__.'/../../includes/mail.php';
$pageTitle='Invite Nurse'; $db=getDB(); $service=new AccountProvisioningService($db);
$error=''; $preview=[]; $results=null; $pending=[]; $ready=true;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_verify_or_die();
    try {
        $action=$_POST['action']??'';
        if ($action==='preview') {
            unset($_SESSION['nurse_invitation_preview']);
            if (isset($_POST['role'])&&$_POST['role']!=='nurse') throw new InvalidArgumentException('Choose a Nurse account.');
            $row=['role'=>'nurse'];
            foreach (array_diff(AccountProvisioningService::COLUMNS,['role']) as $key) $row[$key]=$_POST[$key]??'';
            $preview=$service->preview([$row]);
            if (!$preview[0]['errors']) $_SESSION['nurse_invitation_preview']=['rows'=>[$preview[0]['account']],'nonce'=>bin2hex(random_bytes(24)),'expires'=>time()+900];
        } elseif ($action==='create') {
            $draft=$_SESSION['nurse_invitation_preview']??null;
            if (!$draft||$draft['expires']<time()||!is_string($_POST['nonce']??null)||!hash_equals($draft['nonce'],$_POST['nonce'])) throw new InvalidArgumentException('Preview expired or already submitted. Review the account again.');
            unset($_SESSION['nurse_invitation_preview']);
            $results=$service->create((int)current_user()['id'],$draft['rows']);
        } elseif ($action==='resend') {
            $id=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) throw new InvalidArgumentException('Select a pending Nurse invitation.');
            $results=[['user_id'=>$id,'delivery_status'=>$service->resend((int)current_user()['id'],$id)]];
        } else throw new InvalidArgumentException('Choose an invitation action.');
    } catch (InvalidArgumentException|RuntimeException $e) {
        http_response_code($e instanceof PDOException?503:422);
        $error=$e instanceof PDOException?'Nurse invitations are unavailable. Please try again or contact system support.':$e->getMessage();
    }
}
try {
    $pending=$db->query("SELECT u.id,u.first_name,u.last_name,u.email,i.delivery_status,i.expires_at FROM users u JOIN account_activation_invitations i ON i.user_id=u.id AND i.id=(SELECT MAX(latest.id) FROM account_activation_invitations latest WHERE latest.user_id=u.id) WHERE u.role='nurse' AND u.status='pending' AND u.registration_status='approved' ORDER BY i.id DESC LIMIT 50")->fetchAll();
} catch (PDOException $e) { $ready=false; http_response_code(503); $error='Nurse invitations are temporarily unavailable. Please contact system support.'; clinical_database_failure('doctor.nurse_invitations',$e); }
?>
<!doctype html><html lang="en"><head><?php include __DIR__.'/../../includes/head.php'; ?></head><body><div class="app-wrapper"><?php include __DIR__.'/../../components/sidebar.php'; ?><div class="main-content"><?php include __DIR__.'/../../components/navbar.php'; ?>
<main class="content-area clinical-workspace" id="mainContent">
<header class="app-page-head"><h1 class="h4">Invite Nurse</h1><p class="small text-muted">The Nurse sets a private password using a 24-hour email link, then submits credentials for your review.</p><a href="nurse_verification.php">Back to Manage Nurses</a></header>
<?php if($error): ?><div class="alert alert-warning" role="alert"><?=e($error)?></div><?php endif; ?>
<?php if($results!==null): ?><section class="clinical-panel" role="status"><h2>Invitation result</h2><?php foreach($results as $result): ?><p>Account #<?=(int)$result['user_id']?>: <?=e(['sent'=>'Email sent. Receipt is not confirmed.','failed'=>'Email sending failed. The account is pending; check the mail settings and resend.','pending'=>'Sending status unavailable. Check with system support before resending.'][$result['delivery_status']]??'Sending status unavailable.')?></p><?php endforeach; ?></section><?php endif; ?>
<?php if($preview): $person=$preview[0]['account']; ?><section class="clinical-panel"><h2>Review Nurse account</h2><dl><dt>Name</dt><dd><?=e($person['first_name'].' '.$person['last_name'])?></dd><dt>Email</dt><dd class="text-break"><?=e($person['email'])?></dd><dt>Employee ID</dt><dd><?=e($person['institutional_id'])?></dd><dt>Username</dt><dd><?=e($person['username'])?></dd></dl>
<?php if($preview[0]['errors']): ?><p class="text-danger"><?=e(implode(' ',$preview[0]['errors']))?></p><?php elseif(isset($_SESSION['nurse_invitation_preview'])&&$ready): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create"><input type="hidden" name="nonce" value="<?=e($_SESSION['nurse_invitation_preview']['nonce'])?>"><button class="btn btn-success">Create account and send invitation</button></form><?php endif; ?></section><?php endif; ?>
<section class="clinical-panel"><h2>New Nurse</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="preview"><fieldset <?=$ready?'':'disabled'?>><div class="row g-3">
<?php foreach(['first_name'=>'First name','last_name'=>'Last name','username'=>'Username','institutional_id'=>'Employee ID','email'=>'Institutional email'] as $key=>$label): ?><div class="col-12 col-md-6"><label class="form-label" for="nurse-<?=$key?>"><?=$label?></label><input class="form-control" id="nurse-<?=$key?>" name="<?=$key?>" type="<?=$key==='email'?'email':'text'?>" maxlength="<?=$key==='email'?120:($key==='username'?50:($key==='institutional_id'?30:80))?>" required></div><?php endforeach; ?>
</div><button class="btn btn-outline-success mt-3">Review account</button></fieldset></form></section>
<section class="clinical-panel"><h2>Pending invitations</h2><p class="small text-muted">Latest 50 pending Nurse invitations. Resending replaces previous links. Wait one minute after a successful send. Activation does not grant clinical access.</p>
<?php if($ready): ?><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Nurse</th><th>Email</th><th>Email status</th><th>Link expires</th><th>Action</th></tr></thead><tbody><?php foreach($pending as $person): ?><tr><td><?=e($person['first_name'].' '.$person['last_name'])?></td><td><?=e($person['email'])?></td><td><?=e(['sent'=>'Email sent','failed'=>'Sending failed','pending'=>'Status unavailable'][$person['delivery_status']])?></td><td><?=e(format_datetime($person['expires_at']))?></td><td><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="resend"><input type="hidden" name="user_id" value="<?=(int)$person['id']?>"><button class="btn btn-sm btn-outline-success">Resend invitation</button></form></td></tr><?php endforeach; ?><?php if(!$pending): ?><tr><td colspan="5">No pending Nurse invitations.</td></tr><?php endif; ?></tbody></table></div><?php endif; ?></section>
</main></div></div><?php include __DIR__.'/../../components/footer.php'; ?></body></html>
