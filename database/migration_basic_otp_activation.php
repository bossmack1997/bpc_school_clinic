<?php
/** Portable MySQL/MariaDB CLI migration; preserves all existing account states. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/registration_schema.php';
try{$added=migrate_basic_registration_schema(getDB());echo $added?'Added registration columns: '.implode(', ',$added).PHP_EOL:'Registration schema already current.'.PHP_EOL;}
catch(Throwable $e){fwrite(STDERR,'Registration schema migration failed; code='.(string)$e->getCode().PHP_EOL);exit(1);}
