<?php
/** Read-only local aggregate audit. Does not load application bootstrap or emit identities. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $settings = [];
    foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $settings[trim($key)] = trim(trim($value), "\"'");
    }
    $setting = static fn($key, $fallback='') => getenv($key) !== false ? getenv($key) : ($settings[$key] ?? $fallback);
    $host = $setting('DB_HOST', 'localhost');
    if (!in_array($host, ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Local database required.');
    $db = new PDO('mysql:host='.$host.';port='.(int)$setting('DB_PORT','3306').';dbname='.$setting('DB_NAME','bpc_clinic_system').';charset=utf8mb4', $setting('DB_USER','root'), $setting('DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('SET TRANSACTION READ ONLY'); $db->beginTransaction();
    $report = ['scope'=>'local schema and aggregate integrity only', 'read_only'=>true];
    $report['tables'] = $db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll();
    $report['columns'] = $db->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION')->fetchAll();
    $report['foreign_keys'] = $db->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION')->fetchAll();
    $report['indexes'] = $db->query('SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll();
    $quote = static function(string $name): string { return '`'.str_replace('`','``',$name).'`'; };
    $report['orphan_counts'] = [];
    foreach ($report['foreign_keys'] as $key) {
        $child=$quote($key['TABLE_NAME']); $parent=$quote($key['REFERENCED_TABLE_NAME']);
        $field=$quote($key['COLUMN_NAME']); $target=$quote($key['REFERENCED_COLUMN_NAME']);
        $report['orphan_counts'][] = ['table'=>$key['TABLE_NAME'],'column'=>$key['COLUMN_NAME'],'count'=>(int)$db->query("SELECT COUNT(*) FROM $child c LEFT JOIN $parent p ON c.$field=p.$target WHERE c.$field IS NOT NULL AND p.$target IS NULL")->fetchColumn()];
    }
    $report['profile_integrity'] = [];
    foreach (['student'=>'students','teacher'=>'teachers','nurse'=>'nurses'] as $role=>$table) {
        $report['profile_integrity'][$role] = [
            'missing_profile'=>(int)$db->query("SELECT COUNT(*) FROM users u LEFT JOIN `$table` p ON p.user_id=u.id WHERE u.role='$role' AND p.id IS NULL")->fetchColumn(),
            'duplicate_account_mappings'=>(int)$db->query("SELECT COUNT(*) FROM (SELECT user_id FROM `$table` GROUP BY user_id HAVING COUNT(*)>1) duplicates")->fetchColumn(),
            'wrong_role_mappings'=>(int)$db->query("SELECT COUNT(*) FROM `$table` p JOIN users u ON u.id=p.user_id WHERE u.role<>'$role'")->fetchColumn(),
        ];
    }
    $report['activity_schema_columns'] = array_values(array_map(fn($column)=>$column['COLUMN_NAME'], array_filter($report['columns'], fn($column)=>$column['TABLE_NAME']==='activity_logs')));
    $db->rollBack();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Local read-only schema audit failed ('.get_class($error).'); no data changed.'.PHP_EOL); exit(1);
}
