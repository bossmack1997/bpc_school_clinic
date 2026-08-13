<?php
require_once __DIR__ . '/../../config/config.php';
require_role('admin');
$pageTitle = 'QR Scanner';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter fade-in">
      <div class="alert alert-danger d-flex align-items-center gap-2">
        <i class="bi bi-shield-exclamation-fill"></i>
        <div>
          <strong>Access Denied</strong><br>
          QR scanning is restricted to Nurses only. Administrators can view QR Management and Scan Reports, but cannot operate the QR scanner.
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
