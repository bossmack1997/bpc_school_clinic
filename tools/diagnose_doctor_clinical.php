<?php
/** CLI-only read-only source check. Never prints patient rows or credentials. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/doctor_clinical_service.php';
require_once __DIR__.'/../includes/nurse_verification_service.php';
$db=getDB();$result=['native_prepares'=>!$db->getAttribute(PDO::ATTR_EMULATE_PREPARES),'checked_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('c')];
$probe=static function(string $operation,callable $read)use(&$result):void {
    try{$result[$operation]=['available'=>true,'result'=>$read()];}
    catch(PDOException $e){clinical_database_failure('doctor.diagnostic.'.$operation,$e);$result[$operation]=['available'=>false,'sqlstate'=>$e->getCode(),'driver'=>(int)($e->errorInfo[1]??0)];}
};
// Every application query below is SELECT/SHOW; no schema or authorization changes.
$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
try {
    $staff=new NurseVerificationService($db);
    $result['review_schema']=['ready'=>$staff->ready(),'missing'=>$staff->missingSchema()];
    foreach(['student'=>'students','teacher'=>'teachers'] as $role=>$table) {
        $probe($role.'_profile_integrity',static fn()=>$db->query("SELECT COUNT(*) AS accounts_without_profile FROM users u LEFT JOIN $table p ON p.user_id=u.id WHERE u.role='$role' AND p.id IS NULL")->fetch());
    }
    $doctor=$db->query("SELECT u.* FROM users u JOIN admin_profiles p ON p.user_id=u.id WHERE p.profile='doctor' AND u.status='active' ORDER BY u.id LIMIT 1")->fetch();
    if(!$doctor){$result['service_checks']='Skipped: no active Doctor profile. No authority was manufactured.';}
    else {
        $clinical=new DoctorClinicalService($db,$doctor);
        $probe('command_center',static function()use($clinical){$data=$clinical->commandCenter();$out=['unavailable'=>$data['unavailable']];foreach(['today_consultations','awaiting_review','pending_nurses'] as $key)$out[$key]=$data[$key];foreach(['followups','escalations','alerts','stock','activity'] as $key)$out[$key.'_loaded_up_to_10']=$data[$key]===null?null:count($data[$key]);return $out;});
        foreach(['student','teacher'] as $role) {
            $probe($role.'_directory',static function()use($clinical,$role){return ['loaded_up_to_50'=>count($clinical->patients('',$role))];});
            $probe($role.'_overview',static function()use($clinical,$db,$role){$table=$role==='student'?'students':'teachers';$id=$db->query("SELECT u.id FROM users u JOIN $table p ON p.user_id=u.id WHERE u.role='$role' ORDER BY u.id LIMIT 1")->fetchColumn();if(!$id)return ['skipped'=>'No associated patient profile'];$data=$clinical->overview((int)$id);return ['unavailable'=>$data['unavailable'],'consultations_loaded_up_to_50'=>$data['consultations']===null?null:count($data['consultations']),'documents_loaded_up_to_50'=>$data['documents']===null?null:count($data['documents'])];});
        }
    }
}catch(PDOException $e){clinical_database_failure('doctor.diagnostic.setup',$e);$result['setup_error']=['sqlstate'=>$e->getCode(),'driver'=>(int)($e->errorInfo[1]??0)];}
finally{if($db->inTransaction())$db->rollBack();}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
