<?php
require_once __DIR__ . '/../config/config.php';
require_role('admin');
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

// Co-administrator permissions must be enforced consistently for every
// account-management endpoint, including legacy actions still used by pages.
$permissionByAction = [
    'get_user' => 'manage_users',
    'approve' => 'approve_users',
    'reject' => 'approve_users',
    'deactivate' => 'manage_users',
    'activate' => 'manage_users',
    'update_user' => 'manage_users',
    'bulk_approve' => 'approve_users',
];
if (isset($permissionByAction[$action]) && !is_primary_admin($user)) {
    $hasPermission = has_admin_permission($permissionByAction[$action], $user, $db);
    if (!$hasPermission && in_array($action, ['get_user', 'approve', 'reject', 'bulk_approve'], true)) {
        $hasPermission = has_admin_permission('manage_users', $user, $db);
    }
    if (!$hasPermission) {
        http_response_code(403); json_response(false, [], 'Access denied: you do not have permission to manage user accounts.');
    }
}

if (!is_primary_admin($user) && !isset($permissionByAction[$action])
    && !in_array($action,['create_admin_account','list_admins','update_admin_status','delete_admin'],true)
    && !has_admin_permission('manage_users',$user,$db) && !has_admin_permission('approve_users',$user,$db)) admin_access_denied();
if(in_array($action,['create_admin_account','list_admins','update_admin_status','delete_admin'],true))require_primary_admin();

// Routine access changes are retired; documented security exceptions are the only review path.
if(in_array($action,['approve','reject','activate','deactivate','bulk_approve','update_admin_status','delete_admin'],true)) {
    http_response_code(410);json_response(false,[],'Use the authorized Account Security Exceptions process with a reason and evidence reference.');
}
if(!in_array($action,['get_user','list_admins'],true) && $_SERVER['REQUEST_METHOD']!=='POST') {
    http_response_code(405);json_response(false,[],'Use POST.');
}

// Obsolete manual user-creation endpoints must be strictly blocked.
// All users (Student, Nurse, Teacher, Admin) must use institutional self-registration.
$blockedCreations = [
    'create_doctor', 'create_nurse', 'create_student', 'create_teacher',
    'create_admin', 'create_user', 'add_nurse', 'add_doctor', 'add_teacher', 'add_student'
];
if (in_array($action, $blockedCreations, true)) {
    if ($action === 'create_doctor' || $action === 'add_doctor') {
        json_response(false, [], 'The doctor role has been retired. Create or approve a Nurse account instead.');
    }
    json_response(false, [], 'Manual account creation is disabled. Users must register through the dedicated institutional self-registration portal and undergo verification.');
}

if ($action === 'create_admin_account') {
    csrf_verify_or_die();

    // Only Primary Administrators can create new admin accounts
    if (!is_primary_admin($user)) {
        http_response_code(403); json_response(false, [], 'Access denied: Only the Primary Administrator can create admin accounts.');
    }

    $firstName     = trim($_POST['first_name'] ?? '');
    $middleName    = trim($_POST['middle_name'] ?? '');
    $lastName      = trim($_POST['last_name'] ?? '');
    $email         = strtolower(trim($_POST['email'] ?? ''));
    $username      = trim($_POST['username'] ?? '');
    $password      = $_POST['password'] ?? '';
    $confirmPass   = $_POST['confirm_password'] ?? '';
    $contact       = trim($_POST['contact_number'] ?? '');
    $status        = trim($_POST['status'] ?? 'active');

    // Validation
    if ($firstName === '' || $lastName === '') {
        json_response(false, [], 'First name and Last name are required.');
    }

    if ($email === '' || !validate_email($email)) {
        json_response(false, [], 'A valid email address is required.');
    }

    if ($username === '' || !validate_username($username)) {
        json_response(false, [], 'Username must be 3-50 characters (letters, numbers, underscores only).');
    }

    if ($password === '') {
        json_response(false, [], 'Password is required.');
    }
    if ($password !== $confirmPass) {
        json_response(false, [], 'Passwords do not match.');
    }
    $pwErrors = validate_password_strength($password);
    if (!empty($pwErrors)) {
        json_response(false, [], implode(' ', $pwErrors));
    }

    if (!in_array($status, ['active', 'inactive', 'pending'], true)) {
        $status = 'active';
    }

    // Uniqueness checks
    $chk = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
    $chk->execute([$username, $email]);
    if ($chk->fetch()) {
        json_response(false, [], 'This account already exists.');
    }

    try {
        $db->beginTransaction();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO users (
                role, admin_type, username, email, password_hash,
                first_name, middle_name, last_name, phone,
                profile_picture, status, email_verified,
                registration_status, validation_reason, validation_confidence, risk_level
            ) VALUES (
                'admin', 'co_admin', ?, ?, ?,
                ?, ?, ?, ?,
                'assets/images/default-avatar.png', ?, 1,
                'approved', 'Administrator account created by Primary Administrator', 1.00, 'low'
            )
        ");
        $stmt->execute([
            $username,
            $email,
            $hash,
            $firstName,
            $middleName ?: null,
            $lastName,
            $contact ?: null,
            $status,
        ]);
        $newAdminId = (int)$db->lastInsertId();

        log_activity($user['id'], 'Created administrator account', 'admin', "Admin account #$newAdminId created for {$firstName} {$lastName} (@{$username})");

        $db->commit();
        json_response(true, ['user_id' => $newAdminId], "Administrator account for {$firstName} {$lastName} created successfully.");

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Admin account creation error: ' . $e->getMessage());
        json_response(false, [], safe_api_error($e, 'Failed to create the administrator account. Please try again.', 'users/create_admin'));
    }
}

if ($action === 'list_admins') {
    if (!is_primary_admin($user)) {
        http_response_code(403); json_response(false, [], 'Access denied.');
    }

    $stmt = $db->query("
        SELECT id, role, admin_type, first_name, last_name, email, username,
               phone, status, created_at, last_login
        FROM users
        WHERE role = 'admin'
        ORDER BY admin_type DESC, created_at DESC
    ");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_response(true, ['data' => $admins]);
}

if ($action === 'update_admin_status') {
    csrf_verify_or_die();

    if (!is_primary_admin($user)) {
        http_response_code(403); json_response(false, [], 'Access denied: Only the Primary Administrator can modify admin accounts.');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid admin ID.');

    // Prevent modifying the primary admin
    $stmt = $db->prepare("SELECT id, admin_type FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'Admin account not found.');
    if ($target['admin_type'] === 'primary_admin') json_response(false, [], 'The Primary Administrator account cannot be modified.');

    $newStatus = trim($_POST['new_status'] ?? '');
    if (!in_array($newStatus, ['active', 'inactive', 'pending'], true)) {
        json_response(false, [], 'Invalid status value.');
    }

    $db->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
    log_activity($user['id'], 'Updated admin account status', 'admin', "Admin ID $id status set to {$newStatus}");
    json_response(true, [], 'Admin account status updated successfully.');
}

if ($action === 'delete_admin') {
    csrf_verify_or_die();

    if (!is_primary_admin($user)) {
        http_response_code(403); json_response(false, [], 'Access denied: Only the Primary Administrator can delete admin accounts.');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid admin ID.');

    // Prevent deleting the primary admin
    $stmt = $db->prepare("SELECT id, admin_type FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'Admin account not found.');
    if ($target['admin_type'] === 'primary_admin') {
        json_response(false, [], 'The Primary Administrator account cannot be deleted.');
    }

    $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$id]);
    log_activity($user['id'], 'Disabled admin account', 'admin', "Admin ID $id disabled");
    json_response(true, [], 'Admin account disabled successfully.');
}

if (!in_array($action, ['get_user', 'update_user'], true)) {
    if (empty($action)) json_response(false, [], 'No action specified.');
    json_response(false, [], 'Unknown action.');
}if ($action === 'get_user') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("
        SELECT u.*,
               s.student_number, s.course, s.year_level, s.section, s.valid_id_path AS student_id_path,
               n.employee_id AS nurse_employee_id, n.license_number AS nurse_license_number, n.valid_id_path AS nurse_id_path,
               t.employee_id AS teacher_employee_id, t.department AS teacher_department,
               rev.username AS reviewer_username,
               CONCAT(rev.first_name, ' ', rev.last_name) AS reviewer_name
        FROM users u
        LEFT JOIN students s ON s.user_id = u.id
        LEFT JOIN nurses n ON n.user_id = u.id
        LEFT JOIN teachers t ON t.user_id = u.id
        LEFT JOIN users rev ON rev.id = u.reviewed_by
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['role'] === 'admin' && !is_primary_admin($user)) {
        http_response_code(403); json_response(false, [], 'Access denied: you do not have permission to view administrator accounts.');
    }

    unset($target['password_hash'], $target['otp_hash'], $target['remember_token'], $target['reset_token']);
    json_response(true, ['data' => $target]);
}

json_response(false, [], 'Unknown action.');

if ($action === 'create_doctor') {
    csrf_verify_or_die();
    $firstName     = trim($_POST['first_name'] ?? '');
    $middleName    = trim($_POST['middle_name'] ?? '');
    $lastName      = trim($_POST['last_name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $username      = trim($_POST['username'] ?? '');
    $password      = $_POST['password'] ?? '';
    $employeeId    = trim($_POST['employee_id'] ?? '');
    $licenseNumber = trim($_POST['license_number'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $contact       = trim($_POST['contact_number'] ?? '');
    $gender        = trim($_POST['gender'] ?? '');
    $dob           = trim($_POST['date_of_birth'] ?? '');

    if (!$firstName || !$lastName || !$email || !$username || !$password || !$employeeId || !$licenseNumber) {
        json_response(false, [], 'First name, last name, email, username, password, Doctor ID, and PRC License are required.');
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

    $empChk = $db->prepare("SELECT id FROM doctors WHERE employee_id = ? OR license_number = ? LIMIT 1");
    $empChk->execute([$employeeId, $licenseNumber]);
    if ($empChk->fetch()) {
        json_response(false, [], 'A doctor with this Employee ID or PRC License Number already exists.');
    }

    try {
        $db->beginTransaction();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO users (role, username, email, password_hash, first_name, middle_name, last_name, gender, date_of_birth, phone, profile_picture, status)
            VALUES ('doctor', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'assets/images/default-avatar.png', 'active')
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
            INSERT INTO doctors (user_id, employee_id, license_number, specialization, contact_number, profile_picture)
            VALUES (?, ?, ?, ?, ?, 'assets/images/default-avatar.png')
        ");
        $stmt->execute([
            $newUserId,
            $employeeId,
            $licenseNumber,
            $specialization ?: 'General Medicine',
            $contact ?: null,
        ]);

        $db->commit();
        log_activity($user['id'], 'Created doctor account', 'users', "Doctor #$newUserId ({$firstName} {$lastName})");
        json_response(true, ['user_id' => $newUserId], "Doctor account for Dr. {$firstName} {$lastName} created successfully.");
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Doctor creation error: ' . $e->getMessage());
        json_response(false, [], safe_api_error($e, 'Failed to create the doctor account. Please try again.', 'users/create_doctor'));
    }
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
        json_response(false, [], safe_api_error($e, 'Failed to create the nurse account. Please try again.', 'users/create_nurse'));
    }
}

json_response(false, [], 'Unknown action');
