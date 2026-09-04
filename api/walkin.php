<?php
/**
 * Walk-in Patient Management API
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement
 */

require_once __DIR__ . '/../config/config.php';
require_login();
if (current_user()['role'] !== 'nurse' && current_user()['role'] !== 'admin') {
    json_response(false, [], 'Access denied.');
}
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

// ================================================================
// Helper: Generate unique visit number
// ================================================================
function generateVisitNumber($db) {
    $prefix = 'BPC-CL-';
    $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(visit_number, 8) AS UNSIGNED)) FROM walk_in_visits");
    $maxId = (int)$stmt->fetchColumn();
    return $prefix . str_pad($maxId + 1, 4, '0', STR_PAD_LEFT);
}

// ================================================================
// Helper: Generate queue number
// ================================================================
function generateQueueNumber($db) {
    $date = date('Ymd');
    $stmt = $db->prepare("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $count = (int)$stmt->fetchColumn();
    return 'Q-' . $date . '-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
}

// ================================================================
// Helper: Log visit action
// ================================================================
function logVisitAction($db, $walkInId, $action, $details, $userId) {
    $stmt = $db->prepare("INSERT INTO visit_logs (walk_in_id, action, details, performed_by) VALUES (?,?,?,?)");
    $stmt->execute([$walkInId, $action, $details, $userId]);
}

// ================================================================
// HELPER: Get today's walk-in count for queue
// ================================================================
function getTodayQueueCount($db) {
    $stmt = $db->query("SELECT COUNT(*) FROM walk_in_visits WHERE visit_date = CURDATE() AND status NOT IN ('cancelled')");
    return (int)$stmt->fetchColumn();
}

// ================================================================
// ACTION: Search Students
// ================================================================
if ($action === 'search_students') {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 1) json_response(true, ['data' => []]);
    
    $like = '%' . $q . '%';
    $stmt = $db->prepare("
        SELECT s.id, s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name, u.email, u.phone, u.profile_picture, u.gender, u.birthdate
        FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
        LIMIT 15
    ");
    $stmt->execute([$like, $like, $like, $like]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

// ================================================================
// ACTION: QR-based student check-in (uses centralized QR verification)
// ================================================================
if ($action === 'qr_checkin') {
    csrf_verify_or_die();
    $qrData = trim($_POST['qr_data'] ?? '');
    $purpose = trim($_POST['purpose'] ?? 'check-in');
    if (!$qrData) json_response(false, [], 'Invalid QR data.');

    $result = verify_student_qr($db, $qrData);
    if (!$result['valid']) {
        $scanMethod = strtolower(trim($_POST['scan_method'] ?? 'unknown'));
        $scanMethod = in_array($scanMethod, ['camera', 'upload', 'walk_in'], true) ? $scanMethod : 'unknown';
        log_qr_scan($db, null, '', $purpose, $user['id'], 'failed', 'Walk-in Desk', null, ['reason' => $result['reason']], $scanMethod);
        json_response(false, [], 'QR verification failed: ' . $result['reason']);
    }

    $studentId = $result['student_id'];
    $student = $result['student'];
    $scanMethod = strtolower(trim($_POST['scan_method'] ?? 'unknown'));
    $scanMethod = in_array($scanMethod, ['camera', 'upload', 'walk_in'], true) ? $scanMethod : 'unknown';

    if (is_duplicate_scan($db, $student['qr_token'], get_qr_setting($db, 'duplicate_scan_window', 30))) {
        log_qr_scan($db, $studentId, $student['qr_token'], $purpose, $user['id'], 'duplicate', 'Walk-in Desk', null, null, $scanMethod);
        json_response(false, [], 'Duplicate scan detected. Please wait a moment and try again.');
    }

    log_qr_scan($db, $studentId, $student['qr_token'], $purpose, $user['id'], 'success', 'Walk-in Desk', null, null, $scanMethod);

    $stmt = $db->prepare("
        SELECT s.id, s.student_number, s.course, s.year_level, s.section,
               u.first_name, u.last_name, u.email, u.phone, u.profile_picture, u.gender,
               TIMESTAMPDIFF(YEAR, u.birthdate, CURDATE()) AS age
        FROM students s
        JOIN users u ON u.id = s.user_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $studentRecord = $stmt->fetch();

    if (!$studentRecord) json_response(false, [], 'Student not found.');
    json_response(true, ['data' => $studentRecord]);
}

// ================================================================
// ACTION: Calculate BMI
// ================================================================
if ($action === 'calculate_bmi') {
    $height = (float)($_POST['height_cm'] ?? 0);
    $weight = (float)($_POST['weight_kg'] ?? 0);
    
    if ($height <= 0 || $weight <= 0) json_response(false, [], 'Invalid height or weight.');
    
    $heightM = $height / 100;
    $bmi = round($weight / ($heightM * $heightM), 2);
    
    $status = 'Normal';
    if ($bmi < 18.5) $status = 'Underweight';
    elseif ($bmi < 25) $status = 'Normal';
    elseif ($bmi < 30) $status = 'Overweight';
    else $status = 'Obese';
    
    json_response(true, ['bmi' => $bmi, 'status' => $status]);
}

// ================================================================
// ACTION: Save New Walk-in Visit
// ================================================================
if ($action === 'save_visit') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can create walk-in visits.');
    
    $studentId = !empty($_POST['student_id']) ? (int)$_POST['student_id'] : null;
    $isTemp = empty($studentId) ? 1 : 0;
    
    // Validate required fields
    $chiefComplaint = trim($_POST['chief_complaint'] ?? '');
    if (!$chiefComplaint) json_response(false, [], 'Chief complaint is required.');
    
    // Begin transaction
    $db->beginTransaction();
    try {
        // Generate visit number
        $visitNumber = generateVisitNumber($db);
        
        // Generate queue number
        $queueNumber = generateQueueNumber($db);
        $todayCount = getTodayQueueCount($db);
        $estimatedWait = $todayCount * 15; // 15 minutes per patient
        
        // Create queue entry
        $priority = $_POST['priority_level'] ?? 'normal';
        $fullName = $isTemp ? trim($_POST['temp_full_name'] ?? 'Walk-in Patient') : '';
        
        $qStmt = $db->prepare("
            INSERT INTO queue_numbers (queue_number, student_id, full_name, visit_type, priority, status, estimated_wait_minutes)
            VALUES (?, ?, ?, 'walk-in', ?, 'waiting', ?)
        ");
        $qStmt->execute([$queueNumber, $studentId, $fullName, $priority, $estimatedWait]);
        $queueId = $db->lastInsertId();
        
        // Calculate BMI if height and weight provided
        $height = !empty($_POST['height_cm']) ? (float)$_POST['height_cm'] : null;
        $weight = !empty($_POST['weight_kg']) ? (float)$_POST['weight_kg'] : null;
        $bmi = null;
        $bmiStatus = null;
        if ($height && $weight) {
            $heightM = $height / 100;
            if ($heightM > 0) {
                $bmi = round($weight / ($heightM * $heightM), 2);
                if ($bmi < 18.5) $bmiStatus = 'Underweight';
                elseif ($bmi < 25) $bmiStatus = 'Normal';
                elseif ($bmi < 30) $bmiStatus = 'Overweight';
                else $bmiStatus = 'Obese';
            }
        }
        
        // Parse blood pressure
        $bp = trim($_POST['blood_pressure'] ?? '');
        $bpSystolic = null;
        $bpDiastolic = null;
        if ($bp && str_contains($bp, '/')) {
            $parts = explode('/', $bp);
            $bpSystolic = (int)trim($parts[0]);
            $bpDiastolic = (int)trim($parts[1]);
        }
        
        // Create walk-in visit
        $stmt = $db->prepare("
            INSERT INTO walk_in_visits (
                queue_id, student_id, is_temp_visitor, temp_full_name, temp_course, temp_year_level, temp_contact,
                visit_number, visit_date, arrival_time, visit_type, chief_complaint, priority_level,
                temperature, blood_pressure_systolic, blood_pressure_diastolic, heart_rate,
                respiratory_rate, oxygen_saturation, height_cm, weight_kg, bmi, bmi_status,
                symptoms, assessment, diagnosis, treatment, recommendation,
                need_follow_up, follow_up_date, attended_by, status
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?,
                ?, CURDATE(), NOW(), 'walk-in', ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, 'waiting'
            )
        ");
        $stmt->execute([
            $queueId, $studentId, $isTemp,
            $isTemp ? $fullName : null,
            $isTemp ? trim($_POST['temp_course'] ?? '') : null,
            $isTemp ? trim($_POST['temp_year_level'] ?? '') : null,
            $isTemp ? trim($_POST['temp_contact'] ?? '') : null,
            $visitNumber, $chiefComplaint, $priority,
            !empty($_POST['temperature']) ? (float)$_POST['temperature'] : null,
            $bpSystolic, $bpDiastolic,
            !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null,
            !empty($_POST['respiratory_rate']) ? (int)$_POST['respiratory_rate'] : null,
            !empty($_POST['oxygen_saturation']) ? (float)$_POST['oxygen_saturation'] : null,
            $height, $weight, $bmi, $bmiStatus,
            trim($_POST['symptoms'] ?? ''),
            trim($_POST['assessment'] ?? ''),
            trim($_POST['diagnosis'] ?? ''),
            trim($_POST['treatment'] ?? ''),
            trim($_POST['recommendation'] ?? ''),
            !empty($_POST['need_follow_up']) ? 1 : 0,
            $_POST['follow_up_date'] ?? null,
            $user['id']
        ]);
        $walkInId = $db->lastInsertId();
        
        // Log the action
        logVisitAction($db, $walkInId, 'created', "Walk-in visit #$visitNumber created. Complaint: $chiefComplaint", $user['id']);
        log_activity($user['id'], 'Created walk-in visit', 'walkin', "Visit #$visitNumber");
        
        $db->commit();
        
        json_response(true, [
            'walk_in_id' => $walkInId,
            'visit_number' => $visitNumber,
            'queue_number' => $queueNumber,
            'estimated_wait' => $estimatedWait,
            'bmi' => $bmi,
            'bmi_status' => $bmiStatus,
            'message' => "Walk-in visit #$visitNumber created. Queue: $queueNumber (Est. wait: {$estimatedWait}min)"
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, [], 'Failed to save visit: ' . $e->getMessage());
    }
}

// ================================================================
// ACTION: Update Consultation (nurse completes consultation)
// ================================================================
if ($action === 'update_consultation') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can update consultations.');
    
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid visit ID.');
    
    $stmt = $db->prepare("SELECT * FROM walk_in_visits WHERE id = ?");
    $stmt->execute([$id]);
    $visit = $stmt->fetch();
    if (!$visit) json_response(false, [], 'Visit not found.');
    
    $symptoms = trim($_POST['symptoms'] ?? '');
    $assessment = trim($_POST['assessment'] ?? '');
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $treatment = trim($_POST['treatment'] ?? '');
    $recommendation = trim($_POST['recommendation'] ?? '');
    $needFollowUp = !empty($_POST['need_follow_up']) ? 1 : 0;
    $followUpDate = $_POST['follow_up_date'] ?? null;
    $aiSummary = $_POST['ai_summary'] ?? $visit['ai_summary'];
    $digitalSignature = $_POST['digital_signature'] ?? null;
    
    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            UPDATE walk_in_visits SET
                symptoms = ?, assessment = ?, diagnosis = ?, treatment = ?, recommendation = ?,
                need_follow_up = ?, follow_up_date = ?,
                ai_summary_edited = ?,
                digital_signature = ?,
                consultation_started_at = CASE WHEN consultation_started_at IS NULL THEN NOW() ELSE consultation_started_at END,
                status = CASE WHEN status = 'waiting' THEN 'in_consultation' ELSE status END
            WHERE id = ?
        ");
        $stmt->execute([$symptoms, $assessment, $diagnosis, $treatment, $recommendation,
            $needFollowUp, $followUpDate,
            $aiSummary, $digitalSignature, $id]);
        
        // Update queue status
        if ($visit['queue_id']) {
            $db->prepare("UPDATE queue_numbers SET status = 'serving', called_at = NOW() WHERE id = ?")
               ->execute([$visit['queue_id']]);
        }
        
        logVisitAction($db, $id, 'consultation_updated', "Diagnosis: $diagnosis, Treatment: $treatment", $user['id']);
        log_activity($user['id'], 'Updated consultation', 'walkin', "Visit #{$visit['visit_number']}");
        
        $db->commit();
        json_response(true, [], 'Consultation updated.');
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, [], 'Failed to update: ' . $e->getMessage());
    }
}

// ================================================================
// ACTION: Dispense Medicine
// ================================================================
if ($action === 'dispense_medicine') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can dispense medicine.');
    
    $walkInId = (int)($_POST['walk_in_id'] ?? 0);
    $medicineId = (int)($_POST['medicine_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $dosage = trim($_POST['dosage_instructions'] ?? '');
    
    if (!$walkInId || !$medicineId || $quantity <= 0) json_response(false, [], 'Invalid input.');
    
    // Check medicine stock
    $stmt = $db->prepare("SELECT medicine_name, quantity FROM medicines WHERE id = ?");
    $stmt->execute([$medicineId]);
    $medicine = $stmt->fetch();
    if (!$medicine) json_response(false, [], 'Medicine not found.');
    if ($medicine['quantity'] < $quantity) {
        json_response(false, [], "Insufficient stock! Only {$medicine['quantity']} {$medicine['medicine_name']} available.");
    }
    
    $db->beginTransaction();
    try {
        // Deduct stock
        $db->prepare("UPDATE medicines SET quantity = quantity - ? WHERE id = ?")
           ->execute([$quantity, $medicineId]);
        
        // Record dispensing
        $stmt = $db->prepare("
            INSERT INTO medicine_dispensing (walk_in_id, medicine_id, quantity, dosage_instructions, dispensed_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$walkInId, $medicineId, $quantity, $dosage, $user['id']]);
        
        // Log in medicine_logs
        $db->prepare("INSERT INTO medicine_logs (medicine_id, action, quantity, reason, performed_by) VALUES (?, 'stock_out', ?, ?, ?)")
           ->execute([$medicineId, $quantity, "Dispensed for walk-in #$walkInId", $user['id']]);
        
        logVisitAction($db, $walkInId, 'medicine_dispensed', "Dispensed {$quantity}x {$medicine['medicine_name']}", $user['id']);
        log_activity($user['id'], 'Dispensed medicine', 'walkin', "{$quantity}x {$medicine['medicine_name']}");
        
        $db->commit();
        json_response(true, [
            'new_quantity' => $medicine['quantity'] - $quantity,
            'message' => "{$quantity}x {$medicine['medicine_name']} dispensed."
        ]);
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, [], 'Failed to dispense: ' . $e->getMessage());
    }
}

// ================================================================
// ACTION: Complete Visit
// ================================================================
if ($action === 'complete_visit') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Only nurses can complete visits.');
    
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM walk_in_visits WHERE id = ?");
    $stmt->execute([$id]);
    $visit = $stmt->fetch();
    if (!$visit) json_response(false, [], 'Visit not found.');
    
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE walk_in_visits SET status = 'completed', consultation_ended_at = NOW() WHERE id = ?")
           ->execute([$id]);
        
        // Update queue
        if ($visit['queue_id']) {
            $db->prepare("UPDATE queue_numbers SET status = 'completed', served_at = NOW(), completed_at = NOW() WHERE id = ?")
               ->execute([$visit['queue_id']]);
        }
        
        // If student exists, also save to consultations table
        if ($visit['student_id']) {
            $nurseStmt = $db->prepare("SELECT id FROM nurses WHERE user_id = ?");
            $nurseStmt->execute([$user['id']]);
            $nurseId = $nurseStmt->fetchColumn();
            
            $db->prepare("
                INSERT INTO consultations (student_id, nurse_id, visit_type, consultation_date, chief_complaint, diagnosis, treatment, remarks, follow_up_date)
                VALUES (?, ?, 'walk-in', NOW(), ?, ?, ?, ?, ?)
            ")->execute([
                $visit['student_id'], $nurseId,
                $visit['chief_complaint'],
                $visit['diagnosis'],
                $visit['treatment'],
                $visit['recommendation'],
                $visit['follow_up_date']
            ]);
        }
        
        logVisitAction($db, $id, 'completed', 'Visit completed.', $user['id']);
        log_activity($user['id'], 'Completed walk-in visit', 'walkin', "Visit #{$visit['visit_number']}");
        
        $db->commit();
        json_response(true, ['message' => 'Visit completed successfully.']);
    } catch (Exception $e) {
        $db->rollBack();
        json_response(false, [], 'Failed to complete: ' . $e->getMessage());
    }
}

// ================================================================
// ACTION: Cancel Visit
// ================================================================
if ($action === 'cancel_visit') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("UPDATE walk_in_visits SET status = 'cancelled' WHERE id = ?");
    $stmt->execute([$id]);
    $stmt = $db->prepare("UPDATE queue_numbers SET status = 'cancelled' WHERE id = (SELECT queue_id FROM walk_in_visits WHERE id = ?)");
    $stmt->execute([$id]);
    json_response(true, [], 'Visit cancelled.');
}

// ================================================================
// ACTION: List Today's Queue
// ================================================================
if ($action === 'queue_list') {
    $stmt = $db->query("
        SELECT q.*,
               COALESCE(CONCAT(u.first_name, ' ', u.last_name), q.full_name) AS display_name,
               s.student_number, s.course, s.year_level
        FROM queue_numbers q
        LEFT JOIN students s ON s.id = q.student_id
        LEFT JOIN users u ON u.id = s.user_id
        WHERE DATE(q.created_at) = CURDATE()
        ORDER BY 
            FIELD(q.priority, 'emergency', 'urgent', 'normal'),
            FIELD(q.status, 'waiting', 'serving') DESC,
            q.created_at ASC
    ");
    json_response(true, ['data' => $stmt->fetchAll()]);
}

// ================================================================
// ACTION: List Today's Walk-ins (with search, filter, pagination)
// ================================================================
if ($action === 'list_walkins') {
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $where = "WHERE w.visit_date = CURDATE()";
    $params = [];
    
    if ($status) {
        $where .= " AND w.status = ?";
        $params[] = $status;
    }
    if ($search) {
        $where .= " AND (w.visit_number LIKE ? OR w.chief_complaint LIKE ? OR CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR w.temp_full_name LIKE ?)";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    
    $countStmt = $db->prepare("SELECT COUNT(*) FROM walk_in_visits w LEFT JOIN students s ON s.id = w.student_id LEFT JOIN users u ON u.id = s.user_id $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();
    
    $stmt = $db->prepare("
        SELECT w.*, q.queue_number, q.status AS queue_status,
               COALESCE(CONCAT(u.first_name, ' ', u.last_name), w.temp_full_name) AS patient_name,
               s.student_number, s.course, s.year_level,
               u2.first_name AS nurse_first, u2.last_name AS nurse_last
        FROM walk_in_visits w
        LEFT JOIN queue_numbers q ON q.id = w.queue_id
        LEFT JOIN students s ON s.id = w.student_id
        LEFT JOIN users u ON u.id = s.user_id
        LEFT JOIN users u2 ON u2.id = w.attended_by
        $where
        ORDER BY w.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    json_response(true, ['data' => $rows, 'total' => $total]);
}

// ================================================================
// ACTION: Get Single Visit Details
// ================================================================
if ($action === 'get_visit') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $db->prepare("
        SELECT w.*, q.queue_number, q.status AS queue_status,
               COALESCE(CONCAT(u.first_name, ' ', u.last_name), w.temp_full_name) AS patient_name,
               s.student_number, s.course, s.year_level, s.section,
               u.gender, TIMESTAMPDIFF(YEAR, u.birthdate, CURDATE()) AS age, u.email, u.phone, u.profile_picture,
               u2.first_name AS nurse_first, u2.last_name AS nurse_last
        FROM walk_in_visits w
        LEFT JOIN queue_numbers q ON q.id = w.queue_id
        LEFT JOIN students s ON s.id = w.student_id
        LEFT JOIN users u ON u.id = s.user_id
        LEFT JOIN users u2 ON u2.id = w.attended_by
        WHERE w.id = ?
    ");
    $stmt->execute([$id]);
    $visit = $stmt->fetch();
    if (!$visit) json_response(false, [], 'Visit not found.');
    
    // Get dispensed medicines
    $medStmt = $db->prepare("
        SELECT md.*, m.medicine_name, m.category
        FROM medicine_dispensing md
        JOIN medicines m ON m.id = md.medicine_id
        WHERE md.walk_in_id = ?
    ");
    $medStmt->execute([$id]);
    $visit['medicines'] = $medStmt->fetchAll();
    
    // Get visit logs
    $logStmt = $db->prepare("SELECT * FROM visit_logs WHERE walk_in_id = ? ORDER BY created_at ASC");
    $logStmt->execute([$id]);
    $visit['logs'] = $logStmt->fetchAll();
    
    json_response(true, ['data' => $visit]);
}

// ================================================================
// ACTION: Get Dashboard Stats
// ================================================================
if ($action === 'dashboard_stats') {
    $todayWalkins = $db->query("SELECT COUNT(*) FROM walk_in_visits WHERE visit_date = CURDATE() AND status NOT IN ('cancelled')")->fetchColumn();
    $waiting = $db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'waiting'")->fetchColumn();
    $serving = $db->query("SELECT COUNT(*) FROM queue_numbers WHERE DATE(created_at) = CURDATE() AND status = 'serving'")->fetchColumn();
    $completed = $db->query("SELECT COUNT(*) FROM walk_in_visits WHERE visit_date = CURDATE() AND status = 'completed'")->fetchColumn();
    
    // Medicines dispensed today
    $medsToday = $db->query("
        SELECT COALESCE(SUM(md.quantity), 0) 
        FROM medicine_dispensing md 
        JOIN walk_in_visits w ON w.id = md.walk_in_id 
        WHERE w.visit_date = CURDATE()
    ")->fetchColumn();
    
    // Certificates issued today (placeholder from consultations)
    $certsToday = $db->query("SELECT COUNT(*) FROM certificates WHERE date_issued = CURDATE()")->fetchColumn();
    
    // Most common complaints
    $complaints = $db->query("
        SELECT chief_complaint, COUNT(*) cnt 
        FROM walk_in_visits 
        WHERE visit_date = CURDATE() 
        GROUP BY chief_complaint 
        ORDER BY cnt DESC 
        LIMIT 5
    ")->fetchAll();
    
    json_response(true, [
        'today_walkins' => (int)$todayWalkins,
        'waiting' => (int)$waiting,
        'serving' => (int)$serving,
        'completed' => (int)$completed,
        'medicines_dispensed' => (int)$medsToday,
        'certificates_issued' => (int)$certsToday,
        'common_complaints' => $complaints
    ]);
}

// ================================================================
// ACTION: Generate AI Summary
// ================================================================
if ($action === 'generate_ai_summary') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    
    $stmt = $db->prepare("
        SELECT w.*, COALESCE(CONCAT(u.first_name, ' ', u.last_name), w.temp_full_name) AS patient_name,
               s.student_number, s.course, s.year_level
        FROM walk_in_visits w
        LEFT JOIN students s ON s.id = w.student_id
        LEFT JOIN users u ON u.id = s.user_id
        WHERE w.id = ?
    ");
    $stmt->execute([$id]);
    $visit = $stmt->fetch();
    if (!$visit) json_response(false, [], 'Visit not found.');
    
    // Get dispensed medicines
    $medStmt = $db->prepare("
        SELECT m.medicine_name, md.quantity, md.dosage_instructions
        FROM medicine_dispensing md
        JOIN medicines m ON m.id = md.medicine_id
        WHERE md.walk_in_id = ?
    ");
    $medStmt->execute([$id]);
    $medicines = $medStmt->fetchAll();
    
    // Build AI summary
    $name = $visit['patient_name'] ?? 'Student';
    $complaint = $visit['chief_complaint'] ?? 'Unknown';
    $diagnosis = $visit['diagnosis'] ?? 'Not specified';
    $treatment = $visit['treatment'] ?? 'Not specified';
    $recommendation = $visit['recommendation'] ?? 'Rest and monitor symptoms.';
    $bmi = $visit['bmi'] ?? 'N/A';
    $bmiStatus = $visit['bmi_status'] ?? 'N/A';
    
    $medList = '';
    if (count($medicines) > 0) {
        $medList = "\n\n**Medicine Dispensed:**\n";
        foreach ($medicines as $m) {
            $medList .= "- {$m['medicine_name']} ({$m['quantity']}) - {$m['dosage_instructions']}\n";
        }
    }
    
    $summary = "**Consultation Summary**\n\n";
    $summary .= "{$name} visited the BPC School Clinic as a **Walk-in Patient** on " . date('F d, Y') . ".\n\n";
    $summary .= "**Chief Complaint:** {$complaint}\n";
    $summary .= "**Diagnosis:** {$diagnosis}\n";
    $summary .= "**Treatment Given:** {$treatment}\n";
    $summary .= "**BMI:** {$bmi} ({$bmiStatus})\n";
    $summary .= $medList;
    $summary .= "\n**Recommendation:** {$recommendation}\n\n";
    $summary .= "*Note: This summary is for educational purposes only and does not constitute a medical diagnosis. Please consult a qualified healthcare professional for medical advice.*";
    
    // Save the AI summary
    $db->prepare("UPDATE walk_in_visits SET ai_summary = ? WHERE id = ?")->execute([$summary, $id]);
    
    json_response(true, ['summary' => $summary]);
}

// ================================================================
// ACTION: Get available inventory for dispensing
// ================================================================
if ($action === 'inventory_list') {
    $stmt = $db->query("SELECT id, medicine_name, category, quantity, unit, reorder_level, expiration_date,
                         CASE WHEN expiration_date IS NOT NULL AND expiration_date <= CURDATE() THEN 1 ELSE 0 END AS is_expired,
                         CASE WHEN quantity <= 0 THEN 1 ELSE 0 END AS is_out_of_stock
                         FROM medicines ORDER BY medicine_name");
    json_response(true, ['data' => $stmt->fetchAll()]);
}

// ================================================================
// Unknown action
// ================================================================
json_response(false, [], 'Unknown action: ' . $action);

