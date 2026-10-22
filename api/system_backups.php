<?php
require_once __DIR__ . '/../config/config.php';
require_admin_permission('backups_manage');

$db = getDB();
$user = current_user();
$action = (string)($_REQUEST['action'] ?? 'list');
$backupDir = BASE_PATH . '/storage/backups';

function backup_storage_path(string $directory, string $filename): ?string {
    $name = basename($filename);
    if ($name !== $filename || !preg_match('/^bpc-backup-[0-9]{8}-[0-9]{6}-[a-f0-9]{8}\.sql$/', $name)) return null;
    return $directory . DIRECTORY_SEPARATOR . $name;
}

function sql_literal(PDO $db, mixed $value): string {
    if ($value === null) return 'NULL';
    return $db->quote((string)$value);
}

if ($action === 'download') {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) { http_response_code(400); exit('Invalid backup identifier.'); }
    $stmt = $db->prepare("SELECT filename FROM system_backups WHERE id=? AND status='completed' LIMIT 1");
    $stmt->execute([$id]);
    $filename = (string)$stmt->fetchColumn();
    $path = backup_storage_path($backupDir, $filename);
    if (!$path || !is_file($path) || !is_readable($path)) { http_response_code(404); exit('Backup file is unavailable.'); }
    log_activity((int)$user['id'], 'Downloaded system backup', 'backups', 'Backup record #' . $id);
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

header('Content-Type: application/json');

try {
    if ($action === 'list') {
        $rows = $db->query("SELECT b.id,b.status,b.filename,b.sha256,b.created_at,b.completed_at,b.error_message,
            CONCAT_WS(' ',u.first_name,u.last_name) requested_by_name
            FROM system_backups b LEFT JOIN users u ON u.id=b.requested_by
            ORDER BY b.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        json_response(true, ['backups' => $rows]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        json_response(false, [], 'This action requires POST.');
    }
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        json_response(false, [], 'Your session token expired. Refresh the page and try again.');
    }

    if ($action === 'verify') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $db->prepare("SELECT filename,sha256 FROM system_backups WHERE id=? AND status='completed' LIMIT 1");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        $path = $record ? backup_storage_path($backupDir, (string)$record['filename']) : null;
        if (!$path || !is_file($path)) json_response(false, [], 'Backup file is unavailable on this server.');
        $actual = hash_file('sha256', $path);
        $valid = is_string($actual) && hash_equals((string)$record['sha256'], $actual);
        log_activity((int)$user['id'], 'Verified system backup', 'backups', 'Backup record #' . (int)$id . ': ' . ($valid ? 'valid' : 'failed'));
        json_response($valid, ['valid' => $valid], $valid ? 'Backup integrity verified.' : 'Backup integrity verification failed.');
    }

    if ($action !== 'create') json_response(false, [], 'Unknown backup action.');

    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Backup directory could not be created.');
    }
    if (!is_writable($backupDir)) throw new RuntimeException('Backup directory is not writable.');

    $filename = 'bpc-backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql';
    $path = backup_storage_path($backupDir, $filename);
    $insert = $db->prepare("INSERT INTO system_backups(requested_by,status,filename) VALUES(?,'processing',?)");
    $insert->execute([(int)$user['id'], $filename]);
    $backupId = (int)$db->lastInsertId();
    $handle = null;

    try {
        if (function_exists('set_time_limit')) @set_time_limit(180);
        $handle = fopen($path, 'xb');
        if (!$handle) throw new RuntimeException('Backup file could not be opened.');
        fwrite($handle, "-- BPC Clinic database backup\n-- Generated: " . gmdate('c') . " UTC\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tableStmt = $db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
        $tableStmt->execute([DB_NAME]);
        $tables = $tableStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new RuntimeException('Unsafe table identifier encountered.');
            $quotedTable = '`' . $table . '`';
            $create = $db->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
            if (!$create || empty($create[1])) throw new RuntimeException('Unable to read table definition.');
            fwrite($handle, "DROP TABLE IF EXISTS $quotedTable;\n" . $create[1] . ";\n\n");
            $rows = $db->query('SELECT * FROM ' . $quotedTable);
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_map(static fn($column) => '`' . str_replace('`', '``', $column) . '`', array_keys($row));
                $values = array_map(static fn($value) => sql_literal($db, $value), array_values($row));
                fwrite($handle, 'INSERT INTO ' . $quotedTable . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
            }
            fwrite($handle, "\n");
        }
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
        $handle = null;
        @chmod($path, 0600);
        $sha = hash_file('sha256', $path);
        if (!$sha) throw new RuntimeException('Backup checksum could not be generated.');
        $done = $db->prepare("UPDATE system_backups SET status='completed',sha256=?,completed_at=NOW(),error_message=NULL WHERE id=?");
        $done->execute([$sha, $backupId]);
        log_activity((int)$user['id'], 'Created system backup', 'backups', 'Backup record #' . $backupId);
        json_response(true, ['id' => $backupId], 'Database backup completed successfully.');
    } catch (Throwable $e) {
        if (is_resource($handle)) fclose($handle);
        if ($path && is_file($path)) @unlink($path);
        $failed = $db->prepare("UPDATE system_backups SET status='failed',completed_at=NOW(),error_message=? WHERE id=?");
        $failed->execute(['Backup generation failed. Review the server error log.', $backupId]);
        throw $e;
    }
} catch (Throwable $e) {
    http_response_code(500);
    json_response(false, [], safe_api_error($e, 'Backup operation failed. Review server storage permissions and logs.', 'system_backups'));
}
