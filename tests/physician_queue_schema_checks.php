<?php
/** Schema-only disposable fixture; no configured school records are touched. */
if(!isset($database)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',$database)||$db->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable queue fixture required.');
require_once __DIR__.'/../includes/clinical_diagnostics.php';
function physicianSql(PDO $db,string $name): array {
 $sql=preg_replace('/^\s*--.*$/m','',file_get_contents(__DIR__.'/../database/'.$name));$results=[];
 foreach(array_filter(array_map('trim',explode(';',$sql))) as $command){$statement=$db->query($command);if($statement->columnCount())$results[]=$statement->fetchAll();$statement->closeCursor();}
 return $results;
}
function physicianPreflight(): int {
 $process=proc_open([PHP_BINARY,'-d','session.save_path='.__DIR__.'/../scratch',__DIR__.'/../tools/preflight_physician_queue.php','--queue-only'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);return proc_close($process);
}
checkMis(physicianPreflight()===2,'Queue-only deployment preflight exits 2 for missing handoff table');
try{$db->query('SELECT id FROM physician_queue LIMIT 0');checkMis(false,'Missing handoff detected');}
catch(PDOException $e){checkMis(($e->errorInfo[0]??'')==='42S02'&&(int)($e->errorInfo[1]??0)===1146,'Actual missing physician table throws SQLSTATE 42S02 / 1146');clinical_database_failure('fixture.physician_queue.missing',$e);}
$missingResponse=misRequest($invitee,'/api/physician_queue.php?action=list',null,true);$missingJson=json_decode($missingResponse['body'],true);
checkMis($missingResponse['status']===503&&($missingJson['success']??true)===false&&($missingJson['error_code']??'')==='PHYSICIAN_SCHEMA_UNAVAILABLE'&&!array_key_exists('data',$missingJson)&&!str_contains($missingResponse['body'],'SQLSTATE'),'Authenticated missing-schema API returns structured 503 without fake empty data or raw SQL');
checkMis(misRequest($c,'/api/physician_queue.php?action=list',null,true)['status']===403,'Missing schema never grants MIS physician access');
checkMis(misRequest(misClient(),'/api/physician_queue.php?action=list',null,true)['status']===401,'Unauthenticated queue API remains HTTP 401');
physicianSql($db,'preflight_physician_queue_hostinger_20261030.sql');
checkMis((int)$db->query('SELECT @physician_prerequisites_ready')->fetchColumn()===1,'Hostinger SQL preflight verifies exact queue dependencies');
// Guard must refuse to create a handoff table with incomplete upstream schema.
$db->exec('ALTER TABLE walk_in_visits CHANGE COLUMN symptoms fixture_missing_symptoms TEXT NULL');
try{
 physicianSql($db,'migration_physician_queue_hostinger_20261030.sql');
 checkMis((int)$db->query('SELECT @physician_prerequisites_ready')->fetchColumn()===0&&(int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='physician_queue'")->fetchColumn()===0,'Guarded Hostinger migration blocks incomplete intake schema without creating a partial table');
}finally{$db->exec('ALTER TABLE walk_in_visits CHANGE COLUMN fixture_missing_symptoms symptoms TEXT NULL');}
physicianSql($db,'migration_physician_queue_hostinger_20261030.sql');
physicianSql($db,'migration_physician_queue_hostinger_20261030.sql');
checkMis((int)$db->query('SELECT @physician_prerequisites_ready')->fetchColumn()===1,'Guarded additive Hostinger migration runs twice');
checkMis(physicianPreflight()===0,'Queue-only deployment preflight passes installed schema and constraints');
physicianSql($db,'verify_physician_queue_hostinger_20261030.sql');
$emptyResponse=misRequest($invitee,'/api/physician_queue.php?action=list',null,true);$emptyJson=json_decode($emptyResponse['body'],true);
checkMis($emptyResponse['status']===200&&($emptyJson['success']??false)&&($emptyJson['data']??null)===[],'After migration the same Doctor receives HTTP 200 and a genuine empty queue');
$emptyPage=misRequest($invitee,'/pages/doctor/queue.php');
checkMis($emptyPage['status']===200&&str_contains($emptyPage['body'],'No pending physician referrals')&&!str_contains($emptyPage['body'],'queue setup is incomplete'),'Migrated empty queue renders truthful empty state');
$db->exec('ALTER TABLE physician_queue CHANGE COLUMN priority fixture_missing_priority VARCHAR(20)');
try{$response=misRequest($invitee,'/api/physician_queue.php?action=list',null,true);checkMis($response['status']===503&&(json_decode($response['body'],true)['error_code']??'')==='PHYSICIAN_SCHEMA_UNAVAILABLE','Existing incompatible handoff columns fail truthfully instead of returning zero');checkMis(physicianPreflight()===1,'Queue deployment preflight rejects incompatible existing columns');}
finally{$db->exec("ALTER TABLE physician_queue CHANGE COLUMN fixture_missing_priority priority ENUM('normal','urgent','emergency') NOT NULL DEFAULT 'normal'");}
checkMis(misRequest($invitee,'/api/physician_queue.php?action[]=list',null,true)['status']===400,'Malformed queue operation returns HTTP 400');
file_put_contents(__DIR__.'/../scratch/physician-queue-http-evidence-20261030.json',json_encode(['before'=>['http'=>$missingResponse['status'],'json'=>$missingJson],'after'=>['http'=>$emptyResponse['status'],'json'=>$emptyJson],'sqlstate'=>'42S02','driver'=>1146,'production_changed'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
