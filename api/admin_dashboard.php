<?php
/**
 * api/admin_dashboard.php
 * =====================================================================
 * Live operational metrics for the Admin Dashboard.
 *
 * Powers the controlled periodic refresh so the overview
 * cards, attention badge and activity feed stay current
 * without a full page reload. Every figure is computed from
 * the live database — nothing is hardcoded.
 *
 * Access: admin only. Returns consistent JSON.
 * =====================================================================
 */
require_once __DIR__ . '/../config/config.php';
require_role('admin');
if(permission_profile()==='mis'){require_once __DIR__.'/../includes/system_monitoring.php';json_response(true,['data'=>(new SystemMonitoringService(getDB()))->summary()]);}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

$db = getDB();

// ---------------------------------------------------------------------
// Headline metrics (mirrors pages/admin/dashboard.php)
// ---------------------------------------------------------------------
$activeStudents = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn();

$todaysVisits = (int)$db->query(
    "SELECT COUNT(*) FROM consultations WHERE consultation_date >= CURDATE() AND consultation_date < CURDATE() + INTERVAL 1 DAY"
)->fetchColumn();

$pendingStaff    = (int)$db->query("SELECT COUNT(*) FROM users WHERE role IN ('nurse','teacher','adviser','admin') AND status = 'pending'")->fetchColumn();
$pendingStudents = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'pending'")->fetchColumn();
$pendingTotal    = $pendingStaff + $pendingStudents;

$lowStockCount   = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= reorder_level AND reorder_level > 0 AND (expiration_date IS NULL OR expiration_date >= CURDATE())")->fetchColumn();
$outOfStockCount = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity <= 0")->fetchColumn();
$expiredCount    = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity > 0 AND expiration_date IS NOT NULL AND expiration_date < CURDATE()")->fetchColumn();
$expiringSoon    = (int)$db->query("SELECT COUNT(*) FROM medicines WHERE is_archived = 0 AND quantity > 0 AND expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

$queueWaiting = (int)$db->query(
    "SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'waiting'"
)->fetchColumn();

$queueServing = (int)$db->query(
    "SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status IN ('called','serving')"
)->fetchColumn();

$queueCompleted = (int)$db->query(
    "SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'completed'"
)->fetchColumn();

// Combined inventory attention signal: low stock + out of stock + expired.
// The dashboard shows this as a single headline card; the per-category
// breakdown remains on the dedicated inventory management page.
$inventoryAttention = $lowStockCount + $outOfStockCount + $expiredCount;

$activeEmergencies = (int)$db->query(
    "SELECT COUNT(*) FROM sos_emergencies WHERE status IN ('new','active','acknowledged','responding') AND resolved_at IS NULL"
)->fetchColumn();

// ---------------------------------------------------------------------
// Needs-attention alerts (reuses the shared helper when present)
// ---------------------------------------------------------------------
$attentionTotal = 0;
if (function_exists('get_admin_needs_attention_alerts')) {
    $attention = get_admin_needs_attention_alerts($db);
    $attentionTotal = (int)($attention['total'] ?? 0);
} else {
    $attentionTotal = $pendingTotal + $lowStockCount + $outOfStockCount + $expiredCount + $activeEmergencies;
}

// ---------------------------------------------------------------------
// Recent activity (audit trail) — compact, privacy-aware
// ---------------------------------------------------------------------
$recentActivity = [];
if(has_admin_permission('view_audit_logs')) {
$actStmt = $db->query(
    "SELECT al.action, al.module, al.created_at, u.first_name, u.last_name, u.role
     FROM activity_logs al
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC
     LIMIT 6"
);
foreach ($actStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $recentActivity[] = [
        'action'     => (string)$row['action'],
        'module'     => (string)($row['module'] ?? ''),
        'actor'      => trim(((string)$row['first_name']) . ' ' . ((string)$row['last_name'])),
        'role'       => (string)($row['role'] ?? ''),
        'created_at' => (string)$row['created_at'],
    ];
}

}
json_response(true, [
    'data' => [
        'metrics' => [
            'visits_today'       => $todaysVisits,
            'active_students'    => $activeStudents,
            'pending_approvals'  => $pendingTotal,
            'low_stock'          => $lowStockCount,
            'out_of_stock'       => $outOfStockCount,
            'expired'            => $expiredCount,
            'expiring_soon'      => $expiringSoon,
            'inventory_attention' => $inventoryAttention,
            'queue_waiting'      => $queueWaiting,
            'queue_serving'      => $queueServing,
            'queue_completed'    => $queueCompleted,
            'active_emergencies' => $activeEmergencies,
        ],
        'attention_total' => $attentionTotal,
        'recent_activity' => $recentActivity,
        'server_time'     => date('c'),
    ]
]);
