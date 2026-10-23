<?php
/** Log database diagnostics without SQL, bound values, credentials or clinical text. */
function clinical_database_failure(string $operation, PDOException $error): void
{
    $operation=preg_replace('/[^a-z0-9_.-]/i','_',substr($operation,0,80));
    $state=(string)($error->errorInfo[0]??$error->getCode());
    if(!preg_match('/^[A-Z0-9]{5}$/D',$state))$state='unknown';
    $driver=isset($error->errorInfo[1])&&is_numeric($error->errorInfo[1])?(int)$error->errorInfo[1]:0;
    error_log('Clinical database failure operation='.$operation.' sqlstate='.$state.' driver='.$driver);
}
