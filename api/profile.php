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

    log_activity($user['id'], 'Updated profile info', 'profile');
    json_response(true, [], 'Profile updated successfully.');
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

    // Validate MIME type (security)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mimeType, $allowed, true)) json_response(false, [], 'Invalid file type. Only JPG, PNG, or WEBP images are allowed.');

    if ($file['size'] > 5 * 1024 * 1024) json_response(false, [], 'Image must be under 5MB.');

    // Delete old profile image if not default
    $stmt = $db->prepare("SELECT profile_picture FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $oldPic = $stmt->fetchColumn();
    if ($oldPic && $oldPic !== 'assets/images/default-avatar.png') {
        $oldPath = BASE_PATH . '/' . $oldPic;
        if (file_exists($oldPath)) unlink($oldPath);
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'user_' . $user['id'] . '_' . time() . '.' . $ext;
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
