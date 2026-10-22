<?php
if(PHP_SAPI!=='cli')exit;
ini_set('session.save_path',__DIR__);require __DIR__.'/../config/config.php';
$before=json_decode(file_get_contents(__DIR__.'/backups/co-admin-permissions-20261022/permissions-before.json'),true);
$now=getDB()->query("SELECT id,role,admin_type,status FROM users WHERE role='admin'")->fetchAll();
$perms=getDB()->query('SELECT * FROM admin_permissions ORDER BY user_id,permission_key')->fetchAll();
$ok=$before['users']===$now&&$before['admin_permissions']===$perms;
session_destroy();echo ($ok?'PASS':'FAIL').' existing administrator IDs, roles, classifications, statuses and permission rows match pre-change snapshot.'.PHP_EOL;if(!$ok)exit(1);
