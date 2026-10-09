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
        json_response(false, [], 'Access denied: you do not have permission to manage user accounts.');
    }
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
        json_response(false, [], 'Access denied: Only the Primary Administrator can create admin accounts.');
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
        json_response(false, [], 'Access denied.');
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
        json_response(false, [], 'Access denied: Only the Primary Administrator can modify admin accounts.');
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
        json_response(false, [], 'Access denied: Only the Primary Administrator can delete admin accounts.');
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

if (!in_array($action, ['get_user','approve','reject','deactivate','activate','update_user','bulk_approve'], true)) {
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
    if ($target['role'] === 'admin' && !is_primary_admin($user)
        && !has_admin_permission('approve_admins', $user, $db)) {
        json_response(false, [], 'Access denied: you do not have permission to view administrator accounts.');
    }

    unset($target['password_hash'], $target['otp_hash'], $target['remember_token'], $target['reset_token']);
    json_response(true, ['data' => $target]);
}

if ($action === 'approve') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['status'] === 'active') json_response(false, [], 'Account is already active.');

    if ($target['role'] === 'admin') {
        if (!is_primary_admin($user) && !has_admin_permission('approve_admins', $user)) {
            json_response(false, [], 'Access denied: you do not have permission to approve administrator accounts.');
        }
    } else {
        if (!is_primary_admin($user) && !has_admin_permission('approve_users', $user) && !has_admin_permission('manage_users', $user)) {
            json_response(false, [], 'Access denied: you do not have permission to approve user accounts.');
        }
    }

    $adminTypeSql = ($target['role'] === 'admin' && empty($target['admin_type'])) ? ", admin_type = 'co_admin'" : "";
    $db->prepare("
        UPDATE users 
        SET status = 'active', registration_status = 'approved', reviewed_by = ?, reviewed_at = NOW() {$adminTypeSql}
        WHERE id = ?
    ")->execute([$user['id'], $id]);

    // Auto-resolve corresponding AI registration review action if exists
    try {
        $db->prepare("
            UPDATE ai_actions 
            SET status = 'approved', executed_by = ?, executed_at = NOW() 
            WHERE target_type = 'users' AND target_id = ? AND action_type = 'registration_review' AND status IN ('new', 'reviewed')
        ")->execute([$user['id'], $id]);
    } catch (Exception $e) {
        error_log('Failed to resolve ai_action on approval: ' . $e->getMessage());
    }

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
    } elseif ($target['role'] === 'teacher') {
        try {
            $db->prepare("UPDATE advisory_assignments SET status = 'active', start_date = COALESCE(start_date, CURDATE()) WHERE teacher_id = ?")->execute([$id]);
        } catch (Exception $e) {
            error_log('Activating teacher advisory assignments failed: ' . $e->getMessage());
        }
    }

    // Push notification to user
    try {
        push_notification(
            (int)$id,
            'account',
            'Registration Approved',
            'Your account registration has been approved by the Administrator. You now have full access.',
            APP_URL . '/auth/login.php',
            null,
            'high'
        );
    } catch (Throwable $e) {}

    $roleLabel = ($target['role'] === 'admin') ? 'Co-Administrator' : ucfirst($target['role']);
    log_activity($user['id'], 'Approved user account', 'users', "User ID $id ({$target['role']})");
    json_response(true, [], "$roleLabel account approved successfully.");
}

if ($action === 'reject') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    $rejectionReason = trim($_POST['reason'] ?? $_POST['rejection_reason'] ?? 'Rejected during administrative review.');
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['status'] === 'rejected') json_response(false, [], 'Account is already rejected.');
    if ($target['role'] === 'admin' && !is_primary_admin($user)
        && !has_admin_permission('approve_admins', $user, $db)) {
        json_response(false, [], 'Access denied: you do not have permission to reject administrator accounts.');
    }

    $db->prepare("
        UPDATE users 
        SET status = 'rejected', registration_status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() 
        WHERE id = ?
    ")->execute([$rejectionReason, $user['id'], $id]);

    // Deactivate any pending advisory assignments
    try {
        $db->prepare("UPDATE advisory_assignments SET status = 'inactive' WHERE teacher_id = ?")->execute([$id]);
    } catch (Throwable $e) {}

    // Auto-resolve corresponding AI registration review action if exists
    try {
        $db->prepare("
            UPDATE ai_actions 
            SET status = 'rejected', executed_by = ?, executed_at = NOW() 
            WHERE target_type = 'users' AND target_id = ? AND action_type = 'registration_review' AND status IN ('new', 'reviewed')
        ")->execute([$user['id'], $id]);
    } catch (Exception $e) {
        error_log('Failed to resolve ai_action on rejection: ' . $e->getMessage());
    }

    // Push notification
    try {
        push_notification(
            (int)$id,
            'account',
            'Registration Rejected',
            "Your account registration was not approved: {$rejectionReason}",
            null,
            null,
            'high'
        );
    } catch (Throwable $e) {}

    log_activity($user['id'], 'Rejected user account', 'users', "User ID $id ({$target['role']}): $rejectionReason");
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
    if ($target['id'] == $user['id']) {
        json_response(false, [], 'You cannot deactivate your own active session account.');
    }

    $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$id]);
    log_activity($user['id'], 'Deactivated user account', 'users', "User ID $id ({$target['role']})");
    json_response(true, [], 'Account deactivated successfully.');
}

if ($action === 'activate') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['status'] === 'active') json_response(false, [], 'Account is already active.');
    if ($target['role'] === 'admin' && !is_primary_admin($user)) {
        json_response(false, [], 'Access denied: only the Primary Administrator can activate administrator accounts.');
    }

    $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
    log_activity($user['id'], 'Activated user account', 'users', "User ID $id ({$target['role']})");
    json_response(true, [], 'Account activated successfully.');
}

if ($action === 'update_user') {
    csrf_verify_or_die();
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) json_response(false, [], 'Invalid user ID.');

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) json_response(false, [], 'User not found.');
    if ($target['role'] === 'admin') {
        json_response(false, [], 'Administrator accounts must be managed through the protected Admin Accounts page.');
    }

    $firstName  = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $gender     = trim($_POST['gender'] ?? '');
    $dob        = trim($_POST['date_of_birth'] ?? '');

    if ($firstName === '' || $lastName === '') {
        json_response(false, [], 'First name and Last name are required.');
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            UPDATE users 
            SET first_name = ?, middle_name = ?, last_name = ?, phone = ?, gender = ?, date_of_birth = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $firstName,
            $middleName ?: null,
            $lastName,
            $phone ?: null,
            $gender ?: null,
            $dob ?: null,
            $id
        ]);

        if ($target['role'] === 'student') {
            $studentNo = trim($_POST['student_number'] ?? '');
            $course    = trim($_POST['course'] ?? '');
            $yearLevel = trim($_POST['year_level'] ?? '');
            $section   = trim($_POST['section'] ?? '');

            $db->prepare("
                UPDATE students 
                SET student_number = ?, course = ?, year_level = ?, section = ?
                WHERE user_id = ?
            ")->execute([$studentNo ?: null, $course ?: null, $yearLevel ?: null, $section ?: null, $id]);

        } elseif ($target['role'] === 'teacher' || $target['role'] === 'adviser') {
            $empId = trim($_POST['employee_id'] ?? '');
            $dept  = trim($_POST['department'] ?? '');

            $db->prepare("
                UPDATE teachers 
                SET employee_id = ?, department = ?, first_name = ?, last_name = ?, phone = ?
                WHERE user_id = ?
            ")->execute([$empId ?: null, $dept ?: null, $firstName, $lastName, $phone ?: null, $id]);

        } elseif ($target['role'] === 'nurse') {
            $empId   = trim($_POST['employee_id'] ?? '');
            $license = trim($_POST['license_number'] ?? '');

            $db->prepare("
                UPDATE nurses 
                SET employee_id = ?, license_number = ?, gender = ?, date_of_birth = ?, contact_number = ?
                WHERE user_id = ?
            ")->execute([$empId ?: null, $license ?: null, $gender ?: null, $dob ?: null, $phone ?: null, $id]);
        }

        $db->commit();
        log_activity($user['id'], 'Updated user profile details', 'users', "User ID $id ({$target['role']})");
        json_response(true, [], 'User information updated successfully.');

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('User update error: ' . $e->getMessage());
        json_response(false, [], safe_api_error($e, 'Failed to update this user. Please try again.', 'users/update'));
    }
}

if ($action === 'bulk_approve') {
    csrf_verify_or_die();
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), function ($v) { return $v > 0; }));
    if (!$ids) json_response(false, [], 'No accounts selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, role, status FROM users WHERE id IN ($placeholders) AND (role != 'admin' OR admin_type = 'co_admin')");
    $stmt->execute($ids);
    $targets = array_values(array_filter($stmt->fetchAll(), function ($target) use ($user, $db) {
        return $target['role'] !== 'admin' || is_primary_admin($user)
            || has_admin_permission('approve_admins', $user, $db);
    }));

    if (!$targets) json_response(false, [], 'No eligible accounts selected.');

    $approveIds = [];
    foreach ($targets as $target) {
        if ($target['status'] !== 'active') {
            $approveIds[] = (int)$target['id'];
        }
    }

    if (!$approveIds) json_response(false, [], 'Selected accounts are already active.');

    $approvePlaceholders = implode(',', array_fill(0, count($approveIds), '?'));
    $params = array_merge([$user['id']], $approveIds);
    $db->prepare("
        UPDATE users 
        SET status = 'active', registration_status = 'approved', reviewed_by = ?, reviewed_at = NOW() 
        WHERE id IN ($approvePlaceholders)
    ")->execute($params);

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

        // Auto-resolve AI actions
        try {
            $db->prepare("
                UPDATE ai_actions 
                SET status = 'approved', executed_by = ?, executed_at = NOW() 
                WHERE target_type = 'users' AND target_id = ? AND action_type = 'registration_review' AND status IN ('new', 'reviewed')
            ")->execute([$user['id'], $target['id']]);
        } catch (Exception $e) {}

        log_activity($user['id'], 'Bulk approved user account', 'users', "User ID {$target['id']} ({$target['role']})");
    }

    json_response(true, [], count($approveIds) . ' account' . (count($approveIds) > 1 ? 's' : '') . ' approved successfully.');
}

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
