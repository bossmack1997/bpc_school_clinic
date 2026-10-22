<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Operator CLI only.');}ini_set('session.save_path',__DIR__.'/../scratch');require __DIR__.'/../config/config.php';require __DIR__.'/../includes/admin_profile_service.php';echo 'Applied authorized departures: '.(new AdminProfileService(getDB()))->applyDue().PHP_EOL;
