<?php
/**
 * api/qr_codes.php
 * Centralized QR Management API — student QR listing, generation/regeneration,
 * deactivation, printing, and scan-log retrieval. Also exposes a JSON endpoint
 * that encodes a student's QR payload for front-end rendering via qrcodejs.
 */

require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json');

// API endpoints must return JSON on auth failure instead of redirecting.
if (!is_logged_in()) {
    http_response_code(401);
    json_response(false, [], 'Unauthorized. Please log in.');
}

$db = getDB();
$user = current_user();
$role = $user['role'] ?? 'student';
$action = $_REQUEST['action'] ?? '';

// Students may only perform the "my_qr" action (their own permanent QR).
// Nurse/Admin have full access to the management actions below.
if ($role === 'student' && !in_array($action, ['my_qr', 'student_health_pass', 'student_qr_timeline', 'student_qr_notifications'])) {
    json_response(false, [], 'Access denied.');
}
if (!in_array($role, ['student', 'nurse', 'admin', 'adviser'], true)) {
    json_response(false, [], 'Access denied.');
}

/**
 * Resolve the student_id for the current user. For students, this is their
 * own student record; for nurse/admin, it comes from the request.
 */
function resolve_student_id($db, &$user, $requestedId = 0) {
    $role = $user['role'] ?? 'student';
    if ($role === 'student') {
        // Best-effort: never throw on a missing/renamed table/column —
        // a failure just yields 0 so callers can produce a safe fallback.
        try {
            $sel = $db->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
            $sel->execute([$user['id']]);
            $sid = (int)$sel->fetchColumn();
            return $sid > 0 ? $sid : 0;
        } catch (Throwable $e) {
            error_log('resolve_student_id failed: ' . $e->getMessage());
            return 0;
        }
    }
    return (int)$requestedId;
}

// ================================================================
// LIST — students with their permanent QR (student_qrs join)
// Nurse/Admin only. Students use my_qr/data for their own QR.
// ================================================================
if ($action === 'list') {
    if ($role !== 'nurse' && $role !== 'admin') {
        json_response(false, [], 'Access denied.');
    }
    $search = trim($_GET['search'] ?? '');
    $status = trim($_GET['status'] ?? '');   // active | deactivated | without
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);

    $where = "1=1";
    $params = [];
    if ($search !== '') {
        $where .= " AND (s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
if (in_array($status, ['active', 'inactive', 'revoked', 'regenerated', 'deactivated'], true)) {
        $where .= " AND q.status = ?";
        $params[] = $status;
    } elseif ($status === 'without') {
        $where .= " AND q.id IS NULL";
    }

    $countStmt = $db->prepare("
        SELECT COUNT(*)
        FROM students s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN student_qrs q ON q.student_id = s.id
        WHERE $where
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT s.id AS student_id, s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name, u.profile_picture,
               q.id AS qr_id, q.qr_token, q.qr_data, q.status AS qr_status,
               q.generated_at, q.last_printed_at,
               gu.first_name AS gen_by_first, gu.last_name AS gen_by_last
        FROM students s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN student_qrs q ON q.student_id = s.id
        LEFT JOIN users gu ON gu.id = q.generated_by
        WHERE $where
        ORDER BY u.last_name ASC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);

    json_response(true, ['data' => $stmt->fetchAll(), 'total' => $total]);
}

// ================================================================
// MY_QR — a student's own permanent QR (view/download/print)
// ================================================================
if ($action === 'my_qr') {
    $studentId = resolve_student_id($db, $user);
    if ($studentId <= 0) json_response(false, [], 'No student record found for your account.');

    $qr = ensure_student_qr($db, $studentId, $user['id']);
    if (!$qr) json_response(false, [], 'Unable to generate your QR code.');

    $stmt = $db->prepare("
        SELECT s.id, s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name, u.email
        FROM students s JOIN users u ON u.id = s.user_id
        WHERE s.id = ?
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    json_response(true, [
        'data' => [
            'qr_data'   => $qr['qr_data'],
            'student'   => $student,
            'status'    => $qr['status'],
            'generated_at' => $qr['generated_at'],
        ],
    ]);
}

// ================================================================
// DATA — return the QR payload for one student (for qrcodejs rendering)
// ================================================================
if ($action === 'data') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    // IDOR protection: students can only access their own QR data
    if ($user['role'] === 'student') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user['id']]);
        $myStudentId = (int)$stmt->fetchColumn();
        if ($myStudentId <= 0 || $studentId !== $myStudentId) {
            json_response(false, [], 'Not authorized to access this QR data.');
        }
    }

    $qr = ensure_student_qr($db, $studentId, $user['id']);
    if (!$qr) json_response(false, [], 'Unable to generate QR for this student.');

    // Also fetch student display info
    $stmt = $db->prepare("
        SELECT s.id, s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name
        FROM students s JOIN users u ON u.id = s.user_id
        WHERE s.id = ?
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();

    json_response(true, [
        'data' => [
            'qr_data'   => $qr['qr_data'],
            'student'   => $student,
            'status'    => $qr['status'],
            'generated_at' => $qr['generated_at'],
        ],
    ]);
}

// ================================================================
// REGENERATE — create a fresh token for a student's QR
// Admin-only per the Single Smart QR System (students can never
// regenerate; nurses may view/print but not regenerate/revoke).
// ================================================================
if ($action === 'regenerate') {
    csrf_verify_or_die();
    if ($role !== 'admin') json_response(false, [], 'Access denied. Admins only.');
    $studentId = (int)($_POST['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $stmt = $db->prepare("SELECT student_number FROM students WHERE id = ?");
    $stmt->execute([$studentId]);
    $sno = $stmt->fetchColumn();
    if ($sno === false) json_response(false, [], 'Student not found.');

    $built = build_student_qr_payload($studentId, $sno);

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO student_qrs (student_id, qr_token, qr_data, status, generated_by)
            VALUES (?, ?, ?, 'active', ?)
            ON DUPLICATE KEY UPDATE
                qr_token = VALUES(qr_token),
                qr_data = VALUES(qr_data),
                status = 'active',
                generated_by = VALUES(generated_by),
                updated_at = NOW()
        ")->execute([$studentId, $built['token'], $built['payload'], $user['id']]);

        // Keep students.qr_code in sync
        $db->prepare("UPDATE students SET qr_code = ? WHERE id = ?")->execute([$built['token'], $studentId]);

        $db->commit();
        log_activity($user['id'], 'Regenerated student QR', 'qr_management', "Student #$studentId");
        json_response(true, ['qr_data' => $built['payload']], 'QR code regenerated. Old QR codes will no longer be valid.');
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, [], safe_api_error($e, 'Failed to regenerate the QR code. Please try again.', 'qr/regenerate'));
    }
}

// ================================================================
// DEACTIVATE / REACTIVATE — toggle QR status
// Nurse + Admin may deactivate/reactivate (regenerate/revoke are
// admin-only per the Single Smart QR System).
// ================================================================
if ($action === 'deactivate' || $action === 'reactivate') {
    csrf_verify_or_die();
    if ($role !== 'nurse' && $role !== 'admin') json_response(false, [], 'Access denied.');
    $studentId = (int)($_POST['student_id'] ?? 0);
    $newStatus = $action === 'deactivate' ? 'deactivated' : 'active';
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $stmt = $db->prepare("UPDATE student_qrs SET status = ?, updated_at = NOW() WHERE student_id = ?");
    $stmt->execute([$newStatus, $studentId]);

    log_activity($user['id'], ucfirst($action) . 'd student QR', 'qr_management', "Student #$studentId");
    json_response(true, [], 'QR ' . ($newStatus === 'active' ? 'reactivated' : 'deactivated') . '.');
}

// ================================================================
// REVOKE — admin-only: set a student's QR status to 'revoked'
// and record the reason in the audit history.
// ================================================================
if ($action === 'revoke') {
    csrf_verify_or_die();
    if ($role !== 'admin') json_response(false, [], 'Access denied. Admins only.');
    $studentId = (int)($_POST['student_id'] ?? 0);
    $reason    = trim($_POST['reason'] ?? '');
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $ok = revoke_student_qr($db, $studentId, $reason ?: 'Revoked by admin', $user['id']);
    if (!$ok) json_response(false, [], 'Student QR not found.');

    json_response(true, [], 'QR code has been revoked. The student will need to contact the clinic for a new QR.');
}

// ================================================================
// MARK PRINTED — update last_printed_at
// ================================================================
if ($action === 'mark_printed') {
    csrf_verify_or_die();
    if ($role !== 'nurse' && $role !== 'admin') json_response(false, [], 'Access denied.');
    $studentId = (int)($_POST['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');
    $db->prepare("UPDATE student_qrs SET last_printed_at = NOW() WHERE student_id = ?")->execute([$studentId]);
    json_response(true);
}

// ================================================================
// SCAN LOGS — list QR check-in activity
// Nurse/Admin only.
// ================================================================
if ($action === 'scan_logs') {
    if ($role !== 'nurse' && $role !== 'admin') {
        json_response(false, [], 'Access denied.');
    }
    $search = trim($_GET['search'] ?? '');
    $limit  = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);

    $where = "1=1";
    $params = [];
    $joins = " LEFT JOIN students s ON s.id = qsl.student_id LEFT JOIN users u ON u.id = s.user_id";
    if ($search !== '') {
        $where .= " AND (s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR qsl.purpose LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }

    // Resilient: if qr_scan_logs (or its columns) are missing, return empty
    // results instead of crashing the Health Pass scan-history table.
    $total = (int)safe_fetch_column($db, "SELECT COUNT(*) FROM qr_scan_logs qsl $joins WHERE $where", $params, 0);

    $listParams = $params;
    $listParams[] = $limit;
    $listParams[] = $offset;
    $rows = safe_fetch_all($db, "
        SELECT qsl.*,
               s.student_number,
               u.first_name, u.last_name,
               qsl.scanner_name
        FROM qr_scan_logs qsl
        LEFT JOIN students s ON s.id = qsl.student_id
        LEFT JOIN users u ON u.id = s.user_id
        WHERE $where
        ORDER BY qsl.scan_time DESC
        LIMIT ? OFFSET ?
    ", $listParams, []);

    json_response(true, ['data' => $rows, 'total' => $total]);
}

// ================================================================
// QR SETTINGS — get all QR-related settings
// ================================================================
if ($action === 'settings') {
    json_response(true, get_all_qr_settings($db));
}

// ================================================================
// SAVE SETTINGS — admin only
// ================================================================
if ($action === 'save_settings') {
    csrf_verify_or_die();
    if ($role !== 'admin') json_response(false, [], 'Access denied. Admins only.');

    $settings = $_POST['settings'] ?? [];
    if (!is_array($settings) || empty($settings)) {
        json_response(false, [], 'No settings provided.');
    }

    try {
        update_qr_settings($db, $settings, $user['id']);
        json_response(true, [], 'QR settings saved successfully.');
    } catch (Exception $e) {
        json_response(false, [], safe_api_error($e, 'Failed to save the QR settings. Please try again.', 'qr/save_settings'));
    }
}

// ================================================================
// ANALYTICS — scan statistics and top purposes
// Nurse/Admin only.
// ================================================================
if ($action === 'analytics') {
    if ($role !== 'nurse' && $role !== 'admin') {
        json_response(false, [], 'Access denied.');
    }
    $days = (int)($_GET['days'] ?? 30);
    if ($days < 1 || $days > 365) $days = 30;

    $sql = "
        SELECT
            DATE(scan_time) AS day,
            COUNT(*) AS total_scans,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successful,
            SUM(CASE WHEN status = 'duplicate' THEN 1 ELSE 0 END) AS duplicates,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
        FROM qr_scan_logs
        WHERE scan_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(scan_time)
        ORDER BY day ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$days]);
    $daily = $stmt->fetchAll();

    $stmt = $db->prepare("
        SELECT purpose, COUNT(*) AS cnt
        FROM qr_scan_logs
        WHERE scan_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY purpose
        ORDER BY cnt DESC
    ");
    $stmt->execute([$days]);
    $byPurpose = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT COUNT(*) FROM qr_scan_logs WHERE scan_time >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
    $totalScans = $stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(DISTINCT student_id) FROM qr_scan_logs WHERE scan_time >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
    $uniqueStudents = $stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM qr_scan_logs WHERE status = 'duplicate' AND scan_time >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);
    $duplicateCount = $stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT u.first_name, u.last_name, s.student_number, COUNT(*) AS scans
        FROM qr_scan_logs qsl
        JOIN students s ON s.id = qsl.student_id
        JOIN users u ON u.id = s.user_id
        WHERE qsl.scan_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY qsl.student_id
        ORDER BY scans DESC
        LIMIT 10
    ");
    $stmt->execute([$days]);
    $topStudents = $stmt->fetchAll();

    json_response(true, [
        'period_days'        => $days,
        'total_scans'        => (int)$totalScans,
        'unique_students'    => (int)$uniqueStudents,
        'duplicate_scans'    => (int)$duplicateCount,
        'daily'              => $daily,
        'by_purpose'         => $byPurpose,
        'top_students'       => $topStudents,
    ]);
}

// ================================================================
// CHECK_IN — process a QR scan and generate queue number
// ================================================================
if ($action === 'check_in') {
    csrf_verify_or_die();
    if ($role !== 'nurse') json_response(false, [], 'Access denied. Nurses only.');
    $qrData = $_POST['qr_data'] ?? '';
    $visitType = trim($_POST['visit_type'] ?? 'walk-in');
    $location = trim($_POST['location'] ?? '');

    if (strlen($qrData) < 10) json_response(false, [], 'Invalid QR data.');

    $result = resolve_patient_qr($db, $qrData);
    if (!$result['valid']) {
        $scanMethod = strtolower(trim($_POST['scan_method'] ?? $_GET['scan_method'] ?? 'unknown'));
        $scanMethod = in_array($scanMethod, ['camera', 'upload', 'walk_in'], true) ? $scanMethod : 'unknown';
        log_qr_scan($db, null, '', 'check_in', $user['id'], 'failed', $location, null, ['reason' => $result['reason']], $scanMethod);
        json_response(false, [], 'QR verification failed: ' . ($result['reason'] ?? 'invalid'));
    }

    $patientType = $result['patient_type'];
    $studentId   = $patientType === 'student' ? (int)$result['id'] : null;
    $teacherId   = $patientType === 'teacher' ? (int)$result['id'] : null;
    $record      = $result['record'];
    $qrToken     = $record['qr_token'] ?? '';
    $scanMethod = strtolower(trim($_POST['scan_method'] ?? $_GET['scan_method'] ?? 'unknown'));
    $scanMethod = in_array($scanMethod, ['camera', 'upload', 'walk_in'], true) ? $scanMethod : 'unknown';

    // ---- Teacher/Staff patient check-in (separate, safe path) ----
    if ($patientType === 'teacher') {
        if (is_duplicate_scan($db, $qrToken, 30)) {
            log_qr_scan($db, null, $qrToken, 'check_in', $user['id'], 'duplicate', $location, null, null, $scanMethod);
            json_response(false, [], 'Duplicate scan detected. Please wait 30 seconds before scanning again.');
        }
        $queueNumber = generate_queue_number($db);
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("
                INSERT INTO queue_numbers (teacher_id, patient_type, queue_number, visit_type, qr_token, created_at)
                VALUES (?, 'teacher', ?, ?, ?, NOW())
            ");
            $stmt->execute([$teacherId, $queueNumber, $visitType, $qrToken]);
            $queueId = $db->lastInsertId();
            log_qr_scan($db, null, $qrToken, 'check_in', $user['id'], 'success', $location, $teacherId, [
                'queue_id' => $queueId, 'queue_number' => $queueNumber, 'visit_type' => $visitType,
            ], $scanMethod);
            $db->commit();

            // Notify the teacher
            if (!empty($record['user_id'])) {
                push_notification($record['user_id'], 'qr_checkin', 'Check-in Successful',
                    'Your QR was scanned at the clinic. Queue number: ' . $queueNumber . '. Please proceed to the waiting area.',
                    APP_URL . '/pages/adviser/my_qr.php', null, 'medium', $studentId);
            }
            // Notify nurses
            foreach ($db->query("SELECT user_id FROM nurses")->fetchAll() as $nurse) {
                push_notification($nurse['user_id'], 'qr_checkin', 'Staff Checked In',
                    ($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '') . ' (Teacher/Staff) — Queue: ' . $queueNumber,
                    APP_URL . '/pages/nurse/dashboard.php', null, 'medium', null);
            }
            log_activity($user['id'], 'QR Check-in (Staff)', 'qr_checkin', "Teacher #{$teacherId}, Queue: {$queueNumber}");
            json_response(true, [
                'queue_number' => $queueNumber,
                'queue_id'     => $queueId,
                'student'      => [
                    'id'             => $teacherId,
                    'student_number' => $record['employee_id'] ?? 'STAFF',
                    'name'           => ($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? ''),
                    'course'         => $record['department'] ?? 'Staff',
                    'year_level'     => '',
                    'section'        => 'Teacher/Staff',
                ],
            ], 'Staff check-in successful. Queue number issued: ' . $queueNumber);
        } catch (Exception $e) {
            $db->rollBack();
            json_response(false, [], safe_api_error($e, 'Check-in failed. Please try again.', 'qr/checkin'));
        }
    }

    if (is_duplicate_scan($db, $result['student']['qr_token'], 30)) {
        log_qr_scan($db, $studentId, $result['student']['qr_token'], 'check_in', $user['id'], 'duplicate', $location, null, null, $scanMethod);
        json_response(false, [], 'Duplicate scan detected. Please wait 30 seconds before scanning again.');
    }

    $queueNumber = generate_queue_number($db);

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO queue_numbers (student_id, queue_number, visit_type, qr_token, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$studentId, $queueNumber, $visitType, $result['student']['qr_token']]);
        $queueId = $db->lastInsertId();

        log_qr_scan($db, $studentId, $result['student']['qr_token'], 'check_in', $user['id'], 'success', $location, null, [
            'queue_id'     => $queueId,
            'queue_number' => $queueNumber,
            'visit_type'   => $visitType,
        ], $scanMethod);

        $stmt = $db->prepare("UPDATE students SET last_checkin_at = NOW() WHERE id = ?");
        $stmt->execute([$studentId]);

        $db->commit();

        // Notify the student
        push_notification(
            $user['id'],
            'qr_checkin',
            'Check-in Successful',
            'Your QR was scanned at the clinic. Queue number: ' . $queueNumber . '. Please proceed to the waiting area.',
            APP_URL . '/pages/student/my_qr.php',
            null, 'medium', $studentId
        );

        // Notify all nurses
        $nurseStmt = $db->query("SELECT user_id FROM nurses");
        $nurses = $nurseStmt->fetchAll();
        foreach ($nurses as $nurse) {
            push_notification(
                $nurse['user_id'],
                'qr_checkin',
                'Student Checked In',
                $result['student']['first_name'] . ' ' . $result['student']['last_name'] .
                ' (' . $result['student']['student_number'] . ') — Queue: ' . $queueNumber,
                APP_URL . '/pages/nurse/dashboard.php',
                null, 'medium', $studentId
            );
        }

        // Audit log
        log_activity($user['id'], 'QR Check-in', 'qr_checkin', "Student: {$result['student']['student_number']}, Queue: {$queueNumber}");

        json_response(true, [
            'queue_number' => $queueNumber,
            'queue_id'     => $queueId,
            'student'      => [
                'id'             => $studentId,
                'student_number' => $result['student']['student_number'],
                'name'           => $result['student']['first_name'] . ' ' . $result['student']['last_name'],
                'course'         => $result['student']['course'],
                'year_level'     => $result['student']['year_level'],
                'section'        => $result['student']['section'],
            ],
        ], 'Check-in successful. Queue number issued: ' . $queueNumber);
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, [], safe_api_error($e, 'Check-in failed. Please try again.', 'qr/checkin'));
    }
}

// ================================================================
// GENERATE DYNAMIC — create a one-time-purpose dynamic QR
// ================================================================
if ($action === 'generate_dynamic') {
    csrf_verify_or_die();
    if ($role !== 'nurse' && $role !== 'admin') json_response(false, [], 'Access denied.');
    $purpose = trim($_POST['purpose'] ?? '');
    $referenceId = (int)($_POST['reference_id'] ?? 0);
    $referenceType = trim($_POST['reference_type'] ?? '');
    $studentId = (int)($_POST['student_id'] ?? 0);
    $expiresIn = (int)($_POST['expires_in'] ?? 0);

    $allowedPurposes = ['certificate_verification', 'appointment', 'walk_in', 'medical_record', 'vaccination', 'lab_result'];
    if (!in_array($purpose, $allowedPurposes)) {
        json_response(false, [], 'Invalid or missing purpose.');
    }

    $result = generate_dynamic_qr($db, $purpose, $referenceId, $referenceType, $studentId, $expiresIn, $user['id']);
    log_activity($user['id'], 'Generated dynamic QR', 'qr_management', "Purpose: $purpose, Ref: $referenceId");

    json_response(true, $result, 'Dynamic QR generated.');
}

// ================================================================
// UPLOAD_VERIFY — verify a QR code from an uploaded image.
// Nurse only. The client decodes the QR from the image using
// html5-qrcode, then sends the token here for server-side validation.
// Records the scan with scan_method='upload'.
// ================================================================
if ($action === 'upload_verify') {
    if ($role !== 'nurse') {
        json_response(false, [], 'Access denied. Nurses only.');
    }

    $qrData = trim($_POST['qr_data'] ?? '');
    $location = trim($_POST['location'] ?? 'QR Upload');
    $scanMethod = 'upload';

    if (strlen($qrData) < 10) json_response(false, [], 'Invalid QR data.');

    $result = verify_student_qr($db, $qrData);
    if (!$result['valid']) {
        log_qr_scan($db, null, '', 'upload_verify', $user['id'], 'invalid', $location, null, ['reason' => $result['reason']], $scanMethod);
        json_response(false, [], 'QR verification failed: ' . $result['reason']);
    }

    $studentId = $result['student_id'];
    $student = $result['student'];

    log_qr_scan($db, $studentId, $student['qr_token'], 'upload_verify', $user['id'], 'success', $location, null, null, $scanMethod);

    $studentPayload = [
        'id'             => (int)$studentId,
        'student_number' => $student['student_number'],
        'name'           => ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''),
        'first_name'     => $student['first_name'] ?? '',
        'last_name'      => $student['last_name'] ?? '',
        'course'         => $student['course'] ?? '',
        'year_level'     => $student['year_level'] ?? '',
        'section'        => $student['section'] ?? '',
        'gender'         => $student['gender'] ?? '',
        'email'          => $student['email'] ?? '',
        'profile_picture'=> $student['profile_picture'] ?? '',
    ];

    json_response(true, [
        'valid'     => true,
        'student_id' => $studentId,
        'student'   => $studentPayload,
        'qr_token'  => $student['qr_token'] ?? '',
        'payload'   => $qrData,
    ]);
}

// ================================================================
// VERIFY — verify a QR payload (dynamic or permanent), return student info
// ================================================================
if ($action === 'verify') {
    if ($role !== 'nurse') {
        json_response(false, [], 'Access denied. Nurses only.');
    }

    $qrData = $_POST['qr_data'] ?? $_GET['qr_data'] ?? '';
    $scanMethod = strtolower(trim($_POST['scan_method'] ?? $_GET['scan_method'] ?? 'unknown'));
    $scanMethod = in_array($scanMethod, ['camera', 'upload', 'walk_in'], true) ? $scanMethod : 'unknown';

    if (strlen($qrData) < 10) json_response(false, [], 'Invalid QR data.');

    // The new permanent QR encodes ONLY a token string (e.g. "BPC-4A8F..."),
    // so a non-JSON payload is a valid token-only student QR.
    $decoded = json_decode($qrData, true);

    if (is_array($decoded) && ($decoded['v'] ?? null) === 2 && ($decoded['type'] ?? null) === 'dynamic') {
        $result = verify_dynamic_qr($db, $qrData);
        if (!$result['valid']) {
            json_response(false, [], 'Dynamic QR invalid: ' . $result['reason']);
        }
        $studentId = $result['student_id'];
        $student = null;
        if ($studentId > 0) {
            $stmt = $db->prepare("
                SELECT s.id, s.student_number, s.course, s.year_level, s.section,
                       u.first_name, u.last_name, u.email
                FROM students s JOIN users u ON u.id = s.user_id
                WHERE s.id = ?
            ");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch();
        }
        unset($result['row']);
        $result['student'] = $student;
        json_response(true, $result);
    } else {
        // Resolve to a Student OR a Teacher/Staff patient.
        $result = resolve_patient_qr($db, $qrData);
        if (!$result['valid']) json_response(false, [], 'QR verification failed: ' . ($result['reason'] ?? 'invalid'));
        if ($result['patient_type'] === 'teacher') {
            json_response(true, ['valid' => true, 'patient_type' => 'teacher', 'teacher_id' => $result['id'], 'student' => $result['record']]);
        }
        json_response(true, ['valid' => true, 'patient_type' => 'student', 'student_id' => $result['id'], 'student' => $result['record']]);
    }
}

// ================================================================
// TEACHER_QR — a teacher/staff member's own permanent QR (My Clinic)
// ================================================================
if ($action === 'teacher_qr') {
    if (!in_array($role, ['adviser', 'admin', 'nurse'], true)) json_response(false, [], 'Access denied.');
    // Resolve the teacher record for the current user (adviser) or a provided teacher_id (admin/nurse)
    $teacherId = 0;
    if ($role === 'adviser') {
        $stmt = $db->prepare("SELECT id FROM teachers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user['id']]);
        $teacherId = (int)$stmt->fetchColumn();
    } else {
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
        if ($teacherId <= 0) json_response(false, [], 'Teacher ID required.');
    }
    if ($teacherId <= 0) json_response(false, [], 'No staff record found for your account.');

    $qr = ensure_teacher_qr($db, $teacherId, $user['id']);
    if (!$qr) json_response(false, [], 'Unable to generate your QR code.');

    $stmt = $db->prepare("SELECT t.*, u.first_name, u.last_name, u.email, u.profile_picture FROM teachers t JOIN users u ON u.id=t.user_id WHERE t.id=?");
    $stmt->execute([$teacherId]);
    $teacher = $stmt->fetch();

    // Recent scan history (best-effort)
    $scanHistory = [];
    try {
        $scanHistory = $db->prepare("
            SELECT scan_time, purpose, status, scanner_name
            FROM qr_scan_logs
            WHERE teacher_id = ? ORDER BY scan_time DESC LIMIT 10
        ");
        $scanHistory->execute([$teacherId]);
        $scanHistory = $scanHistory->fetchAll();
    } catch (Throwable $e) { $scanHistory = []; }

    json_response(true, [
        'qr'        => ['qr_data' => $qr['qr_data'], 'status' => $qr['status'], 'generated_at' => $qr['generated_at']],
        'teacher'   => $teacher,
        'scan_history' => $scanHistory,
    ]);
}

// ================================================================
// STUDENT LOOKUP — get student info for scanned QR
// Nurse only.
// ================================================================
if ($action === 'student_lookup') {
    if ($role !== 'nurse') {
        json_response(false, [], 'Access denied. Nurses only.');
    }
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    // NOTE: gender/birthdate live on the `students` table in the base schema.
    $stmt = $db->prepare("
        SELECT s.id, s.student_number, s.course, s.year_level, s.section, s.blood_type, s.allergies,
               s.gender, s.birthdate,
               u.first_name, u.last_name, u.email, u.phone
        FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE s.id = ?
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student) json_response(false, [], 'Student not found.');

    json_response(true, ['student' => $student]);
}

// ================================================================
// MEDICAL RECORD SUMMARY — for MICA AI context after QR scan
// ================================================================
if ($action === 'medical_record_summary') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $summary = get_student_medical_summary($db, $studentId);

    // Resilient — falls back to null/default if student_qrs / qr_scan_logs
    // tables are not present (migration not yet applied).
    $qrStatus = safe_fetch_one($db, "
        SELECT sq.status AS qr_status, sq.generated_at,
               COUNT(qcl.id) AS recent_visits
        FROM student_qrs sq
        LEFT JOIN qr_scan_logs qcl ON qcl.student_id = sq.student_id AND qcl.scan_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        WHERE sq.student_id = ?
        GROUP BY sq.id
    ", [$studentId], null);
    $summary['qr_status'] = $qrStatus;

    json_response(true, ['summary' => $summary]);
}

// ================================================================
// STUDENT_HEALTH_PASS — comprehensive health pass data for a student.
// Returns QR info, student profile, appointments, queue, vitals,
// vaccination status, recent medicines, and scan history.
//
// RESILIENCE: Optional tables/columns (added by migration files) are
// queried through safe_fetch_* helpers so a missing table (e.g.
// student_qrs, queue_numbers, qr_scan_logs, qr_settings, or the
// students.last_checkin_at column) never crashes the endpoint with a
// 500 error. Each optional section falls back to a safe default.
// ================================================================
if ($action === 'student_health_pass') {
    $studentId = resolve_student_id($db, $user);
    if ($studentId <= 0) {
        // Allow nurse/admin to specify a student_id
        if ($user['role'] === 'nurse' || $user['role'] === 'admin') {
            $studentId = (int)($_GET['student_id'] ?? 0);
        }
        if ($studentId <= 0) json_response(false, [], 'No student record found.');
    }

    // QR record — build_student_qr_payload is pure PHP (no schema needed)
    // for the token; ensure_student_qr writes to student_qrs if present,
    // but we never let a missing table stop the Health Pass from loading.
    $qr = null;
    try {
        $qr = ensure_student_qr($db, $studentId, $user['id']);
    } catch (Throwable $e) {
        error_log('student_health_pass ensure_student_qr: ' . $e->getMessage());
        // Fall back to building a payload in-memory without persisting.
        $qSno = safe_fetch_column($db, "SELECT student_number FROM students WHERE id = ?", [$studentId], '');
        $built = build_student_qr_payload($studentId, $qSno);
        $qr = [
            'student_id'   => $studentId,
            'qr_token'     => $built['token'],
            'qr_data'      => $built['payload'],
            'status'       => 'active',
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }
    $qrData = is_array($qr) ? ($qr['qr_data'] ?? '') : '';
    $qrToken = is_array($qr) ? ($qr['qr_token'] ?? '') : '';
    $qrStatus = is_array($qr) ? ($qr['status'] ?? 'active') : 'active';
    $qrGeneratedAt = is_array($qr) ? ($qr['generated_at'] ?? '') : '';

    // Student profile (including photo) — core tables always present.
    // NOTE: gender/birthdate live on the `students` table (not `users`) in
    // the base schema. `last_checkin_at` on `students` is added by a
    // migration, so it is only referenced in the optional first query.
    $student = safe_fetch_one($db, "
        SELECT s.id, s.student_number, s.course, s.year_level, s.section,
               s.blood_type, s.allergies, s.emergency_contact_name, s.emergency_contact_number,
               s.last_checkin_at, s.created_at, s.gender, s.birthdate,
               u.first_name, u.last_name, u.email, u.phone, u.profile_picture
        FROM students s JOIN users u ON u.id = s.user_id
        WHERE s.id = ?
    ", [$studentId], null);

    if (!$student) {
        // Last-chance: select without last_checkin_at (migration-only column)
        $student = safe_fetch_one($db, "
            SELECT s.id, s.student_number, s.course, s.year_level, s.section,
                   s.blood_type, s.allergies, s.emergency_contact_name, s.emergency_contact_number,
                   s.created_at, s.gender, s.birthdate,
                   u.first_name, u.last_name, u.email, u.phone, u.profile_picture
            FROM students s JOIN users u ON u.id = s.user_id
            WHERE s.id = ?
        ", [$studentId], []);
    }

    // QR token (stored server-side, never in QR payload — payload only has the token)
    $qrExpiration = (int)get_qr_setting($db, 'token_expiration', 0);
    $qrExpiresAt = ($qrExpiration > 0 && $qrGeneratedAt)
        ? date('M j, Y g:i A', strtotime($qrGeneratedAt) + $qrExpiration)
        : null;

    // Today's appointment
    $today = date('Y-m-d');
    $todayAppt = safe_fetch_one($db, "
        SELECT id, appointment_date, appointment_time, reason, status, nurse_id
        FROM appointments
        WHERE student_id = ? AND appointment_date = ?
        ORDER BY appointment_time ASC
        LIMIT 1
    ", [$studentId, $today], null);

    // Upcoming appointments (next 7 days)
    $upcomingAppts = safe_fetch_all($db, "
        SELECT id, appointment_date, appointment_time, reason, status
        FROM appointments
        WHERE student_id = ? AND appointment_date >= ? AND status IN ('pending','approved')
        ORDER BY appointment_date ASC, appointment_time ASC
        LIMIT 5
    ", [$studentId, $today], []);

    // Current queue number (active: waiting or serving)
    $currentQueue = safe_fetch_one($db, "
        SELECT queue_number, visit_type, status, created_at, estimated_wait_minutes, called_at, served_at
        FROM queue_numbers
        WHERE student_id = ? AND status IN ('waiting','serving')
        ORDER BY created_at DESC
        LIMIT 1
    ", [$studentId], null);

    // Estimated waiting time (from queue settings or dynamic calculation)
    $estimatedWait = null;
    if ($currentQueue && !empty($currentQueue['estimated_wait_minutes'])) {
        $estimatedWait = $currentQueue['estimated_wait_minutes'];
    } elseif ($currentQueue && ($currentQueue['status'] ?? '') === 'waiting') {
        // Calculate position in queue
        $position = (int)safe_fetch_column($db, "
            SELECT COUNT(*) FROM queue_numbers
            WHERE status IN ('waiting','serving') AND created_at <= ?
            AND visit_type = ?
        ", [$currentQueue['created_at'], $currentQueue['visit_type'] ?? ''], 0);
        $avgServiceTime = (int)get_qr_setting($db, 'avg_service_time', 5);
        $estimatedWait = $position * $avgServiceTime;
    }

    // Last 3 consultations
    $recentVisits = safe_fetch_all($db, "
        SELECT id, visit_type, consultation_date, chief_complaint, diagnosis, treatment, appointment_id
        FROM consultations
        WHERE student_id = ?
        ORDER BY consultation_date DESC
        LIMIT 3
    ", [$studentId], []);

    // Latest Self-Recorded BMI
    $latestSelfBmi = safe_fetch_one($db, "
        SELECT height_cm, weight_kg, bmi, bmi_status, recorded_at
        FROM health_monitoring
        WHERE student_id = ? AND bmi IS NOT NULL AND recorded_by IS NULL
        ORDER BY recorded_at DESC
        LIMIT 1
    ", [$studentId], null);

    // Latest Nurse Assessment
    $latestNurseAssessment = safe_fetch_one($db, "
        SELECT height_cm, weight_kg, bmi, bmi_status, blood_pressure, heart_rate, recorded_at
        FROM health_monitoring
        WHERE student_id = ? AND bmi IS NOT NULL AND recorded_by IS NOT NULL
        ORDER BY recorded_at DESC
        LIMIT 1
    ", [$studentId], null);

    // Vaccination status
    $vaccinations = safe_fetch_all($db, "
        SELECT vaccine_name, dose_number, date_administered, next_dose_date
        FROM vaccinations
        WHERE student_id = ?
        ORDER BY date_administered DESC
    ", [$studentId], []);
    $vaccinationStatus = [];
    foreach ($vaccinations as $v) {
        $vaccinationStatus[] = ($v['vaccine_name'] ?? '') . ' (Dose ' . ($v['dose_number'] ?? '') . ')';
    }

    // Recent medicines dispensed (via medicine_logs stock_out for this student)
    $recentMedicines = safe_fetch_all($db, "
        SELECT ml.performed_at, m.medicine_name, ml.quantity, ml.reason
        FROM medicine_logs ml
        JOIN medicines m ON m.id = ml.medicine_id
        WHERE ml.student_id = ? AND ml.action = 'stock_out'
        ORDER BY ml.performed_at DESC
        LIMIT 5
    ", [$studentId], []);

    // Last scan date/time
    $lastScan = safe_fetch_one($db, "
        SELECT scan_time, purpose, status
        FROM qr_scan_logs
        WHERE student_id = ?
        ORDER BY scan_time DESC
        LIMIT 1
    ", [$studentId], null);

    json_response(true, [
        'student'   => $student,
        'qr'          => [
            'qr_data'       => $qrData,
            'qr_token'      => $qrToken,  // server-only, never exposed in QR payload
            'status'        => $qrStatus,
            'generated_at'  => $qrGeneratedAt,
            'expires_at'    => $qrExpiresAt,
            'token_expires_in_seconds' => $qrExpiration,
        ],
        'today_appointment' => $todayAppt,
        'upcoming_appointments' => $upcomingAppts,
        'current_queue'   => $currentQueue,
        'estimated_wait'  => $estimatedWait,
        'recent_visits'   => $recentVisits,
        'latest_self_bmi'      => $latestSelfBmi,
        'latest_nurse_assessment' => $latestNurseAssessment,
        'vaccinations'    => $vaccinationStatus,
        'vaccination_count' => count($vaccinations),
        'recent_medicines'=> $recentMedicines,
        'last_scan'       => $lastScan,
        'last_checkin_at' => $student ? ($student['last_checkin_at'] ?? null) : null,
    ]);
}

// ================================================================
// STUDENT_QR_TIMELINE — chronological visit timeline after a QR scan.
// Returns the full lifecycle: scanned → check-in → queue → consultation
// → medicine → certificate → completed.
// ================================================================
if ($action === 'student_qr_timeline') {
    $studentId = resolve_student_id($db, $user);
    if ($studentId <= 0) {
        if ($user['role'] === 'nurse' || $user['role'] === 'admin') {
            $studentId = (int)($_GET['student_id'] ?? 0);
        }
        if ($studentId <= 0) json_response(false, [], 'No student record found.');
    }

$limit = min((int)($_GET['limit'] ?? 20), 100);
    $events = [];

    // 1. QR scan logs (check-in, appointment verify, etc.)
    $scanRows = safe_fetch_all($db, "
        SELECT scan_time, purpose, status, scan_location
        FROM qr_scan_logs
        WHERE student_id = ?
        ORDER BY scan_time DESC
        LIMIT ?
    ", [$studentId, $limit], []);
    foreach ($scanRows as $row) {
        $events[] = [
            'timestamp' => $row['scan_time'],
            'type'      => 'scan',
            'title'     => 'QR Scanned',
            'detail'    => ucfirst($row['purpose']) . ' • ' . ucfirst($row['scan_location'] ?? ''),
            'status'    => $row['status'],
            'icon'      => '',
        ];
    }

    // 2. Queue numbers (check-in / queue assigned)
    $queueRows = safe_fetch_all($db, "
        SELECT queue_number, visit_type, created_at, called_at, served_at, completed_at, status
        FROM queue_numbers
        WHERE student_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ", [$studentId, $limit], []);
    foreach ($queueRows as $row) {
        $events[] = [
            'timestamp' => $row['created_at'],
            'type'      => 'queue',
            'title'     => 'Queue Assigned',
            'detail'    => $row['queue_number'] . ' (' . ucfirst($row['visit_type']) . ')',
            'status'    => $row['status'],
            'icon'      => '',
        ];
        if (!empty($row['called_at'])) {
            $events[] = [
                'timestamp' => $row['called_at'],
                'type'      => 'consultation',
                'title'     => 'Consultation Started',
                'detail'    => 'Queue ' . $row['queue_number'],
                'status'    => 'serving',
                'icon'      => '',
            ];
        }
        if (!empty($row['completed_at'])) {
            $events[] = [
                'timestamp' => $row['completed_at'],
                'type'      => 'visit_completed',
                'title'     => 'Visit Completed',
                'detail'    => 'Queue ' . $row['queue_number'],
                'status'    => 'completed',
                'icon'      => '',
            ];
        }
    }

    // 3. Consultations
    $consultRows = safe_fetch_all($db, "
        SELECT consultation_date, visit_type, chief_complaint, diagnosis
        FROM consultations
        WHERE student_id = ?
        ORDER BY consultation_date DESC
        LIMIT ?
    ", [$studentId, $limit], []);
    foreach ($consultRows as $row) {
        $events[] = [
            'timestamp' => $row['consultation_date'],
            'type'      => 'consultation',
            'title'     => 'Consultation',
            'detail'    => $row['chief_complaint'] . ($row['diagnosis'] ? ' — ' . $row['diagnosis'] : ''),
            'status'    => 'completed',
            'icon'      => '',
        ];
    }

    // 4. Medicine dispensing
    $medRows = safe_fetch_all($db, "
        SELECT md.performed_at, m.medicine_name, md.quantity, u.first_name AS nurse_name
        FROM medicine_dispensing md
        JOIN medicines m ON m.id = md.medicine_id
        LEFT JOIN users u ON u.id = md.dispensed_by
        WHERE md.walk_in_id IN (
            SELECT id FROM walk_in_visits WHERE student_id = ?
        )
        ORDER BY md.performed_at DESC
        LIMIT ?
    ", [$studentId, $limit], []);
    foreach ($medRows as $row) {
        $events[] = [
            'timestamp' => $row['performed_at'],
            'type'    => 'medicine',
            'title'   => 'Medicine Dispensed',
            'detail'  => $row['medicine_name'] . ' (' . $row['quantity'] . ')',
            'status'  => 'completed',
            'icon'    => '',
        ];
    }

    // 5. Certificates issued
    $certRows = safe_fetch_all($db, "
        SELECT date_issued, certificate_type, purpose
        FROM certificates
        WHERE student_id = ?
        ORDER BY date_issued DESC
        LIMIT ?
    ", [$studentId, $limit], []);
    foreach ($certRows as $row) {
        $events[] = [
            'timestamp' => $row['date_issued'] . ' 00:00:00',
            'type'      => 'certificate',
            'title'     => 'Medical Certificate Issued',
            'detail'    => $row['certificate_type'] . ($row['purpose'] ? ' — ' . $row['purpose'] : ''),
            'status'    => 'completed',
            'icon'      => '',
        ];
    }

    // Sort chronologically (newest first)
    usort($events, function ($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });

    json_response(true, ['timeline' => array_slice($events, 0, $limit)]);
}

// ================================================================
// STUDENT_QR_NOTIFICATIONS — notifications related to the student's QR
// scans (for the notification bell / history).
// ================================================================
if ($action === 'student_qr_notifications') {
    $studentId = resolve_student_id($db, $user);
    if ($studentId <= 0) {
        if ($user['role'] === 'nurse' || $user['role'] === 'admin') {
            $studentId = (int)($_GET['student_id'] ?? 0);
        }
        if ($studentId <= 0) json_response(false, [], 'No student record found.');
    }

    $limit = min((int)($_GET['limit'] ?? 10), 50);

    // Resilient: related_id column is added by a migration — fall back to a
    // simpler query (or empty list) if the notifications schema differs.
    $notifications = safe_fetch_all($db, "
        SELECT n.id, n.type, n.title, n.message, n.link, n.is_read, n.created_at
        FROM notifications n
        WHERE n.user_id = (
            SELECT user_id FROM students WHERE id = ? LIMIT 1
        )
        OR n.related_id = ?
        ORDER BY n.created_at DESC
        LIMIT ?
    ", [$studentId, $studentId, $limit], []);
    if (empty($notifications)) {
        $notifications = safe_fetch_all($db, "
            SELECT n.id, n.type, n.title, n.message, n.link, n.is_read, n.created_at
            FROM notifications n
            WHERE n.user_id = (
                SELECT user_id FROM students WHERE id = ? LIMIT 1
            )
            ORDER BY n.created_at DESC
            LIMIT ?
        ", [$studentId, $limit], []);
    }

    json_response(true, ['notifications' => $notifications]);
}

// ================================================================
// STUDENT_QR_NOTIFY — trigger post-scan notifications (student + nurse + audit)
// Called after a successful QR scan + check-in.
// ================================================================
if ($action === 'student_qr_notify') {
    csrf_verify_or_die();
    if ($role !== 'nurse' && $role !== 'admin') json_response(false, [], 'Access denied.');

    $studentId = (int)($_POST['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    // Fetch student user_id
    $stmt = $db->prepare("
        SELECT s.id, s.student_number, s.course, s.year_level, u.id AS user_id, u.first_name, u.last_name
        FROM students s JOIN users u ON u.id = s.user_id
        WHERE s.id = ?
    ");
    $stmt->execute([$studentId]);
    $s = $stmt->fetch();
    if (!$s) json_response(false, [], 'Student not found.');

    $queueNumber = $_POST['queue_number'] ?? null;
    $purpose     = trim($_POST['purpose'] ?? 'check-in');

    // Notify the student
    push_notification(
        $s['user_id'],
        'qr_scan',
        'QR Check-in Successful',
        'Welcome, ' . $s['first_name'] . '! Your QR was scanned at the clinic. ' .
        ($queueNumber ? 'Your queue number is ' . $queueNumber . '. Please proceed to the waiting area.' : ''),
        APP_URL . '/pages/student/my_qr.php',
        null,
        'medium',
        $studentId
    );

    // Notify all nurses on duty
    $nurseStmt = $db->query("SELECT id, user_id FROM nurses");
    $nurses = $nurseStmt->fetchAll();
    foreach ($nurses as $nurse) {
        push_notification(
            $nurse['user_id'],
            'qr_scan',
            'Student Checked In',
            $s['first_name'] . ' ' . $s['last_name'] . ' (' . $s['student_number'] . ') ' .
            ($queueNumber ? 'Queue: ' . $queueNumber : '') . ' — ' . ucfirst($purpose),
            APP_URL . '/pages/nurse/dashboard.php',
            null,
            'medium',
            $studentId
        );
    }

    // Audit log
    log_activity($user['id'], 'QR Scan Notification', 'qr_checkin', "Student ID: {$s['student_number']}, Queue: {$queueNumber}, Purpose: {$purpose}");

    json_response(true, [], 'Notifications sent.');
}

// ================================================================
// SMART_SCAN — Smart QR workflow auto-detection + check-in.
// Scans a student QR, verifies it, detects the correct scenario
// (appointment vs walk-in), and (optionally) performs the check-in:
//   - Marks the same-day appointment as "arrived" (if any)
//   - Generates a queue number
//   - Logs the scan with a scan_intent
//   - Notifies student + nurses
//   - Returns a MICA greeting when enabled
// ================================================================
if ($action === 'smart_scan') {
    csrf_verify_or_die();
    if ($role !== 'nurse') json_response(false, [], 'Access denied. Nurses only.');

    $qrData   = trim($_POST['qr_data'] ?? '');
    $location = trim($_POST['location'] ?? 'Smart QR Scanner');
    $performCheckIn = filter_var($_POST['perform_checkin'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $scanMethod = strtolower(trim($_POST['scan_method'] ?? 'unknown'));
    $scanMethod = in_array($scanMethod, ['camera', 'upload', 'walk_in'], true) ? $scanMethod : 'unknown';

    if (strlen($qrData) < 10) json_response(false, [], 'Invalid QR data.');

    // 1. Verify the QR (Student OR Teacher/Staff)
    $result = resolve_patient_qr($db, $qrData);
    if (!$result['valid']) {
        log_qr_scan($db, null, '', 'smart_scan', $user['id'], 'invalid', $location, null, ['reason' => $result['reason']], $scanMethod);
        json_response(false, [], 'QR verification failed: ' . ($result['reason'] ?? 'invalid'));
    }

    $patientType = $result['patient_type'];
    $studentId   = $patientType === 'student' ? (int)$result['id'] : null;
    $teacherId   = $patientType === 'teacher' ? (int)$result['id'] : null;
    $student     = $result['record'];
    $qrToken     = $student['qr_token'] ?? '';

    // Teacher/Staff smart scan: verify + (optional) check-in, no student-only workflow
    if ($patientType === 'teacher') {
        if (is_duplicate_scan($db, $qrToken, get_qr_setting($db, 'duplicate_scan_window', 30))) {
            log_qr_scan($db, null, $qrToken, 'smart_scan', $user['id'], 'duplicate', $location, $teacherId, null, $scanMethod);
            json_response(false, [], 'Duplicate scan detected. Please wait 30 seconds before scanning again.');
        }
        $studentPayload = [
            'id'             => (int)$teacherId,
            'student_number' => $student['employee_id'] ?? 'STAFF',
            'name'           => ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''),
            'first_name'     => $student['first_name'] ?? '',
            'last_name'     => $student['last_name'] ?? '',
            'course'         => $student['department'] ?? 'Staff',
            'year_level'     => '',
            'section'        => 'Teacher/Staff',
            'gender'         => '',
            'email'          => $student['email'] ?? '',
            'profile_picture'=> $student['profile_picture'] ?? '',
        ];
        if (!$performCheckIn) {
            log_qr_scan($db, null, $qrToken, 'smart_scan', $user['id'], 'success', $location, $teacherId, ['scan_intent' => 'walk_in'], $scanMethod);
            json_response(true, ['student' => $studentPayload, 'workflow' => ['scenario' => 'walk_in'], 'purpose' => 'smart_scan']);
        }
        $queueNumber = generate_queue_number($db);
        try {
            $db->beginTransaction();
            $stmt = $db->prepare("INSERT INTO queue_numbers (teacher_id, patient_type, queue_number, visit_type, qr_token, status, created_at) VALUES (?, 'teacher', ?, 'walk-in', ?, 'waiting', NOW())");
            $stmt->execute([$teacherId, $queueNumber, $qrToken]);
            $queueId = $db->lastInsertId();
            log_qr_scan($db, null, $qrToken, 'smart_scan', $user['id'], 'success', $location, $teacherId, ['queue_id' => $queueId, 'queue_number' => $queueNumber, 'scan_intent' => 'walk_in'], $scanMethod);
            $db->commit();
            if (!empty($student['user_id'])) {
                push_notification($student['user_id'], 'qr_checkin', 'Check-in Successful',
                    'Your QR was scanned at the clinic. Queue number: ' . $queueNumber . '.', APP_URL . '/pages/adviser/my_qr.php', null, 'medium', null);
            }
            foreach ($db->query("SELECT user_id FROM nurses")->fetchAll() as $nurse) {
                push_notification($nurse['user_id'], 'qr_checkin', 'Staff Checked In',
                    ($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '') . ' (Teacher/Staff) — Queue: ' . $queueNumber, APP_URL . '/pages/nurse/dashboard.php', null, 'medium', null);
            }
            json_response(true, ['queue_number' => $queueNumber, 'student' => ['name' => $studentPayload['name'], 'student_number' => $studentPayload['student_number']]]);
        } catch (Exception $e) {
            $db->rollBack();
            json_response(false, [], safe_api_error($e, 'Smart check-in failed. Please try again.', 'qr/smart_checkin'));
        }
    }

    // 2. Duplicate scan prevention (30-second cooldown)
    if (is_duplicate_scan($db, $qrToken, get_qr_setting($db, 'duplicate_scan_window', 30))) {
        log_qr_scan($db, $studentId, $qrToken, 'smart_scan', $user['id'], 'duplicate', $location, null, null, $scanMethod);
        json_response(false, [], 'Duplicate scan detected. Please wait 30 seconds before scanning again.');
    }

    // 3. Detect the smart workflow scenario
    $workflow = smart_qr_detect_workflow($db, $studentId);

    // 4. Build the student display payload
    $studentPayload = [
        'id'             => (int)$studentId,
        'student_number' => $student['student_number'],
        'name'           => $student['first_name'] . ' ' . $student['last_name'],
        'first_name'     => $student['first_name'],
        'last_name'      => $student['last_name'],
        'course'         => $student['course'],
        'year_level'     => $student['year_level'],
        'section'        => $student['section'],
        'gender'         => $student['gender'],
        'email'          => $student['email'],
        'profile_picture'=> $student['profile_picture'],
    ];

    // 4a. Latest Self-Recorded BMI
    $latestSelfBmi = safe_fetch_one($db, "
        SELECT height_cm, weight_kg, bmi, bmi_status, recorded_at
        FROM health_monitoring
        WHERE student_id = ? AND bmi IS NOT NULL AND recorded_by IS NULL
        ORDER BY recorded_at DESC
        LIMIT 1
    ", [$studentId], null);

    // 4b. Latest Nurse Assessment
    $latestNurseAssessment = safe_fetch_one($db, "
        SELECT height_cm, weight_kg, bmi, bmi_status, blood_pressure, heart_rate, recorded_at
        FROM health_monitoring
        WHERE student_id = ? AND bmi IS NOT NULL AND recorded_by IS NOT NULL
        ORDER BY recorded_at DESC
        LIMIT 1
    ", [$studentId], null);

    // If not performing check-in, just return the verification + workflow
    if (!$performCheckIn) {
        log_qr_scan($db, $studentId, $student['qr_token'], 'smart_scan', $user['id'], 'success', $location, null, [
            'scan_intent' => $workflow['scenario'],
        ], $scanMethod);
        json_response(true, [
            'student'  => $studentPayload,
            'workflow' => $workflow,
            'purpose'  => 'smart_scan',
            'latest_self_bmi' => $latestSelfBmi,
            'latest_nurse_assessment' => $latestNurseAssessment,
        ]);
    }

    // 5. Perform the check-in transaction
    $queueNumber = generate_queue_number($db);
    $scanIntent = $workflow['scenario'];

    try {
        $db->beginTransaction();

        // 5a. Mark appointment as arrived (if there's a same-day appointment)
        $appointmentMarked = false;
        if ($workflow['today_appointment']) {
            $appointmentMarked = mark_appointment_arrived($db, $studentId, $workflow['today_appointment']['id'], $student['qr_token']);
        }

        // 5b. Insert queue number
        $stmt = $db->prepare("
            INSERT INTO queue_numbers (student_id, queue_number, visit_type, qr_token, status, created_at)
            VALUES (?, ?, ?, ?, 'waiting', NOW())
        ");
        $stmt->execute([$studentId, $queueNumber, $scanIntent, $student['qr_token']]);
        $queueId = $db->lastInsertId();

        // 5c. Log the successful scan with scan_intent
        log_qr_scan($db, $studentId, $student['qr_token'], 'smart_scan', $user['id'], 'success', $location, null, [
            'queue_id'     => $queueId,
            'queue_number' => $queueNumber,
            'scan_intent'  => $scanIntent,
            'appointment_marked' => $appointmentMarked,
        ], $scanMethod);

        // 5d. Update last check-in
        $db->prepare("UPDATE students SET last_checkin_at = NOW() WHERE id = ?")->execute([$studentId]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, [], safe_api_error($e, 'Smart check-in failed. Please try again.', 'qr/smart_checkin'));
    }

    // 5e. Notify the student
    $studentUserId = $student['user_id'] ?? null;
    if ($studentUserId) {
        push_notification(
            $studentUserId,
            'qr_checkin',
            'Check-in Successful',
            'Your QR was scanned at the clinic. Queue number: ' . $queueNumber . '. Please proceed to the waiting area.',
            APP_URL . '/pages/student/my_qr.php',
            null, 'medium', $studentId
        );
    }

    // 5f. Notify all nurses
    $nurseStmt = $db->query("SELECT user_id FROM nurses");
    foreach ($nurseStmt->fetchAll() as $nurse) {
        push_notification(
            $nurse['user_id'],
            'qr_checkin',
            'Student Checked In',
            $student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['student_number'] . ') — Queue: ' . $queueNumber . ' (' . ucfirst($scanIntent) . ')',
            APP_URL . '/pages/nurse/dashboard.php',
            null, 'medium', $studentId
        );
    }

    // 5g. Audit log
    log_activity($user['id'], 'Smart QR Check-in', 'qr_checkin', "Student: {$student['student_number']}, Queue: {$queueNumber}, Intent: {$scanIntent}");

    // 5h. Build MICA greeting
    $micaGreeting = null;
    if (get_qr_setting($db, 'mica_scan_greeting_enabled', '1') == '1') {
        $micaGreeting = build_mica_scan_greeting($db, $studentId, $queueNumber);
    }

    json_response(true, [
        'queue_number' => $queueNumber,
        'queue_id'     => $queueId,
        'student'      => $studentPayload,
        'workflow'     => $workflow,
        'appointment_marked' => $appointmentMarked ?? false,
        'mica_greeting' => $micaGreeting,
        'purpose'      => 'smart_scan',
    ], 'Check-in successful. Queue number issued: ' . $queueNumber);
}

// ================================================================
// MARK_ARRIVED — mark a student's same-day appointment as arrived
// (used by the Smart QR scanner for the appointment scenario).
// ================================================================
if ($action === 'mark_arrived') {
    csrf_verify_or_die();
    if ($role !== 'nurse') json_response(false, [], 'Access denied. Nurses only.');

    $studentId = (int)($_POST['student_id'] ?? 0);
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);
    $qrData = trim($_POST['qr_data'] ?? '');

    if ($studentId <= 0 || $appointmentId <= 0) json_response(false, [], 'Invalid student or appointment.');

    // Verify the QR belongs to the student if provided
    if ($qrData !== '') {
        $result = verify_student_qr($db, $qrData);
        if (!$result['valid'] || (int)$result['student_id'] !== (int)$studentId) {
            json_response(false, [], 'QR verification failed.');
        }
    }

    $marked = mark_appointment_arrived($db, $studentId, $appointmentId, $qrData ? ($result['student']['qr_token'] ?? null) : null);

    // Log scan intent
    $stmt = $db->prepare("SELECT qr_token FROM student_qrs WHERE student_id = ? LIMIT 1");
    $stmt->execute([$studentId]);
    $qrToken = $stmt->fetchColumn();
    log_qr_scan($db, $studentId, $qrToken, 'smart_scan', $user['id'], 'success', 'Smart QR Scanner', null, ['scan_intent' => 'appointment', 'appointment_id' => $appointmentId], 'camera');

    log_activity($user['id'], 'Marked appointment arrived', 'qr_checkin', "Student #$studentId, Appointment #$appointmentId");

    json_response(true, ['marked' => $marked], $marked ? 'Appointment marked as arrived.' : 'Appointment already marked or not applicable.');
}

// ================================================================
// QR EXPORT — export QR-related data as CSV (default) or JSON rows
// (scan logs, daily check-ins, walk-in statistics, appointment
// attendance, queue). The JSON format is consumed by the client-side
// SheetJS Excel export in the QR Management UI.
// ================================================================
if ($action === 'qr_export') {
    if ($role !== 'nurse' && $role !== 'admin') json_response(false, [], 'Access denied.');

    $type   = trim($_GET['type'] ?? 'qr_scan_logs');
    $from   = trim($_GET['from_date'] ?? '');
    $to     = trim($_GET['to_date'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $format = trim($_GET['format'] ?? 'csv');   // csv (default) | json

    $allowed = ['qr_scan_logs', 'daily_checkins', 'walkin_statistics', 'appointment_attendance', 'queue_report'];
    if (!in_array($type, $allowed, true)) json_response(false, [], 'Invalid export type.');

    $rows = qr_export_rows($db, $type, $from, $to, $search);
    $stamp = date('Ymd_His');
    $filename = 'bpc_qr_' . $type . '_' . $stamp;

    log_activity($user['id'], 'Exported QR report', 'qr_management', "$type ($from - $to)");

    if ($format === 'json') {
        // JSON rows — used by the client-side SheetJS (Excel) export.
        json_response(true, [
            'data'     => $rows,
            'filename' => $filename . '.xlsx',
            'type'     => $type,
        ]);
    }

    qr_export_csv($rows, $filename . '.csv');
}

json_response(false, [], 'Unknown action');

