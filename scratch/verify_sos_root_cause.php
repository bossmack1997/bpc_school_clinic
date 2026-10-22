<?php
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';require __DIR__.'/../includes/ai/MICAAuthorization.php';
foreach(['nurse','admin'] as $role)echo $role.' MICA sos_update_status allowed: '.(MICAAuthorization::actionAllowed($role,'sos_update_status')?'yes':'no').PHP_EOL;
session_destroy();
