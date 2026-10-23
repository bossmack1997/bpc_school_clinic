<?php
/**
 * api/nurse_documents.php
 * ------------------------------------------------------------------
 * Secure endpoint to view / download nurse registration & credential
 * documents without public URL exposure.
 *
 * Access Rules:
 * - Authorized Doctor / Clinical Co-Admin can inspect any nurse document.
 * - The Nurse themselves can view their own document.
 * - All other users / unauthenticated requests are denied.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permission_profiles.php';

require_login();
$user = current_user();
if (!$user) {
    http_response_code(401);
    exit('Authentication required.');
}

$nurseUserId = (int)($_GET['id'] ?? 0);
if ($nurseUserId <= 0) {
    http_response_code(400);
    exit('Invalid nurse account specified.');
}

$isDoctor = (permission_profile($user) === 'doctor');
$isSelf = (canonical_role($user['role'] ?? '') === 'nurse' && (int)$user['id'] === $nurseUserId);

if (!$isDoctor && !$isSelf) {
    http_response_code(403);
    exit('Access denied: You are not authorized to view this confidential credential document.');
}

$db = getDB();
$stmt = $db->prepare("SELECT valid_id_path FROM nurses WHERE user_id = ? LIMIT 1");
$stmt->execute([$nurseUserId]);
$relPath = $stmt->fetchColumn();

if (!$relPath || !is_string($relPath)) {
    http_response_code(404);
    exit('No document uploaded for this nurse account.');
}

// Clean and validate path
$relPath = str_replace('\\', '/', trim($relPath));
if (str_contains($relPath, '..') || str_contains($relPath, "\0")) {
    http_response_code(400);
    exit('Malformed document path.');
}

$fullPath = realpath(BASE_PATH . '/' . ltrim($relPath, '/'));
$storageRoot = realpath(BASE_PATH . '/storage/registration_documents');
$assetsRoot = realpath(BASE_PATH . '/assets/images');

if (!$fullPath || !is_file($fullPath)) {
    http_response_code(404);
    exit('The document file is not found on the server.');
}

// Security: Must reside within storage/registration_documents or assets/images
$inStorage = ($storageRoot && str_starts_with($fullPath, $storageRoot));
$inAssets  = ($assetsRoot && str_starts_with($fullPath, $assetsRoot));

if (!$inStorage && !$inAssets) {
    http_response_code(403);
    exit('Unauthorized file location.');
}

$ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
];
$contentType = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($fullPath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$filename = basename($fullPath);
$disposition = in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode($filename) . '"');

readfile($fullPath);
exit;
