<?php
if(PHP_SAPI!=='cli')exit;ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';$db=getDB();$db->exec(file_get_contents(__DIR__.'/../database/migration_administrative_profiles.sql'));
$db->exec("INSERT IGNORE INTO admin_profiles(user_id,profile,appointment_reference,verified_by) SELECT id,'mis','Existing primary administrator mapped after role audit 2026-10-22',id FROM users WHERE id=1 AND role='admin' AND admin_type='primary_admin'");
$db->exec("INSERT IGNORE INTO admin_profiles(user_id,profile,appointment_reference,verified_by) SELECT id,'legacy','Existing co-admin retained; clinical appointment not inferred',1 FROM users WHERE id=167 AND role='admin' AND admin_type='co_admin'");
echo "Additive profile migration applied; explicit mapping 1=MIS, 167=legacy when existing account types match.\n";
foreach($db->query("SHOW COLUMNS FROM consultations WHERE Field IN ('student_id','teacher_id','nurse_id','clinician_user_id')")->fetchAll() as $r)echo $r['Field'].' nullable='.$r['Null'].PHP_EOL;
