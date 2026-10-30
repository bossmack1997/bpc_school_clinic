<?php
require_once __DIR__.'/../../includes/doctor_page.php';
require_once __DIR__.'/../../includes/doctor_clinical_service.php';
$data=(new DoctorClinicalService(getDB(),current_user()))->commandCenter();$pageTitle='Doctor Dashboard';
?>
<!doctype html><html lang="en"><head><?php include __DIR__.'/../../includes/head.php'; ?></head><body><div class="app-wrapper">
<?php include __DIR__.'/../../components/sidebar.php'; ?><div class="main-content"><?php include __DIR__.'/../../components/navbar.php'; ?>
<main class="content-area clinical-workspace" id="mainContent">
<header class="app-page-head mb-3"><div><h1 class="h3 mb-1">Doctor Dashboard</h1><p class="text-muted mb-1">Clinical oversight and the next actions for your team.</p><small class="text-muted">Updated <?=e($data['generated_at'])?></small></div><a class="btn btn-outline-success" href="dashboard.php" data-clinical-reload>Refresh overview</a></header>
<div class="clinical-grid clinical-kpis">
<?php foreach(['patients_seen_today'=>["Today's Patients",'patients.php'],'awaiting_doctor'=>['Waiting for Doctor','queue.php'],'pending_nurses'=>['Nurses to Review','nurse_verification.php'],'followup_visits'=>['Follow-up Visits','consultations.php?tab=followups']] as $key=>[$label,$url]): ?>
<section class="clinical-panel p-3" data-metric="<?=e($key)?>"><h2 class="h6"><i class="bi bi-<?=e(['patients_seen_today'=>'people','awaiting_doctor'=>'clipboard-pulse','pending_nurses'=>'person-check','followup_visits'=>'calendar-check'][$key])?> me-2" aria-hidden="true"></i><a href="<?=e($url)?>"><?=e($label)?></a></h2>
<?php if($data[$key]===null): ?><p class="small text-muted mb-0" role="status">Couldn't load this count. Please try again.</p><?php else: ?><strong class="clinical-metric"><?=(int)$data[$key]?></strong><?php endif; ?></section>
<?php endforeach; ?></div>
<p class="small text-muted">Patients today counts distinct patients with a physician encounter today. Waiting counts unclaimed referrals; follow-ups counts scheduled visits from today onward.</p>
<div class="clinical-work-grid">
<section class="clinical-panel"><div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><h2>Physician referrals</h2><a href="queue.php">Open Clinic Queue</a></div>
<?php if($data['physician_referrals']===null): ?><p role="status">Physician referrals are temporarily unavailable. <a href="dashboard.php">Retry</a></p><?php elseif(!$data['physician_referrals']): ?><p class="text-muted mb-0">No active physician referrals.</p><?php else: ?><ul class="clinical-list"><?php foreach($data['physician_referrals'] as $referral): ?><li><a href="queue.php"><?=e($referral['first_name'].' '.$referral['last_name'])?></a><small class="d-block text-muted"><?=e(ucfirst($referral['priority']).' / '.str_replace('_',' ',$referral['status']))?></small></li><?php endforeach; ?></ul><?php endif; ?></section>
<section class="clinical-panel"><div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><h2>Nurse credential reviews</h2><a href="nurse_verification.php">Manage Nurses</a></div>
<?php if($data['nurse_reviews']===null): ?><p role="status">Credential review requests are temporarily unavailable. <a href="dashboard.php">Retry</a></p><?php elseif(!$data['nurse_reviews']): ?><p class="text-muted mb-0">No pending clinical credential reviews.</p><?php else: ?><ul class="clinical-list"><?php foreach($data['nurse_reviews'] as $review): ?><li><a href="nurse_verification.php?view=<?=(int)$review['user_id']?>"><?=e($review['first_name'].' '.$review['last_name'])?></a><small class="d-block text-muted">Pending clinical review</small></li><?php endforeach; ?></ul><?php endif; ?></section>
</div>
<section class="clinical-panel"><h2>Upcoming visits</h2>
<?php if($data['followups']===null): ?><p class="small text-muted mb-0" role="status">Follow-up visits couldn't be loaded.</p><?php elseif(!$data['followups']): ?><p class="small text-muted mb-0">No upcoming follow-up visits recorded.</p><?php else: ?><ul class="clinical-list"><?php foreach($data['followups'] as $item): ?><li><a href="patients.php?patient=<?=(int)$item['patient_user_id']?>"><?=e($item['first_name'].' '.$item['last_name'])?></a><small class="d-block text-muted"><?=e($item['follow_up_date'])?></small></li><?php endforeach; ?></ul><?php endif; ?></section>
<?php if($data['alerts']===null||$data['stock']===null||$data['alerts']||$data['stock']): ?><section class="clinical-panel"><h2>Clinic updates</h2>
<?php foreach(['alerts'=>'Emergency reports','stock'=>'Medicine stock'] as $key=>$label): ?>
<?php if($data[$key]===null): ?><p class="small text-muted" role="status"><?=e($label)?> couldn't be loaded.</p><?php elseif($data[$key]): ?><details class="mb-2"><summary><?=e($label)?></summary><ul class="clinical-list mt-2"><?php foreach($data[$key] as $item): ?><li><?php if($key==='alerts'): ?><a href="emergencies.php">SOS #<?=(int)$item['id']?>: <?=e($item['emergency_type'])?></a><small class="d-block text-muted"><?=e($item['status'])?></small><?php else: ?><a href="inventory.php"><?=e($item['medicine_name'])?></a><small class="d-block text-muted">Usable quantity: <?=(int)$item['usable_stock']?></small><?php endif; ?></li><?php endforeach; ?></ul></details><?php endif; ?>
<?php endforeach; ?></section><?php endif; ?>
<details class="clinical-panel"><summary>Recent clinical activity</summary>
<?php if($data['activity']===null): ?><p class="small text-muted mt-2" role="status">Activity couldn't be loaded.</p><?php elseif(!$data['activity']): ?><p class="small text-muted mt-2">No recent clinical activity.</p><?php else: ?><ul class="clinical-list mt-2"><?php foreach($data['activity'] as $item): ?><li><?=e($item['action'])?><small class="d-block text-muted"><?=e($item['created_at'])?></small></li><?php endforeach; ?></ul><?php endif; ?></details>
</main></div></div><?php include __DIR__.'/../../components/footer.php'; ?><script src="<?=e(asset_url('js/doctor-clinical.js',true))?>"></script></body></html>
