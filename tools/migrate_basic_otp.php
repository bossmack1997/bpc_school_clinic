<?php
/** Additive schema migration. No activation, backfill or permission changes. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());
require __DIR__.'/../config/config.php';
if(!in_array('--apply',$argv,true)){fwrite(STDERR,"Usage: php tools/migrate_basic_otp.php --apply\nOnly adds email_verified_at and self_service_only columns.\n");exit(1);}
$db=getDB();
$columns=$db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('email_verified_at',$columns,true))$db->exec('ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL');
if(!in_array('self_service_only',$columns,true))$db->exec('ALTER TABLE users ADD COLUMN self_service_only TINYINT(1) NOT NULL DEFAULT 0');
echo "Basic OTP schema ready. No account statuses, permissions or timestamps were backfilled.\n";
