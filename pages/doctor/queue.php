<?php
require_once __DIR__.'/../../includes/doctor_page.php';
require_once __DIR__.'/../../includes/physician_queue_service.php';
$pageTitle='Clinic Queue';$error='';$rows=null;$service=new PhysicianQueueService(getDB(),current_user());
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_verify_or_die();$action=$_POST['action']??'';
        if (!is_string($action)) throw new InvalidArgumentException('Choose a valid action.');
        $service->transition($action,$_POST);set_flash('success','Physician workflow saved and audited.');redirect('queue.php');
    }
    $rows=$service->rows();
} catch (PDOException $e) {clinical_database_failure('doctor.queue',$e);http_response_code(503);$error='The physician queue is temporarily unavailable. Please retry. If this continues, contact system support. Missing data is not an empty queue.';}
catch (InvalidArgumentException|RuntimeException $e) {http_response_code(422);$error=$e->getMessage();$rows=$service->rows();}
?>
<!doctype html><html lang="en"><head><?php include __DIR__.'/../../includes/head.php'; ?></head><body><div class="app-wrapper">
<?php include __DIR__.'/../../components/sidebar.php'; ?><div class="main-content"><?php include __DIR__.'/../../components/navbar.php'; ?>
<main class="content-area clinical-workspace" id="mainContent">
<header class="clinical-hero"><span class="clinical-kicker">Physician assessment</span><h1 class="h3 mt-2">Clinic Queue</h1><p>Nurse-reviewed referrals for Student and Teacher patients, ordered by clinical priority. Start an assessment to claim it before documenting the physician decision.</p></header>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?=e($error)?> <a class="btn btn-outline-danger btn-sm" href="queue.php">Reload queue</a></div><?php endif; ?>
<?php if($rows===[]): ?><section class="clinical-panel"><p class="mb-0">No patients are awaiting physician assessment.</p></section><?php endif; ?>
<?php foreach($rows??[] as $row): $own=(int)$row['assigned_doctor_id']===(int)current_user()['id']; ?>
<section class="clinical-panel"><h2><?=e($row['first_name'].' '.$row['last_name'])?> <span class="badge bg-<?=$row['priority']==='normal'?'success':'danger'?>"><?=e(ucfirst($row['priority']))?></span></h2>
<p><?=e($row['queue_number'].' · '.ucfirst($row['role']).' · '.$row['status'])?></p>
<a class="btn btn-outline-success mb-3" href="patients.php?patient=<?=(int)$row['patient_user_id']?>">Open clinical record</a>
<dl><dt>Referral reason</dt><dd><?=nl2br(e($row['reason']))?></dd><?php foreach(['chief_complaint'=>'Chief complaint','symptoms'=>'Symptoms','assessment'=>'Nursing assessment','recommendation'=>'Nursing instructions','temperature'=>'Temperature (°C)','blood_pressure_systolic'=>'Systolic BP (mmHg)','blood_pressure_diastolic'=>'Diastolic BP (mmHg)','heart_rate'=>'Heart rate (bpm)','respiratory_rate'=>'Respiratory rate (/min)','oxygen_saturation'=>'Oxygen saturation (%)','height_cm'=>'Height (cm)','weight_kg'=>'Weight (kg)'] as $field=>$label): ?><dt><?=e($label)?></dt><dd><?=nl2br(e($row[$field]===null||$row[$field]===''?'Not recorded':$row[$field]))?></dd><?php endforeach; ?></dl>
<?php if($row['status']==='awaiting'||$own): ?>
<form method="post"><?=csrf_field()?><input type="hidden" name="handoff_id" value="<?=(int)$row['id']?>"><input type="hidden" name="record_version" value="<?=(int)$row['record_version']?>">
<?php if($row['status']==='awaiting'): ?><button class="btn btn-success" name="action" value="start">Start physician assessment</button>
<?php else: ?>
<?php foreach(['chief_complaint'=>'Chief complaint','diagnosis'=>'Physician findings / diagnosis','treatment'=>'Physician treatment plan','prescription'=>'Prescription / medication recommendations','remarks'=>'Follow-up instructions / clinical notes'] as $field=>$label): $value=($_SERVER['REQUEST_METHOD']==='POST'&&(int)($_POST['handoff_id']??0)===(int)$row['id']&&is_string($_POST[$field]??null))?$_POST[$field]:($field==='chief_complaint'?$row['chief_complaint']:''); ?>
<label class="form-label" for="handoff-<?=(int)$row['id']?>-<?=$field?>"><?=e($label)?></label><textarea class="form-control mb-3" id="handoff-<?=(int)$row['id']?>-<?=$field?>" name="<?=$field?>" maxlength="<?=in_array($field,['chief_complaint','diagnosis'],true)?255:5000?>" <?=in_array($field,['chief_complaint','diagnosis','treatment'],true)?'required':''?>><?=e($value??'')?></textarea>
<?php endforeach; ?><label class="form-label" for="follow-<?=(int)$row['id']?>">Follow-up date (optional)</label><input class="form-control mb-3" id="follow-<?=(int)$row['id']?>" name="follow_up_date" type="date" value="<?=e(is_string($_POST['follow_up_date']??null)&&(int)($_POST['handoff_id']??0)===(int)$row['id']?$_POST['follow_up_date']:'')?>"><button class="btn btn-success" name="action" value="complete">Confirm physician decision and complete</button>
<?php endif; ?></form>
<?php else: ?><p class="text-muted">Assessment claimed by another Doctor. Only the assigned Doctor can complete it.</p><?php endif; ?></section>
<?php endforeach; ?><p class="small text-muted">Up to 100 active physician handoffs. Completion records a physician encounter; nursing intake and the operational clinic ticket remain under nursing control.</p>
</main></div></div><?php include __DIR__.'/../../components/footer.php'; ?></body></html>
