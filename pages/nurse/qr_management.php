<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'QR Management';
$roleDir = 'nurse';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include __DIR__ . '/../../includes/head.php'; ?></head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area">
      <?php include __DIR__ . '/../../includes/qr_management_ui.php'; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>

