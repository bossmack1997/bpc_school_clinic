<?php
/** Exact Hostinger SQL, disposable LOCAL databases only. Does not use application records. */
if (PHP_SAPI !== 'cli') exit;
ini_set('session.save_path', __DIR__ . '/../scratch');
require __DIR__ . '/../config/config.php';
if (APP_ENV !== 'local' || !in_array(DB_HOST, ['localhost','127.0.0.1','::1'], true)) {
    throw new RuntimeException('Disposable local database required.');
}
$db = getDB();
$name = 'bpc_nurse_sql_test_' . bin2hex(random_bytes(5));
$count = 0;
$migration = file_get_contents(__DIR__.'/../database/migration_nurse_clinical_authorization_hostinger.sql');
$preflight = file_get_contents(__DIR__.'/../database/preflight_nurse_clinical_authorization_hostinger.sql');
// Rebind the fixed production target only inside disposable test SQL.
$preflight = str_replace('u962995739_bpc_clinic', $name, $preflight);
$post = file_get_contents(__DIR__.'/../database/verify_nurse_clinical_authorization_hostinger.sql');
function check(bool $ok, string $label): void {
    global $count;
    if (!$ok) throw new RuntimeException($label);
    ++$count; echo "PASS $label\n";
}
function statements(string $sql): array {
    return array_values(array_filter(array_map('trim', explode(';', preg_replace('/^\s*--.*$/m', '', $sql)))));
}
function runSql(PDO $db, string $sql, bool $continue = false): array {
    $results = []; $errors = [];
    foreach (statements($sql) as $statement) {
        try {
            $q = $db->query($statement);
            if ($q->columnCount()) $results[] = $q->fetchAll(PDO::FETCH_ASSOC);
            $q->closeCursor();
        } catch (PDOException $e) {
            if (!$continue) throw $e;
            $errors[] = $e->getCode();
        }
    }
    return [$results, $errors];
}
function fixture(PDO $db, string $name, string $sign = ''): void {
    if (!preg_match('/^bpc_nurse_sql_test_[a-f0-9]{10}$/', $name)) throw new RuntimeException('Invalid fixture');
    $db->exec("DROP DATABASE IF EXISTS `$name`");
    $db->exec("CREATE DATABASE `$name`"); $db->exec("USE `$name`");
    $db->exec("CREATE TABLE users (id INT$sign PRIMARY KEY, self_service_only TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $db->exec("CREATE TABLE nurses (id INT PRIMARY KEY, user_id INT$sign NOT NULL) ENGINE=InnoDB");
    $db->exec('CREATE TABLE synthetic_clinical_records (id INT PRIMARY KEY, notes TEXT) ENGINE=InnoDB');
    $db->exec("INSERT INTO synthetic_clinical_records VALUES (1,'preserve clinical record')");
    $db->exec('INSERT INTO users VALUES (1,0),(2,1)'); $db->exec('INSERT INTO nurses VALUES (1,1)');
}
function migrated(PDO $db, string $sql): void {
    runSql($db, $sql);
    check((int)$db->query('SELECT @bpc_complete')->fetchColumn() === 1, 'Migration reports actual schema completion');
}
function removeAuditFks(PDO $db): void {
    $keys = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($keys as $key) $db->exec('ALTER TABLE nurse_clinical_reviews DROP FOREIGN KEY `' . str_replace('`','``',$key) . '`');
}
function restrictive(PDO $db): bool {
    return (int)$db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='nurse_clinical_reviews' AND DELETE_RULE IN ('RESTRICT','NO ACTION') AND UPDATE_RULE IN ('RESTRICT','NO ACTION')")->fetchColumn() === 2;
}
function schemaSnapshot(PDO $db): array {
    $out = [];
    foreach (['users','nurses','nurse_clinical_reviews'] as $table) {
        $exists=$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")->fetchColumn();
        if ($exists) $out[$table] = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    }
    return $out;
}
try {
    echo 'LOCAL SERVER ' . $db->query('SELECT VERSION()')->fetchColumn() . "\n";
    foreach ([$preflight, $post] as $readOnly) {
        foreach (statements($readOnly) as $statement) check((bool)preg_match('/^(SELECT|SHOW)\b/i', $statement), 'Production check statement is read-only');
    }
    foreach (statements($migration) as $statement) check(!preg_match('/^(UPDATE|DELETE|INSERT|REPLACE|TRUNCATE)\b/i', $statement), 'Migration statement has no account/patient DML');
    check(!preg_match('/[\x27\x22](?:UPDATE|DELETE|INSERT|REPLACE|TRUNCATE)\s/i', $migration), 'Dynamic SQL contains no data mutation');
    if (in_array('--context-preflight', $argv, true)) {
        fixture($db,$name);
        $before=schemaSnapshot($db);
        [$selectedResults]=runSql($db,$preflight);
        $db->exec('USE information_schema');
        [$wrongContextResults]=runSql($db,$preflight);
        check($db->query('SELECT DATABASE()')->fetchColumn()==='information_schema','Preflight preserves selected information_schema context');
        check($wrongContextResults[0][0]['target_result']==='TARGET_VERIFIED','Explicit target verified from information_schema');
        check($wrongContextResults[0][0]['intended_database']===$name,'Diagnostic names the intended fixture schema');
        $summary=static function($sets){foreach($sets as $rows)if(isset($rows[0]['preflight_result']))return $rows[0]['preflight_result'];throw new RuntimeException('Preflight summary missing');};
        check($summary($wrongContextResults)===$summary($selectedResults)&&str_starts_with($summary($wrongContextResults),'METADATA_READY_ONLY'),'Metadata decision is independent of selected database');
        $noContext=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        [$noContextResults]=runSql($noContext,$preflight);
        check($noContext->query('SELECT DATABASE()')->fetchColumn()===null&&$summary($noContextResults)===$summary($selectedResults),'No selected database is required and context remains unset');
        foreach($wrongContextResults as $rows)foreach($rows as $row)foreach(['nurse_orphan_check_sql','nurse_duplicate_check_sql'] as $key)if(isset($row[$key])){
            check(str_contains($row[$key],'`'.$name.'`.'),'Generated patient-free data check is fully schema-qualified');
            $db->query($row[$key])->closeCursor();
        }
        check($db->query('SELECT DATABASE()')->fetchColumn()==='information_schema','Generated data checks preserve selected database');
        [$missingResults]=runSql($db,str_replace($name,$name.'_missing',$preflight));
        check($missingResults[0][0]['target_result']==='BLOCK_TARGET_NOT_VERIFIED'&&$summary($missingResults)==='BLOCK','Missing or invisible target fails closed');
        foreach($missingResults as $rows)foreach($rows as $row)foreach(['nurse_orphan_check_sql','nurse_duplicate_check_sql'] as $key)if(isset($row[$key]))check(str_starts_with($row[$key],'BLOCK_TARGET_NOT_VERIFIED'),'Unverified target suppresses runnable generated data checks');
        [$wrongCaseResults]=runSql($db,str_replace($name,strtoupper($name),$preflight));
        check($wrongCaseResults[0][0]['target_result']==='BLOCK_TARGET_NOT_VERIFIED'&&$summary($wrongCaseResults)==='BLOCK','Schema case mismatch fails exact-name verification');
        $db->exec("USE `$name`");
        check(schemaSnapshot($db)===$before,'Context preflight preserves schema and records');
        $db->exec('CREATE TABLE nurse_clinical_reviews(nurse_user_id INT,doctor_user_id INT) ENGINE=InnoDB');
        $db->exec('INSERT INTO nurse_clinical_reviews VALUES (1,2),(999,2)');
        $db->exec('USE information_schema');
        [$auditResults]=runSql($db,$preflight);
        foreach($auditResults as $rows)if(isset($rows[0]['audit_data_check_sql'])){
            $auditSql=$rows[0]['audit_data_check_sql'];
            check(str_contains($auditSql,'`'.$name.'`.`nurse_clinical_reviews`')&&(int)$db->query($auditSql)->fetchColumn()===1,'Qualified generated audit check detects an orphan from information_schema');
        }
        check($summary($auditResults)==='BLOCK','Malformed audit schema remains blocked regardless of selected database');
        check((int)$db->query("SELECT COUNT(*) FROM `$name`.nurse_clinical_reviews")->fetchColumn()===2,'Read-only preflight preserves synthetic audit history including orphan');
        $db->exec("USE `$name`");
        $db->exec('DROP TABLE nurses');
        $db->exec('USE information_schema');
        [$missingTableResults]=runSql($db,$preflight);
        check($missingTableResults[0][0]['target_result']==='BLOCK_TARGET_NOT_VERIFIED'&&$summary($missingTableResults)==='BLOCK','Missing lowercase base table fails closed');
        $db->exec("USE `$name`");
    } elseif (in_array('--metadata-smoke', $argv, true)) {
        fixture($db,$name);runSql($db,$preflight);
        check(true,'Final preflight including existing-FK inventory executes without audit table');
        migrated($db,$migration);runSql($db,$post);
        check(true,'Final post-verification including existing-FK inventory executes');
    } elseif (in_array('--foreign-key-edges', $argv, true)) {
        foreach (['wrong_schema','wrong_column','one_missing','nullable_set_null'] as $case) {
            fixture($db,$name); migrated($db,$migration);removeAuditFks($db);
            if($case==='wrong_schema') {
                $external=$name.'_external';
                $db->exec("CREATE DATABASE `$external`");
                $db->exec("CREATE TABLE `$external`.users(id INT PRIMARY KEY) ENGINE=InnoDB");
                $db->exec("ALTER TABLE nurse_clinical_reviews ADD FOREIGN KEY(nurse_user_id) REFERENCES `$external`.users(id)");
            }
            if($case==='wrong_column') {
                $db->exec('ALTER TABLE users ADD legacy_id INT, ADD UNIQUE KEY legacy_id(legacy_id)');
                $db->exec('ALTER TABLE nurse_clinical_reviews ADD FOREIGN KEY(nurse_user_id) REFERENCES users(legacy_id)');
            }
            if($case==='one_missing')$db->exec('ALTER TABLE nurse_clinical_reviews ADD FOREIGN KEY(nurse_user_id) REFERENCES users(id)');
            if($case==='nullable_set_null')$db->exec('ALTER TABLE nurse_clinical_reviews MODIFY nurse_user_id INT NULL, ADD FOREIGN KEY(nurse_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE SET NULL');
            $snapshot=schemaSnapshot($db);runSql($db,$preflight);runSql($db,$migration);
            if($case==='one_missing')check((int)$db->query('SELECT @bpc_complete')->fetchColumn()===1&&restrictive($db),'One missing actor FK completed without duplicating existing FK');
            else check((int)$db->query('SELECT @bpc_complete')->fetchColumn()===0&&schemaSnapshot($db)===$snapshot,"Blocks $case without schema rewrite");
            if($case==='wrong_schema'){$db->exec('DROP TABLE nurse_clinical_reviews');$db->exec("DROP DATABASE `$external`");unset($external);}
        }
        fixture($db,$name);$db->exec('DROP TABLE nurses');$db->exec('DROP TABLE users');
        runSql($db,$preflight);runSql($db,$migration);
        check((int)$db->query('SELECT @bpc_complete')->fetchColumn()===0,'Absent base tables preflight executes and migration blocks');
    } else {
    foreach (['',' UNSIGNED'] as $sign) {
        fixture($db,$name,$sign);
        $before=schemaSnapshot($db); runSql($db,$preflight);
        check(schemaSnapshot($db)===$before, "Preflight runs read-only without audit table ($sign)");
        migrated($db,$migration);
        check($db->query('SELECT clinical_authorization_status FROM nurses')->fetchColumn()==='pending','Legacy scope zero does not imply authorization');
        check((int)$db->query('SELECT self_service_only FROM users WHERE id=1')->fetchColumn()===0,'Existing scope remains unchanged');
        check($db->query('SELECT notes FROM synthetic_clinical_records')->fetchColumn()==='preserve clinical record','Clinical records preserved');
        check(restrictive($db),'Exactly two restrictive audit FKs installed');
        $db->exec("INSERT INTO nurse_clinical_reviews (nurse_user_id,doctor_user_id,action,previous_status,new_status,notes) VALUES (1,2,'authorize','pending','authorized','synthetic verified review')");
        $db->exec("UPDATE nurses SET clinical_authorization_status='authorized',clinical_reviewed_by=2,clinical_reviewed_at='2026-01-02 03:04:05',clinical_review_notes='retain review'");
        $history=$db->query('SELECT * FROM nurse_clinical_reviews')->fetchAll(); $nurses=$db->query('SELECT * FROM nurses')->fetchAll();
        migrated($db,$migration);
        check($history===$db->query('SELECT * FROM nurse_clinical_reviews')->fetchAll() && $nurses===$db->query('SELECT * FROM nurses')->fetchAll(),'Rerun preserves audit and existing authorization evidence');
        foreach (['DELETE FROM users WHERE id=1','DELETE FROM users WHERE id=2','UPDATE users SET id=99 WHERE id=2'] as $mutation) {
            $blocked=false; try {$db->exec($mutation);} catch(PDOException $e) {$blocked=$e->getCode()==='23000';}
            check($blocked,'Audit restricts nurse/reviewer delete and ID update');
        }
        runSql($db,$post); check(true,'Post-verification executes with audit data');
        removeAuditFks($db);
        $db->exec('ALTER TABLE nurse_clinical_reviews ADD FOREIGN KEY (nurse_user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE, ADD FOREIGN KEY (doctor_user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE');
        runSql($db,$preflight); migrated($db,$migration);
        check(restrictive($db) && $history===$db->query('SELECT * FROM nurse_clinical_reviews')->fetchAll(),'Existing delete/update cascades repaired without history loss');
        removeAuditFks($db); migrated($db,$migration); check(restrictive($db),'Existing audit table missing both FKs repaired');
        removeAuditFks($db);
        $db->exec("INSERT INTO nurse_clinical_reviews (nurse_user_id,doctor_user_id,action,previous_status,new_status) VALUES (999,2,'revoke','authorized','revoked')");
        $schema=schemaSnapshot($db); runSql($db,$preflight); runSql($db,$migration);
        check((int)$db->query('SELECT @bpc_complete')->fetchColumn()===0 && schemaSnapshot($db)===$schema,'Orphans block all DDL');
        check((int)$db->query('SELECT COUNT(*) FROM nurse_clinical_reviews')->fetchColumn()===2,'Orphan audit history retained');
    }
    fixture($db,$name);
    $db->exec("ALTER TABLE nurses ADD clinical_authorization_status VARCHAR(30) NOT NULL DEFAULT 'pending'");
    $db->exec("UPDATE nurses SET clinical_authorization_status='revoked'");
    migrated($db,$migration);
    check($db->query('SELECT clinical_authorization_status FROM nurses')->fetchColumn()==='revoked','Partially migrated schema completes without revoking or granting status');
    foreach (['duplicate','wrong_target','composite','unexpected_column','custom_unique','custom_check','custom_trigger','wrong_signedness','partial_audit'] as $case) {
        fixture($db,$name); migrated($db,$migration);
        if($case==='duplicate') $db->exec('ALTER TABLE nurse_clinical_reviews ADD CONSTRAINT duplicate_nurse FOREIGN KEY(nurse_user_id) REFERENCES users(id)');
        if($case==='wrong_target') {
            removeAuditFks($db);$db->exec('CREATE TABLE other_users (id INT PRIMARY KEY) ENGINE=InnoDB');
            $db->exec('ALTER TABLE nurse_clinical_reviews ADD FOREIGN KEY(nurse_user_id) REFERENCES other_users(id)');
        }
        if($case==='composite') {
            removeAuditFks($db);$db->exec('CREATE TABLE actor_pairs (nurse INT,doctor INT,PRIMARY KEY(nurse,doctor)) ENGINE=InnoDB');
            $db->exec('ALTER TABLE nurse_clinical_reviews ADD FOREIGN KEY(nurse_user_id,doctor_user_id) REFERENCES actor_pairs(nurse,doctor)');
        }
        if($case==='unexpected_column') $db->exec('ALTER TABLE nurse_clinical_reviews ADD auxiliary_user INT, ADD FOREIGN KEY(auxiliary_user) REFERENCES users(id)');
        if($case==='custom_unique') $db->exec('ALTER TABLE nurse_clinical_reviews ADD UNIQUE KEY one_review(nurse_user_id)');
        if($case==='custom_check') $db->exec("ALTER TABLE nurse_clinical_reviews ADD CONSTRAINT custom_action CHECK(action='authorize')");
        if($case==='custom_trigger') $db->exec('CREATE TRIGGER custom_review BEFORE INSERT ON nurse_clinical_reviews FOR EACH ROW SET NEW.notes=NEW.notes');
        if($case==='wrong_signedness') {removeAuditFks($db);$db->exec('ALTER TABLE nurse_clinical_reviews MODIFY nurse_user_id INT UNSIGNED NOT NULL');}
        if($case==='partial_audit') $db->exec('ALTER TABLE nurse_clinical_reviews DROP notes');
        $snapshot=schemaSnapshot($db);runSql($db,$preflight);runSql($db,$migration);
        check((int)$db->query('SELECT @bpc_complete')->fetchColumn()===0 && schemaSnapshot($db)===$snapshot,"Blocks $case without schema rewrite");
    }
    foreach(['bad_scope','bad_credential','engine','duplicate_profiles','missing_user'] as $case) {
        fixture($db,$name);
        if($case==='bad_scope')$db->exec('ALTER TABLE users MODIFY self_service_only VARCHAR(10)');
        if($case==='bad_credential')$db->exec('ALTER TABLE users ADD credential_version INT NOT NULL DEFAULT 0');
        if($case==='engine')$db->exec('ALTER TABLE users ENGINE=MyISAM');
        if($case==='duplicate_profiles')$db->exec('INSERT INTO nurses VALUES(2,1)');
        if($case==='missing_user')$db->exec('INSERT INTO nurses VALUES(2,999)');
        $snapshot=schemaSnapshot($db);runSql($db,$migration);
        check((int)$db->query('SELECT @bpc_complete')->fetchColumn()===0 && schemaSnapshot($db)===$snapshot,"Blocks incompatible $case before DDL");
    }
    fixture($db,$name);$db->exec('SET FOREIGN_KEY_CHECKS=0');runSql($db,$migration);
    check((int)$db->query('SELECT @bpc_complete')->fetchColumn()===0,'Disabled FK checks block migration');$db->exec('SET FOREIGN_KEY_CHECKS=1');
    fixture($db,$name);
    $injected=str_replace('ADD COLUMN `clinical_reviewed_by` ', 'ADD COLUMN `clinical_reviewed_by` INVALID_TYPE ', $migration);
    [, $errors]=runSql($db,$injected,true);
    check(count($errors)>0 && (int)$db->query('SELECT @bpc_complete')->fetchColumn()===0,'Continued partial DDL error cannot report completion');
    check((int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nurses' AND COLUMN_NAME='clinical_reviewed_at'")->fetchColumn()===0,'DDL after failed column step is skipped');
    migrated($db,$migration);check(restrictive($db),'Whole-file rerun recovers partial DDL safely');
    }
    echo "All $count checks passed. Production not accessed.\n";
} finally {
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
    if(isset($external)&&preg_match('/^bpc_nurse_sql_test_[a-f0-9]{10}_external$/',$external)){
        $db->exec('DROP TABLE IF EXISTS nurse_clinical_reviews');$db->exec("DROP DATABASE IF EXISTS `$external`");
    }
    if(preg_match('/^bpc_nurse_sql_test_[a-f0-9]{10}$/',$name))$db->exec("DROP DATABASE IF EXISTS `$name`");
}
