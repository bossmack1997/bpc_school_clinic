<?php
require_once __DIR__ . '/../../config/config.php';
require_login();
$user = current_user();
if ($user['role'] !== 'nurse' || empty($user['self_service_only'])) redirect(dashboard_url_for($user['role']));
$pageTitle = 'Nurse Account';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body class="bg-light">
<main class="container py-5" style="max-width:720px">
<div class="card border-success shadow-sm"><div class="card-body p-4">
<h1 class="h3 text-success">Nurse Account</h1>
<p>Welcome, <?= e($user['first_name']) ?>. Your email is verified and your basic account is active.</p>
<p>Confidential patient records and clinical functions require trusted staff authorization. Email verification does not grant those permissions.</p>
<p class="text-muted">Your professional information and submitted documents remain on your account.</p>
<a class="btn btn-outline-success" href="<?= e(APP_URL) ?>/auth/logout.php">Sign out</a>
</div></div>
</main>
</body></html>
