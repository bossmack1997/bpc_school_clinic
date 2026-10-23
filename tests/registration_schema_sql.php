<?php
/** Execute the exact phpMyAdmin SQL against disposable local databases only. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());
require __DIR__.'/../config/config.php';
require __DIR__.'/../includes/registration_schema.php';
if(APP_ENV!=='local'||!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Local database required.');
$db=getDB();$fixture='bpc_schema_test_'.bin2hex(random_bytes(5));$checks=0;$failed=false;
function schemaCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";}
function statements(string $source): array {
    $source=preg_replace('/^\s*--.*$/m','',$source);
    return array_values(array_filter(array_map('trim',explode(';',$source))));
}
function runSchemaSql(PDO $db,string $sql): array {
    $results=[];
    foreach(statements($sql) as $statement){
        $q=$db->query($statement);
        if($q->columnCount())$results[]=$q->fetchAll(PDO::FETCH_ASSOC);
        $q->closeCursor();
    }
    return $results;
}
$sql=file_get_contents(__DIR__.'/../database/migration_basic_otp_activation_hostinger.sql');
$verify=file_get_contents(__DIR__.'/../database/verify_basic_otp_activation_hostinger.sql');
try {
    $db->exec("CREATE DATABASE `$fixture`");
    // Replace only the explicit production USE target. No production connection exists.
    $sql=str_replace('USE `u962995739_bpc_clinic`','USE `'.$fixture.'`',$sql);
    $db->exec("USE `$fixture`");
    foreach([0,1,2,3] as $present){
        $db->exec('CREATE TABLE users (id INT PRIMARY KEY,username VARCHAR(50),password_hash VARCHAR(255),role VARCHAR(20),status VARCHAR(20),document_path VARCHAR(255))');
        $db->exec("INSERT INTO users VALUES (1,'synthetic','SYNTHETIC_PASSWORD_HASH','nurse','suspended','synthetic/document.pdf')");
        if($present&1)$db->exec('ALTER TABLE users ADD email_verified_at DATETIME NULL DEFAULT NULL');
        if($present&2)$db->exec('ALTER TABLE users ADD self_service_only TINYINT(1) NOT NULL DEFAULT 0');
        if($present&1)$db->exec("UPDATE users SET email_verified_at='2026-01-02 03:04:05'");
        if($present&2)$db->exec('UPDATE users SET self_service_only=1');
        $before=$db->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
        runSchemaSql($db,$sql);
        $after=$db->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC);
        foreach($before[0] as $column=>$value)schemaCheck($after[0][$column]===$value,"Existing $column preserved (scenario $present)");
        schemaCheck(count($after)===1,'No account rows added or removed');
        if(!($present&1))schemaCheck($after[0]['email_verified_at']===null,'Missing verification timestamp defaults to NULL');
        if(!($present&2))schemaCheck((int)$after[0]['self_service_only']===0,'Existing scope defaults to legacy value zero');
        $results=runSchemaSql($db,$verify);$rows=end($results);
        schemaCheck(count($rows)===2&&array_column($rows,'verification')===['PASS','PASS'],'Read-only verification confirms both definitions');
        runSchemaSql($db,$sql);
        schemaCheck($db->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC)===$after,'Second SQL import preserves every value');
        schemaCheck(migrate_basic_registration_schema($db)===[],'PHP migration accepts SQL-migrated schema without changes');
        schemaCheck(count($db->query('SHOW INDEX FROM users')->fetchAll())===1,'No redundant indexes added');
        $db->exec('DROP TABLE users'); // Disposable database only.
    }
    $db->exec('CREATE TABLE users (id INT PRIMARY KEY)');
    $results=runSchemaSql($db,$verify);$rows=end($results);
    schemaCheck(array_column($rows,'verification')===['MISSING','MISSING'],'Verification identifies missing columns without changing schema');
    schemaCheck(count(migrate_basic_registration_schema($db))===2,'PHP migration adds missing columns');
    runSchemaSql($db,$sql);
    schemaCheck(migrate_basic_registration_schema($db)===[],'SQL and PHP migrations may run in either order');
    $db->exec("ALTER TABLE users MODIFY self_service_only VARCHAR(10) NULL DEFAULT NULL");
    $results=runSchemaSql($db,$verify);$rows=end($results);
    schemaCheck($rows[1]['verification']==='INCOMPATIBLE - review before proceeding','Verification flags an incompatible existing definition');
    echo "All $checks schema migration checks passed; production was not accessed.\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL '.$e->getMessage().PHP_EOL);$failed=true;}
finally {
    $db->exec('USE `'.DB_NAME.'`');
    if(preg_match('/^bpc_schema_test_[a-f0-9]{10}$/',$fixture))$db->exec("DROP DATABASE IF EXISTS `$fixture`");
}
exit($failed?1:0);
