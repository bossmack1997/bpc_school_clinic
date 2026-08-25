<?php
/**
 * api/referrals.php
 * Support Referral workflow (nurse -> authorized support personnel).
 * Server-side role checks; sensitive referral details restricted.
 */
require_once __DIR__ . '/../config/config.php';
require_login();

header('Content-Type: application/json');
$db = getDB();
$u = current_user();
$role = $u['role'];
$action = $_REQUEST['action'] ?? '';

if (!can_manage_referrals($role)) {
    json_response(false, [], 'Access denied.');
}

// ---------------------------------------------------------------------
// CREATE referral (nurse / guidance)
// ---------------------------------------------------------------------
if ($action === 'create') {
    csrf_verify_or_die();

    $studentId = (int)($_POST['student_id'] ?? 0);
    $referredTo = trim($_POST['referred_to'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $priority = trim($_POST['priority'] ?? 'routine');
    $notes = trim($_POST['notes'] ?? '');
    $studentInformed = !empty($_POST['student_informed']) ? 1 : 0;

    if ($studentId <= 0) json_response(false, [], 'A valid student is required.');
    if ($referredTo === '') json_response(false, [], 'Referral destination is required.');
    if (!in_array($priority, ['routine', 'soon', 'urgent'], true)) $priority = 'routine';

    $stmt = $db->prepare("SELECT id FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    if (!$stmt->fetch()) json_response(false, [], 'Student not found.');

    $db->prepare("
        INSERT INTO support_referrals
            (student_id, created_by, referred_to, reason, priority, notes, student_informed, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ")->execute([
        $studentId, $u['id'], $referredTo, $reason, $priority, $notes, $studentInformed
    ]);

    $referralId = $db->lastInsertId();
    log_activity($u['id'], 'Created support referral', 'referral', "Referral #$referralId -> $referredTo (priority: $priority)");

    // Notify guidance / authorized support if that role exists
    try {
        $guidanceStmt = $db->query("SELECT id FROM users WHERE role = 'guidance' AND status = 'active'");
        $title = '📋 New Support Referral';
        $msg = "A student has been referred to $referredTo (Priority: " . ucfirst($priority) . ").";
        foreach ($guidanceStmt->fetchAll() as $g) {
            push_notification((int)$g['id'], 'system', $title, $msg, APP_URL . '/pages/guidance/dashboard.php', null, 'high', $referralId);
        }
    } catch (Throwable $e) {
        error_log('Referral guidance notify failed: ' . $e->getMessage());
    }

    json_response(true, ['referral_id' => $referralId], 'Referral created successfully.');
}

// ---------------------------------------------------------------------
// LIST referrals (nurse / guidance)
// ---------------------------------------------------------------------
if ($action === 'list') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    $where = '';
    $params = [];
    if ($studentId > 0) {
        $where = 'WHERE r.student_id = ?';
        $params[] = $studentId;
    }
    $stmt = $db->prepare("
        SELECT r.*,
               s.student_number,
               u.first_name, u.last_name,
               c.first_name AS c_first, c.last_name AS c_last
        FROM support_referrals r
        JOIN students s ON s.id = r.student_id
        JOIN users u ON u.id = s.user_id
        LEFT JOIN users c ON c.id = r.created_by
        $where
        ORDER BY r.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

// ---------------------------------------------------------------------
// UPDATE status (nurse / guidance)
// ---------------------------------------------------------------------
if ($action === 'update_status') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    $status = trim($_POST['status'] ?? '');

    if (!in_array($status, ['pending', 'accepted', 'in_support', 'follow_up_required', 'closed'], true)) {
        json_response(false, [], 'Invalid status.');
    }
    $stmt = $db->prepare("SELECT id FROM support_referrals WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) json_response(false, [], 'Referral not found.');

    $db->prepare("UPDATE support_referrals SET status = ?, updated_at = NOW() WHERE id = ?")
       ->execute([$status, $id]);
    log_activity($u['id'], 'Updated referral status', 'referral', "Referral #$id -> $status");
    json_response(true, [], 'Referral updated.');
}

json_response(false, [], 'Unknown action.');
