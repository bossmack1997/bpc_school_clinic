<?php
require_once __DIR__ . '/../config/config.php';
require_role(['nurse','admin']);
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'search') {
    $q = '%' . ($_GET['q'] ?? '') . '%';
    // Students
    $stmt = $db->prepare("SELECT s.id, s.student_number, s.course, s.year_level, s.section, u.first_name, u.last_name, u.email, u.phone, u.profile_picture, 'student' AS patient_type FROM students s JOIN users u ON u.id=s.user_id WHERE s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? ORDER BY u.last_name LIMIT 20");
    $stmt->execute([$q, $q, $q]);
    $data = $stmt->fetchAll();
    // Teachers/Staff
    $stmt2 = $db->prepare("SELECT t.id, t.employee_id AS student_number, t.department AS course, '' AS year_level, 'Teacher/Staff' AS section, t.first_name, t.last_name, t.email, t.phone, u.profile_picture, 'teacher' AS patient_type FROM teachers t JOIN users u ON u.id=t.user_id WHERE t.employee_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? ORDER BY u.last_name LIMIT 20");
    $stmt2->execute([$q, $q, $q]);
    $data = array_merge($data, $stmt2->fetchAll());
    json_response(true, ['data' => $data]);
}

if ($action === 'walkin_start') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized. Only nurses can start walk-in consultations.');
    $patientType = trim($_POST['patient_type'] ?? 'student');
    $studentId = (int)($_POST['student_id'] ?? 0);
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $chiefComplaint = trim($_POST['chief_complaint'] ?? '');
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $treatment = trim($_POST['treatment'] ?? '');
    $prescription = trim($_POST['prescription'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $followUp = $_POST['follow_up_date'] ?? null;

    if (!in_array($patientType, ['student', 'teacher'], true)) json_response(false, [], 'Invalid patient type.');
    if (($patientType === 'student' && $studentId <= 0) || ($patientType === 'teacher' && $teacherId <= 0) || !$chiefComplaint) {
        json_response(false, [], 'Patient and chief complaint are required.');
    }

    // Get nurse record
    $nurseStmt = $db->prepare("SELECT id FROM nurses WHERE user_id=?");
    $nurseStmt->execute([$user['id']]);
    $nurseId = $nurseStmt->fetchColumn();

    if ($patientType === 'teacher') {
        $stmt = $db->prepare("INSERT INTO consultations (teacher_id, patient_type, nurse_id, visit_type, consultation_date, chief_complaint, diagnosis, treatment, prescription, remarks, follow_up_date) VALUES (?, 'teacher', ?, 'walk-in', NOW(), ?,?,?,?,?,?)");
        $stmt->execute([$teacherId, $nurseId ?: null, $chiefComplaint, $diagnosis ?: null, $treatment ?: null, $prescription ?: null, $remarks ?: null, $followUp]);
        $consultationId = $db->lastInsertId();
        $teacherUserStmt = $db->prepare("SELECT u.id FROM teachers t JOIN users u ON u.id=t.user_id WHERE t.id=?");
        $teacherUserStmt->execute([$teacherId]);
        if ($tid = $teacherUserStmt->fetchColumn()) {
            push_notification($tid, 'consultation', 'Walk-in Consultation', 'A walk-in consultation was recorded for you.', APP_URL . '/pages/adviser/my_qr.php');
        }
        log_activity($user['id'], 'Walk-in consultation (Staff)', 'consultations', "Teacher #$teacherId");
        json_response(true, ['id' => $consultationId], 'Walk-in consultation recorded.');
    }

    $stmt = $db->prepare("INSERT INTO consultations (student_id, nurse_id, visit_type, consultation_date, chief_complaint, diagnosis, treatment, prescription, remarks, follow_up_date) VALUES (?,?, 'walk-in', NOW(), ?,?,?,?,?,?)");
    $stmt->execute([$studentId, $nurseId ?: null, $chiefComplaint, $diagnosis ?: null, $treatment ?: null, $prescription ?: null, $remarks ?: null, $followUp]);

    $consultationId = $db->lastInsertId();

    // Notify student
    $studentUserStmt = $db->prepare("SELECT u.id FROM students s JOIN users u ON u.id=s.user_id WHERE s.id=?");
    $studentUserStmt->execute([$studentId]);
    if ($sid = $studentUserStmt->fetchColumn()) {
        push_notification($sid, 'consultation', 'Walk-in Consultation', 'A walk-in consultation was recorded for you.', APP_URL . '/pages/student/health_monitoring.php');
    }

    log_activity($user['id'], 'Walk-in consultation', 'consultations', "Student #$studentId");
    json_response(true, ['id' => $consultationId], 'Walk-in consultation recorded.');
}

if ($action === 'patient_history') {
    $patientType = trim($_GET['patient_type'] ?? 'student');
    $studentId = (int)($_GET['student_id'] ?? 0);
    $teacherId = (int)($_GET['teacher_id'] ?? 0);
    if ($patientType === 'teacher') {
        if ($teacherId <= 0) json_response(false, [], 'Invalid teacher.');
    } elseif ($studentId <= 0) {
        json_response(false, [], 'Invalid student.');
    }

    // IDOR protection: students/advisers can only access their own history
    if ($user['role'] === 'student') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user['id']]);
        $myStudentId = (int)$stmt->fetchColumn();
        if ($patientType !== 'student' || $myStudentId <= 0 || $studentId !== $myStudentId) {
            json_response(false, [], 'Not authorized to view this patient history.');
        }
    }

    if ($patientType === 'teacher') {
        $stmt = $db->prepare("SELECT c.*, n.license_number FROM consultations c LEFT JOIN nurses n ON n.id=c.nurse_id WHERE c.teacher_id=? AND c.patient_type='teacher' ORDER BY c.consultation_date DESC");
        $stmt->execute([$teacherId]);
        $consultations = $stmt->fetchAll();
        $vaccinations = [];
        $appointments = [];
        $health = [];
        json_response(true, [
            'consultations' => $consultations,
            'health_monitoring' => $health,
            'vaccinations' => $vaccinations,
            'appointments' => $appointments,
            'patient_type' => 'teacher'
        ]);
    }

    $stmt = $db->prepare("SELECT c.*, n.license_number FROM consultations c LEFT JOIN nurses n ON n.id=c.nurse_id WHERE c.student_id=? ORDER BY c.consultation_date DESC");
    $stmt->execute([$studentId]);
    $consultations = $stmt->fetchAll();

    $stmt2 = $db->prepare("SELECT *, CASE WHEN recorded_by IS NULL THEN 'self_recorded' ELSE 'nurse_assessment' END AS record_type FROM health_monitoring WHERE student_id=? ORDER BY recorded_at DESC LIMIT 10");
    $stmt2->execute([$studentId]);
    $health = $stmt2->fetchAll();

    $stmt3 = $db->prepare("SELECT * FROM vaccinations WHERE student_id=? ORDER BY date_administered DESC");
    $stmt3->execute([$studentId]);
    $vaccinations = $stmt3->fetchAll();

    $stmt4 = $db->prepare("SELECT * FROM appointments WHERE student_id=? ORDER BY appointment_date DESC LIMIT 10");
    $stmt4->execute([$studentId]);
    $appointments = $stmt4->fetchAll();

    json_response(true, [
        'consultations' => $consultations,
        'health_monitoring' => $health,
        'vaccinations' => $vaccinations,
        'appointments' => $appointments,
        'patient_type' => 'student'
    ]);
}

if ($action === 'today_patients') {
    $stmt = $db->query("
        SELECT c.*, u.first_name, u.last_name, s.student_number, s.course, s.year_level
        FROM consultations c
        JOIN students s ON s.id = c.student_id
        JOIN users u ON u.id = s.user_id
        WHERE DATE(c.consultation_date) = CURDATE()
        ORDER BY c.consultation_date DESC
    ");
    json_response(true, ['data' => $stmt->fetchAll()]);
}

// Teacher/Staff viewing their OWN consultations (My Clinic)
if ($action === 'my_consultations') {
    $role = $user['role'] ?? '';
    if (!in_array($role, ['adviser', 'admin'], true)) json_response(false, [], 'Access denied.');
    $teacherId = 0;
    if ($role === 'adviser') {
        $stmt = $db->prepare("SELECT id FROM teachers WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user['id']]);
        $teacherId = (int)$stmt->fetchColumn();
    } else {
        $teacherId = (int)($_GET['teacher_id'] ?? 0);
    }
    if ($teacherId <= 0) json_response(true, ['consultations' => []]);
    $stmt = $db->prepare("SELECT c.*, n.license_number FROM consultations c LEFT JOIN nurses n ON n.id=c.nurse_id WHERE c.teacher_id=? AND c.patient_type='teacher' ORDER BY c.consultation_date DESC");
    $stmt->execute([$teacherId]);
    json_response(true, ['consultations' => $stmt->fetchAll(), 'patient_type' => 'teacher']);
}

// Admin editing student enrollment (course, year_level, section, student_number)
if ($action === 'update_enrollment') {
    if ($user['role'] !== 'admin') json_response(false, [], 'Admin access required.');
    csrf_verify_or_die();

    $studentId = (int)($_POST['student_id'] ?? 0);
    $course = trim($_POST['course'] ?? '');
    $yearLevel = trim($_POST['year_level'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $studentNumber = trim($_POST['student_number'] ?? '');

    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    $stmt = $db->prepare("SELECT s.id, s.user_id, s.student_number, u.first_name, u.last_name FROM students s JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1");
    $stmt->execute([$studentId]);
    $st = $stmt->fetch();
    if (!$st) json_response(false, [], 'Student not found.');

    // Duplicate student number check
    if ($studentNumber !== '' && $studentNumber !== $st['student_number']) {
        $chk = $db->prepare("SELECT id FROM students WHERE student_number = ? AND id <> ? LIMIT 1");
        $chk->execute([$studentNumber, $studentId]);
        if ($chk->fetch()) {
            json_response(false, [], 'Student number is already in use by another student.');
        }
    }

    $upd = $db->prepare("
        UPDATE students
        SET course = ?, year_level = ?, section = ?, student_number = ?
        WHERE id = ?
    ");
    $upd->execute([
        $course ?: null,
        $yearLevel ?: null,
        $section ?: null,
        $studentNumber ?: $st['student_number'],
        $studentId
    ]);

    log_activity($user['id'], 'Updated student enrollment', 'students', "Student #$studentId ({$st['first_name']} {$st['last_name']}) -> Course: $course, Year: $yearLevel, Section: $section");
    json_response(true, [], "Enrollment updated successfully for {$st['first_name']} {$st['last_name']}.");
}

json_response(false, [], 'Unknown action');

