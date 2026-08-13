<?php
require_once __DIR__ . '/../../config/config.php';
require_role('nurse');
$pageTitle = 'QR Check-In Scanner';
$scannerMode = 'smart';
$scannerTitle = 'Smart QR Scanner';
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
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="section-title mb-0">
          <i class="bi bi-qr-code-scan text-success me-1"></i> Smart QR Scanner
        </h6>
        <span class="badge bg-success">Auto-Detect Workflow</span>
      </div>

      <?php include __DIR__ . '/../../includes/qr_scanner_ui.php'; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
</body>
</html>
