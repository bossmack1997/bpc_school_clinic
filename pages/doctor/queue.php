<?php
require_once __DIR__.'/../../includes/doctor_page.php';
require_once __DIR__.'/../../includes/physician_queue_service.php';
$view=is_string($_GET['view']??'active')&&($_GET['view']??'active')==='history'?'history':'active';
$pageTitle='Clinic Queue';$error='';$rows=null;$actor=current_user();$service=new PhysicianQueueService(getDB(),$actor);
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_verify_or_die();$action=$_POST['action']??'';
        if (!is_string($action)) throw new InvalidArgumentException('Choose a valid action.');
        $service->transition($action,$_POST);set_flash('success','Physician workflow saved and audited.');redirect('queue.php');
    }
    $rows=$service->rows($view);
} catch (PDOException $e) {clinical_database_failure('doctor.queue',$e);http_response_code(503);$error=in_array((int)($e->errorInfo[1]??0),[1146,1054],true)?'Physician queue setup is incomplete. System support must verify the deployed database schema. Missing data is not an empty queue.':'The physician queue is temporarily unavailable. Please retry. If this continues, contact system support. Missing data is not an empty queue.';}
catch (InvalidArgumentException|RuntimeException $e) {
    http_response_code($e instanceof InvalidArgumentException?422:409);$error=$e->getMessage();
    try{$rows=$service->rows($view);}catch(PDOException $failure){clinical_database_failure('doctor.queue.reload',$failure);http_response_code(503);$rows=null;$error='The physician queue could not be reloaded. No clinical operation was confirmed. Please retry.';}
}
?>
<!doctype html><html lang="en"><head><?php include __DIR__.'/../../includes/head.php'; ?></head><body><div class="app-wrapper">
<?php include __DIR__.'/../../components/sidebar.php'; ?><div class="main-content"><?php include __DIR__.'/../../components/navbar.php'; ?>
<main class="content-area clinical-workspace" id="mainContent">
<header class="clinical-panel d-flex flex-wrap align-items-center justify-content-between gap-3"><div><span class="text-success small fw-semibold">Physician assessment</span><h1 class="h3 mt-1 mb-2">Clinic Queue</h1><p class="text-muted mb-0">Nurse referrals ordered by priority. Claim an assessment to document your clinical decision.</p></div><a class="btn btn-outline-success" href="<?=e($view==='history'?'queue.php?view=history':'queue.php')?>" data-queue-reload>Refresh queue</a></header>
<nav class="clinical-tabs" aria-label="Physician referral views"><a href="queue.php" <?=$view==='active'?'aria-current="page"':''?>>Active referrals</a><a href="queue.php?view=history" <?=$view==='history'?'aria-current="page"':''?>>Completed assessments</a></nav>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?> <a class="btn btn-outline-danger btn-sm" href="queue.php">Reload queue</a></div><?php endif; ?>
<p id="queueLoading" class="small text-muted d-none" role="status" aria-live="polite"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading physician referrals…</p>
<?php if($rows===[]): ?><section class="clinical-panel" role="status"><h2><?=$view==='history'?'No completed physician assessments':'No pending physician referrals'?></h2><p class="mb-0 text-muted"><?=$view==='history'?'No completed assessments are recorded in the loaded referral history.':'No patients are awaiting physician assessment. New referrals appear after a Nurse saves intake and submits the handoff.'?></p></section><?php endif; ?>
<?php foreach($rows??[] as $row): $own=(int)$row['assigned_doctor_id']===(int)$actor['id']; ?>
<section class="clinical-panel"><h2><?=e($row['first_name'].' '.$row['last_name'])?> <span class="badge bg-<?=$row['priority']==='normal'?'success':'danger'?>"><?=e(ucfirst($row['priority']))?></span></h2>
<p class="small text-muted"><?=e($row['queue_number'].' · '.ucfirst($row['role']))?> <span class="badge bg-<?=$row['status']==='awaiting'?'secondary':'primary'?>"><?=e($row['status']==='awaiting'?'Awaiting physician':($row['status']==='completed'?'Completed assessment':'Assessment in progress'))?></span></p>
<a class="btn btn-outline-success mb-3" href="patients.php?patient=<?=(int)$row['patient_user_id']?>">Open clinical record</a>
<dl class="row g-2 mb-3"><dt class="col-sm-4">Referral reason</dt><dd class="col-sm-8"><?=nl2br(e($row['reason']))?></dd><dt class="col-sm-4">Nursing assessment</dt><dd class="col-sm-8"><?=nl2br(e($row['assessment']??'Not recorded'))?></dd></dl>
<details class="mb-3"><summary class="text-success">Intake details and vital signs</summary><dl class="row g-2 mt-2"><?php foreach(['chief_complaint'=>'Chief complaint','symptoms'=>'Symptoms','recommendation'=>'Nursing instructions','temperature'=>'Temperature (°C)','blood_pressure_systolic'=>'Systolic BP (mmHg)','blood_pressure_diastolic'=>'Diastolic BP (mmHg)','heart_rate'=>'Heart rate (bpm)','respiratory_rate'=>'Respiratory rate (/min)','oxygen_saturation'=>'Oxygen saturation (%)','height_cm'=>'Height (cm)','weight_kg'=>'Weight (kg)'] as $field=>$label): ?><dt class="col-sm-4"><?=e($label)?></dt><dd class="col-sm-8"><?=nl2br(e($row[$field]===null||$row[$field]===''?'Not recorded':$row[$field]))?></dd><?php endforeach; ?></dl></details>
<p class="small text-muted">Referred <?=e($row['created_at'])?><?php if($row['completed_at']): ?> / Completed <?=e($row['completed_at'])?><?php endif; ?></p>
<?php if($row['status']==='awaiting'||($row['status']==='in_progress'&&$own)): ?>
<form method="post"><?=csrf_field()?><input type="hidden" name="handoff_id" value="<?=(int)$row['id']?>"><input type="hidden" name="record_version" value="<?=(int)$row['record_version']?>">
<?php if($row['status']==='awaiting'): ?><button class="btn btn-success" name="action" value="start">Start physician assessment</button>
<?php else: ?>
<?php foreach(['chief_complaint'=>'Chief complaint','diagnosis'=>'Physician findings / diagnosis','treatment'=>'Physician treatment plan','prescription'=>'Prescription / medication recommendations','remarks'=>'Follow-up instructions / clinical notes'] as $field=>$label): $value=($_SERVER['REQUEST_METHOD']==='POST'&&(int)($_POST['handoff_id']??0)===(int)$row['id']&&is_string($_POST[$field]??null))?$_POST[$field]:($field==='chief_complaint'?$row['chief_complaint']:''); ?>
<label class="form-label" for="handoff-<?=(int)$row['id']?>-<?=$field?>"><?=e($label)?></label><textarea class="form-control mb-3" id="handoff-<?=(int)$row['id']?>-<?=$field?>" name="<?=$field?>" maxlength="<?=in_array($field,['chief_complaint','diagnosis'],true)?255:5000?>" <?=in_array($field,['chief_complaint','diagnosis','treatment'],true)?'required':''?>><?=e($value??'')?></textarea>
<?php endforeach; ?><label class="form-label" for="follow-<?=(int)$row['id']?>">Follow-up date (optional)</label><input class="form-control mb-3" id="follow-<?=(int)$row['id']?>" name="follow_up_date" type="date" value="<?=e(is_string($_POST['follow_up_date']??null)&&(int)($_POST['handoff_id']??0)===(int)$row['id']?$_POST['follow_up_date']:'')?>"><button class="btn btn-success" name="action" value="complete">Confirm physician decision and complete</button>
<?php endif; ?></form>
<?php elseif($row['status']==='completed'): ?><a class="btn btn-outline-success" href="patients.php?patient=<?=(int)$row['patient_user_id']?>&amp;tab=consultations">View documented encounter #<?=(int)$row['consultation_id']?></a><?php else: ?><p class="text-muted">Assessment claimed by another Doctor. Only the assigned Doctor can complete it.</p><?php endif; ?></section>
<?php endforeach; ?><p class="small text-muted">Up to 100 <?=$view==='history'?'completed physician assessments':'active physician handoffs'?>. Completion records a physician encounter; nursing intake and the operational clinic ticket remain under nursing control.</p>
</main></div></div><?php include __DIR__.'/../../components/footer.php'; ?><script>
document.querySelectorAll('[data-queue-reload]').forEach(link=>link.addEventListener('click',()=>{document.getElementById('queueLoading').classList.remove('d-none');}));
document.querySelectorAll('main form').forEach(form=>form.addEventListener('submit',()=>{document.getElementById('queueLoading').classList.remove('d-none');form.querySelectorAll('button').forEach(button=>{button.classList.add('disabled');button.setAttribute('aria-disabled','true');});}));
</script></body></html>
