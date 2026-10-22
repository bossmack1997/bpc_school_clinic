<?php
// Included only after the authenticated admin settings entry point.
if (!defined('APP_URL')) {
    http_response_code(404);
    exit;
}
require_admin_permission('manage_security');

// -------------------------------------------------------------
// POST Request Actions (Security Reset & Clear)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify_or_die();
    $action = $_POST['action'] ?? '';

    if ($action === 'clear_ip_lockout') {
        $ip = trim($_POST['ip_address'] ?? '');
        if ($ip !== '') {
            login_rate_limit_clear($ip);
            log_activity($u['id'], "Cleared login rate-limit lockout for IP: {$ip}", 'security');
            set_flash('success', "Rate-limit lockout for IP {$ip} has been cleared.");
        }
        redirect('settings.php?tab=security');
    }

    if ($action === 'clear_all_lockouts') {
        $db->query("DELETE FROM login_attempts WHERE UNIX_TIMESTAMP(attempted_at) >= (UNIX_TIMESTAMP() - 900)");
        log_activity($u['id'], "Cleared all active IP rate-limit lockouts", 'security');
        set_flash('success', "All active IP rate-limit lockouts cleared.");
        redirect('settings.php?tab=security');
    }
}

// -------------------------------------------------------------
// Security Metrics & Telemetry
// -------------------------------------------------------------
$totalFailed24h = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= (NOW() - INTERVAL 24 HOUR)")->fetchColumn();
$recentAttempts = $db->query("
    SELECT ip_address, username, attempted_at
    FROM login_attempts
    ORDER BY attempted_at DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// Rate-Limited IPs (>= 5 failed attempts in last 15 min)
$lockedIps = $db->query("
    SELECT ip_address, COUNT(*) AS attempt_count, MAX(attempted_at) AS last_attempt,
           GROUP_CONCAT(DISTINCT username SEPARATOR ', ') AS targeted_accounts
    FROM login_attempts
    WHERE UNIX_TIMESTAMP(attempted_at) >= (UNIX_TIMESTAMP() - 900)
    GROUP BY ip_address
    HAVING COUNT(*) >= 5
    ORDER BY attempt_count DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalAdmins = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
