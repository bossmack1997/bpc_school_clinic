<?php
/**
 * api/patients.php
 * =====================================================================
 * Centralized Patient Workspace API for BPC School Clinic Management System
 * Single Source of Truth for Student Identification, Health Data, Live Status,
 * Clinic History, and Contextual Workflow Actions.
 * =====================================================================
 */

require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    json_response(false, [], 'Unauthorized. Please log in.');
}

$db = getDB();
$u = current_user();
$role = $u['role'] ?? 'student';
$action = $_REQUEST['action'] ?? '';

// ---------------------------------------------------------------------
// 1. GET WORKSPACE DATA (By Student ID, QR Token, or Queue Ticket ID)
// ---------------------------------------------------------------------
if ($action === 'get_workspace' || $action === 'lookup') {
    $studentId = (int)($_REQUEST['student_id'] ?? 0);
    $qrToken   = trim((string)($_REQUEST['qr_token'] ?? $_REQUEST['token'] ?? ''));
    $ticketId  = (int)($_REQUEST['ticket_id'] ?? 0);

    // Resolve student ID from ticket if provided
    if ($ticketId > 0 && $studentId <= 0) {
        $stmtT = $db->prepare("SELECT student_id FROM queue_numbers WHERE id = ? LIMIT 1");
        $stmtT->execute([$ticketId]);
        $studentId = (int)$stmtT->fetchColumn();
    }

    // Resolve student ID from QR token / payload if provided
    if ($qrToken !== '' && $studentId <= 0) {
        $qrRes = resolve_patient_qr($db, $qrToken);
        if ($qrRes['valid'] && $qrRes['patient_type'] === 'student') {
            $studentId = (int)$qrRes['id'];
        } elseif ($qrRes['valid'] && $qrRes['patient_type'] === 'teacher') {
            // Teacher patient support
            $teacherId = (int)$qrRes['id'];
            $teacher = $qrRes['record'];
            json_response(true, [
                'patient_type' => 'teacher',
                'patient' => [
                    'id'             => $teacherId,
                    'patient_type'   => 'teacher',
                    'student_number' => $teacher['employee_id'] ?? 'FACULTY',
                    'name'           => trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? '')),
                    'first_name'     => $teacher['first_name'] ?? '',
                    'last_name'      => $teacher['last_name'] ?? '',
                    'course'         => $teacher['department'] ?? 'Faculty / Staff',
                    'year_level'     => 'Faculty',
                    'section'        => 'Staff',
                    'email'          => $teacher['email'] ?? '',
                    'phone'          => $teacher['phone'] ?? '',
                    'profile_picture'=> $teacher['profile_picture'] ?? 'assets/images/default-avatar.svg',
                ],
                'health_profile' => [
                    'blood_type'         => '—',
                    'allergies'          => 'None recorded',
                    'medical_conditions' => 'None recorded',
                    'current_medications'=> 'None recorded'
                ],
                'emergency_contact' => [
                    'name'   => 'Office of HR / Department',
                    'number' => '—'
                ],
                'current_status' => null,
                'clinic_history' => [],
                'certificates'   => []
            ]);
        } else {
            json_response(false, [], 'Invalid or unrecognized patient QR code.');
        }
    }

    // If student role, force student ID to own record (IDOR protection)
    if ($role === 'student') {
        $stmtOwn = $db->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
        $stmtOwn->execute([$u['id']]);
        $ownStudentId = (int)$stmtOwn->fetchColumn();
        if ($studentId > 0 && $studentId !== $ownStudentId) {
            json_response(false, [], 'Access denied. You can only access your own health profile.');
        }
        $studentId = $ownStudentId;
    }

    if ($studentId <= 0) {
        json_response(false, [], 'Patient not found. Please provide a valid student identifier or QR token.');
    }

    // Teacher authorization: verify student is in an assigned section
    if ($role === 'teacher') {
        if (!adviser_can_access_student($db, $u['id'], $studentId)) {
            json_response(false, [], 'Access denied. This student is not in your assigned advisory sections.');
        }
    }

    // 1. Fetch Patient Demographics & Identification
    $stmt = $db->prepare("
        SELECT s.id, s.user_id, s.student_number, s.course, s.year_level, s.section,
               s.blood_type, s.allergies, s.emergency_contact_name, s.emergency_contact_number,
               s.guardian_name, s.guardian_contact, s.valid_id_path, s.created_at AS student_created_at,
               u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.gender,
               u.date_of_birth, u.status AS user_status, u.profile_picture,
               sq.qr_token, sq.status AS qr_status, sq.generated_at AS qr_generated_at
        FROM students s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN student_qrs sq ON sq.student_id = s.id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $st = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$st) {
        json_response(false, [], 'Patient profile not found.');
    }

    // Full name and patient identifier
    $mName = (!empty($st['middle_name']) && !in_array(strtolower(trim($st['middle_name'])), ['none', 'null', 'n/a', '-'], true)) ? trim($st['middle_name']) : '';
    $fullName = trim("{$st['first_name']} " . ($mName !== '' ? "{$mName} " : '') . "{$st['last_name']}");
    $patientCode = !empty($st['student_number']) ? $st['student_number'] : ('BPC-' . date('Y') . '-' . str_pad((string)$st['id'], 5, '0', STR_PAD_LEFT));

    // Profile photo fallback
    $profilePic = !empty($st['profile_picture']) ? app_url($st['profile_picture']) : asset_url('images/default-avatar.svg');

    // 2. Fetch Active Clinic Status Today (Queue Ticket & Emergency)
    $stmtQ = $db->prepare("
        SELECT q.id AS ticket_id, q.queue_number, q.visit_type, q.status, q.priority,
               q.created_at, q.called_at, q.served_at, q.completed_at,
               TIMESTAMPDIFF(MINUTE, q.created_at, NOW()) AS wait_minutes
        FROM queue_numbers q
        WHERE q.student_id = ? AND DATE(q.created_at) = CURDATE()
        ORDER BY q.id DESC
        LIMIT 1
    ");
    $stmtQ->execute([$studentId]);
    $currentQueue = $stmtQ->fetch(PDO::FETCH_ASSOC);

    $stmtE = $db->prepare("
        SELECT id, emergency_type, location, location_source, latitude, longitude, status, created_at
        FROM sos_emergencies
        WHERE student_id = ? AND status IN ('new', 'active', 'acknowledged', 'responding')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtE->execute([$studentId]);
    $activeEmergency = $stmtE->fetch(PDO::FETCH_ASSOC);

    // 3. Fetch Complete Clinic History (Consultations, Vitals, Dispensed Medicines)
    $stmtHist = $db->prepare("
        SELECT c.id AS consultation_id, c.consultation_date, c.visit_type, c.chief_complaint,
               c.diagnosis, c.treatment, c.prescription, c.remarks, c.follow_up_date,
               c.vital_signs,
               nu.first_name AS nurse_first_name, nu.last_name AS nurse_last_name,
               cert.id AS certificate_id, cert.certificate_number, cert.certificate_type
        FROM consultations c
        LEFT JOIN nurses n ON n.id = c.nurse_id
        LEFT JOIN users nu ON nu.id = n.user_id
        LEFT JOIN certificates cert ON cert.consultation_id = c.id
        WHERE c.student_id = ?
        ORDER BY c.consultation_date DESC
        LIMIT 20
    ");
    $stmtHist->execute([$studentId]);
    $rawHistory = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    $clinicHistory = [];
    foreach ($rawHistory as $h) {
        $cId = (int)$h['consultation_id'];
        
        // Fetch dispensed medicines for this visit
        $stmtMeds = $db->prepare("
            SELECT md.quantity, md.dosage_instructions, m.medicine_name, m.generic_name, m.unit
            FROM medicine_dispensing md
            JOIN medicines m ON m.id = md.medicine_id
            WHERE md.walk_in_id = ?
        ");
        $stmtMeds->execute([$cId]);
        $meds = $stmtMeds->fetchAll(PDO::FETCH_ASSOC);

        // Parse vitals JSON if stored
        $vitalsData = null;
        if (!empty($h['vital_signs'])) {
            $vitalsData = json_decode($h['vital_signs'], true);
        }

        $clinicHistory[] = [
            'consultation_id'     => $cId,
            'consultation_date'   => $h['consultation_date'],
            'formatted_date'      => date('M j, Y h:i A', strtotime($h['consultation_date'])),
            'visit_type'          => ucfirst($h['visit_type'] ?? 'Walk-in'),
            'chief_complaint'     => $h['chief_complaint'] ?? '—',
            'diagnosis'           => $h['diagnosis'] ?? '—',
            'treatment'           => $h['treatment'] ?? '—',
            'prescription'        => $h['prescription'] ?? '',
            'remarks'             => $h['remarks'] ?? '',
            'follow_up_date'      => $h['follow_up_date'] ?? null,
            'nurse_name'          => trim(($h['nurse_first_name'] ?? '') . ' ' . ($h['nurse_last_name'] ?? '')) ?: 'Clinic Staff',
            'vitals'              => $vitalsData,
            'medicines_dispensed' => $meds,
            'certificate'         => $h['certificate_id'] ? [
                'id'     => (int)$h['certificate_id'],
                'number' => $h['certificate_number'],
                'type'   => $h['certificate_type']
            ] : null
        ];
    }

    // 4. Fetch Issued Certificates
    $stmtCerts = $db->prepare("
        SELECT id, certificate_number, certificate_type, purpose, date_issued, file_path
        FROM certificates
        WHERE student_id = ?
        ORDER BY date_issued DESC
    ");
    $stmtCerts->execute([$studentId]);
    $certificates = $stmtCerts->fetchAll(PDO::FETCH_ASSOC);

    // 5. Build Contextual Actions
    $canStartVisit = empty($currentQueue) || in_array($currentQueue['status'], ['completed', 'cancelled', 'no_show', 'skipped'], true);
    $canCall       = !empty($currentQueue) && in_array($currentQueue['status'], ['waiting'], true);
    $canConsult    = !empty($currentQueue) && in_array($currentQueue['status'], ['called', 'serving', 'waiting'], true);
    $canDispense   = true;
    $canIssueCert  = !empty($clinicHistory);

    // Clean payload assembly
    $payload = [
        'patient_type' => 'student',
        'patient' => [
            'id'             => (int)$st['id'],
            'user_id'        => (int)$st['user_id'],
            'student_number' => $st['student_number'],
            'patient_code'   => $patientCode,
            'name'           => $fullName,
            'first_name'     => $st['first_name'],
            'middle_name'    => $st['middle_name'],
            'last_name'      => $st['last_name'],
            'course'         => $st['course'] ?? '—',
            'year_level'     => $st['year_level'] ?? '—',
            'section'        => $st['section'] ?? '—',
            'gender'         => $st['gender'] ?? '—',
            'date_of_birth'  => $st['date_of_birth'] ?? '',
            'phone'          => $st['phone'] ?? '—',
            'email'          => $st['email'] ?? '—',
            'profile_picture'=> $profilePic,
            'user_status'    => $st['user_status'],
            'qr_token'       => $st['qr_token'] ?? '',
            'qr_status'      => $st['qr_status'] ?? 'active'
        ],
        'health_profile' => [
            'blood_type'         => !empty($st['blood_type']) ? $st['blood_type'] : '—',
            'allergies'          => !empty($st['allergies']) ? $st['allergies'] : 'None Recorded',
            'has_allergies'      => !empty($st['allergies']) && strtolower(trim($st['allergies'])) !== 'none',
            'medical_conditions' => 'None Recorded',
            'current_medications'=> 'None'
        ],
        'emergency_contact' => [
            'name'             => $st['emergency_contact_name'] ?: ($st['guardian_name'] ?: '—'),
            'number'           => $st['emergency_contact_number'] ?: ($st['guardian_contact'] ?: '—'),
            'guardian_name'    => $st['guardian_name'] ?: '—',
            'guardian_contact' => $st['guardian_contact'] ?: '—'
        ],
        'current_status' => [
            'queue'     => $currentQueue,
            'emergency' => $activeEmergency
        ],
        'clinic_history' => $clinicHistory,
        'certificates'   => $certificates,
        'actions'        => [
            'can_start_visit' => $canStartVisit,
            'can_call'        => $canCall,
            'can_consult'     => $canConsult,
            'can_dispense'    => $canDispense,
            'can_issue_cert'  => $canIssueCert
        ]
    ];

    // Log patient workspace view by clinical staff
    if ($role === 'nurse' || $role === 'admin') {
        log_activity($u['id'], 'Accessed central patient workspace', 'patients', "Student ID #$studentId ({$st['student_number']})");
    }

    $responsePayload = array_merge($payload, ['data' => $payload]);
    json_response(true, $responsePayload, 'Patient workspace loaded.');
}

// ---------------------------------------------------------------------
// 2. START CLINIC VISIT (From Patient Workspace)
// ---------------------------------------------------------------------
if ($action === 'start_visit') {
    csrf_verify_or_die();
    if ($role !== 'nurse' && $role !== 'admin') {
        json_response(false, [], 'Access denied. Clinic staff only.');
    }

    $studentId = (int)($_POST['student_id'] ?? 0);
    $visitType = trim((string)($_POST['visit_type'] ?? 'walk-in'));
    if (!in_array($visitType, ['walk-in', 'appointment', 'emergency'], true)) {
        $visitType = 'walk-in';
    }

    if ($studentId <= 0) {
        json_response(false, [], 'Valid student ID is required.');
    }

    // Check if student is active
    $stmt = $db->prepare("SELECT s.id, s.user_id, s.student_number, u.first_name, u.last_name FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        json_response(false, [], 'Student not found.');
    }

    // Check if an open ticket exists today
    $stmtCheck = $db->prepare("SELECT id, queue_number, status FROM queue_numbers WHERE student_id = ? AND DATE(created_at) = CURDATE() AND status IN ('waiting', 'called', 'serving') LIMIT 1");
    $stmtCheck->execute([$studentId]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        json_response(true, [
            'ticket_id'    => (int)$existing['id'],
            'queue_number' => $existing['queue_number'],
            'status'       => $existing['status'],
            'message'      => "Patient already has an active queue ticket (#{$existing['queue_number']}) today."
        ]);
    }

    // Generate new queue number
    $queueNumber = generate_queue_number($db);
    $qrToken = ensure_student_qr($db, $studentId, $u['id'])['qr_token'] ?? null;

    $stmtInsert = $db->prepare("
        INSERT INTO queue_numbers (student_id, queue_number, visit_type, qr_token, status, created_at)
        VALUES (?, ?, ?, ?, 'waiting', NOW())
    ");
    $stmtInsert->execute([$studentId, $queueNumber, $visitType, $qrToken]);
    $ticketId = (int)$db->lastInsertId();

    // Notify student
    push_notification(
        (int)$student['user_id'],
        'queue',
        'Clinic Ticket Issued',
        "Your clinic queue ticket #$queueNumber has been issued. Status: Waiting.",
        APP_URL . '/pages/student/queue.php',
        null,
        'normal',
        $ticketId
    );

    log_activity($u['id'], 'Started clinic visit', 'queue', "Ticket #$queueNumber for Student #$studentId ({$student['student_number']})");

    json_response(true, [
        'ticket_id'    => $ticketId,
        'queue_number' => $queueNumber,
        'status'       => 'waiting'
    ], "Visit started. Ticket #$queueNumber generated.");
}

// ---------------------------------------------------------------------
// 3. UPDATE HEALTH INFORMATION (Allergies, Blood Type, Emergency Contact)
// ---------------------------------------------------------------------
if ($action === 'update_health_profile') {
    csrf_verify_or_die();
    $studentId = (int)($_POST['student_id'] ?? 0);

    if ($role === 'student') {
        $stmtOwn = $db->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
        $stmtOwn->execute([$u['id']]);
        $studentId = (int)$stmtOwn->fetchColumn();
    } elseif ($role !== 'nurse' && $role !== 'admin') {
        json_response(false, [], 'Access denied.');
    }

    if ($studentId <= 0) {
        json_response(false, [], 'Invalid student ID.');
    }

    $bloodType    = trim((string)($_POST['blood_type'] ?? ''));
    $allergies    = trim((string)($_POST['allergies'] ?? ''));
    $emergName    = trim((string)($_POST['emergency_contact_name'] ?? ''));
    $emergNumber  = trim((string)($_POST['emergency_contact_number'] ?? ''));

    $stmt = $db->prepare("
        UPDATE students
        SET blood_type = ?,
            allergies = ?,
            emergency_contact_name = ?,
            emergency_contact_number = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $bloodType ?: null,
        $allergies ?: null,
        $emergName ?: null,
        $emergNumber ?: null,
        $studentId
    ]);

    log_activity($u['id'], 'Updated patient health profile', 'medical_records', "Student #$studentId");
    json_response(true, [], 'Health profile updated successfully.');
}

// ================================================================
// 4. SEARCH STUDENTS (for nurse walk-in desk)
// ================================================================
if ($action === 'search_students') {
    if (!in_array($role, ['nurse', 'admin'], true)) {
        json_response(false, [], 'Access denied. Clinic staff only.');
    }
    
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) json_response(true, ['data' => []]);
    
    $like = '%' . $q . '%';
    $stmt = $db->prepare("
        SELECT s.id, s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name, u.email, u.phone, u.profile_picture, u.gender, u.date_of_birth
        FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
        LIMIT 15
    ");
    $stmt->execute([$like, $like, $like, $like]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

json_response(false, [], 'Invalid patient action.');

