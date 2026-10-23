<?php
/** Compatibility entry point: workflow tests now use a disposable schema-only database. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/mis_admin_http.php').' --doctor-clinical --staff-verification',$status);
exit($status);
