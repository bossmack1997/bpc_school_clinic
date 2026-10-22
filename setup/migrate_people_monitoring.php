<?php
/** Additive, idempotent MICA/people monitoring schema; operator CLI only. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Operator CLI only.');}
ini_set('session.save_path',__DIR__.'/../scratch');
require __DIR__.'/../config/config.php';
$db=getDB();
foreach(['migration_mica_hub.sql','migration_people_monitoring.sql'] as $file){$db->exec(file_get_contents(__DIR__.'/../database/'.$file));echo 'Applied '.$file.PHP_EOL;}
