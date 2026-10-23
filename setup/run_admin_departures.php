<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Operator CLI only.');}
ini_set('session.save_path',__DIR__.'/../scratch');
require __DIR__.'/../config/config.php';require __DIR__.'/../includes/admin_profile_service.php';
try {
 $db=getDB();$count=(new AdminProfileService($db))->applyDue();
 $db->prepare("INSERT INTO system_settings(setting_key,setting_value) VALUES ('admin_departures_last_run',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([date('c')]);
 echo 'Applied authorized departures: '.$count.PHP_EOL;
}catch(Throwable $e){error_log('Administrator departure worker failed; code='.(string)$e->getCode());fwrite(STDERR,'Departure worker failed. Review server logs.'.PHP_EOL);exit(1);}
