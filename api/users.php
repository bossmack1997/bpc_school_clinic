<?php
require_once __DIR__ . '/../config/config.php';
require_role('admin');
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'approve') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['status'] === 'active') json_response(false, [], 'Account is already active.');

    // -----------------------------------------------------------------
    // Teacher / Adviser self-registration: activate + provision staff
    // record + teacher QR (advisory sections are chosen by the teacher
    // themselves in the portal — NOT assigned here).
    // -----------------------------------------------------------------
    if (in_array($target['role'], ['adviser', 'teacher'], true)) {
        try {
            $db->beginTransaction();

            // Mark the matching pending request approved (if any)
            $db->prepare("
                UPDATE teacher_registration_requests
                SET status = 'approved', reviewed_by = ?, reviewed_at = NOW()
                WHERE user_id = ? AND status = 'pending'
            ")->execute([$user['id'], $id]);

            // Provision the teachers patient record (used for QR/consultations)
            $tChk = $db->prepare("SELECT id, employee_id FROM teachers WHERE user_id = ? LIMIT 1");
            $tChk->execute([$id]);
            $teacher = $tChk->fetch();
            if (!$teacher) {
                $reqStmt = $db->prepare("SELECT teacher_id_number FROM teacher_registration_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                $reqStmt->execute([$id]);
                $empId = $reqStmt->fetchColumn();
                $db->prepare("INSERT INTO teachers (user_id, employee_id, first_name, middle_name, last_name, email, phone)
                              VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([$id, $empId ?: null, $target['first_name'], $target['middle_name'] ?? null, $target['last_name'], $target['email'], $target['phone'] ?? null]);
                $teacherId = (int)$db->lastInsertId();
            } else {
                $teacherId = (int)$teacher['id'];
            }

            ensure_teacher_qr($db, $teacherId, $user['id']);

            $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Staff account approval failed: ' . $e->getMessage());
            json_response(false, [], 'Approval failed due to a server error. Please try again.');
        }

        log_activity($user['id'], 'Approved ' . $target['role'] . ' account', 'users', "User ID $id");
        json_response(true, [], ucfirst($target['role']) . ' account approved successfully.');
    }

    $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);

    // Auto-generate a permanent QR for approved student accounts.
    if ($target['role'] === 'student') {
        try {
            $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
            $stmt->execute([$id]);
            $studentId = $stmt->fetchColumn();
            if ($studentId) {
                ensure_student_qr($db, (int)$studentId, $user['id']);
            }
        } catch (Exception $e) {
            error_log('QR generation on account approval failed: ' . $e->getMessage());
        }
    }

    log_activity($user['id'], 'Approved user account', 'users', "User ID $id ({$target['role']})");
    json_response(true, [], 'Account approved successfully.');
}

if ($action === 'reject') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['status'] === 'rejected') json_response(false, [], 'Account is already rejected.');

    $db->prepare("UPDATE users SET status = 'rejected' WHERE id = ?")->execute([$id]);

    // Mark any pending self-registration request as rejected too
    $db->prepare("
        UPDATE teacher_registration_requests
        SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW()
        WHERE user_id = ? AND status = 'pending'
    ")->execute([$user['id'], $id]);

    log_activity($user['id'], 'Rejected user account', 'users', "User ID $id ({$target['role']})");
    json_response(true, [], 'Account rejected successfully.');
}

if ($action === 'deactivate') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['role'] === 'admin') json_response(false, [], 'Cannot deactivate an admin account.');

    $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$id]);
    log_activity($user['id'], 'Deactivated user account', 'users', "User ID $id ({$target['role']})");
    json_response(true, [], 'Account deactivated successfully.');
}

if ($action === 'bulk_approve') {
    csrf_verify_or_die();
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), function ($v) { return $v > 0; }));
    if (!$ids) json_response(false, [], 'No accounts selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, role, status FROM users WHERE id IN ($placeholders) AND role != 'admin'");
    $stmt->execute($ids);
    $targets = $stmt->fetchAll();

    if (!$targets) json_response(false, [], 'No eligible accounts selected.');

    $approveIds = [];
    foreach ($targets as $target) {
        if ($target['status'] !== 'active') {
            $approveIds[] = (int)$target['id'];
        }
    }

    if (!$approveIds) json_response(false, [], 'Selected accounts are already active.');

    $approvePlaceholders = implode(',', array_fill(0, count($approveIds), '?'));
    $db->prepare("UPDATE users SET status = 'active' WHERE id IN ($approvePlaceholders)")->execute($approveIds);

    foreach ($targets as $target) {
        if ($target['role'] === 'student') {
            try {
                $stmt = $db->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
                $stmt->execute([$target['id']]);
                $studentId = $stmt->fetchColumn();
                if ($studentId) {
                    ensure_student_qr($db, (int)$studentId, $user['id']);
                }
            } catch (Exception $e) {
                error_log('QR generation on bulk approval failed: ' . $e->getMessage());
            }
        }
        log_activity($user['id'], 'Bulk approved user account', 'users', "User ID {$target['id']} ({$target['role']})");
    }

    json_response(true, [], count($approveIds) . ' account' . (count($approveIds) > 1 ? 's' : '') . ' approved successfully.');
}

if ($action === 'create_nurse') {
    csrf_verify_or_die();
    $firstName     = trim($_POST['first_name'] ?? '');
    $middleName    = trim($_POST['middle_name'] ?? '');
    $lastName      = trim($_POST['last_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $username      = trim($_POST['username'] ?? '');
    $password      = $_POST['password'] ?? '';
    $employeeId    = trim($_POST['employee_id'] ?? '');
    $licenseNumber = trim($_POST['license_number'] ?? '');
    $contact       = trim($_POST['contact_number'] ?? '');
    $gender        = trim($_POST['gender'] ?? '');
    $dob           = trim($_POST['date_of_birth'] ?? '');

    if (!$firstName || !$lastName || !$email || !$username || !$password) {
        json_response(false, [], 'First name, last name, email, username, and password are required.');
    }
    if (!validate_email($email)) {
        json_response(false, [], 'Please enter a valid email address.');
    }
    if (!validate_username($username)) {
        json_response(false, [], 'Username must be 3-50 characters (letters, numbers, underscores only).');
    }
    $pwErrors = validate_password_strength($password);
    if (!empty($pwErrors)) {
        json_response(false, [], implode(' ', $pwErrors));
    }

    $chk = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
    $chk->execute([$username, $email]);
    if ($chk->fetch()) {
        json_response(false, [], 'A user with this username or email already exists.');
    }

    if ($employeeId !== '') {
        $empChk = $db->prepare("SELECT id FROM nurses WHERE employee_id = ? LIMIT 1");
        $empChk->execute([$employeeId]);
        if ($empChk->fetch()) {
            json_response(false, [], 'A nurse with this Employee ID already exists.');
        }
    }

    try {
        $db->beginTransaction();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO users (role, username, email, password_hash, first_name, middle_name, last_name, gender, date_of_birth, phone, profile_picture, status)
            VALUES ('nurse', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'assets/images/default-avatar.png', 'active')
        ");
        $stmt->execute([
            $username,
            $email,
            $hash,
            $firstName,
            $middleName ?: null,
            $lastName,
            $gender ?: null,
            $dob ?: null,
            $contact ?: null,
        ]);
        $newUserId = (int)$db->lastInsertId();

        $stmt = $db->prepare("
            INSERT INTO nurses (user_id, employee_id, license_number, gender, date_of_birth, contact_number, profile_picture)
            VALUES (?, ?, ?, ?, ?, ?, 'assets/images/default-avatar.png')
        ");
        $stmt->execute([
            $newUserId,
            $employeeId ?: null,
            $licenseNumber ?: null,
            $gender ?: null,
            $dob ?: null,
            $contact ?: null,
        ]);

        $db->commit();
        log_activity($user['id'], 'Created nurse account', 'users', "Nurse #$newUserId ({$firstName} {$lastName})");
        json_response(true, ['user_id' => $newUserId], "Nurse account for {$firstName} {$lastName} created successfully.");
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Nurse creation error: ' . $e->getMessage());
        json_response(false, [], 'Failed to create nurse account: ' . $e->getMessage());
    }
}

json_response(false, [], 'Unknown action');