<?php
/**
 * api/qr_image.php
 * Generate a clean QR code PNG for a given token.
 * Uses the vendored qrcode-generator library + PHP GD for reliable rendering.
 */

require_once __DIR__ . '/../config/config.php';
require_login();
require_once __DIR__ . '/../assets/vendor/phpqrcode.php';

header('Content-Type: image/png');

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    exit('Missing token');
}

if (strlen($token) < 10 || strlen($token) > 255) {
    http_response_code(400);
    exit('Invalid token length');
}

try {
    $qr = QRCode::getMinimumQRCode($token, QR_ERROR_CORRECT_LEVEL_M);
    
    $moduleCount = $qr->getModuleCount();
    $margin = 4;
    $targetSize = 300;
    $cellSize = (int) floor($targetSize / ($moduleCount + $margin * 2));
    if ($cellSize < 1) $cellSize = 1;
    
    $image = $qr->createImage($cellSize, $margin, 0x000000, 0xFFFFFF, false);
    
    if ($image) {
        imagepng($image, null, 9);
        imagedestroy($image);
        exit;
    }
} catch (Throwable $e) {
    error_log('QR image generation failed: ' . $e->getMessage());
}

http_response_code(500);
exit('QR generation failed');
