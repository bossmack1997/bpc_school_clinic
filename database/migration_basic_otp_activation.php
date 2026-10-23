<?php
/** Portable MySQL/MariaDB CLI migration; preserves all existing account states. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$migrationComplete=false;
register_shutdown_function(function()use(&$migrationComplete){if(!$migrationComplete){fwrite(STDERR,"Registration schema migration did not complete.\n");exit(1);}});
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/registration_schema.php';
try{$added=migrate_basic_registration_schema(getDB());$migrationComplete=true;echo $added?'Added registration columns: '.implode(', ',$added).PHP_EOL:'Registration schema already current.'.PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,'Registration schema migration failed; code='.(string)$e->getCode().PHP_EOL);exit(1);}
