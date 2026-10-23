<?php
require_once __DIR__.'/../config/config.php';
require_doctor();
require_once __DIR__.'/clinical_diagnostics.php';
ob_start();
set_exception_handler(static function(Throwable $error): void {
    if($error instanceof PDOException)clinical_database_failure('doctor.page.unhandled',$error);
    else error_log('Doctor page failure: '.get_class($error).' code='.$error->getCode());
    if(ob_get_length())ob_clean();
    http_response_code(503);header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Clinical workspace unavailable</title><link rel="stylesheet" href="'.e(asset_url('css/bootstrap.min.css')).'"><main class="container py-5"><h1 class="h3">Clinical workspace temporarily unavailable</h1><p role="alert">The requested clinical information could not be loaded. No changes have been confirmed. Please reload or contact your system administrator if this continues.</p><a class="btn btn-success" href="'.e(app_url('pages/doctor/dashboard.php')).'">Clinical Command Center</a> <a class="btn btn-outline-success" href="'.e(app_url('pages/doctor/patients.php')).'">Patient records</a></main></html>';
});
header('Cache-Control: no-store, private');
