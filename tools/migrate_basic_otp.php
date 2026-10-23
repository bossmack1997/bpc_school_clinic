<?php
/** Additive schema migration. No activation, backfill or permission changes. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$migrationReady=false;
register_shutdown_function(function()use(&$migrationReady){if(!$migrationReady){fwrite(STDERR,"Basic OTP migration did not complete.\n");exit(1);}});
ini_set('session.save_path',sys_get_temp_dir());
require __DIR__.'/../config/config.php';
if(!in_array('--apply',$argv,true)){fwrite(STDERR,"Usage: php tools/migrate_basic_otp.php --apply\nOnly adds email_verified_at and self_service_only columns.\n");exit(1);}
require_once __DIR__.'/../includes/registration_schema.php';
migrate_basic_registration_schema(getDB());
$migrationReady=true;echo "Basic OTP schema ready. No account statuses, permissions or timestamps were backfilled.\n";
