<?php
/** CLI-only, loopback-only audit. No application bootstrap, writes or personal fields. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $env = [];
    foreach (file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
    $value = static fn($key, $default = '') => getenv($key) !== false ? getenv($key) : ($env[$key] ?? $default);
    $host = $value('DB_HOST', 'localhost');
    if (!in_array($host, ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Only a local database may be audited.');
    $db = new PDO('mysql:host='.$host.';port='.(int)$value('DB_PORT', '3306').';dbname='.$value('DB_NAME', 'bpc_clinic_system').';charset=utf8mb4', $value('DB_USER', 'root'), $value('DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $quote = static fn($name) => '`'.str_replace('`','``',$name).'`';
    $columns = $db->query('SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchAll();
    $tables = [];
    foreach ($columns as $column) $tables[$column['TABLE_NAME']][$column['COLUMN_NAME']] = $column['DATA_TYPE'];
    $report = ['read_only'=>true,'scope'=>'local only','table_counts'=>[], 'candidates'=>[], 'foreign_key_orphans'=>[], 'limitations'=>['Markers are suspicion only; no deletion approved.', 'Foreign keys do not cover all application-level relationships.', 'No backup created because no data mutation is performed.']];
    foreach ($tables as $table=>$fields) {
        $report['table_counts'][$table] = (int)$db->query('SELECT COUNT(*) FROM '.$quote($table))->fetchColumn();
        $search = array_intersect(array_keys($fields), ['username','email','first_name','last_name','name','section','student_number','employee_id','title']);
        foreach ($search as $field) {
            if (!in_array($fields[$field], ['varchar','char','text'], true)) continue;
            $sql = 'SELECT '.(isset($fields['id']) ? $quote('id') : 'NULL AS id').' FROM '.$quote($table).' WHERE '.$quote($field).' REGEXP ?';
            $stmt = $db->prepare($sql);
            $stmt->execute(['(^|[^a-z])(smoke[-_ ]?[ab]?|dummy|fixture|synthetic|test|demo)([^a-z]|$)|@example[.](invalid|test|com)$']);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) $report['candidates'][] = ['table'=>$table,'matched_column'=>$field,'ids'=>$ids,'classification'=>'unconfirmed; retain pending relationship and provenance review'];
        }
    }
    $keys = $db->query('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll();
    foreach ($keys as $key) {
        $sql = 'SELECT COUNT(*) FROM '.$quote($key['TABLE_NAME']).' c LEFT JOIN '.$quote($key['REFERENCED_TABLE_NAME']).' p ON c.'.$quote($key['COLUMN_NAME']).'=p.'.$quote($key['REFERENCED_COLUMN_NAME']).' WHERE c.'.$quote($key['COLUMN_NAME']).' IS NOT NULL AND p.'.$quote($key['REFERENCED_COLUMN_NAME']).' IS NULL';
        $report['foreign_key_orphans'][] = ['relationship'=>$key,'count'=>(int)$db->query($sql)->fetchColumn()];
    }
    $report['section_review'] = [];
    if (isset($tables['sections']['name'])) {
        $sections = $db->query("SELECT id,name FROM sections WHERE name IN ('SMOKE-A','SMOKE-B')")->fetchAll();
        foreach ($sections as $section) {
            $related = [];
            foreach ($tables as $table=>$fields) {
                foreach (['section_id','section'] as $field) {
                    if (!isset($fields[$field]) || $table === 'sections') continue;
                    $target = $field === 'section_id' ? $section['id'] : $section['name'];
                    $stmt = $db->prepare('SELECT COUNT(*) FROM '.$quote($table).' WHERE '.$quote($field).'=?');
                    $stmt->execute([$target]);
                    $related[$table.'.'.$field] = (int)$stmt->fetchColumn();
                }
            }
            $report['section_review'][] = ['id'=>$section['id'],'name'=>$section['name'],'assignments'=>$related,'decision'=>'retain; audit-text history and legitimate usage not yet established'];
        }
    }
    $db->rollBack();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Cleanup audit failed ('.get_class($error).'); no records changed.'.PHP_EOL);
    exit(1);
}
