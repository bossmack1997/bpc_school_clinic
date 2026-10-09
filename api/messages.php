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
        return true;
    }
    // Students & Teachers must be recorded participants
    $stmt = $db->prepare("SELECT COUNT(*) FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$convId, $userId]);
    return ((int)$stmt->fetchColumn()) > 0;
}

// ------------------------------------------------------------------
// 1. AVAILABLE CLINIC STAFF (Nurses & Healthcare Personnel)
// ------------------------------------------------------------------
if ($action === 'available_staff') {
    $stmt = $db->query("
        SELECT u.id, u.first_name, u.last_name, u.role, u.profile_picture, u.last_activity, u.clinic_status
        FROM users u
        WHERE u.role = 'nurse' AND u.status = 'active'
        ORDER BY u.last_activity DESC, u.first_name ASC
    ");
    $nurses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $staffList = [];
    foreach ($nurses as $n) {
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
    $rStmt = $db->prepare("SELECT id, role, first_name, last_name, profile_picture, status FROM users WHERE id = ? LIMIT 1");
    $rStmt->execute([$recipientId]);
    $recip = $rStmt->fetch(PDO::FETCH_ASSOC);

    if (!$recip || $recip['status'] !== 'active') {
        json_response(false, [], 'Recipient is not active.');
    }

    $recipRole = canonical_role($recip['role']);

    // Students and Teachers can only message Nurses
    if (($userRole === 'student' || $userRole === 'teacher') && $recipRole !== 'nurse') {
        json_response(false, [], 'Students and Teachers may only communicate directly with Clinic Nurses.');
    }

    // Determine conversation type
    $convType = 'student_nurse';
    if ($userRole === 'teacher' || $recipRole === 'teacher') {
        $convType = 'teacher_nurse';
    }

    // Check if an active conversation thread already exists between these 2 users
    $findStmt = $db->prepare("
        SELECT c.id, c.title, c.conversation_type, c.status
        FROM conversations c
        JOIN conversation_participants cp1 ON cp1.conversation_id = c.id AND cp1.user_id = ?
        JOIN conversation_participants cp2 ON cp2.conversation_id = c.id AND cp2.user_id = ?
        WHERE c.status = 'active'
        LIMIT 1
    ");
    $findStmt->execute([$userId, $recipientId]);
    $existing = $findStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        json_response(true, [
            'conversation_id'   => (int)$existing['id'],
            'conversation_type' => $existing['conversation_type'],
            'title'             => $existing['title'],
            'is_new'            => false
        ]);
    }

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
                       SELECT m.message_body 
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
            WHERE c.status = 'active' {$typeCondition}
            ORDER BY COALESCE(last_message_time, c.updated_at) DESC
        ");
        $stmt->execute(array_merge([$userId], $params));
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // Students and Teachers see only their own conversations
        $stmt = $db->prepare("
            SELECT c.id, c.conversation_type, c.title, c.created_by, c.status, c.created_at, c.updated_at,
                   (
                       SELECT m.message_body 
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
            WHERE cp.user_id = ? AND c.status = 'active'
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
            SELECT u.id, u.first_name, u.last_name, u.role, u.profile_picture, u.last_activity, u.clinic_status,
                   s.id AS student_record_id, s.student_number, s.course, s.year_level, s.section
            FROM conversation_participants cp
            JOIN users u ON u.id = cp.user_id
            LEFT JOIN students s ON s.user_id = u.id
            WHERE cp.conversation_id = ? AND u.id != ?
            LIMIT 1
        ");
        $pStmt->execute([$convId, $userId]);
        $other = $pStmt->fetch(PDO::FETCH_ASSOC);

        if (!$other) {
            // Fallback if no other participant (e.g. nurse viewing thread where creator is user)
            $pStmt2 = $db->prepare("
                SELECT u.id, u.first_name, u.last_name, u.role, u.profile_picture, u.last_activity, u.clinic_status,
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

    // Mark messages as read for this user
    try {
        $db->prepare("
            UPDATE conversation_participants 
            SET last_read_at = NOW() 
            WHERE conversation_id = ? AND user_id = ?
        ")->execute([$convId, $userId]);

        $db->prepare("
            UPDATE messages 
            SET read_at = NOW() 
            WHERE conversation_id = ? AND sender_id != ? AND read_at IS NULL
        ")->execute([$convId, $userId]);
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
        SELECT u.id, u.first_name, u.last_name, u.role, u.profile_picture, u.last_activity, u.clinic_status,
               s.student_number, s.course, s.year_level, s.section, s.blood_type, s.allergies
        FROM conversation_participants cp
        JOIN users u ON u.id = cp.user_id
        LEFT JOIN students s ON s.user_id = u.id
        WHERE cp.conversation_id = ? AND u.id != ?
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
    $afterCond = ($afterId > 0) ? "AND m.id > {$afterId}" : "";
    $mStmt = $db->prepare("
        SELECT m.id, m.conversation_id, m.sender_id, m.message_body, m.message_type, m.read_at, m.created_at,
               u.first_name, u.last_name, u.role, u.profile_picture
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.conversation_id = ? {$afterCond}
        ORDER BY m.created_at ASC
    ");
    $mStmt->execute([$convId]);
    $messages = $mStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($messages as &$msg) {
        $msg['id'] = (int)$msg['id'];
        $msg['sender_id'] = (int)$msg['sender_id'];
        $msg['is_outgoing'] = ($msg['sender_id'] === $userId);
        $msg['avatar'] = !empty($msg['profile_picture']) ? app_url($msg['profile_picture']) : asset_url('images/default-avatar.svg');
    }
    unset($msg);

    json_response(true, [
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

    if ($convId <= 0) {
        json_response(false, [], 'Invalid conversation ID.');
    }
    if (empty($messageBody)) {
        json_response(false, [], 'Message cannot be empty.');
    }
    if (mb_strlen($messageBody) > 3000) {
        json_response(false, [], 'Message exceeds 3,000 character limit.');
    }

    if (!is_authorized_in_conversation($db, $convId, $userId, $userRole)) {
        json_response(false, [], 'Access denied: You are not authorized to send messages in this conversation.');
    }

    // If nurse is replying but not yet listed as participant, add nurse to participants
    if ($userRole === 'nurse') {
        $checkP = $db->prepare("SELECT COUNT(*) FROM conversation_participants WHERE conversation_id = ? AND user_id = ?");
        $checkP->execute([$convId, $userId]);
        if (((int)$checkP->fetchColumn()) === 0) {
            $db->prepare("INSERT INTO conversation_participants (conversation_id, user_id, last_read_at) VALUES (?, ?, NOW())")
               ->execute([$convId, $userId]);
        }
    }

    // Insert message
    $stmt = $db->prepare("
        INSERT INTO messages (conversation_id, sender_id, message_body, message_type, created_at)
        VALUES (?, ?, ?, 'text', NOW())
    ");
    $stmt->execute([$convId, $userId, $messageBody]);
    $messageId = (int)$db->lastInsertId();

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
        $snippet = mb_substr($messageBody, 0, 80);

        foreach ($recipients as $recipId) {
            $targetRoleStmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $targetRoleStmt->execute([$recipId]);
            $tRole = canonical_role($targetRoleStmt->fetchColumn() ?: 'student');
            $dir = ($tRole === 'teacher') ? 'adviser' : $tRole;

            push_notification(
                (int)$recipId,
                'chat',
                "New message from {$senderName} ({$roleLabel})",
                $snippet,
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

// ------------------------------------------------------------------
// 7. UNREAD COUNT
// ------------------------------------------------------------------
if ($action === 'unread_count') {
    if ($userRole === 'nurse') {
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM messages m
            JOIN conversations c ON c.id = m.conversation_id
            WHERE c.status = 'active'
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
              AND c.status = 'active'
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

    $stmt = $db->prepare("
        SELECT m.message_body, m.created_at, u.first_name, u.last_name, u.role
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$convId]);
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
        $ai = new AIService();
        $prompt = "You are MICA, an AI clinic assistant at Bulacan Polytechnic College.
Summarize the following patient/teacher communication with the clinic nurse.
Follow these critical rules:
1. Provide a concise bulleted summary of: Chief Complaint / Situation reported, Timeframe, and Any mentioned symptoms.
2. Provide an 'Assistive Clinical Reminder' for the nurse (e.g. 'Consider measuring temperature and blood pressure upon student arrival.').
3. NEVER make a medical diagnosis or prescribe medications.
4. Add the standard disclaimer: 'AI-assisted operational summary for clinic triage. Healthcare professional makes all clinical decisions.'

Conversation:
{$threadText}";

        $res = $ai->chat($prompt, 'You are MICA, assistive clinic intelligence.', []);
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
