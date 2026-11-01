<?php
// Parallel worker is restricted to the schema-only test database.
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/account_provisioning_service.php';
if(!preg_match('/^bpc_mis_test_[a-f0-9]{10}$/D',DB_NAME)||getDB()->query('SELECT DATABASE()')->fetchColumn()!==DB_NAME)exit(2);
$service=new AccountProvisioningService(getDB(),static fn()=>true);
$report=$service->importStudents((int)($argv[1]??0),[['role'=>'student','username'=>'concurrent_import','email'=>'concurrent_import@example.invalid','first_name'=>'Concurrent','last_name'=>'Fixture','institutional_id'=>'CONCURRENT-IMPORT']]);
echo json_encode(array_column($report,'status'));
