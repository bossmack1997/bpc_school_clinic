<?php
/** Isolated two-process service worker, never accepts a school database. */
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../config/config.php';require __DIR__.'/../includes/physician_queue_service.php';
if(APP_ENV!=='local'||!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',DB_NAME))throw new RuntimeException('Disposable claim database required.');
$db=getDB();$actor=$db->prepare('SELECT * FROM users WHERE id=?');$actor->execute([(int)($argv[2]??0)]);$user=$actor->fetch();
if(!$user)throw new RuntimeException('Fixture actor missing.');
echo "READY\n";flush();fgets(STDIN);
try{(new PhysicianQueueService($db,$user))->transition('start',['handoff_id'=>(int)$argv[1],'record_version'=>0]);echo "CLAIMED\n";}
catch(RuntimeException $e){if($e instanceof PDOException)throw $e;echo "CONFLICT\n";}
