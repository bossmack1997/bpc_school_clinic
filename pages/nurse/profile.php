<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$db=getDB(); $u=current_user();
$stmt=$db->prepare("SELECT n.*,u.first_name,u.middle_name,u.last_name,u.status,u.profile_picture FROM nurses n JOIN users u ON u.id=n.user_id WHERE n.user_id=? LIMIT 1");
$stmt->execute([$u['id']]);
$nurse=$stmt->fetch() ?: array_merge($u,['employee_id'=>'','license_number'=>'','specialization'=>'','shift_schedule'=>'']);
$pageTitle='My Profile';
$display=static fn($v)=>($v===null||trim((string)$v)==='')?'Not provided':(string)$v;
?>
<!doctype html><html lang="en"><head><?php include __DIR__.'/../../includes/head.php'; ?></head><body><div class="app-wrapper">
<?php include __DIR__.'/../../components/sidebar.php'; ?><main class="main-content"><?php include __DIR__.'/../../components/navbar.php'; ?><div class="content-area page-enter" id="mainContent">
<div class="card p-4 mb-3"><div class="d-flex flex-wrap align-items-center gap-3"><img src="<?= e(avatar_url($nurse['profile_picture'] ?? null)) ?>" onerror="this.src='<?= e(asset_url('images/default-avatar.svg')) ?>'" class="rounded-circle border" width="88" height="88" style="object-fit:cover" alt="Profile picture"><div><h2 class="h4 mb-1"><?= e(trim($nurse['first_name'].' '.($nurse['middle_name']??'').' '.$nurse['last_name'])) ?></h2><p class="text-muted mb-2">School Clinic Nurse</p><span class="badge bg-success"><?= e(ucfirst($nurse['status']??'active')) ?></span></div><a href="credentials.php" class="btn btn-success ms-auto"><i class="bi bi-patch-check me-1"></i>Professional Credentials</a><a href="settings.php" class="btn btn-outline-success"><i class="bi bi-gear me-1"></i>Account Settings</a></div></div>
<section class="card p-4" aria-labelledby="professionalTitle"><h2 id="professionalTitle" class="h5 mb-3"><i class="bi bi-briefcase text-success me-2"></i>Professional Information</h2><div class="row g-3">
<?php foreach ([['Employee ID','employee_id'],['License Number','license_number'],['Specialization','specialization'],['Shift Schedule','shift_schedule']] as [$label,$field]): ?><div class="col-md-6"><div class="border rounded-3 p-3 h-100"><div class="small text-muted mb-1"><?= e($label) ?></div><div class="fw-semibold"><?= e($display($nurse[$field]??null)) ?></div></div></div><?php endforeach; ?>
</div></section></div></main></div><?php include __DIR__.'/../../components/footer.php'; ?></body></html>
