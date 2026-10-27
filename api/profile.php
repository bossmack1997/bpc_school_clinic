<?php
require_once __DIR__ . '/../config/config.php';
require_login();
header('Content-Type: application/json');

$db = getDB();
$user = current_user();
$action = $_REQUEST['action'] ?? '';
if($_SERVER['REQUEST_METHOD']==='POST'&&in_array($action,['save_account_settings','update_info','update_personal_info'],true)&&isset($_POST['email'])){
    if(!is_string($_POST['email'])||($_POST['email']!==''&&strtolower(trim($_POST['email']))!==strtolower($user['email']??''))){http_response_code(422);json_response(false,[],'Official email changes require MIS verification. Your current verified email was preserved.');}
}

if ($action === 'save_account_settings' || $action === 'change_password') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); json_response(false, [], 'Use POST.'); }
    csrf_verify_or_die();require_once __DIR__.'/../includes/account_settings.php';
    try {
        if ($action === 'save_account_settings') { account_settings_save($db,$user,$_POST); log_activity($user['id'],'Updated personal settings','profile'); }
        else { account_settings_password($db,$user,$_POST); log_activity($user['id'],'Changed password','profile'); }
        json_response(true, [], $action === 'change_password' ? 'Password changed. Other sessions have been signed out.' : 'Personal information saved.');
    } catch (InvalidArgumentException $e) { json_response(false, [], $e->getMessage()); }
    catch (Throwable $e) { error_log('Account settings update failed for user #'.(int)$user['id']); http_response_code(500); json_response(false, [], 'Unable to save changes. Please try again.'); }
}

if ($action === 'update_info') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { http_response_code(403); json_response(false, [], 'Your session form expired. Refresh the page and try again.'); }
    foreach(['first_name','middle_name','last_name','phone','email'] as $field){if(!is_string($_POST[$field]??'')){http_response_code(422);json_response(false,[],'Invalid personal information.');}}
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');

    if (!$firstName || !$lastName || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email)>120
        || mb_strlen($firstName) > 60 || mb_strlen($middleName) > 60 || mb_strlen($lastName) > 60) {
        json_response(false, [], 'Please provide a valid name and email.');
    }
    if ($phone !== '' && !preg_match('/^[+0-9() .-]{7,30}$/', $phone)) {
        json_response(false, [], 'Please provide a valid contact number.');
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE email=? AND id<>?");
    $stmt->execute([$email, $user['id']]);
    if ($stmt->fetch()) json_response(false, [], 'That email is already used by another account.');

    $db->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, phone=?, email=? WHERE id=?")
       ->execute([$firstName, $middleName ?: null, $lastName, $phone, $email, $user['id']]);

    $_SESSION['user']['first_name'] = $firstName;
    $_SESSION['user']['middle_name'] = $middleName;
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

if ($action === 'upload_picture') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); json_response(false, [], 'Use POST to upload a profile picture.'); }
    if (!csrf_verify($_POST['csrf_token'] ?? '')) { http_response_code(403); json_response(false, [], 'Your session form expired. Refresh the page and try again.'); }
    if (empty($_FILES['picture'])) json_response(false, [], 'Please select an image to upload.');
    $allowed = ['image/jpeg','image/png','image/webp'];
    $file = $_FILES['picture'];
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'The image exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'The image exceeds the form upload limit.',
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please select an image to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image.',
            UPLOAD_ERR_EXTENSION => 'The image upload was blocked by a server extension.',
        ];
        json_response(false, [], $messages[$uploadError] ?? 'The image could not be uploaded.');
    }
    if (!is_uploaded_file($file['tmp_name'])) json_response(false, [], 'The uploaded image could not be verified. Please select it again.');

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeType, $allowed, true)) json_response(false, [], 'Invalid file type. Only JPG, PNG, or WEBP images are allowed.');
    if (@getimagesize($file['tmp_name']) === false) json_response(false, [], 'The selected file is not a valid image.');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowedExts, true)) json_response(false, [], 'Invalid file extension. Allowed: JPG, JPEG, PNG, WEBP.');

    // Prevent executable uploads
    $dangerous = ['php', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'js'];
    if (in_array($ext, $dangerous, true)) json_response(false, [], 'Executable files are not allowed.');

    if ($file['size'] > 5 * 1024 * 1024) json_response(false, [], 'Image must be under 5MB.');

    $filename = 'user_' . $user['id'] . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destRel = 'assets/images/profiles/' . $filename;
    $destAbs = BASE_PATH . '/' . $destRel;

    // Ensure directory exists
    $dir = dirname($destAbs);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) json_response(false, [], 'The profile image folder could not be created.');
    if (!is_writable($dir)) json_response(false, [], 'The profile image folder is not writable. Contact the system administrator.');

    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        json_response(false, [], 'The server could not save the uploaded image.');
    }
    try {
        $db->beginTransaction();
        $stmt = $db->prepare("SELECT profile_picture FROM users WHERE id=? FOR UPDATE");
        $stmt->execute([$user['id']]);
        $oldPic = $stmt->fetchColumn();
        $db->prepare("UPDATE users SET profile_picture=? WHERE id=?")->execute([$destRel, $user['id']]);
        log_activity($user['id'], 'Updated profile picture', 'profile');
        $db->commit();
        $_SESSION['user']['profile_picture'] = $destRel;

        // Keep previous uploads intact; existing messages/pages may still reference them.
        json_response(true, ['path' => avatar_url($destRel)], 'Profile picture updated successfully.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if (is_file($destAbs)) @unlink($destAbs);
        error_log('Profile picture upload failed for user #' . (int)$user['id'] . ': ' . $e->getMessage());
        http_response_code(500);
        json_response(false, [], 'The profile picture could not be saved. Please try again.');
    }
}

json_response(false, [], 'Unknown action');
