<?php
/**
 * api/messages.php
 * =====================================================================
 * Messenger-Grade Real-Time Clinic Communications API
 * BPC AI-Enhanced School Clinic Management System
 * =====================================================================
 */
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$userId = (int)$user['id'];
$userRole = canonical_role($user['role'] ?? '');
$action = $_REQUEST['action'] ?? '';
header('Cache-Control: no-store');
if (!in_array($userRole, ['student', 'teacher', 'nurse'], true)) {
    http_response_code(403);
    json_response(false, [], 'Clinic messaging is restricted to patients and clinic nurses.');
}
if (in_array($action, ['presence_ping', 'get_or_create_conversation', 'send_message', 'message_action', 'ai_summarize'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_response(false, [], 'POST required.'); }
    csrf_verify_or_die();
}
require_once __DIR__ . '/../includes/message_interactions.php';
require_once __DIR__ . '/../includes/messaging_threads.php';

// ------------------------------------------------------------------
// Helper: Helper to compute presence status
// ------------------------------------------------------------------
function compute_presence_status(?string $lastActivity): array {
    if (empty($lastActivity)) {
        return ['status' => 'offline', 'label' => 'Offline', 'badge' => 'status-offline'];
    }
    $diffSec = time() - strtotime($lastActivity);
    if ($diffSec <= 120) { // 2 minutes
        return ['status' => 'online', 'label' => 'Online', 'badge' => 'status-online'];
    } elseif ($diffSec <= 600) { // 10 minutes
        return ['status' => 'away', 'label' => 'Away', 'badge' => 'status-away'];
    } else {
        return ['status' => 'offline', 'label' => 'Offline', 'badge' => 'status-offline'];
    }
}

// ------------------------------------------------------------------
// Helper: Check if user is an authorized participant in conversation
// ------------------------------------------------------------------
function is_authorized_in_conversation(PDO $db, int $convId, int $userId, string $role): bool {
    // Nurses have clinic triage access to student and teacher conversations
    if ($role === 'nurse') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM conversations WHERE id = ? AND conversation_type IN ('student_nurse', 'teacher_nurse')");
        $stmt->execute([$convId]);
        return (int)$stmt->fetchColumn() > 0;
    }
    // Students & Teachers must be recorded participants
    $stmt = $db->prepare("SELECT COUNT(*) FROM conversation_participants p JOIN conversations c ON c.id=p.conversation_id WHERE p.conversation_id = ? AND p.user_id = ? AND c.conversation_type IN ('student_nurse','teacher_nurse')");
    $stmt->execute([$convId, $userId]);
    return ((int)$stmt->fetchColumn()) > 0;
}

// ------------------------------------------------------------------
// 1. AVAILABLE CLINIC STAFF (Nurses & Healthcare Personnel)
// ------------------------------------------------------------------
if ($action === 'available_contacts') {
    if ($userRole !== 'nurse') { http_response_code(403); json_response(false, [], 'Access denied.'); }
    $stmt = $db->query("SELECT id, first_name, last_name, role, profile_picture, last_activity FROM users WHERE role IN ('student', 'teacher', 'adviser') AND status = 'active' ORDER BY first_name, last_name");
    $contacts = [];
    foreach ($stmt as $person) {
        $presence = compute_presence_status($person['last_activity'] ?? null);
        $contacts[] = [
            'id'=>(int)$person['id'], 'name'=>trim($person['first_name'].' '.$person['last_name']),
            'first_name'=>$person['first_name'], 'role'=>canonical_role($person['role']) === 'teacher' ? 'Teacher' : 'Student',
            'avatar'=>!empty($person['profile_picture']) ? app_url($person['profile_picture']) : asset_url('images/default-avatar.svg'),
            'presence_status'=>$presence['status'], 'presence_badge'=>$presence['badge'], 'availability_label'=>$presence['label']
        ];
    }
    json_response(true, ['staff'=>$contacts]);
}
if ($action === 'available_staff') {
    $stmt = $db->query("
        SELECT u.id, u.first_name, u.last_name, u.role, u.self_service_only, u.profile_picture, u.last_activity, u.clinic_status
        FROM users u
        WHERE u.role = 'nurse' AND u.status = 'active' AND u.self_service_only=0
        ORDER BY u.last_activity DESC, u.first_name ASC
    ");
    $nurses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $staffList = [];
    foreach ($nurses as $n) {
        if(!has_permission('clinical_messages',$n,$db))continue;
        $presence = compute_presence_status($n['last_activity'] ?? null);
        $clinicStatus = $n['clinic_status'] ?? 'available';

        $availabilityLabel = 'Offline · Messages delivered';
        if ($presence['status'] === 'online') {
            if ($clinicStatus === 'busy') {
                $availabilityLabel = 'Assisting a patient';
            } elseif ($clinicStatus === 'away') {
                $availabilityLabel = 'Away from desk';
            } else {
                $availabilityLabel = 'Available for messages';
            }
        } elseif ($presence['status'] === 'away') {
            $availabilityLabel = 'Away from desk';
        }

        $avatar = !empty($n['profile_picture']) ? app_url($n['profile_picture']) : asset_url('images/default-avatar.svg');

        $staffList[] = [
            'id'                 => (int)$n['id'],
            'name'               => trim("{$n['first_name']} {$n['last_name']}"),
            'first_name'         => $n['first_name'],
            'last_name'          => $n['last_name'],
            'role'               => 'Clinic Nurse',
            'avatar'             => $avatar,
            'presence_status'    => $presence['status'],
            'presence_badge'     => $presence['badge'],
            'clinic_status'      => $clinicStatus,
            'availability_label' => $availabilityLabel,
            'is_online'          => ($presence['status'] === 'online')
        ];
    }

    json_response(true, ['staff' => $staffList]);
}

// ------------------------------------------------------------------
// 2. PRESENCE HEARTBEAT PING
// ------------------------------------------------------------------
if ($action === 'presence_ping') {
    try {
        $newClinicStatus = trim($_POST['clinic_status'] ?? '');
        if ($userRole === 'nurse' && in_array($newClinicStatus, ['available', 'busy', 'away', 'offline'], true)) {
            $db->prepare("UPDATE users SET last_activity = NOW(), clinic_status = ? WHERE id = ?")->execute([$newClinicStatus, $userId]);
        } else {
            $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$userId]);
        }
        json_response(true, ['status' => 'online', 'timestamp' => date('Y-m-d H:i:s')]);
    } catch (Exception $e) {
        json_response(false, [], 'Failed to update presence.');
    }
}

// ------------------------------------------------------------------
// 3. GET OR CREATE 1-ON-1 CONVERSATION THREAD (Strictly 1 thread)
// ------------------------------------------------------------------
if ($action === 'get_or_create_conversation') {
    $recipientId = (int)($_POST['recipient_id'] ?? $_GET['recipient_id'] ?? 0);

    if ($recipientId <= 0 || $recipientId === $userId) {
        json_response(false, [], 'Invalid recipient specified.');
    }

    // Role boundary validation
    $rStmt = $db->prepare("SELECT id, role, self_service_only, first_name, last_name, profile_picture, status FROM users WHERE id = ? LIMIT 1");
    $rStmt->execute([$recipientId]);
    $recip = $rStmt->fetch(PDO::FETCH_ASSOC);

    if (!$recip || $recip['status'] !== 'active') {
        json_response(false, [], 'Recipient is not active.');
    }

    $recipRole = canonical_role($recip['role']);
    if($recipRole==='nurse' && !has_permission('clinical_messages',$recip,$db))json_response(false,[],'This Nurse is not authorized for clinical messaging.');

    // Students and Teachers can only message Nurses
    if (($userRole === 'student' || $userRole === 'teacher') && $recipRole !== 'nurse') {
        json_response(false, [], 'Students and Teachers may only communicate directly with Clinic Nurses.');
    }

    if ($userRole === 'nurse' && !in_array($recipRole, ['student', 'teacher'], true)) json_response(false, [], 'Select a student or teacher.');

    // Determine conversation type
    $convType = 'student_nurse';
    if ($userRole === 'teacher' || $recipRole === 'teacher') {
        $convType = 'teacher_nurse';
    }

    $patientId = $userRole === 'nurse' ? $recipientId : $userId;
    $lockName = 'clinic_patient_chat_' . $patientId;
    $lock = $db->prepare('SELECT GET_LOCK(?, 5)'); $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) json_response(false, [], 'Conversation is busy. Please try again.');
    register_shutdown_function(function() use ($db, $lockName) {
        if ($db->inTransaction()) $db->rollBack();
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    });
    $singlePatientCondition = $userRole === 'nurse' ? "AND (SELECT COUNT(*) FROM conversation_participants p JOIN users pu ON pu.id = p.user_id WHERE p.conversation_id = c.id AND pu.role IN ('student', 'teacher', 'adviser')) = 1" : '';
    // Nurses reuse the patient's shared clinic thread; patients keep their authorized staff threads.
    $findStmt = $db->prepare("
        SELECT c.id, c.title, c.conversation_type, c.status
        FROM conversations c
        JOIN conversation_participants cp1 ON cp1.conversation_id = c.id AND cp1.user_id = ?
        JOIN conversation_participants cp2 ON cp2.conversation_id = c.id AND cp2.user_id = ?
        WHERE c.status = 'active' AND c.conversation_type IN ('student_nurse', 'teacher_nurse') {$singlePatientCondition}
        ORDER BY c.id
        LIMIT 1
    ");
    $findStmt->execute([$userRole === 'nurse' ? $recipientId : $userId, $recipientId]);
    $existing = $findStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        json_response(true, [
            'conversation_id'   => (int)$existing['id'],
            'conversation_type' => $existing['conversation_type'],
            'title'             => $existing['title'],
            'is_new'            => false
        ]);
    }

    $db->beginTransaction();
    // Create a new single 1-on-1 thread
    $title = ($userRole === 'nurse') 
        ? "Clinic Consultation ({$recip['first_name']} {$recip['last_name']})"
        : "Clinic Consultation (" . trim("{$user['first_name']} {$user['last_name']}") . ")";

    $cStmt = $db->prepare("
        INSERT INTO conversations (conversation_type, title, created_by, status, created_at, updated_at)
        VALUES (?, ?, ?, 'active', NOW(), NOW())
    ");
    $cStmt->execute([$convType, $title, $userId]);
    $newConvId = (int)$db->lastInsertId();

    // Add both participants
    $pStmt = $db->prepare("INSERT INTO conversation_participants (conversation_id, user_id, last_read_at) VALUES (?, ?, NOW())");
    $pStmt->execute([$newConvId, $userId]);

    $pStmt2 = $db->prepare("INSERT INTO conversation_participants (conversation_id, user_id, last_read_at) VALUES (?, ?, NULL)");
    $pStmt2->execute([$newConvId, $recipientId]);

    $db->commit();
    log_activity($userId, 'Started 1-on-1 clinic conversation', 'messages', "Conv ID $newConvId with User #$recipientId");

    json_response(true, [
        'conversation_id'   => $newConvId,
        'conversation_type' => $convType,
        'title'             => $title,
        'is_new'            => true
    ], 'New conversation initialized.');
}

// ------------------------------------------------------------------
// 4. LIST CONVERSATIONS
// ------------------------------------------------------------------
if ($action === 'list_conversations') {
    $filterType = $_GET['type'] ?? 'all';

    if ($userRole === 'nurse') {
        // Nurse sees all active clinic conversations
        $typeCondition = "";
        $params = [];
        if ($filterType === 'student_nurse') {
            $typeCondition = "AND c.conversation_type = 'student_nurse'";
        } elseif ($filterType === 'teacher_nurse') {
            $typeCondition = "AND c.conversation_type = 'teacher_nurse'";
        }

        $stmt = $db->prepare("
            SELECT c.id, c.conversation_type, c.title, c.created_by, c.status, c.created_at, c.updated_at,
                   (
                       SELECT COALESCE(NULLIF(m.message_body, ''), CONCAT('[Attachment] ', COALESCE(m.attachment_name, '')))
                       FROM messages m 
                       WHERE m.conversation_id = c.id 
                       ORDER BY m.created_at DESC LIMIT 1
                   ) AS last_message,
                   (
                       SELECT m.created_at 
                       FROM messages m 
                       WHERE m.conversation_id = c.id 
                       ORDER BY m.created_at DESC LIMIT 1
                   ) AS last_message_time,
                   (
                       SELECT m.sender_id 
                       FROM messages m 
                       WHERE m.conversation_id = c.id 
                       ORDER BY m.created_at DESC LIMIT 1
                   ) AS last_sender_id,
                   (
                       SELECT COUNT(*) 
                       FROM messages m 
                       WHERE m.conversation_id = c.id 
                         AND m.sender_id != ? 
                         AND m.read_at IS NULL AND m.sender_id IN (SELECT id FROM users WHERE role IN ('student', 'teacher', 'adviser'))
                   ) AS unread_count
            FROM conversations c
            WHERE c.status = 'active' AND c.conversation_type IN ('student_nurse', 'teacher_nurse') {$typeCondition}
            ORDER BY COALESCE(last_message_time, c.updated_at) DESC
        ");
        $stmt->execute(array_merge([$userId], $params));
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // Students and Teachers see only their own conversations
        $stmt = $db->prepare("
            SELECT c.id, c.conversation_type, c.title, c.created_by, c.status, c.created_at, c.updated_at,
                   (
                       SELECT COALESCE(NULLIF(m.message_body, ''), CONCAT('[Attachment] ', COALESCE(m.attachment_name, '')))
                       FROM messages m 
                       WHERE m.conversation_id = c.id 
                       ORDER BY m.created_at DESC LIMIT 1
                   ) AS last_message,
                   (
                       SELECT m.created_at 
                       FROM messages m 
                       WHERE m.conversation_id = c.id 
                       ORDER BY m.created_at DESC LIMIT 1
                   ) AS last_message_time,
                   (
                       SELECT m.sender_id 
                       FROM messages m 
                       WHERE m.conversation_id = c.id 
                       ORDER BY m.created_at DESC LIMIT 1
                   ) AS last_sender_id,
                   (
                       SELECT COUNT(*) 
                       FROM messages m 
                       WHERE m.conversation_id = c.id 
                         AND m.sender_id != ? 
                         AND m.read_at IS NULL
                   ) AS unread_count
            FROM conversations c
            JOIN conversation_participants cp ON cp.conversation_id = c.id
            WHERE cp.user_id = ? AND c.status = 'active' AND c.conversation_type IN ('student_nurse','teacher_nurse')
            ORDER BY COALESCE(last_message_time, c.updated_at) DESC
        ");
        $stmt->execute([$userId, $userId]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Enrich conversation items with the counterpart participant details
    foreach ($conversations as &$conv) {
        $convId = (int)$conv['id'];
        // Find other participant (or if nurse, find student/teacher)
        $pStmt = $db->prepare("
            SELECT u.id, u.first_name, u.last_name, u.role, u.self_service_only, u.profile_picture, u.last_activity, u.clinic_status,
                   s.id AS student_record_id, s.student_number, s.course, s.year_level, s.section
            FROM conversation_participants cp
            JOIN users u ON u.id = cp.user_id
            LEFT JOIN students s ON s.user_id = u.id
            WHERE cp.conversation_id = ? AND u.id != ?
            ORDER BY (u.role IN ('student', 'teacher', 'adviser')) DESC
            LIMIT 1
        ");
        $pStmt->execute([$convId, $userId]);
        $other = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$other) {
            // Fallback if no other participant (e.g. nurse viewing thread where creator is user)
            $pStmt2 = $db->prepare("
                SELECT u.id, u.first_name, u.last_name, u.role, u.self_service_only, u.profile_picture, u.last_activity, u.clinic_status,
                       s.id AS student_record_id, s.student_number, s.course, s.year_level, s.section
                FROM conversation_participants cp
                JOIN users u ON u.id = cp.user_id
                LEFT JOIN students s ON s.user_id = u.id
                WHERE cp.conversation_id = ?
                LIMIT 1
            ");
            $pStmt2->execute([$convId]);
            $other = $pStmt2->fetch(PDO::FETCH_ASSOC);
        }

        if ($other) {
            $presence = compute_presence_status($other['last_activity'] ?? null);
            $roleLabel = ($other['role'] === 'nurse') ? 'Clinic Nurse' : (($other['role'] === 'teacher') ? 'Teacher' : 'Student');
            $subText = $roleLabel;
            if (!empty($other['student_number'])) {
                $subText = "{$other['student_number']} · " . trim("{$other['course']} {$other['year_level']}");
            }

            $conv['counterpart'] = [
                'id'                => (int)$other['id'],
                'student_record_id' => !empty($other['student_record_id']) ? (int)$other['student_record_id'] : null,
                'student_number'    => $other['student_number'] ?? null,
                'course'            => $other['course'] ?? null,
                'year_level'        => $other['year_level'] ?? null,
                'section'           => $other['section'] ?? null,
                'name'              => trim("{$other['first_name']} {$other['last_name']}"),
                'first_name'        => $other['first_name'],
                'last_name'         => $other['last_name'],
                'role'              => $other['role'],
                'role_label'        => $roleLabel,
                'subtext'           => $subText,
                'avatar'            => !empty($other['profile_picture']) ? app_url($other['profile_picture']) : asset_url('images/default-avatar.svg'),
                'presence_status'   => $presence['status'],
                'presence_badge'    => $presence['badge'],
                'is_online'         => ($presence['status'] === 'online')
            ];
        } else {
            $conv['counterpart'] = [
                'id'              => 0,
                'name'            => $conv['title'] ?: 'Clinic Consultation',
                'first_name'      => 'Clinic',
                'last_name'       => 'Station',
                'role'            => 'nurse',
                'role_label'      => 'Clinic Nurse',
                'subtext'         => 'Clinic Station',
                'avatar'          => asset_url('images/default-avatar.svg'),
                'presence_status' => 'offline',
                'presence_badge'  => 'status-offline',
                'is_online'       => false
            ];
        }
    }
    unset($conv);

    if (messaging_interactions_ready($db)) {
        $removed = $db->prepare('SELECT i.deleted_at FROM messages m LEFT JOIN message_interactions i ON i.message_id = m.id WHERE m.conversation_id = ? ORDER BY m.created_at DESC, m.id DESC LIMIT 1');
        foreach ($conversations as &$item) {
            $removed->execute([(int)$item['id']]);
            if ($removed->fetchColumn()) $item['last_message'] = 'Message removed';
        }
        unset($item);
    }
    if ($userRole === 'nurse') $conversations = group_clinic_patient_threads($db, $conversations);
    json_response(true, ['conversations' => $conversations]);
}

// ------------------------------------------------------------------
// 5. GET MESSAGES (Supports Delta Fetching via after_id)
// ------------------------------------------------------------------
if ($action === 'get_messages') {
    $convId = (int)($_GET['conversation_id'] ?? 0);
    $afterId = (int)($_GET['after_id'] ?? 0);

    if ($convId <= 0) {
        json_response(false, [], 'Invalid conversation ID.');
    }

    if (!is_authorized_in_conversation($db, $convId, $userId, $userRole)) {
        json_response(false, [], 'Access denied: You are not authorized to view this clinic conversation.');
    }

    $threadIds = clinic_patient_thread_ids($db, $convId, $userRole);
    $threadSlots = implode(',', array_fill(0, count($threadIds), '?'));
    $incomingCondition = $userRole === 'nurse' ? "AND sender_id IN (SELECT id FROM users WHERE role IN ('student', 'teacher', 'adviser'))" : '';
    // Mark messages as read for this user
    try {
        $db->prepare("
            UPDATE conversation_participants 
            SET last_read_at = NOW() 
            WHERE conversation_id IN ({$threadSlots}) AND user_id = ?
        ")->execute(array_merge($threadIds, [$userId]));

        $db->prepare("
            UPDATE messages 
            SET read_at = NOW() 
            WHERE conversation_id IN ({$threadSlots}) AND sender_id != ? AND read_at IS NULL {$incomingCondition}
        ")->execute(array_merge($threadIds, [$userId]));
    } catch (Exception $e) {
        error_log('Error updating read status: ' . $e->getMessage());
    }

    // Fetch conversation info
    $convStmt = $db->prepare("SELECT * FROM conversations WHERE id = ? LIMIT 1");
    $convStmt->execute([$convId]);
    $conversation = $convStmt->fetch(PDO::FETCH_ASSOC);
    if (!$conversation) {
        json_response(false, [], 'Conversation not found.');
    }

    // Find counterpart info for header
    $pStmt = $db->prepare("
        SELECT u.id, u.first_name, u.last_name, u.role, u.self_service_only, u.profile_picture, u.last_activity, u.clinic_status,
               s.student_number, s.course, s.year_level, s.section, s.blood_type, s.allergies
        FROM conversation_participants cp
        JOIN users u ON u.id = cp.user_id
        LEFT JOIN students s ON s.user_id = u.id
        WHERE cp.conversation_id = ? AND u.id != ?
        ORDER BY (u.role IN ('student', 'teacher', 'adviser')) DESC
        LIMIT 1
    ");
    $pStmt->execute([$convId, $userId]);
    $other = $pStmt->fetch(PDO::FETCH_ASSOC);

    if ($other) {
        $presence = compute_presence_status($other['last_activity'] ?? null);
        $roleLabel = ($other['role'] === 'nurse') ? 'Clinic Nurse' : (($other['role'] === 'teacher') ? 'Teacher' : 'Student');
        $subText = $roleLabel;
        if (!empty($other['student_number'])) {
            $subText = "{$other['student_number']} · " . trim("{$other['course']} {$other['year_level']} {$other['section']}");
        }

        $conversation['counterpart'] = [
            'id'              => (int)$other['id'],
            'name'            => trim("{$other['first_name']} {$other['last_name']}"),
            'role'            => $other['role'],
            'role_label'      => $roleLabel,
            'subtext'         => $subText,
            'avatar'          => !empty($other['profile_picture']) ? app_url($other['profile_picture']) : asset_url('images/default-avatar.svg'),
            'presence_status' => $presence['status'],
            'presence_label'  => $presence['label'],
            'presence_badge'  => $presence['badge'],
            'is_online'       => ($presence['status'] === 'online'),
            'blood_type'      => $other['blood_type'] ?? null,
            'allergies'       => $other['allergies'] ?? null,
        ];
    } else {
        $conversation['counterpart'] = [
            'id'              => 0,
            'name'            => $conversation['title'] ?: 'Clinic Consultation',
            'role'            => 'nurse',
            'role_label'      => 'Clinic Nurse',
            'subtext'         => 'Clinic Station',
            'avatar'          => asset_url('images/default-avatar.svg'),
            'presence_status' => 'offline',
            'presence_label'  => 'Offline',
            'presence_badge'  => 'status-offline',
            'is_online'       => false
        ];
    }

    // Fetch messages (if after_id is given, only fetch newer messages)
    $beforeId = max(0, (int)($_GET['before_id'] ?? 0));
    $beforeCond = $beforeId ? 'AND m.id < ' . $beforeId : '';
    $afterCond = ($afterId > 0) ? "AND m.id > {$afterId}" : "";
    $mStmt = $db->prepare("
        SELECT m.id, m.conversation_id, m.sender_id, m.message_body, m.message_type, m.attachment_path, m.attachment_name, m.attachment_mime, m.attachment_size, m.read_at, m.created_at,
               u.first_name, u.last_name, u.role, u.profile_picture
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.conversation_id IN ({$threadSlots}) {$afterCond} {$beforeCond}
        ORDER BY m.id DESC LIMIT 81
    ");
    $mStmt->execute($threadIds);
    $messages = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($messages) > 80;
    if ($hasMore) array_pop($messages);
    $messages = array_reverse($messages);

    foreach ($messages as &$msg) {
        $msg['id'] = (int)$msg['id'];
        $msg['sender_id'] = (int)$msg['sender_id'];
        $msg['is_outgoing'] = ($msg['sender_id'] === $userId);
        $msg['avatar'] = !empty($msg['profile_picture']) ? app_url($msg['profile_picture']) : asset_url('images/default-avatar.svg');
        $msg['attachment_url'] = !empty($msg['attachment_path']) ? APP_URL . '/api/messages.php?action=download_attachment&id=' . (int)$msg['id'] : null;
        unset($msg['attachment_path']);
    }
    unset($msg);

    enrich_message_interactions($db, $messages);
    json_response(true, [
        'has_more' => $hasMore,
        'interactions_enabled' => messaging_interactions_ready($db),
        'conversation' => $conversation,
        'messages'     => $messages
    ]);
}

// ------------------------------------------------------------------
// 6. SEND MESSAGE
// ------------------------------------------------------------------
if ($action === 'send_message') {
    csrf_verify_or_die();
    $convId = (int)($_POST['conversation_id'] ?? 0);
    $messageBody = trim($_POST['message_body'] ?? '');
    $upload = $_FILES['attachment'] ?? null;

    if ($convId <= 0) {
        json_response(false, [], 'Invalid conversation ID.');
    }
    $hasUpload = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($messageBody === '' && !$hasUpload) json_response(false, [], 'Write a message or attach a file.');
    if (mb_strlen($messageBody) > 3000) {
        json_response(false, [], 'Message exceeds 3,000 character limit.');
    }
    if (!is_authorized_in_conversation($db, $convId, $userId, $userRole)) {
        json_response(false, [], 'Access denied: You are not authorized to send messages in this conversation.');
    }
    $activeConversation = $db->prepare("SELECT COUNT(*) FROM conversations WHERE id = ? AND status = 'active'");
    $activeConversation->execute([$convId]);
    if (!(int)$activeConversation->fetchColumn()) json_response(false, [], 'This conversation is no longer available.');

    $replyTo = (int)($_POST['reply_to_id'] ?? 0);
    if ($replyTo) {
        if (!messaging_interactions_ready($db)) json_response(false, [], 'Reply support is unavailable.');
        $replyThreads = clinic_patient_thread_ids($db, $convId, $userRole);
        $replySlots = implode(',', array_fill(0, count($replyThreads), '?'));
        $replyCheck = $db->prepare("SELECT COUNT(*) FROM messages WHERE id = ? AND conversation_id IN ($replySlots)");
        $replyCheck->execute(array_merge([$replyTo], $replyThreads));
        if (!(int)$replyCheck->fetchColumn()) json_response(false, [], 'Reply must belong to this conversation.');
    }
    $storedPath = null;
    $originalName = null;
    $mimeType = null;
    $fileSize = null;
    if ($hasUpload) {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_response(false, [], 'The attachment could not be uploaded. Try a smaller file.');
        if ((int)$upload['size'] > 10 * 1024 * 1024) json_response(false, [], 'Attachments must be 10 MB or smaller.');
        $extension = strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION));
        $allowed = [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'], 'webp' => ['image/webp'],
            'doc' => ['application/msword', 'application/CDFV2', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        ];
        if (!isset($allowed[$extension])) json_response(false, [], 'Allowed attachments: PDF, JPG, PNG, WEBP, DOC, DOCX.');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($upload['tmp_name']);
        if (!in_array($detectedMime, $allowed[$extension], true)) json_response(false, [], 'The file content does not match its file type.');
        $originalName = mb_substr(basename($upload['name']), 0, 255);
        $mimeType = $detectedMime;
        $fileSize = (int)$upload['size'];
        $relativeDir = 'uploads/messages';
        $absoluteDir = BASE_PATH . '/' . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0750, true) && !is_dir($absoluteDir)) json_response(false, [], 'Attachment storage is unavailable.');
        $filename = bin2hex(random_bytes(24)) . '.' . $extension;
        $storedPath = $relativeDir . '/' . $filename;
        if (!move_uploaded_file($upload['tmp_name'], BASE_PATH . '/' . $storedPath)) json_response(false, [], 'Failed to save the attachment.');
    }

    // If nurse is replying but not yet listed as participant, add nurse to participants
    if ($userRole === 'nurse') {
        $checkP = $db->prepare("SELECT COUNT(*) FROM conversation_participants p JOIN conversations c ON c.id=p.conversation_id WHERE p.conversation_id = ? AND p.user_id = ? AND c.conversation_type IN ('student_nurse','teacher_nurse')");
        $checkP->execute([$convId, $userId]);
        if (((int)$checkP->fetchColumn()) === 0) {
            $db->prepare("INSERT INTO conversation_participants (conversation_id, user_id, last_read_at) VALUES (?, ?, NOW())")
               ->execute([$convId, $userId]);
        }
    }

    // Save the message and its reply metadata atomically.
    $messageTransactionOwner = !$db->inTransaction();
    if ($messageTransactionOwner) $db->beginTransaction();
    // Insert message
    $stmt = $db->prepare("
        INSERT INTO messages (conversation_id, sender_id, message_body, message_type, attachment_path, attachment_name, attachment_mime, attachment_size, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    try {
        $stmt->execute([$convId, $userId, $messageBody, $hasUpload ? 'file' : 'text', $storedPath, $originalName, $mimeType, $fileSize]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($storedPath && is_file(BASE_PATH . '/' . $storedPath)) unlink(BASE_PATH . '/' . $storedPath);
        throw $e;
    }
    $messageId = (int)$db->lastInsertId();
    try {
        if ($replyTo) $db->prepare('INSERT INTO message_interactions (message_id, reply_to_id) VALUES (?, ?)')->execute([$messageId, $replyTo]);
        if ($messageTransactionOwner) $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($storedPath && is_file(BASE_PATH . '/' . $storedPath)) unlink(BASE_PATH . '/' . $storedPath);
        throw $e;
    }

    // Update conversation timestamp
    $db->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?")->execute([$convId]);
    $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$userId]);

    // Push notification to other participants
    try {
        $pStmt = $db->prepare("SELECT user_id FROM conversation_participants WHERE conversation_id = ? AND user_id != ?");
        $pStmt->execute([$convId, $userId]);
        $recipients = $pStmt->fetchAll(PDO::FETCH_COLUMN);

        $senderName = trim("{$user['first_name']} {$user['last_name']}");
        $roleLabel = ($userRole === 'nurse') ? 'Nurse' : (($userRole === 'teacher') ? 'Teacher' : 'Student');
        $snippet = $messageBody !== '' ? mb_substr($messageBody, 0, 80) : ('Attached a file: ' . ($originalName ?: 'attachment'));

        foreach ($recipients as $recipId) {
            $targetRoleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $targetRoleStmt->execute([$recipId]);
            $tRole = canonical_role($targetRoleStmt->fetchColumn() ?: 'student');
            $dir = ($tRole === 'teacher') ? 'adviser' : $tRole;

            push_notification(
                (int)$recipId,
                'chat',
                "New message from {$senderName} ({$roleLabel})",
                'You have a new clinic message. Open Messages to read it.',
                APP_URL . "/pages/{$dir}/messages.php?id={$convId}",
                null,
                'medium',
                $convId
            );
        }
    } catch (Exception $e) {
        error_log('Notification on message error: ' . $e->getMessage());
    }

    json_response(true, [
        'message_id' => $messageId,
        'created_at' => date('Y-m-d H:i:s')
    ], 'Message sent successfully.');
}

// Private attachment download: require conversation access for every request.
if ($action === 'download_attachment') {
    $messageId = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT conversation_id, attachment_path, attachment_name, attachment_mime FROM messages WHERE id = ? AND attachment_path IS NOT NULL LIMIT 1');
    $stmt->execute([$messageId]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (messaging_interactions_ready($db)) {
        $removed = $db->prepare('SELECT deleted_at FROM message_interactions WHERE message_id = ?');
        $removed->execute([$messageId]);
        if ($removed->fetchColumn()) $attachment = false;
    }
    if (!$attachment || !is_authorized_in_conversation($db, (int)$attachment['conversation_id'], $userId, $userRole)) {
        http_response_code(404);
        exit('Attachment not found.');
    }
    $baseDir = realpath(BASE_PATH . '/uploads/messages');
    $filePath = realpath(BASE_PATH . '/' . $attachment['attachment_path']);
    if (!$baseDir || !$filePath || strpos($filePath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
        http_response_code(404);
        exit('Attachment not found.');
    }
    $safeName = str_replace(["\r", "\n", '"'], ['', '', "'"], basename($attachment['attachment_name'] ?: 'attachment'));
    header_remove('Content-Type');
    header('Content-Type: ' . ($attachment['attachment_mime'] ?: 'application/octet-stream'));
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
    header('X-Content-Type-Options: nosniff');
    readfile($filePath);
    exit;
}

// ------------------------------------------------------------------
// 7. UNREAD COUNT
// ------------------------------------------------------------------
if ($action === 'unread_count') {
    if ($userRole === 'nurse') {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            WHERE c.status = 'active' AND c.conversation_type IN ('student_nurse', 'teacher_nurse')
              AND m.sender_id IN (SELECT id FROM users WHERE role IN ('student', 'teacher', 'adviser'))
              AND m.sender_id != ? 
              AND m.read_at IS NULL
        ");
        $stmt->execute([$userId]);
        $unread = (int)$stmt->fetchColumn();
    } else {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            JOIN conversation_participants cp ON cp.conversation_id = c.id
            WHERE cp.user_id = ?
              AND c.status = 'active' AND c.conversation_type IN ('student_nurse','teacher_nurse')
              AND m.sender_id != ? 
              AND m.read_at IS NULL
        ");
        $stmt->execute([$userId, $userId]);
        $unread = (int)$stmt->fetchColumn();
    }

    json_response(true, ['unread_count' => $unread]);
}

// ------------------------------------------------------------------
// 8. AI CONVERSATION SUMMARIZATION (Nurse Decision Support)
// ------------------------------------------------------------------
if ($action === 'ai_summarize') {
    csrf_verify_or_die();
    if ($userRole !== 'nurse' && !is_primary_admin($user)) {
        json_response(false, [], 'Access denied: AI Clinic Summarization is reserved for authorized healthcare personnel.');
    }

    $convId = (int)($_POST['conversation_id'] ?? 0);
    if ($convId <= 0) {
        json_response(false, [], 'Invalid conversation ID.');
    }

    if (!is_authorized_in_conversation($db, $convId, $userId, $userRole)) json_response(false, [], 'Conversation unavailable.');

    $summaryThreads = clinic_patient_thread_ids($db, $convId, $userRole);
    $summarySlots = implode(',', array_fill(0, count($summaryThreads), '?'));
    $stmt = $db->prepare("
        SELECT m.message_body, m.created_at, u.first_name, u.last_name, u.role
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.conversation_id IN ({$summarySlots})
        ORDER BY m.created_at ASC
    ");
    $stmt->execute($summaryThreads);
    $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($msgs)) {
        json_response(false, [], 'No messages to summarize in this conversation.');
    }

    // Build plain text thread for summarization
    $threadText = "";
    foreach ($msgs as $m) {
        $sender = "{$m['first_name']} ({$m['role']})";
        $threadText .= "[{$m['created_at']}] {$sender}: {$m['message_body']}\n";
    }

    // Generate clinical operational summary using assistive heuristics or AI service
    $summary = "";
    try {
        require_once __DIR__ . '/../includes/ai/AIService.php';
        $ai = new AIService($db, $user);
        $prompt = "You are MICA, an AI clinic assistant at Bulacan Polytechnic College.
Summarize the following patient/teacher communication with the clinic nurse.
Follow these critical rules:
1. Provide a concise bulleted summary of: Chief Complaint / Situation reported, Timeframe, and Any mentioned symptoms.
2. Provide an 'Assistive Clinical Reminder' for the nurse (e.g. 'Consider measuring temperature and blood pressure upon student arrival.').
3. NEVER make a medical diagnosis or prescribe medications.
4. Add the standard disclaimer: 'AI-assisted operational summary for clinic triage. Healthcare professional makes all clinical decisions.'

Conversation:
{$threadText}";

        $res = $ai->getOpenRouter()->complete('You are MICA, assistive clinic intelligence.', [['role'=>'user','content'=>$prompt]]);
        $summary = $res['reply'] ?? '';
    } catch (Exception $e) {
        error_log("AI summarize error: " . $e->getMessage());
    }

    // Fallback deterministic summary if AI API is offline
    if (empty($summary)) {
        $firstMsg = $msgs[0]['message_body'] ?? '';
        $lastMsg = end($msgs)['message_body'] ?? '';
        $summary = "**AI-Assisted Conversation Summary:**\n" .
                   "- **Initial Report:** " . htmlspecialchars(mb_substr($firstMsg, 0, 150)) . "\n" .
                   "- **Latest Update:** " . htmlspecialchars(mb_substr($lastMsg, 0, 150)) . "\n\n" .
                   "**Assistive Clinical Reminder:** Consider reviewing patient vitals and health records upon clinic visit.\n\n" .
                   "*Note: AI-assisted operational summary for triage. Healthcare professional makes all clinical decisions.*";
    }

    json_response(true, ['summary' => $summary]);
}

json_response(false, [], 'Unknown action.');
