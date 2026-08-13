<?php
require_once __DIR__ . '/../config/config.php';
require_role(['nurse','admin']);
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'search') {
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $stmt = $db->prepare("SELECT s.id, s.student_number, s.course, s.year_level, s.section, u.first_name, u.last_name, u.email, u.phone, u.profile_picture FROM students s JOIN users u ON u.id=s.user_id WHERE s.student_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? ORDER BY u.last_name LIMIT 20");
    $stmt->execute([$q, $q, $q]);
    json_response(true, ['data' => $stmt->fetchAll()]);
}

// ================================================================
// IMPORT — bulk import students from CSV (auto-generates QR)
// Expected columns:
//   Student Number, First Name, Last Name, Course, Year Level,
//   Section, Email, Username, Password, Gender, Birthdate, Phone
// ================================================================
if ($action === 'import_students') {
    csrf_verify_or_die();
    if ($user['role'] !== 'admin') json_response(false, [], 'Only admins can import students.');

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_response(false, [], 'Please choose a CSV file.');
    }

    $tmp = $_FILES['file']['tmp_name'];
    $handle = fopen($tmp, 'r');
    if (!$handle) json_response(false, [], 'Cannot open file.');

    $data = [];
    while (($line = fgetcsv($handle)) !== false) {
        $data[] = array_map('trim', $line);
    }
    fclose($handle);

    if (count($data) < 2) json_response(false, [], 'File appears empty.');

    // Skip the header row (row 0)
    $rows = array_slice($data, 1);

    $inserted = 0;
    $duplicates = 0;
    $errors = [];
    $skipped = [];

    foreach ($rows as $idx => $row) {
        $lineNo = $idx + 2;
        $studentNo    = (string)($row[0] ?? '');
        $firstName    = (string)($row[1] ?? '');
        $lastName     = (string)($row[2] ?? '');
        $course       = (string)($row[3] ?? '');
        $yearLevel    = (string)($row[4] ?? '');
        $section      = (string)($row[5] ?? '');
        $email        = (string)($row[6] ?? '');
        $username     = (string)($row[7] ?? '');
        $password     = (string)($row[8] ?? '');
        $gender       = (string)($row[9] ?? '');
        $birthdate    = (string)($row[10] ?? '');
        $phone        = (string)($row[11] ?? '');

        if (!$studentNo || !$firstName || !$lastName) {
            $errors[] = ['line' => $lineNo, 'reason' => 'Student number, first name, and last name are required.'];
            continue;
        }
        if (!$email) {
            $errors[] = ['line' => $lineNo, 'reason' => 'Email is required.'];
            continue;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['line' => $lineNo, 'reason' => 'Invalid email.'];
            continue;
        }
        if (!$username) {
            $errors[] = ['line' => $lineNo, 'reason' => 'Username is required.'];
            continue;
        }
        if (strlen($password) < 8) {
            $errors[] = ['line' => $lineNo, 'reason' => 'Password must be at least 8 characters.'];
            continue;
        }

        // Duplicate checks
        $dupStmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $dupStmt->execute([$username, $email]);
        if ($dupStmt->fetch()) {
            $duplicates++;
            $skipped[] = ['line' => $lineNo, 'student' => $studentNo, 'reason' => 'Username or email already exists.'];
            continue;
        }
        $dupStmt2 = $db->prepare("SELECT id FROM students WHERE student_number = ?");
        $dupStmt2->execute([$studentNo]);
        if ($dupStmt2->fetch()) {
            $duplicates++;
            $skipped[] = ['line' => $lineNo, 'student' => $studentNo, 'reason' => 'Student number already exists.'];
            continue;
        }

try {
            $db->beginTransaction();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insUser = $db->prepare(
                "INSERT INTO users (role, username, email, password_hash, first_name, last_name, phone, status)
                 VALUES ('student', ?, ?, ?, ?, ?, ?, 'active')"
            );
            $insUser->execute([$username, $email, $hash, $firstName, $lastName, $phone ?: null]);
            $userId = $db->lastInsertId();

            $insStudent = $db->prepare(
                "INSERT INTO students (user_id, student_number, course, year_level, section, gender, birthdate) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $insStudent->execute([$userId, $studentNo, $course ?: null, $yearLevel ?: null, $section ?: null, $gender ?: null, $birthdate ?: null]);
            $studentId = $db->lastInsertId();

            // Auto-generate permanent QR
            try {
                ensure_student_qr($db, $studentId, $user['id']);
            } catch (Exception $qrE) {
                error_log('QR generation on import failed (line ' . $lineNo . '): ' . $qrE->getMessage());
            }

            $db->commit();
            $inserted++;
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = ['line' => $lineNo, 'reason' => $e->getMessage()];
        }
    }

    log_activity($user['id'], 'Imported students', 'students', "$inserted student(s) imported");
    json_response(true, [
        'inserted' => $inserted,
        'duplicates' => $duplicates,
        'total' => count($rows),
        'errors' => $errors,
        'skipped' => $skipped,
    ], "$inserted student(s) imported successfully.");
}

if ($action === 'walkin_start') {
    csrf_verify_or_die();
    if ($user['role'] !== 'nurse') json_response(false, [], 'Not authorized. Only nurses can start walk-in consultations.');
    $studentId = (int)($_POST['student_id'] ?? 0);
    $chiefComplaint = trim($_POST['chief_complaint'] ?? '');
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $treatment = trim($_POST['treatment'] ?? '');
    $prescription = trim($_POST['prescription'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $followUp = $_POST['follow_up_date'] ?? null;

    if ($studentId <= 0 || !$chiefComplaint) json_response(false, [], 'Student and chief complaint are required.');

    // Get nurse record
    $nurseStmt = $db->prepare("SELECT id FROM nurses WHERE user_id=?");
    $nurseStmt->execute([$user['id']]);
    $nurseId = $nurseStmt->fetchColumn();

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
    $studentId = (int)($_GET['student_id'] ?? 0);
    if ($studentId <= 0) json_response(false, [], 'Invalid student.');

    // IDOR protection: students can only access their own history
    if ($user['role'] === 'student') {
        $stmt = $db->prepare("SELECT id FROM students WHERE user_id=?");
        $stmt->execute([$user['id']]);
        $myStudentId = (int)$stmt->fetchColumn();
        if ($myStudentId <= 0 || $studentId !== $myStudentId) {
            json_response(false, [], 'Not authorized to view this patient history.');
        }
    }

    $stmt = $db->prepare("SELECT c.*, n.license_number FROM consultations c LEFT JOIN nurses n ON n.id=c.nurse_id WHERE c.student_id=? ORDER BY c.consultation_date DESC");
    $stmt->execute([$studentId]);
    $consultations = $stmt->fetchAll();

    $stmt2 = $db->prepare("SELECT * FROM health_monitoring WHERE student_id=? ORDER BY recorded_at DESC LIMIT 10");
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
        'appointments' => $appointments
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

json_response(false, [], 'Unknown action');
