<?php
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';

if ($action === 'update_info') {
    csrf_verify_or_die();
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');

    if (!$firstName || !$lastName || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, [], 'Please provide a valid name and email.');
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE email=? AND id<>?");
    $stmt->execute([$email, $user['id']]);
    if ($stmt->fetch()) json_response(false, [], 'That email is already used by another account.');

    $db->prepare("UPDATE users SET first_name=?, last_name=?, phone=?, email=? WHERE id=?")
       ->execute([$firstName, $lastName, $phone, $email, $user['id']]);

    $_SESSION['user']['first_name'] = $firstName;
    $_SESSION['user']['last_name'] = $lastName;
    $_SESSION['user']['email'] = $email;
    $_SESSION['user']['phone'] = $phone;

    log_activity($user['id'], 'Updated profile info', 'profile');
    json_response(true, [], 'Profile updated successfully.');
}

if ($action === 'update_personal_info') {
    csrf_verify_or_die();

    $userFields = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name'  => trim($_POST['last_name'] ?? ''),
        'phone'      => trim($_POST['phone'] ?? ''),
    ];

    $email = trim($_POST['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, [], 'Please provide a valid email address.');
    }

    // Require at least first_name or last_name
    if ($userFields['first_name'] === '' && $userFields['last_name'] === '') {
        json_response(false, [], 'Please provide a valid name.');
    }

    if ($email !== '') {
        $stmt = $db->prepare("SELECT id FROM users WHERE email=? AND id<>?");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) json_response(false, [], 'That email is already used by another account.');
    }

    // Student-specific fields (do NOT touch course/year_level/section — they are read-only academic fields)
    $studentFields = [
        'gender'                 => trim($_POST['gender'] ?? ''),
        'address'                => trim($_POST['address'] ?? ''),
        'blood_type'             => trim($_POST['blood_type'] ?? ''),
        'allergies'              => trim($_POST['allergies'] ?? ''),
        'guardian_name'          => trim($_POST['guardian_name'] ?? ''),
        'guardian_contact'       => trim($_POST['guardian_contact'] ?? ''),
        'emergency_contact_name' => trim($_POST['emergency_contact_name'] ?? ''),
        'emergency_contact_number' => trim($_POST['emergency_contact_number'] ?? ''),
    ];

    // Birthdate (field name may be 'birthdate' or 'date_of_birth')
    $birthdate = $_POST['birthdate'] ?? ($_POST['date_of_birth'] ?? '');

    try {
        $db->beginTransaction();

        // --- Dynamic UPDATE for users table ---
        $setParts = [];
        $setParams = [];
        foreach ($userFields as $col => $val) {
            if (array_key_exists($col, $_POST)) {
                $setParts[] = "$col = ?";
                $setParams[] = $val;
            }
        }
        if ($email !== '' && array_key_exists('email', $_POST)) {
            $setParts[] = "email = ?";
            $setParams[] = $email;
        }
        if (!empty($setParts)) {
            $setParams[] = $user['id'];
            $db->prepare("UPDATE users SET " . implode(', ', $setParts) . " WHERE id=?")
               ->execute($setParams);
        }

        // --- Dynamic UPDATE for students table ---
        $stuSetParts = [];
        $stuSetParams = [];
        foreach ($studentFields as $col => $val) {
            if (array_key_exists($col, $_POST)) {
                $stuSetParts[] = "$col = ?";
                $stuSetParams[] = $val !== '' ? $val : null;
            }
        }
        // Handle birthdate
        if (array_key_exists('birthdate', $_POST) || array_key_exists('date_of_birth', $_POST)) {
            $bd = $_POST['birthdate'] ?? ($_POST['date_of_birth'] ?? '');
            if ($bd !== '' && $bd !== null) {
                $d = DateTime::createFromFormat('Y-m-d', $bd);
                if (!$d || $d->format('Y-m-d') !== $bd) {
                    json_response(false, [], 'Please provide a valid birthdate (YYYY-MM-DD).');
                }
                if ($bd > date('Y-m-d')) {
                    json_response(false, [], 'Birthdate cannot be in the future.');
                }
                $stuSetParts[] = "birthdate = ?";
                $stuSetParams[] = $bd;
            } else {
                $stuSetParts[] = "birthdate = ?";
                $stuSetParams[] = null;
            }
        }
        if (!empty($stuSetParts)) {
            $stuSetParams[] = $user['id'];
            $db->prepare("UPDATE students SET " . implode(', ', $stuSetParts) . " WHERE user_id=?")
               ->execute($stuSetParams);
        }

        // Refresh session
        if (isset($userFields['first_name']) && $userFields['first_name'] !== '') {
            $_SESSION['user']['first_name'] = $userFields['first_name'];
        }
        if (isset($userFields['last_name']) && $userFields['last_name'] !== '') {
            $_SESSION['user']['last_name'] = $userFields['last_name'];
        }
        if ($email !== '' && array_key_exists('email', $_POST)) {
            $_SESSION['user']['email'] = $email;
        }
        if (array_key_exists('phone', $_POST)) {
            $_SESSION['user']['phone'] = $userFields['phone'];
        }

        push_notification($user['id'], 'account', 'Profile Updated', 'Your personal information has been successfully updated.', APP_URL . '/pages/student/profile.php');
        log_activity($user['id'], 'Updated personal information', 'profile');
        $db->commit();
        json_response(true, [], 'Personal information updated successfully.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Update personal info failed: ' . $e->getMessage() . ' | User: ' . $user['id']);
        json_response(false, [], 'Unable to update personal information. Please try again.');
    }
}

if ($action === 'update_nurse_info') {
    csrf_verify_or_die();

    $userFields = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'last_name'  => trim($_POST['last_name'] ?? ''),
        'phone'      => trim($_POST['phone'] ?? ''),
    ];

    $email = trim($_POST['email'] ?? '');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(false, [], 'Please provide a valid email address.');
    }

    if (array_key_exists('email', $_POST) && $email !== '') {
        $stmt = $db->prepare("SELECT id FROM users WHERE email=? AND id<>?");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) json_response(false, [], 'That email is already used by another account.');
    }

    $nurseFields = [
        'gender'          => trim($_POST['gender'] ?? ''),
        'employee_id'     => trim($_POST['employee_id'] ?? ''),
        'license_number'  => trim($_POST['license_number'] ?? ''),
        'specialization'  => trim($_POST['specialization'] ?? ''),
        'shift_schedule'  => trim($_POST['shift_schedule'] ?? ''),
        'contact_number'  => trim($_POST['contact_number'] ?? ''),
    ];

    $bd = $_POST['date_of_birth'] ?? ($_POST['birthdate'] ?? null);
    if ($bd !== '' && $bd !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $bd);
        if (!$d || $d->format('Y-m-d') !== $bd) {
            json_response(false, [], 'Please provide a valid date of birth (YYYY-MM-DD).');
        }
        if ($bd > date('Y-m-d')) {
            json_response(false, [], 'Date of birth cannot be in the future.');
        }
    }

    try {
        $db->beginTransaction();

        // --- Dynamic UPDATE for users table ---
        $setParts = [];
        $setParams = [];
        foreach ($userFields as $col => $val) {
            if (array_key_exists($col, $_POST)) {
                $setParts[] = "$col = ?";
                $setParams[] = $val;
            }
        }
        if ($email !== '' && array_key_exists('email', $_POST)) {
            $setParts[] = "email = ?";
            $setParams[] = $email;
        }
        if (!empty($setParts)) {
            $setParams[] = $user['id'];
            $db->prepare("UPDATE users SET " . implode(', ', $setParts) . " WHERE id=?")
               ->execute($setParams);
        }

        // --- Dynamic UPDATE for nurses table ---
        $nurseSetParts = [];
        $nurseSetParams = [];
        foreach ($nurseFields as $col => $val) {
            if (array_key_exists($col, $_POST)) {
                $nurseSetParts[] = "$col = ?";
                $nurseSetParams[] = $val !== '' ? $val : null;
            }
        }
        if (array_key_exists('date_of_birth', $_POST) || array_key_exists('birthdate', $_POST)) {
            $nurseSetParts[] = "date_of_birth = ?";
            $nurseSetParams[] = ($bd !== '' && $bd !== null) ? $bd : null;
        }
        if (!empty($nurseSetParts)) {
            $nurseSetParams[] = $user['id'];
            $db->prepare("UPDATE nurses SET " . implode(', ', $nurseSetParts) . " WHERE user_id=?")
               ->execute($nurseSetParams);
        }

        // Refresh session
        if (array_key_exists('first_name', $_POST) && $userFields['first_name'] !== '') {
            $_SESSION['user']['first_name'] = $userFields['first_name'];
        }
        if (array_key_exists('last_name', $_POST) && $userFields['last_name'] !== '') {
            $_SESSION['user']['last_name'] = $userFields['last_name'];
        }
        if ($email !== '' && array_key_exists('email', $_POST)) {
            $_SESSION['user']['email'] = $email;
        }
        if (array_key_exists('phone', $_POST)) {
            $_SESSION['user']['phone'] = $userFields['phone'];
        }

        push_notification($user['id'], 'account', 'Profile Updated', 'Your profile information has been successfully updated.', APP_URL . '/pages/nurse/profile.php');
        log_activity($user['id'], 'Updated nurse profile', 'profile');
        $db->commit();
        json_response(true, [], 'Profile updated successfully.');
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Update nurse profile failed: ' . $e->getMessage() . ' | User: ' . $user['id']);
        json_response(false, [], 'Unable to update profile. Please try again.');
    }
}

if ($action === 'change_password') {
    csrf_verify_or_die();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $hash = $stmt->fetchColumn();

    if (!password_verify($current, $hash)) json_response(false, [], 'Current password is incorrect.');
    if (strlen($new) < 8) json_response(false, [], 'New password must be at least 8 characters.');
    if ($new !== $confirm) json_response(false, [], 'New passwords do not match.');

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$newHash, $user['id']]);
    log_activity($user['id'], 'Changed password', 'profile');
    json_response(true, [], 'Password changed successfully.');
}

if ($action === 'upload_picture') {
    if (empty($_FILES['picture']) || $_FILES['picture']['error'] !== UPLOAD_ERR_OK) {
        json_response(false, [], 'Please select an image to upload.');
    }
    $allowed = ['image/jpeg','image/png','image/webp'];
    $file = $_FILES['picture'];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeType, $allowed, true)) json_response(false, [], 'Invalid file type. Only JPG, PNG, or WEBP images are allowed.');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExts, true)) json_response(false, [], 'Invalid file extension. Allowed: JPG, JPEG, PNG, WEBP.');

    // Prevent executable uploads
    $dangerous = ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'js'];
    if (in_array($ext, $dangerous, true)) json_response(false, [], 'Executable files are not allowed.');

    if ($file['size'] > 5 * 1024 * 1024) json_response(false, [], 'Image must be under 5MB.');

    // Delete old profile image if not default
    $stmt = $db->prepare("SELECT profile_picture FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $oldPic = $stmt->fetchColumn();
    if ($oldPic && $oldPic !== 'assets/images/default-avatar.png') {
        $oldPath = BASE_PATH . '/' . $oldPic;
        if (file_exists($oldPath)) unlink($oldPath);
    }

    $filename = 'user_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destRel = 'assets/images/profiles/' . $filename;
    $destAbs = BASE_PATH . '/' . $destRel;

    // Ensure directory exists
    $dir = dirname($destAbs);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        json_response(false, [], 'Failed to upload image. Please check directory permissions.');
    }

    $db->prepare("UPDATE users SET profile_picture=? WHERE id=?")->execute([$destRel, $user['id']]);
    $_SESSION['user']['profile_picture'] = $destRel;
    log_activity($user['id'], 'Updated profile picture', 'profile');
    json_response(true, ['path' => APP_URL . '/' . $destRel], 'Profile picture updated successfully.');
}

json_response(false, [], 'Unknown action');
