<?php
/**
 * api/queue.php
 * =====================================================================
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 * Dedicated Queue Management REST API
 * =====================================================================
 */

require_once __DIR__ . '/../config/config.php';
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? 'get_status';

// Helper: Response formatting
function q_json($success, $data = [], $message = '') {
    echo json_encode([
        'success' => (bool)$success,
        'data'    => $data,
        'message' => $message,
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =====================================================================
// ACTION: public_display (TV / Monitor Live Screen - Privacy Safe)
// =====================================================================
if ($action === 'public_display') {
    // 1. Get currently called or serving ticket(s)
    $stmt = $db->query("
        SELECT id, queue_number, status, patient_type, priority,
               called_at, served_at,
               TIMESTAMPDIFF(SECOND, COALESCE(called_at, served_at, created_at), NOW()) AS elapsed_seconds
        FROM queue_numbers
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
          AND status IN ('called', 'serving')
        ORDER BY 
            FIELD(status, 'called', 'serving'),
            FIELD(priority, 'emergency', 'urgent', 'normal'),
            COALESCE(called_at, served_at) DESC
        LIMIT 1
    ");
    $nowServing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // 2. Next in line tickets (waiting or called but not yet in room)
    $nextStmt = $db->prepare("
        SELECT id, queue_number, status, patient_type, priority, created_at
        FROM queue_numbers
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
          AND status = 'waiting'
        ORDER BY
            FIELD(priority, 'emergency', 'urgent', 'normal'),
            created_at ASC
        LIMIT 6
    ");
    $nextStmt->execute();
    $nextInLine = $nextStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 3. Recently completed
    $recentStmt = $db->query("
        SELECT queue_number, status, patient_type, completed_at
        FROM queue_numbers
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
          AND status = 'completed'
        ORDER BY completed_at DESC
        LIMIT 4
    ");
    $recentCompleted = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 4. Queue metrics (single consolidated query)
    $metricsStmt = $db->query("
        SELECT 
            SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) AS total_waiting,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS total_served
        FROM queue_numbers
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
    ");
    $metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalWaiting = (int)($metrics['total_waiting'] ?? 0);
    $totalServed = (int)($metrics['total_served'] ?? 0);

    $avgWait = calculate_queue_wait_time($db, $totalWaiting);

    q_json(true, [
        'now_serving'        => $nowServing,
        'next_in_line'       => $nextInLine,
        'recent_completed'   => $recentCompleted,
        'total_waiting'      => $totalWaiting,
        'total_served'       => $totalServed,
        'estimated_wait_min' => $avgWait,
        'clinic_open'        => true,
        'announcement'       => 'Please prepare your Student ID or QR Pass when called.',
    ]);
}

// =====================================================================
// ACTION: get_status (For Student / Teacher / Nurse / Public Widget)
// =====================================================================
if ($action === 'get_status') {
    // Current serving (direct indexed user joins)
    $stmt = $db->query("
        SELECT q.id, q.queue_number, q.status, q.patient_type, q.priority, q.called_at, q.served_at,
               COALESCE(
                   CONCAT(us.first_name, ' ', SUBSTRING(us.last_name, 1, 1), '.'),
                   CONCAT(ut.first_name, ' ', SUBSTRING(ut.last_name, 1, 1), '.'),
                   q.full_name,
                   'Patient'
               ) AS display_name
        FROM queue_numbers q
        LEFT JOIN students s ON s.id = q.student_id
        LEFT JOIN users us ON us.id = s.user_id
        LEFT JOIN teachers t ON t.id = q.teacher_id
        LEFT JOIN users ut ON ut.id = t.user_id
        WHERE q.created_at >= CURDATE() AND q.created_at < CURDATE() + INTERVAL 1 DAY
          AND q.status IN ('called', 'serving')
        ORDER BY 
            FIELD(q.status, 'called', 'serving'),
            FIELD(q.priority, 'emergency', 'urgent', 'normal'),
            COALESCE(q.called_at, q.served_at) DESC
        LIMIT 1
    ");
    $nowServing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Next upcoming
    $nextStmt = $db->query("
        SELECT q.id, q.queue_number, q.status, q.patient_type, q.priority
        FROM queue_numbers q
        WHERE q.created_at >= CURDATE() AND q.created_at < CURDATE() + INTERVAL 1 DAY
          AND q.status = 'waiting'
        ORDER BY
            FIELD(q.priority, 'emergency', 'urgent', 'normal'),
            q.created_at ASC
        LIMIT 5
    ");
    $nextInLine = $nextStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Consolidated counts (single indexed range query)
    $countsStmt = $db->query("
        SELECT 
            SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) AS total_waiting,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS total_completed
        FROM queue_numbers
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
    ");
    $countsRow = $countsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalWaiting = (int)($countsRow['total_waiting'] ?? 0);
    $totalCompleted = (int)($countsRow['total_completed'] ?? 0);

    // Check if the current logged-in user has an active ticket
    $myTicket = null;
    if ($user) {
        $patientId = null;
        $patientType = 'student';
        if ($user['role'] === 'student') {
            $st = $db->prepare("SELECT id FROM students WHERE user_id = ?");
            $st->execute([$user['id']]);
            $patientId = $st->fetchColumn();
            $patientType = 'student';
        } elseif ($user['role'] === 'teacher') {
            $tt = $db->prepare("SELECT id FROM teachers WHERE user_id = ?");
            $tt->execute([$user['id']]);
            $patientId = $tt->fetchColumn();
            $patientType = 'teacher';
        }

        if ($patientId) {
            $field = ($patientType === 'teacher') ? 'teacher_id' : 'student_id';
            $tStmt = $db->prepare("
                SELECT q.*
                FROM queue_numbers q
                WHERE q.{$field} = ?
                  AND q.created_at >= CURDATE() AND q.created_at < CURDATE() + INTERVAL 1 DAY
                  AND q.status IN ('waiting', 'called', 'serving', 'skipped')
                ORDER BY q.id DESC
                LIMIT 1
            ");
            $tStmt->execute([$patientId]);
            $ticket = $tStmt->fetch(PDO::FETCH_ASSOC);

            if ($ticket) {
                // Calculate patients ahead
                $aheadStmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM queue_numbers
                    WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
                      AND status = 'waiting'
                      AND (
                          FIELD(priority, 'emergency', 'urgent', 'normal') < FIELD(?, 'emergency', 'urgent', 'normal')
                          OR (priority = ? AND created_at < ?)
                      )
                ");
                $aheadStmt->execute([$ticket['priority'], $ticket['priority'], $ticket['created_at']]);
                $patientsAhead = (int)$aheadStmt->fetchColumn();

                $ticket['patients_ahead'] = $patientsAhead;
                $ticket['estimated_wait_minutes'] = calculate_queue_wait_time($db, $patientsAhead);
                $myTicket = $ticket;
            }
        }
    }

    q_json(true, [
        'now_serving'     => $nowServing,
        'next_in_line'    => $nextInLine,
        'total_waiting'   => $totalWaiting,
        'total_completed' => $totalCompleted,
        'my_ticket'       => $myTicket,
    ]);
}

// =====================================================================
// ACTION: checkin (Self-Checkin or Nurse Desk Checkin)
// =====================================================================
if ($action === 'checkin') {
    if (!$user) {
        q_json(false, [], 'Authentication required to check in.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') q_json(false, [], 'Use POST to join the queue.');
    csrf_verify_or_die();

    // Rate-limiting / Cooldown
    $patientType = $_POST['patient_type'] ?? ($user['role'] === 'teacher' ? 'teacher' : 'student');
    $priority = in_array($_POST['priority'] ?? '', ['normal', 'urgent', 'emergency'], true) ? $_POST['priority'] : 'normal';
    $visitType = in_array($_POST['visit_type'] ?? '', ['walk-in', 'appointment', 'emergency'], true) ? $_POST['visit_type'] : 'walk-in';
    $complaint = trim($_POST['chief_complaint'] ?? '');

    $studentId = null;
    $teacherId = null;
    $fullName = null;

    if ($user['role'] === 'student') {
        $st = $db->prepare("SELECT id FROM students WHERE user_id = ?");
        $st->execute([$user['id']]);
        $studentId = $st->fetchColumn();
        if (!$studentId) q_json(false, [], 'Student profile not found.');
        $patientType = 'student';
    } elseif ($user['role'] === 'teacher') {
        $tt = $db->prepare("SELECT id FROM teachers WHERE user_id = ?");
        $tt->execute([$user['id']]);
        $teacherId = $tt->fetchColumn();
        if (!$teacherId) q_json(false, [], 'Teacher profile not found.');
        $patientType = 'teacher';
    } elseif ($user['role'] === 'nurse' || $user['role'] === 'admin') {
        // Nurse manual check-in
        if (!empty($_POST['student_id'])) {
            $studentId = (int)$_POST['student_id'];
            $patientType = 'student';
        } elseif (!empty($_POST['teacher_id'])) {
            $teacherId = (int)$_POST['teacher_id'];
            $patientType = 'teacher';
        } else {
            $fullName = trim($_POST['full_name'] ?? 'Walk-in Patient');
        }
    }
    if(in_array($user['role'],['student','teacher'],true)&&mb_strlen($complaint)<3)q_json(false,[],'Please describe the purpose of your clinic visit.');

    $identityId=$teacherId?:$studentId;
    $lockName=$identityId?'bpc_queue_'.$patientType.'_'.(int)$identityId:null;
    if($lockName){$lockStmt=$db->prepare('SELECT GET_LOCK(?,5)');$lockStmt->execute([$lockName]);if((int)$lockStmt->fetchColumn()!==1)q_json(false,[],'Queue check-in is busy. Please try again.');}

    // 1. Duplicate check-in prevention
    if ($studentId) {
        $existing = get_active_patient_queue_ticket($db, $studentId, 'student');
        if ($existing) {
            if($lockName)$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            q_json(true, [
                'queue_number' => $existing['queue_number'],
                'queue_id'     => $existing['id'],
                'status'       => $existing['status'],
                'is_existing'  => true,
            ], 'You already have an active queue ticket today: ' . $existing['queue_number']);
        }
    } elseif ($teacherId) {
        $existing = get_active_patient_queue_ticket($db, $teacherId, 'teacher');
        if ($existing) {
            if($lockName)$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            q_json(true, [
                'queue_number' => $existing['queue_number'],
                'queue_id'     => $existing['id'],
                'status'       => $existing['status'],
                'is_existing'  => true,
            ], 'You already have an active queue ticket today: ' . $existing['queue_number']);
        }
    }

    // 2. Generate new ticket
    $queueNumber = generate_queue_number($db);
    $waitingCount = (int)$db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'waiting'")->fetchColumn();
    $estimatedWait = calculate_queue_wait_time($db, $waitingCount);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO queue_numbers (
                queue_number, student_id, teacher_id, patient_type, full_name,
                visit_type, priority, status, estimated_wait_minutes, source, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'waiting', ?, 'walkin_module', NOW())
        ");
        $stmt->execute([
            $queueNumber,
            $studentId,
            $teacherId,
            $patientType,
            $fullName,
            $visitType,
            $priority,
            $estimatedWait
        ]);
        $queueId = (int)$db->lastInsertId();

        $patientDisplayName=$fullName?:trim(($user['first_name']??'').' '.($user['last_name']??''));
        $visitNumber='BPC-CL-'.date('Ymd').'-'.str_pad((string)$queueId,4,'0',STR_PAD_LEFT);
        $visitStmt=$db->prepare("INSERT INTO walk_in_visits(queue_id,student_id,is_temp_visitor,temp_full_name,visit_number,visit_date,arrival_time,visit_type,chief_complaint,priority_level,status) VALUES(?,?,?, ?,?,CURDATE(),NOW(),'walk-in',?,?,'waiting')");
        $visitStmt->execute([$queueId,$studentId,$studentId?0:1,$studentId?null:$patientDisplayName,$visitNumber,$complaint,$priority]);

        if ($studentId) {
            $db->prepare("UPDATE students SET last_checkin_at = NOW() WHERE id = ?")->execute([$studentId]);
        }

        // Notify nurses
        $nurses = $db->query("SELECT user_id FROM nurses")->fetchAll();
        foreach ($nurses as $nurse) {
            push_notification(
                (int)$nurse['user_id'],
                'clinic',
                'New Patient Queued: ' . $queueNumber,
                "{$patientDisplayName} joined the queue ({$priority} priority).",
                APP_URL . '/pages/nurse/queue.php',
                null,
                $priority === 'emergency' ? 'urgent' : 'medium'
            );
        }

        log_activity($user['id'], 'Queue Check-in', 'queue', "Ticket #{$queueNumber} issued for {$patientDisplayName}");
        $db->commit();
        if($lockName)$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);

        q_json(true, [
            'queue_id'               => $queueId,
            'queue_number'           => $queueNumber,
            'status'                 => 'waiting',
            'estimated_wait_minutes' => $estimatedWait,
            'patients_ahead'         => $waitingCount,
            'is_existing'            => false,
        ], "Check-in successful! Your queue number is {$queueNumber}.");
    } catch (Exception $e) {
        if($db->inTransaction())$db->rollBack();
        if($lockName)$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        q_json(false, [], safe_api_error($e, 'Check-in failed. Please try again.', 'queue/checkin'));
    }
}

// =====================================================================
// NURSE-ONLY ACTIONS (call_next, recall, start_serving, complete, skip, cancel, queue_list)
// =====================================================================
require_login();
if($user['role']==='admin'&&!is_primary_admin($user))admin_access_denied('Clinical queue controls are restricted to authorized clinic staff.');
if (!in_array($user['role'], ['nurse', 'admin'], true)) {
    q_json(false, [], 'Access denied. Nurse or Administrator privileges required.');
}

require_once __DIR__ . '/../includes/queue_consultation.php';
if (in_array($action, ['start_serving', 'save_consultation_draft', 'complete_consultation_full', 'get_patient_medical_summary'], true) && $user['role'] !== 'nurse') {
    q_json(false, [], 'Only nurses can access consultations.');
}

// =====================================================================
// ACTION: queue_list (Nurse Queue Console Table / Filter)
// =====================================================================
if ($action === 'queue_list') {
    $statusFilter = $_GET['status'] ?? '';
    $search = trim($_GET['search'] ?? '');

    $where = "WHERE q.created_at >= CURDATE() AND q.created_at < CURDATE() + INTERVAL 1 DAY";
    $params = [];

    if ($statusFilter && in_array($statusFilter, ['waiting', 'called', 'serving', 'completed', 'skipped', 'cancelled'], true)) {
        $where .= " AND q.status = ?";
        $params[] = $statusFilter;
    }
    if ($search !== '') {
        $where .= " AND (q.queue_number LIKE ? OR us.first_name LIKE ? OR us.last_name LIKE ? OR ut.first_name LIKE ? OR ut.last_name LIKE ? OR s.student_number LIKE ? OR q.full_name LIKE ?)";
        $like = "%{$search}%";
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
    }

    $stmt = $db->prepare("
        SELECT q.id, q.queue_number, q.student_id, q.teacher_id, CASE WHEN q.student_id IS NULL AND q.teacher_id IS NULL THEN 'guest' ELSE q.patient_type END AS patient_type, q.full_name,
               q.visit_type, q.priority, q.status, q.estimated_wait_minutes, q.called_at, q.served_at, q.completed_at, q.created_at,
               COALESCE(CONCAT(us.first_name, ' ', us.last_name), CONCAT(ut.first_name, ' ', ut.last_name), q.full_name, 'Guest Patient') AS patient_name,
               COALESCE(us.email, ut.email) AS email,
               COALESCE(us.phone, ut.phone) AS phone,
               COALESCE(us.profile_picture, ut.profile_picture) AS profile_picture,
               COALESCE(us.gender, ut.gender) AS gender,
               s.student_number, s.course, s.year_level, s.section,
               t.employee_id, t.department,
               TIMESTAMPDIFF(MINUTE, q.created_at, NOW()) AS wait_minutes,
               w.id AS walkin_visit_id, w.chief_complaint, w.diagnosis
        FROM queue_numbers q
        LEFT JOIN students s ON s.id = q.student_id
        LEFT JOIN users us ON us.id = s.user_id
        LEFT JOIN teachers t ON t.id = q.teacher_id
        LEFT JOIN users ut ON ut.id = t.user_id
        LEFT JOIN walk_in_visits w ON w.queue_id = q.id
        {$where}
        ORDER BY
            FIELD(q.status, 'called', 'serving', 'waiting', 'skipped', 'completed', 'cancelled'),
            FIELD(q.priority, 'emergency', 'urgent', 'normal'),
            q.created_at ASC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Single consolidated count query using index
    $countsStmt = $db->query("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) AS waiting,
            SUM(CASE WHEN status = 'called' THEN 1 ELSE 0 END) AS called,
            SUM(CASE WHEN status = 'serving' THEN 1 ELSE 0 END) AS serving,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped
        FROM queue_numbers
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
    ");
    $countsRow = $countsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $counts = [
        'waiting'   => (int)($countsRow['waiting'] ?? 0),
        'called'    => (int)($countsRow['called'] ?? 0),
        'serving'   => (int)($countsRow['serving'] ?? 0),
        'completed' => (int)($countsRow['completed'] ?? 0),
        'skipped'   => (int)($countsRow['skipped'] ?? 0),
        'total'     => (int)($countsRow['total'] ?? 0),
    ];

    q_json(true, ['items' => $rows, 'counts' => $counts]);
}

// =====================================================================
// ACTION: call_next (Calls the next patient in line)
// =====================================================================
if ($action === 'call_next') {
    csrf_verify_or_die();
    $ticketId = !empty($_POST['ticket_id']) ? (int)$_POST['ticket_id'] : null;

    $db->beginTransaction();
    try {
        // If specific ticket given, fetch it; otherwise pick next waiting ticket
        if ($ticketId) {
            $stmt = $db->prepare("SELECT * FROM queue_numbers WHERE id = ? AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY FOR UPDATE");
            $stmt->execute([$ticketId]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->query("
                SELECT * FROM queue_numbers
                WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
                  AND status = 'waiting'
                ORDER BY
                    FIELD(priority, 'emergency', 'urgent', 'normal'),
                    created_at ASC
                LIMIT 1
                FOR UPDATE
            ");
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$ticket) {
            $db->rollBack();
            q_json(false, [], 'No patients are currently waiting in the queue.');
        }

        // Update ticket to 'called'
        $updateStmt = $db->prepare("
            UPDATE queue_numbers
            SET status = 'called',
                called_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$ticket['id']]);

        // Send push notification to the patient if linked to a user
        $patientUserId = null;
        if ($ticket['student_id']) {
            $patientUserId = $db->query("SELECT user_id FROM students WHERE id = " . (int)$ticket['student_id'])->fetchColumn();
        } elseif ($ticket['teacher_id']) {
            $patientUserId = $db->query("SELECT user_id FROM teachers WHERE id = " . (int)$ticket['teacher_id'])->fetchColumn();
        }

        if ($patientUserId) {
            push_notification(
                (int)$patientUserId,
                'clinic',
                'Now Serving: ' . $ticket['queue_number'],
                'Your queue number ' . $ticket['queue_number'] . ' is now being called. Please proceed to the Clinic Nurse Station.',
                APP_URL . '/pages/' . ($ticket['patient_type'] === 'teacher' ? 'adviser' : 'student') . '/queue.php',
                null,
                'urgent'
            );
        }

        log_activity($user['id'], 'Called Queue Ticket', 'queue', "Called ticket {$ticket['queue_number']}");
        $db->commit();

        q_json(true, [
            'ticket_id'    => $ticket['id'],
            'queue_number' => $ticket['queue_number'],
            'patient_type' => $ticket['patient_type'],
            'priority'     => $ticket['priority'],
            'status'       => 'called',
        ], "Now calling ticket {$ticket['queue_number']}!");
    } catch (Exception $e) {
        $db->rollBack();
        q_json(false, [], safe_api_error($e, 'Failed to call the next patient. Please try again.', 'queue/call_next'));
    }
}

// =====================================================================
// ACTION: recall (Re-announce current or specific ticket)
// =====================================================================
if ($action === 'recall') {
    csrf_verify_or_die();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);

    if ($ticketId <= 0) {
        // Find latest called or serving ticket
        $ticketId = (int)$db->query("
            SELECT id FROM queue_numbers
            WHERE DATE(created_at) = CURDATE() AND status IN ('called', 'serving')
            ORDER BY COALESCE(called_at, served_at) DESC
            LIMIT 1
        ")->fetchColumn();
    }

    if (!$ticketId) {
        q_json(false, [], 'No active ticket to recall.');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM queue_numbers WHERE id = ? FOR UPDATE");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            $db->rollBack();
            q_json(false, [], 'Ticket not found.');
        }

        $db->prepare("UPDATE queue_numbers SET called_at = NOW(), status = 'called' WHERE id = ?")->execute([$ticketId]);

        // Push notification again
        $patientUserId = null;
        if ($ticket['student_id']) {
            $patientUserId = $db->query("SELECT user_id FROM students WHERE id = " . (int)$ticket['student_id'])->fetchColumn();
        } elseif ($ticket['teacher_id']) {
            $patientUserId = $db->query("SELECT user_id FROM teachers WHERE id = " . (int)$ticket['teacher_id'])->fetchColumn();
        }

        if ($patientUserId) {
            push_notification(
                (int)$patientUserId,
                'clinic',
                '2nd Notice: ' . $ticket['queue_number'],
                'Second Call: Please proceed immediately to the Clinic Nurse Station.',
                APP_URL . '/pages/' . ($ticket['patient_type'] === 'teacher' ? 'adviser' : 'student') . '/queue.php',
                null,
                'urgent'
            );
        }

        log_activity($user['id'], 'Recalled Queue Ticket', 'queue', "Recalled ticket {$ticket['queue_number']}");
        $db->commit();

        q_json(true, [
            'ticket_id'    => $ticket['id'],
            'queue_number' => $ticket['queue_number'],
            'status'       => 'called',
        ], "Recalled ticket {$ticket['queue_number']}.");
    } catch (Exception $e) {
        $db->rollBack();
        q_json(false, [], safe_api_error($e, 'Failed to recall this ticket. Please try again.', 'queue/recall'));
    }
}

// =====================================================================
// ACTION: start_serving (Begin consultation)
// =====================================================================
if ($action === 'start_serving') {
    csrf_verify_or_die();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) q_json(false, [], 'Invalid ticket ID.');

    $db->beginTransaction();
    try {
        $ticket = queue_consultation_lock($db, $ticketId);
        $existingStmt = $db->prepare('SELECT * FROM walk_in_visits WHERE queue_id = ? LIMIT 1 FOR UPDATE');
        $existingStmt->execute([$ticketId]);
        $existingVisit = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingVisit) {
            if (isset($_POST['draft_version']) && $_POST['draft_version'] === '') throw new DomainException('Another nurse has opened this consultation. Reopen the patient to review the saved record.');
            queue_consultation_check_version($existingVisit);
        }
        $visit = queue_consultation_visit($db, $ticket, (int)$user['id']);
        $db->prepare("
            UPDATE queue_numbers
            SET status = 'serving',
                served_at = CASE WHEN served_at IS NULL THEN NOW() ELSE served_at END
            WHERE id = ?
        ")->execute([$ticketId]);

        // If walk-in visit exists, set in_consultation
        $db->prepare("
            UPDATE walk_in_visits
            SET status = 'in_consultation',
                consultation_started_at = CASE WHEN consultation_started_at IS NULL THEN NOW() ELSE consultation_started_at END
            WHERE queue_id = ?
        ")->execute([$ticketId]);

        $revisionStmt = $db->prepare('SELECT * FROM walk_in_visits WHERE id = ?');
        $revisionStmt->execute([$visit['id']]);
        $revision = queue_consultation_version($revisionStmt->fetch(PDO::FETCH_ASSOC));
        log_activity($user['id'], 'Started Consultation', 'queue', "Started serving ticket ID #{$ticketId}");
        $db->commit();

        q_json(true, ['ticket_id' => $ticketId, 'status' => 'serving', 'walk_in_id' => $visit['id'], 'draft_version' => $revision], 'Patient is now in consultation.');
    } catch (Throwable $e) {
        $db->rollBack();
        q_json(false, [], $e instanceof DomainException ? $e->getMessage() : safe_api_error($e, 'Failed to update the queue status. Please try again.', 'queue/update_status'));
    }
}

if ($action === 'save_consultation_draft') {
    csrf_verify_or_die();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    try {
        $db->beginTransaction();
        $pendingMedicines = json_decode($_POST['medicines'] ?? '[]', true);
        if (!is_array($pendingMedicines) || !array_is_list($pendingMedicines)) throw new DomainException('Invalid medication draft.');
        foreach ($pendingMedicines as $medicine) {
            if (!is_array($medicine) || !filter_var($medicine['medicine_id'] ?? 0, FILTER_VALIDATE_INT) || (int)$medicine['medicine_id'] <= 0 || !filter_var($medicine['quantity'] ?? 0, FILTER_VALIDATE_INT) || (int)$medicine['quantity'] <= 0) throw new DomainException('Select a valid medicine and a positive whole-number quantity.');
        }
        $ticket = queue_consultation_lock($db, $ticketId);
        $visit = queue_consultation_visit($db, $ticket, (int)$user['id']);
        queue_consultation_check_version($visit);
        queue_consultation_save($db, (int)$visit['id'], $_POST);
        $db->prepare("UPDATE queue_numbers SET status = 'serving', served_at = COALESCE(served_at, NOW()) WHERE id = ?")->execute([$ticketId]);
        $db->prepare("UPDATE walk_in_visits SET status = 'in_consultation', consultation_started_at = COALESCE(consultation_started_at, NOW()) WHERE id = ?")->execute([$visit['id']]);
        $stmt = $db->prepare('SELECT * FROM walk_in_visits WHERE id = ?');
        $stmt->execute([$visit['id']]);
        $saved = $stmt->fetch(PDO::FETCH_ASSOC);
        log_activity($user['id'], 'Saved Consultation Draft', 'walkin', 'Visit #' . $visit['visit_number']);
        $db->prepare("INSERT INTO visit_logs (walk_in_id, action, details, performed_by) VALUES (?, 'consultation_updated', 'Saved from Clinic Queue', ?)")->execute([$visit['id'], $user['id']]);
        $db->commit();
        // Pending dispensing/certificate choices are drafts, never stock deductions.
        $_SESSION['queue_consultation_drafts'][$ticketId] = [
            'medicines' => $pendingMedicines,
            'issue_certificate' => !empty($_POST['issue_certificate']),
            'certificate_type' => $_POST['certificate_type'] ?? 'Clinic Visit Pass',
            'certificate_purpose' => $_POST['certificate_purpose'] ?? '',
        ];
        q_json(true, ['walk_in_id' => $visit['id'], 'draft_version' => queue_consultation_version($saved)], 'Draft saved.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        q_json(false, [], $e instanceof DomainException ? $e->getMessage() : safe_api_error($e, 'Unable to save draft.', 'queue/draft'));
    }
}

// =====================================================================
// ACTION: skip_ticket (Mark as skipped / pass to next)
// =====================================================================
if ($action === 'skip_ticket') {
    csrf_verify_or_die();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) q_json(false, [], 'Invalid ticket ID.');

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE queue_numbers SET status = 'skipped' WHERE id = ?")->execute([$ticketId]);
        log_activity($user['id'], 'Skipped Queue Ticket', 'queue', "Skipped ticket ID #{$ticketId}");
        $db->commit();

        q_json(true, ['ticket_id' => $ticketId, 'status' => 'skipped'], 'Ticket has been marked as skipped.');
    } catch (Exception $e) {
        $db->rollBack();
        q_json(false, [], safe_api_error($e, 'Failed to skip this ticket. Please try again.', 'queue/skip'));
    }
}

// =====================================================================
// ACTION: complete_ticket (Finish visit from queue view)
// =====================================================================
if ($action === 'complete_ticket') {
    csrf_verify_or_die();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) q_json(false, [], 'Invalid ticket ID.');

    $db->beginTransaction();
    try {
        $db->prepare("
            UPDATE queue_numbers
            SET status = 'completed',
                served_at = CASE WHEN served_at IS NULL THEN NOW() ELSE served_at END,
                completed_at = NOW()
            WHERE id = ?
        ")->execute([$ticketId]);

        // If walk-in visit exists, also mark completed
        $db->prepare("
            UPDATE walk_in_visits
            SET status = 'completed',
                consultation_ended_at = NOW()
            WHERE queue_id = ?
        ")->execute([$ticketId]);

        log_activity($user['id'], 'Completed Queue Ticket', 'queue', "Completed ticket ID #{$ticketId}");
        $db->commit();

        q_json(true, ['ticket_id' => $ticketId, 'status' => 'completed'], 'Ticket completed successfully.');
    } catch (Exception $e) {
        $db->rollBack();
        q_json(false, [], safe_api_error($e, 'Failed to complete this ticket. Please try again.', 'queue/complete'));
    }
}

// =====================================================================
// ACTION: cancel_ticket
// =====================================================================
if ($action === 'cancel_ticket') {
    csrf_verify_or_die();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) q_json(false, [], 'Invalid ticket ID.');

    $db->beginTransaction();
    try {
        queue_consultation_lock($db, $ticketId);
        $db->prepare("UPDATE queue_numbers SET status = 'cancelled' WHERE id = ?")->execute([$ticketId]);
        $db->prepare("UPDATE walk_in_visits SET status = 'cancelled' WHERE queue_id = ?")->execute([$ticketId]);
        log_activity($user['id'], 'Cancelled Queue Ticket', 'queue', "Cancelled ticket ID #{$ticketId}");
        $db->commit();

        q_json(true, ['ticket_id' => $ticketId, 'status' => 'cancelled'], 'Ticket cancelled.');
    } catch (Throwable $e) {
        $db->rollBack();
        q_json(false, [], $e instanceof DomainException ? $e->getMessage() : safe_api_error($e, 'Failed to cancel this ticket. Please try again.', 'queue/cancel'));
    }
}

// =====================================================================
// ACTION: get_patient_medical_summary (Context for Consultation Workspace)
// =====================================================================
if ($action === 'get_patient_medical_summary') {
    $ticketId = filter_var($_GET['ticket_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$ticketId || $ticketId <= 0) {
        q_json(false, [], 'Invalid or missing ticket ID.');
    }

    try {
        $stmt = $db->prepare("
            SELECT q.id, q.queue_number, q.student_id, q.teacher_id, CASE WHEN q.student_id IS NULL AND q.teacher_id IS NULL THEN 'guest' ELSE q.patient_type END AS patient_type, q.full_name,
                   q.visit_type, q.priority, q.status, q.estimated_wait_minutes, q.called_at, q.served_at, q.completed_at, q.created_at,
                   COALESCE(CONCAT(us.first_name, ' ', us.last_name), CONCAT(ut.first_name, ' ', ut.last_name), q.full_name, 'Guest Patient') AS patient_name,
                   COALESCE(us.id, ut.id) AS user_id,
                   COALESCE(us.email, ut.email) AS email,
                   COALESCE(us.phone, ut.phone) AS phone,
                   COALESCE(us.profile_picture, ut.profile_picture) AS profile_picture,
                   COALESCE(us.gender, ut.gender, s.gender) AS gender,
                   COALESCE(us.date_of_birth, ut.date_of_birth, s.birthdate) AS birthdate,
                   TIMESTAMPDIFF(YEAR, COALESCE(us.date_of_birth, ut.date_of_birth, s.birthdate), CURDATE()) AS age,
                   s.id AS student_id_record, s.student_number, s.course, s.year_level, s.section, COALESCE(s.blood_type, t.blood_type) AS blood_type, COALESCE(s.allergies, t.allergies) AS allergies,
                   t.id AS teacher_id_record, t.employee_id, t.department,
                   w.id AS walkin_id, w.chief_complaint AS initial_complaint, w.priority_level
            FROM queue_numbers q
            LEFT JOIN students s ON s.id = q.student_id
            LEFT JOIN users us ON us.id = s.user_id
            LEFT JOIN teachers t ON t.id = q.teacher_id
            LEFT JOIN users ut ON ut.id = t.user_id
            LEFT JOIN walk_in_visits w ON w.queue_id = q.id
            WHERE q.id = ?
            LIMIT 1
        ");
        $stmt->execute([$ticketId]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$patient) {
            q_json(false, [], 'Queue ticket not found.');
        }

        $medicalRecord = [
            'blood_type' => $patient['blood_type'] ?? null,
            'allergies'  => $patient['allergies'] ?? null,
        ];

        $recentVisits = [];
        $latestVitals = null;

        if (!empty($patient['student_id_record'])) {
            $sid = (int)$patient['student_id_record'];

            // Recent consultations
            $cStmt = $db->prepare("
                SELECT c.id, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment,
                       COALESCE(CONCAT(un.first_name, ' ', un.last_name), CONCAT(u_direct.first_name, ' ', u_direct.last_name), 'Clinic Nurse') AS nurse_name
                FROM consultations c
                LEFT JOIN nurses n ON n.id = c.nurse_id
                LEFT JOIN users un ON un.id = n.user_id
                LEFT JOIN users u_direct ON u_direct.id = c.nurse_id
                WHERE c.student_id = ?
                ORDER BY c.consultation_date DESC
                LIMIT 5
            ");
            $cStmt->execute([$sid]);
            $recentVisits = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Latest vitals
            $vStmt = $db->prepare("SELECT * FROM health_monitoring WHERE student_id = ? ORDER BY recorded_at DESC LIMIT 1");
            $vStmt->execute([$sid]);
            $latestVitals = $vStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif (!empty($patient['teacher_id_record'])) {
            $tid=(int)$patient['teacher_id_record'];
            $cStmt=$db->prepare("SELECT c.id,c.consultation_date,c.chief_complaint,c.diagnosis,c.treatment,c.vital_signs,COALESCE(CONCAT(un.first_name,' ',un.last_name),'Clinic Nurse') nurse_name FROM consultations c LEFT JOIN nurses n ON n.id=c.nurse_id LEFT JOIN users un ON un.id=n.user_id WHERE c.teacher_id=? AND c.patient_type='teacher' ORDER BY c.consultation_date DESC LIMIT 5");
            $cStmt->execute([$tid]);$recentVisits=$cStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
            if($recentVisits&&!empty($recentVisits[0]['vital_signs']))$latestVitals=json_decode($recentVisits[0]['vital_signs'],true)?:null;
        }

        // Available inventory for dispensing
        $medsStmt = $db->query("
            SELECT id, medicine_name, category, quantity, unit, reorder_level, expiration_date
            FROM medicines
            WHERE is_archived = 0 AND quantity > 0 AND (expiration_date IS NULL OR expiration_date >= CURDATE())
            ORDER BY medicine_name ASC
        ");
        $medicines = $medsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $visitStmt = $db->prepare('SELECT * FROM walk_in_visits WHERE queue_id = ? LIMIT 1');
        $visitStmt->execute([$ticketId]);
        $visit = $visitStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $dispensing = [];
        if ($visit) {
            $dispStmt = $db->prepare('SELECT md.quantity, md.dosage_instructions, md.dispensed_at, m.medicine_name FROM medicine_dispensing md JOIN medicines m ON m.id = md.medicine_id WHERE md.walk_in_id = ? ORDER BY md.dispensed_at');
            $dispStmt->execute([$visit['id']]);
            $dispensing = $dispStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        q_json(true, [
            'patient'        => $patient,
            'medical_record' => $medicalRecord,
            'recent_visits'  => $recentVisits,
            'visit' => $visit,
            'draft_version' => queue_consultation_version($visit),
            'draft_options' => $_SESSION['queue_consultation_drafts'][$ticketId] ?? [],
            'dispensing' => $dispensing,
            'latest_vitals'  => $latestVitals,
            'medicines'      => $medicines,
        ]);
    } catch (Throwable $e) {
        error_log('get_patient_medical_summary error: ' . $e->getMessage());
        q_json(false, [], 'Unable to retrieve patient medical summary.');
    }
}

// =====================================================================
// ACTION: complete_consultation_full (Unified Atomic Post-Consultation Workflow)
// =====================================================================
if ($action === 'complete_consultation_full') {
    csrf_verify_or_die();
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId <= 0) q_json(false, [], 'Invalid ticket ID.');

    // Fetch ticket details
    $stmt = $db->prepare("SELECT * FROM queue_numbers WHERE id = ?");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) q_json(false, [], 'Queue ticket not found.');

    $nurseStmt = $db->prepare("SELECT id FROM nurses WHERE user_id = ?");
    $nurseStmt->execute([$user['id']]);
    $nurseId = $nurseStmt->fetchColumn() ?: null;

    $chiefComplaint = trim($_POST['chief_complaint'] ?? '');
    if (!$chiefComplaint) q_json(false, [], 'Chief complaint is required.');

    $symptoms = trim($_POST['symptoms'] ?? '');
    $assessment = trim($_POST['assessment'] ?? '');
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $treatment = trim($_POST['treatment'] ?? '');
    $recommendation = trim($_POST['recommendation'] ?? '');
    if ($assessment === '' || $diagnosis === '' || $treatment === '') q_json(false, [], 'Assessment, diagnosis, and treatment are required before completing a consultation.');
    $needFollowUp = !empty($_POST['need_follow_up']) ? 1 : 0;
    $followUpDate = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;

    // Vitals
    $temperature = !empty($_POST['temperature']) ? (float)$_POST['temperature'] : null;
    $bpSystolic = !empty($_POST['blood_pressure_systolic']) ? (int)$_POST['blood_pressure_systolic'] : null;
    $bpDiastolic = !empty($_POST['blood_pressure_diastolic']) ? (int)$_POST['blood_pressure_diastolic'] : null;
    $heartRate = !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null;
    $respRate = !empty($_POST['respiratory_rate']) ? (int)$_POST['respiratory_rate'] : null;
    $o2Sat = !empty($_POST['oxygen_saturation']) ? (float)$_POST['oxygen_saturation'] : null;
    $heightCm = !empty($_POST['height_cm']) ? (float)$_POST['height_cm'] : null;
    $weightKg = !empty($_POST['weight_kg']) ? (float)$_POST['weight_kg'] : null;

    // BMI calculation
    $bmi = null;
    $bmiStatus = null;
    if ($heightCm && $weightKg && $heightCm > 0) {
        $hM = $heightCm / 100;
        $bmi = round($weightKg / ($hM * $hM), 2);
        if ($bmi < 18.5) $bmiStatus = 'Underweight';
        elseif ($bmi < 25) $bmiStatus = 'Normal';
        elseif ($bmi < 30) $bmiStatus = 'Overweight';
        else $bmiStatus = 'Obese';
    }

    $bpString = ($bpSystolic && $bpDiastolic) ? "{$bpSystolic}/{$bpDiastolic}" : null;

    // Medicines array
    $rawMeds = $_POST['medicines'] ?? [];
    if (is_string($rawMeds)) {
        $medicinesList = json_decode($rawMeds, true) ?: [];
    } else {
        $medicinesList = is_array($rawMeds) ? $rawMeds : [];
    }

    if ((is_string($rawMeds) && json_last_error() !== JSON_ERROR_NONE) || !is_array($medicinesList) || !array_is_list($medicinesList)) {
        q_json(false, [], 'Invalid medication list. Review the selected medicines.');
    }
    foreach ($medicinesList as $medItem) {
        if (!is_array($medItem) || !filter_var($medItem['medicine_id'] ?? 0, FILTER_VALIDATE_INT) || (int)$medItem['medicine_id'] <= 0 || !filter_var($medItem['quantity'] ?? 0, FILTER_VALIDATE_INT) || (int)$medItem['quantity'] <= 0) {
            q_json(false, [], 'Select a valid medicine and a positive whole-number quantity.');
        }
    }

    $db->beginTransaction();
    try {
        $ticket = queue_consultation_lock($db, $ticketId);
        $lockedVisit = queue_consultation_visit($db, $ticket, (int)$user['id']);
        queue_consultation_check_version($lockedVisit);
        queue_consultation_save($db, (int)$lockedVisit['id'], $_POST);
        // 1. Create or Update walk_in_visits
        $wStmt = $db->prepare("SELECT id FROM walk_in_visits WHERE queue_id = ? LIMIT 1");
        $wStmt->execute([$ticketId]);
        $walkInId = $wStmt->fetchColumn();

        if ($walkInId) {
            $updateWalkin = $db->prepare("
                UPDATE walk_in_visits SET
                    chief_complaint = ?, priority_level = ?,
                    temperature = ?, blood_pressure_systolic = ?, blood_pressure_diastolic = ?,
                    heart_rate = ?, respiratory_rate = ?, oxygen_saturation = ?,
                    height_cm = ?, weight_kg = ?, bmi = ?, bmi_status = ?,
                    symptoms = ?, assessment = ?, diagnosis = ?, treatment = ?, recommendation = ?,
                    need_follow_up = ?, follow_up_date = ?, attended_by = ?,
                    status = 'completed',
                    consultation_started_at = COALESCE(consultation_started_at, NOW()),
                    consultation_ended_at = NOW()
                WHERE id = ?
            ");
            $updateWalkin->execute([
                $chiefComplaint, $ticket['priority'],
                $temperature, $bpSystolic, $bpDiastolic,
                $heartRate, $respRate, $o2Sat,
                $heightCm, $weightKg, $bmi, $bmiStatus,
                $symptoms, $assessment, $diagnosis, $treatment, $recommendation,
                $needFollowUp, $followUpDate, $user['id'],
                $walkInId
            ]);
        } else {
            $visitNumber = 'BPC-CL-' . date('Ymd') . '-' . str_pad((string)$ticketId, 4, '0', STR_PAD_LEFT);
            $insertWalkin = $db->prepare("
                INSERT INTO walk_in_visits (
                    queue_id, student_id, is_temp_visitor, temp_full_name,
                    visit_number, visit_date, arrival_time, visit_type, chief_complaint, priority_level,
                    temperature, blood_pressure_systolic, blood_pressure_diastolic, heart_rate,
                    respiratory_rate, oxygen_saturation, height_cm, weight_kg, bmi, bmi_status,
                    symptoms, assessment, diagnosis, treatment, recommendation,
                    need_follow_up, follow_up_date, attended_by, status,
                    consultation_started_at, consultation_ended_at
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, CURDATE(), NOW(), 'walk-in', ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, 'completed',
                    NOW(), NOW()
                )
            ");
            $insertWalkin->execute([
                $ticketId, $ticket['student_id'], empty($ticket['student_id']) ? 1 : 0, $ticket['full_name'],
                $visitNumber, $chiefComplaint, $ticket['priority'],
                $temperature, $bpSystolic, $bpDiastolic, $heartRate,
                $respRate, $o2Sat, $heightCm, $weightKg, $bmi, $bmiStatus,
                $symptoms, $assessment, $diagnosis, $treatment, $recommendation,
                $needFollowUp, $followUpDate, $user['id']
            ]);
            $walkInId = (int)$db->lastInsertId();
        }

        // 2. Insert authoritative record into consultations table
        $vitalSigns=json_encode(['temperature'=>$temperature,'blood_pressure'=>$bpString,'heart_rate'=>$heartRate,'respiratory_rate'=>$respRate,'oxygen_saturation'=>$o2Sat,'height_cm'=>$heightCm,'weight_kg'=>$weightKg,'bmi'=>$bmi,'bmi_status'=>$bmiStatus],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $consultStmt = $db->prepare("
            INSERT INTO consultations (
                student_id, nurse_id, visit_type, consultation_date,
                chief_complaint, diagnosis, treatment, remarks, follow_up_date,
                patient_type, teacher_id, vital_signs
            ) VALUES (?, ?, 'walk-in', NOW(), ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $consultStmt->execute([
            $ticket['student_id'], $nurseId,
            $chiefComplaint, $diagnosis, $treatment, $recommendation, $followUpDate,
            $ticket['patient_type'], $ticket['teacher_id'], $vitalSigns
        ]);
        $consultationId = (int)$db->lastInsertId();

        // 3. Record Vitals in health_monitoring if student
        if ($ticket['student_id'] && ($bmi || $temperature || $bpString || $heartRate)) {
            $db->prepare("
                INSERT INTO health_monitoring (
                    student_id, recorded_by, height_cm, weight_kg, bmi, bmi_status,
                    blood_pressure, heart_rate, temperature, oxygen_saturation, recorded_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $ticket['student_id'], $user['id'],
                $heightCm, $weightKg, $bmi, $bmiStatus,
                $bpString, $heartRate, $temperature, $o2Sat
            ]);
        }

        // 4. Dispense medicines & deduct inventory
        $lowStockWarnings = [];
        foreach ($medicinesList as $medItem) {
            $medId = (int)($medItem['medicine_id'] ?? 0);
            $qty = (int)($medItem['quantity'] ?? 0);
            $dosage = trim($medItem['dosage_instructions'] ?? '');

            if ($medId > 0 && $qty > 0) {
                // Check stock
                $mCheck = $db->prepare("SELECT id, medicine_name, quantity, reorder_level FROM medicines WHERE id = ? AND is_archived = 0 AND (expiration_date IS NULL OR expiration_date >= CURDATE()) FOR UPDATE");
                $mCheck->execute([$medId]);
                $medData = $mCheck->fetch(PDO::FETCH_ASSOC);

                if (!$medData || $qty > (int)$medData['quantity']) throw new DomainException('A selected medicine is unavailable or has insufficient usable stock. Review the medication quantities.');
                if ($medData) {
                    $deductQty = $qty;
                    if ($deductQty > 0) {
                        // Deduct stock and update status
                        $db->prepare("UPDATE medicines SET quantity = quantity - ? WHERE id = ?")->execute([$deductQty, $medId]);
                        update_medicine_status($db, $medId);

                        // Record in medicine_dispensing
                        $db->prepare("
                            INSERT INTO medicine_dispensing (walk_in_id, medicine_id, quantity, dosage_instructions, dispensed_by, dispensed_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ")->execute([$walkInId, $medId, $deductQty, $dosage, $user['id']]);

                        // Record in medicine_logs
                        $db->prepare("
                            INSERT INTO medicine_logs (medicine_id, action, quantity, reason, performed_by)
                            VALUES (?, 'stock_out', ?, ?, ?)
                        ")->execute([$medId, $deductQty, "Dispensed in Consultation #{$consultationId}", $user['id']]);

                        // Check low stock alert
                        $remaining = $medData['quantity'] - $deductQty;
                        if ($remaining <= $medData['reorder_level']) {
                            $lowStockWarnings[] = "{$medData['medicine_name']} is now low in stock ({$remaining} left).";
                            try {
                                // Notify clinical nurses and admins
                                $nUsers = $db->query("SELECT id FROM users WHERE role IN ('nurse','admin') AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
                                foreach ($nUsers as $nUid) {
                                    push_notification(
                                        (int)$nUid,
                                        'inventory',
                                        'Low Stock Warning',
                                        "{$medData['medicine_name']} is low in stock ({$remaining} remaining).",
                                        APP_URL . '/pages/nurse/inventory.php?status=low_stock',
                                        null,
                                        'high'
                                    );
                                }
                            } catch (Throwable $ignore) {}
                        }
                    }
                }
            }
        }

        // Link all dispensing, including medicine given before completion, to medical history.
        $db->prepare('INSERT INTO consultation_medicines (consultation_id, medicine_id, quantity, dosage_instructions, dispensed_by)
            SELECT ?, medicine_id, quantity, dosage_instructions, dispensed_by FROM medicine_dispensing WHERE walk_in_id = ?')
            ->execute([$consultationId, $walkInId]);
        $db->prepare("INSERT INTO visit_logs (walk_in_id, action, details, performed_by) VALUES (?, 'completed', 'Completed from Clinic Queue', ?)")->execute([$walkInId, $user['id']]);

        // 5. Generate Certificate if requested
        $certNumber = null;
        if (!empty($_POST['issue_certificate']) && $ticket['student_id']) {
            $certType = trim($_POST['certificate_type'] ?? 'Medical Certificate');
            $certPurpose = trim($_POST['certificate_purpose'] ?? 'Clinic Consultation & Clearance');
            $certNumber = 'CERT-' . date('Ymd') . '-' . str_pad((string)random_int(100, 9999), 4, '0', STR_PAD_LEFT);

            $db->prepare("
                INSERT INTO certificates (
                    certificate_number, student_id, issued_by, certificate_type, purpose, remarks, date_issued, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), NOW())
            ")->execute([
                $certNumber, $ticket['student_id'], $user['id'],
                $certType, $certPurpose, "Diagnosis: {$diagnosis}. Recommendation: {$recommendation}"
            ]);
        }

        // 6. Complete Queue Ticket
        $db->prepare("
            UPDATE queue_numbers
            SET status = 'completed',
                served_at = COALESCE(served_at, NOW()),
                completed_at = NOW()
            WHERE id = ?
        ")->execute([$ticketId]);

        // 7. Push Student Notification
        $patientUserId = null;
        if ($ticket['student_id']) {
            $patientUserId = $db->query("SELECT user_id FROM students WHERE id = " . (int)$ticket['student_id'])->fetchColumn();
        } elseif ($ticket['teacher_id']) {
            $patientUserId = $db->query("SELECT user_id FROM teachers WHERE id = " . (int)$ticket['teacher_id'])->fetchColumn();
        }

        if ($patientUserId) {
            push_notification(
                (int)$patientUserId,
                'clinic',
                'Clinic Visit Completed',
                "Your clinic consultation has been completed. Diagnosis: {$diagnosis}.",
                ($ticket['patient_type'] === 'teacher' ? APP_URL . '/pages/adviser/consultations.php' : APP_URL . '/pages/student/medical_record.php'),
                null,
                'medium'
            );
        }

        // 8. Audit Log
        $patientName = $ticket['full_name'] ?: 'Student';
        if ($ticket['student_id']) {
            $sName = $db->query("SELECT CONCAT(u.first_name, ' ', u.last_name) FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = " . (int)$ticket['student_id'])->fetchColumn();
            if ($sName) $patientName = $sName;
        } elseif ($ticket['teacher_id']) {
            $tName=$db->query("SELECT CONCAT(u.first_name,' ',u.last_name) FROM teachers t JOIN users u ON u.id=t.user_id WHERE t.id=".(int)$ticket['teacher_id'])->fetchColumn();
            if($tName)$patientName=$tName;
        }
        log_activity($user['id'], 'Completed Consultation', 'clinic', "Completed consultation for {$patientName}. Diagnosis: {$diagnosis}");

        $db->commit();
        unset($_SESSION['queue_consultation_drafts'][$ticketId]);

        q_json(true, [
            'ticket_id'         => $ticketId,
            'walk_in_id'        => $walkInId,
            'consultation_id'   => $consultationId,
            'certificate_no'    => $certNumber,
            'low_stock_alerts'  => $lowStockWarnings,
        ], "Consultation completed successfully! Medical records, inventory, and queue updated.");

    } catch (Throwable $e) {
        $db->rollBack();
        q_json(false, [], $e instanceof DomainException ? $e->getMessage() : safe_api_error($e, 'Failed to complete the consultation. Please try again.', 'queue/complete_consultation'));
    }
}

// Fallthrough
q_json(false, [], 'Unknown action: ' . $action);
