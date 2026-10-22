<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';$db=getDB();
foreach(['qr_scan_logs','qr_checkins','queue_numbers','consultations','certificates','medicine_dispensing','walk_in_visits'] as $table){try{echo $table.': '.json_encode($db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN)).PHP_EOL;}catch(Throwable $e){echo $table.': unavailable'.PHP_EOL;}}
echo 'scan_count: '.$db->query('SELECT COUNT(*) FROM qr_scan_logs')->fetchColumn().PHP_EOL;
