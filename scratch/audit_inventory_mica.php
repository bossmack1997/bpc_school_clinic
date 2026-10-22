<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';$db=getDB();
$data=[];foreach(['medicines','medicine_logs','admin_permissions'] as $table){$data[$table]=$db->query("SELECT * FROM $table ORDER BY id")->fetchAll();echo $table.' records: '.count($data[$table]).PHP_EOL;}
$data['settings']=$db->query("SELECT * FROM system_settings WHERE setting_key LIKE 'inventory_%' OR setting_key LIKE 'mica_stock_%'")->fetchAll();
$path=__DIR__.'/backups/inventory-mica-20261022/data-before.json';if(is_file($path))exit(1);file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT));
foreach(['medicines','medicine_logs'] as $table){echo $table.' columns: ';echo implode(',',array_column($db->query("SHOW COLUMNS FROM $table")->fetchAll(),'Field')).PHP_EOL;}
session_destroy();
