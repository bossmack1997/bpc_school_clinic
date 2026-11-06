<?php
/** Separate PDO connection for a synthetic two-editor race; never school data. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/queue_consultation.php';
if(APP_ENV!=='local'||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',DB_NAME))throw new RuntimeException('Disposable fixture required.');
$db=getDB();if($db->query('SELECT DATABASE()')->fetchColumn()!==DB_NAME)throw new RuntimeException('Fixture connection mismatch.');
[$script,$ticket,$actor,$version,$note]=$argv;
if(!ctype_digit($ticket)||!ctype_digit($actor)||!preg_match('/^[a-f0-9]{64}$/D',$version)||!in_array($note,['Editor A','Editor B'],true))throw new RuntimeException('Invalid fixture arguments.');
echo "READY\n";fflush(STDOUT);if(trim(fgets(STDIN))!=='GO')exit(1);
try {
    $db->beginTransaction();
    $row=queue_consultation_lock($db,(int)$ticket);
    $visit=queue_consultation_visit($db,$row,(int)$actor);
    $_POST['draft_version']=$version;queue_consultation_check_version($visit);
    queue_consultation_save($db,(int)$visit['id'],['chief_complaint'=>'Concurrent synthetic draft','assessment'=>$note]);
    $db->commit();echo "SAVED\n";
}catch(DomainException $e){if($db->inTransaction())$db->rollBack();echo "CONFLICT\n";}
