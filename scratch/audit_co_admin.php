<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';
$db=getDB();
if(is_file(__DIR__.'/backups/co-admin-permissions-20261022/permissions-before.json')){fwrite(STDERR,'Before-image already exists; refusing to overwrite it.'.PHP_EOL);exit(1);}
$backup=['captured_at'=>date(DATE_ATOM),'users'=>$db->query("SELECT id,role,admin_type,status FROM users WHERE role='admin'")->fetchAll(),'admin_permissions'=>$db->query('SELECT * FROM admin_permissions ORDER BY user_id,permission_key')->fetchAll()];
file_put_contents(__DIR__.'/backups/co-admin-permissions-20261022/permissions-before.json',json_encode($backup,JSON_PRETTY_PRINT));
echo 'Admin classifications: '.json_encode($db->query("SELECT admin_type,COUNT(*) AS count FROM users WHERE role='admin' GROUP BY admin_type")->fetchAll()).PHP_EOL;
echo 'Permission keys: '.json_encode($db->query('SELECT permission_key,COUNT(*) AS count FROM admin_permissions GROUP BY permission_key')->fetchAll()).PHP_EOL;
foreach($db->query('SHOW COLUMNS FROM admin_permissions') as $r)echo $r['Field'].' '.$r['Type'].PHP_EOL;
echo 'Indexes: '.json_encode(array_map(fn($r)=>[$r['Key_name'],$r['Column_name'],$r['Non_unique']],$db->query('SHOW INDEX FROM admin_permissions')->fetchAll())).PHP_EOL;
session_destroy();
