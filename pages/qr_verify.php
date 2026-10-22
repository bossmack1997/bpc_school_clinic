<?php
/**
 * pages/qr_verify.php
 * Public-facing QR certificate verification page.
 * Accepts a 'data' GET param with the QR payload, or renders the verifier UI.
 */
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'QR Verification';
$qrData = trim($_GET['data'] ?? '');
$verificationResult = null;
$verificationError = null;

if ($qrData) {
    $db = getDB();
    $result = verify_dynamic_qr($db, $qrData);
    if (!$result['valid']) {
        $verificationError = 'Dynamic QR invalid: ' . $result['reason'];
    } else {
        $verificationResult = $result;
    }
    unset($db);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="<?= asset_url('css/bootstrap.min.css') ?>">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="<?= asset_url('css/bootstrap-icons.min.css') ?>">
  <!-- SweetAlert2 -->
  <link rel="stylesheet" href="<?= asset_url('css/sweetalert2.min.css') ?>">
  <!-- App styles -->
  <link href="<?= asset_url('css/style.css') ?>" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('css/design-system.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('css/components.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('css/responsive.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('css/theme.css') ?>">
  <script src="<?= asset_url('js/qrcode.min.js') ?>"></script>
  <script src="<?= asset_url('js/html5-qrcode.min.js') ?>"></script>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
      <div class="card shadow-sm border-0">
        <div class="card-body text-center py-4">
          <img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Logo" style="height:48px;">
          <h1 class="fw-bold mb-1 mt-2">QR Certificate Verification</h1>
          <p class="text-muted small">BPC School Clinic</p>

          <?php if ($verificationError): ?>
            <div class="alert alert-danger mt-3">
              <i class="bi bi-x-circle-fill me-1"></i> <?= e($verificationError) ?>
            </div>
          <?php elseif ($verificationResult): ?>
            <?php
              $valid = $verificationResult['valid'] ?? false;
              $student = null;
              if ($verificationResult['student_id'] > 0) {
                  $db = getDB();
                  $stmt = $db->prepare("
                      SELECT s.student_number, s.course, s.year_level, s.section,
                             u.first_name, u.last_name
                      FROM students s JOIN users u ON u.id = s.user_id
                      WHERE s.id = ?
                  ");
                  $stmt->execute([$verificationResult['student_id']]);
                  $student = $stmt->fetch();
              }
              $purpose = $verificationResult['purpose'] ?? '';
            ?>
            <?php if ($valid): ?>
              <div class="alert alert-success mt-3">
                <i class="bi bi-check-circle-fill me-1"></i> <strong>QR Verified Successfully</strong>
              </div>
              <div class="text-start small bg-light p-3 rounded-3">
                <div class="d-flex justify-content-between py-1"><span class="text-muted">Purpose</span><span class="fw-semibold"><?= e(ucfirst(str_replace('_', ' ', $purpose))) ?></span></div>
                <?php if ($student): ?>
                  <div class="d-flex justify-content-between py-1"><span class="text-muted">Student Name</span><span class="fw-semibold"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></span></div>
                  <div class="d-flex justify-content-between py-1"><span class="text-muted">Student Number</span><span class="fw-semibold"><?= e($student['student_number']) ?></span></div>
                  <div class="d-flex justify-content-between py-1"><span class="text-muted">Course / Year</span><span class="fw-semibold"><?= e($student['course'] . ' · ' . $student['year_level']) ?></span></div>
                  <div class="d-flex justify-content-between py-1"><span class="text-muted">Section</span><span class="fw-semibold"><?= e($student['section']) ?></span></div>
                <?php endif; ?>
                <?php if ($verificationResult['expires_at'] ?? null): ?>
                  <div class="d-flex justify-content-between py-1"><span class="text-muted">Expires</span><span class="fw-semibold"><?= e($verificationResult['expires_at']) ?></span></div>
                <?php else: ?>
                  <div class="d-flex justify-content-between py-1"><span class="text-muted">Expiration</span><span class="fw-semibold">No expiration</span></div>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="alert alert-danger mt-3">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> This QR code is not valid.
              </div>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-muted small">Paste a QR code or scan one to verify.</p>
          <?php endif; ?>

          <hr class="my-3">

          <div id="verifyScannerContainer" class="mx-auto" style="max-width: 240px; height: 200px; display: none;">
            <video id="verifyVideo" class="w-100 h-100 object-fit-cover rounded" autoplay playsinline></video>
          </div>
          <button type="button" class="btn btn-sm btn-outline-success mb-2" id="startVerifyScan" style="display: none;">
            <i class="bi bi-camera-video me-1"></i> Scan QR Code
          </button>

          <div class="mb-3">
            <textarea class="form-control form-control-sm" id="verifyQrInput" rows="2"
                      placeholder='{"v":2,"type":"dynamic","p":"certificate_verification",...}'></textarea>
          </div>
          <button type="button" class="btn btn-success w-100" id="verifySubmitBtn">
            <i class="bi bi-search me-1"></i> Verify QR Code
          </button>

          <p class="text-muted small mt-2">
            <i class="bi bi-shield-check me-1"></i> All verification is performed server-side and never exposes personal data.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="<?= asset_url('js/jquery.min.js') ?>"></script>
<script src="<?= asset_url('js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset_url('js/sweetalert2.all.min.js') ?>"></script>
<script>
(function () {
  'use strict';
  const $ = window.jQuery;
  const APP = "<?= APP_URL ?>";
  let html5QrCode = null;

  $('#verifySubmitBtn').on('click', function () {
    const data = $('#verifyQrInput').val().trim();
    if (data.length < 10) {
      Swal.fire({ icon: 'warning', title: 'Empty QR', text: 'Please enter or scan a QR code.' });
      return;
    }
    window.location.href = APP + '/pages/qr_verify.php?data=' + encodeURIComponent(data);
  });

    $('#startVerifyScan').on('click', async function () {
    if (!window.Html5Qrcode) {
      Swal.fire({ icon: 'error', title: 'Scanner Error', text: 'Scanner library not loaded.' });
      return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      Swal.fire({ icon: 'error', title: 'Camera Error', text: 'This browser does not support camera access required by the QR scanner.' });
      return;
    }
    $(this).prop('disabled', true).text('Scanning…');
    $('#verifyScannerContainer').show();
    try {
      html5QrCode = new Html5Qrcode('verifyVideo');
      // Desktop-compatible: Html5Qrcode requires the camera config object to
      // have exactly ONE key (facingMode OR deviceId). { facingMode: "environment" }
      // lets the browser pick any available camera (built-in, USB, external).
      await html5QrCode.start(
        { facingMode: "environment" },
        { fps: 10, qrbox: 200 },
        function (decoded) {
          $('#verifyQrInput').val(decoded);
          stopVerifyScan();
          $('#verifySubmitBtn').click();
        },
        function () { /* no error handling */ }
      );
    } catch (e) {
      console.error('[QR Verify] Camera initialization failed:', e);
      let msg = e.message || 'Could not access camera.';
      if (e && e.name === 'NotAllowedError') {
        msg = 'Camera permission was denied. Please allow camera access and try again.';
      } else if (e && e.name === 'NotFoundError') {
        msg = 'No camera was detected. Please connect a webcam.';
      } else if (e && e.name === 'NotReadableError') {
        msg = 'The camera is currently being used by another application.';
      } else if (e && e.name === 'OverconstrainedError') {
        msg = 'No camera matching the requested settings was found. Please connect a webcam and try again.';
      } else if (e && e.name === 'AbortError') {
        msg = 'Camera access was aborted. Please try again.';
      } else if (e && e.name === 'TypeError') {
        msg = 'Camera request was invalid. Please refresh the page and try again.';
      }
      Swal.fire({ icon: 'error', title: 'Camera Error', text: msg });
      $(this).prop('disabled', false).text('Scan QR Code');
    }
  });

  function stopVerifyScan() {
    if (html5QrCode) {
      html5QrCode.stop().then(function () {
        html5QrCode.clear();
      }).catch(function () {});
      html5QrCode = null;
    }
    $('#verifyScannerContainer').hide();
    $('#startVerifyScan').prop('disabled', false).text('Scan QR Code');
  }

  <?php if (!$qrData): ?>
    $('#verifyScannerContainer').show();
    $('#startVerifyScan').show();
  <?php endif; ?>
})();
</script>
</body>
</html>
