<?php
require_once __DIR__.'/../../includes/doctor_page.php';
require_once __DIR__.'/../../includes/doctor_clinical_service.php';
$data=(new DoctorClinicalService(getDB(),current_user()))->commandCenter();$pageTitle='Clinical Command Center';
?>
<!doctype html><html lang="en"><head><?php include __DIR__.'/../../includes/head.php'; ?></head><body><div class="app-wrapper">
<?php include __DIR__.'/../../components/sidebar.php'; ?><div class="main-content"><?php include __DIR__.'/../../components/navbar.php'; ?>
<main class="content-area clinical-workspace" id="mainContent">
<header class="clinical-hero"><span class="clinical-kicker">Doctor / Clinical Co-Administrator</span><h1 class="h3 mt-2">Clinical Command Center</h1><p>Review clinical priorities, follow patient care and support nursing escalations. Clinical decisions remain with the attending professional.</p><small>Updated <?=e($data['generated_at'])?></small></header>
<?php if($data['unavailable']): ?><div class="alert alert-warning" role="alert">Some clinical sources are temporarily unavailable. Unavailable data is not a zero count. Refresh or contact MIS if this continues.</div><?php endif; ?>
<div class="clinical-grid">
<section class="clinical-panel"><h2><a href="nurse_verification.php">Pending Nurse verifications</a></h2><strong class="clinical-metric"><?=$data['pending_nurses']===null?'Unavailable':(int)$data['pending_nurses']?></strong><p class="small text-muted mb-0">Credential applications awaiting physician review.</p></section>
<?php foreach(['today_consultations'=>'Consultations today','awaiting_review'=>'Patients in today’s active queue'] as $key=>$label): ?><section class="clinical-panel"><h2><?=e($label)?></h2><strong class="clinical-metric"><?= $data[$key]===null?'Unavailable':(int)$data[$key] ?></strong><p class="small text-muted mb-0"><?=$key==='awaiting_review'?'Waiting, called or serving; queue membership does not imply a physician review request.':'Recorded encounters, not unique patients.'?></p></section><?php endforeach; ?>
<section class="clinical-panel"><h2>Upcoming follow-ups</h2><strong class="clinical-metric"><?=$data['followups']===null?'Unavailable':count($data['followups'])?></strong><p class="small text-muted mb-0">Next 10 dated follow-ups, today onward. A recorded date does not establish completion.</p></section></div>
<nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Clinical quick actions"><a class="btn btn-success" href="patients.php">Patient &amp; Consultation Records</a><a class="btn btn-outline-success" href="inventory.php">Medicine oversight</a><a class="btn btn-outline-danger" href="emergencies.php">Emergency oversight</a><a class="btn btn-outline-success" href="reports.php">Clinical reports</a></nav>
<div class="row g-3">
<?php foreach(['followups'=>'Scheduled clinical follow-ups','escalations'=>'Nurse referrals to a physician','alerts'=>'Important clinical alerts','stock'=>'Medicine-related concerns','activity'=>'Recent clinical activity'] as $key=>$label): ?><div class="col-12 col-lg-6"><section class="clinical-panel h-100"><h2><?=e($label)?></h2>
<?php if($data[$key]===null): ?><p class="text-muted" role="status">This source could not be loaded. Please retry later.</p><?php elseif(!$data[$key]): ?><p class="text-muted mb-0">No matching <?=e(strtolower($label))?> recorded.</p><?php else: ?><ul class="clinical-list"><?php foreach($data[$key] as $item): ?><li>
<?php if(in_array($key,['followups','escalations'],true)): ?><a href="patients.php?patient=<?=(int)$item['patient_user_id']?>"><?=e($item['first_name'].' '.$item['last_name'])?></a><small class="d-block text-muted"><?=e($item['follow_up_date']??($item['priority'].' · '.$item['status']))?></small>
<?php elseif($key==='alerts'): ?><a href="emergencies.php">SOS #<?=(int)$item['id']?> · <?=e($item['emergency_type'])?></a><small class="d-block text-muted"><?=e($item['status'])?></small>
<?php elseif($key==='stock'): ?><a href="inventory.php"><?=e($item['medicine_name']??'Medicine stock')?></a><small class="d-block text-muted"><?=e($item['stock_status']??'Review inventory')?> · Usable quantity <?=(int)$item['usable_stock']?></small>
<?php else: ?><strong><?=e($item['action'])?></strong><small class="d-block text-muted"><?=e($item['created_at'])?></small><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if($key==='escalations'): ?><p class="small text-muted mt-3 mb-0">Existing nurse-created support referrals addressed to Doctor/Physician only. Other referrals are not counted as physician escalations.</p><?php endif; ?>
</section></div><?php endforeach; ?></div>
</main></div></div><?php include __DIR__.'/../../components/footer.php'; ?></body></html>
