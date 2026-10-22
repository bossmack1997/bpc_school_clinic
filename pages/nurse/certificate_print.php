<?php
require_once __DIR__ . '/../../config/config.php';
require_role(['nurse','admin']);
$pageTitle = 'Print Certificate';

$certId = (int)($_GET['id'] ?? 0);
if ($certId <= 0) {
    http_response_code(404);
    exit('Invalid certificate.');
}

$db = getDB();
$stmt = $db->prepare("
    SELECT c.*, s.student_number, s.course, s.year_level, s.section,
           u.first_name AS student_first, u.last_name AS student_last,
           u2.first_name AS nurse_first, u2.last_name AS nurse_last
    FROM certificates c
    JOIN students s ON s.id = c.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN users u2 ON u2.id = c.issued_by
    WHERE c.id = ?
    LIMIT 1
");
$stmt->execute([$certId]);
$cert = $stmt->fetch();

if (!$cert) {
    http_response_code(404);
    exit('Certificate not found.');
}

$certNumber = $cert['certificate_number'] || ('MC-' . date('Y') . '-' . str_pad($cert['id'], 5, '0', STR_PAD_LEFT));
$studentName = trim(($cert['student_first'] ?? '') . ' ' . ($cert['student_last'] ?? ''));
$nurseName = trim(($cert['nurse_first'] ?? '') . ' ' . ($cert['nurse_last'] ?? ''));
$dateIssued = date('F d, Y', strtotime($cert['date_issued'] ?? date('Y-m-d')));
$purpose = $cert['purpose'] ?: 'Medical Documentation';
$qrData = '';

// Get QR data for certificate verification
$q = $db->prepare("SELECT qr_data FROM dynamic_qrs WHERE reference_id = ? AND reference_type = 'certificates' AND purpose = 'certificate_verification' AND status = 'active' LIMIT 1");
$q->execute([$certId]);
$qrData = $q->fetchColumn();

if (!$qrData) {
    $dynamicQr = generate_dynamic_qr($db, 'certificate_verification', $certId, 'certificates', $cert['student_id'], 0, $cert['issued_by']);
    $qrData = $dynamicQr['qr_data'];
}

$verifyUrl = APP_URL . '/pages/qr_verify.php?data=' . urlencode($qrData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Certificate <?= e($certNumber) ?> — <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= asset_url('css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('css/bootstrap-icons.min.css') ?>">
  <link href="<?= asset_url('css/design-system.css') ?>" rel="stylesheet">
  <link href="<?= asset_url('css/components.css') ?>" rel="stylesheet">
  <script src="<?= asset_url('js/qrcode.min.js') ?>"></script>
  <style>
    @page {
      size: A4;
      margin: 15mm;
    }
    body {
      font-family: var(--font-sans);
      background: #f8f9fa;
    }
    .cert-container {
      max-width: 800px;
      margin: 0 auto;
      background: #fff;
      border: 3px solid var(--primary);
      border-radius: 12px;
      padding: 40px;
      position: relative;
    }
    .cert-header {
      text-align: center;
      border-bottom: 2px solid var(--primary);
      padding-bottom: 20px;
      margin-bottom: 30px;
    }
    .cert-header img {
      height: 60px;
      margin-bottom: 10px;
    }
    .cert-header h1 {
      color: var(--primary);
      font-size: 28px;
      font-weight: 700;
      margin: 0;
      letter-spacing: 1px;
    }
    .cert-header .subtitle {
      color: #666;
      font-size: 14px;
      margin-top: 5px;
    }
    .cert-body {
      font-size: 15px;
      line-height: 1.8;
      color: #333;
    }
    .cert-body .student-name {
      font-size: 20px;
      font-weight: 700;
      color: var(--primary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .cert-body .info-row {
      display: flex;
      justify-content: space-between;
      border-bottom: 1px solid #eee;
      padding: 8px 0;
    }
    .cert-body .info-label {
      font-weight: 600;
      color: #555;
    }
    .cert-body .info-value {
      font-weight: 500;
      color: #333;
    }
    .cert-footer {
      margin-top: 40px;
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
    }
    .cert-footer .qr-section {
      text-align: center;
    }
    .cert-footer .qr-section canvas, .cert-footer .qr-section img {
      border: 1px solid #ddd;
      padding: 5px;
      border-radius: 4px;
    }
    .cert-footer .qr-section .qr-text {
      font-size: 10px;
      color: #666;
      margin-top: 5px;
    }
    .cert-footer .signature {
      text-align: center;
    }
    .cert-footer .signature-line {
      border-top: 1px solid #333;
      width: 200px;
      margin: 0 auto 5px;
    }
    .cert-footer .signature-label {
      font-size: 12px;
      color: #666;
    }
    .cert-watermark {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%) rotate(-30deg);
      font-size: 120px;
      font-weight: 900;
      color: rgba(11, 107, 58, 0.03);
      pointer-events: none;
      white-space: nowrap;
    }
    @media print {
      body { background: #fff; }
      .cert-container { border: 3px solid var(--primary) !important; box-shadow: none !important; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>
  <div class="container py-4">
    <div class="cert-container">
      <div class="cert-watermark">BPC CLINIC</div>

      <div class="cert-header">
        <img src="<?= APP_URL ?>/assets/images/bpc_logo.png" alt="BPC Logo">
        <h1>BULACAN POLYTECHNIC COLLEGE</h1>
        <div class="subtitle">SCHOOL CLINIC</div>
        <h2 style="color:var(--primary); margin-top:15px; font-size:22px;">MEDICAL CERTIFICATE</h2>
      </div>

      <div class="cert-body">
        <p class="text-center fw-semibold mb-4">This is to certify that</p>
        <div class="text-center mb-3">
          <span class="student-name"><?= e($studentName) ?></span>
        </div>
        <p class="text-center mb-2">
          Student ID: <strong><?= e($cert['student_number'] ?? '—') ?></strong><br>
          Course: <strong><?= e($cert['course'] ?? '—') ?></strong> &nbsp;|&nbsp; Year Level: <strong><?= e($cert['year_level'] ?? '—') ?></strong> &nbsp;|&nbsp; Section: <strong><?= e($cert['section'] ?? '—') ?></strong>
        </p>
        <p class="text-center mb-4">
          was seen and assessed at the <strong>BPC School Clinic</strong> on <strong><?= e($dateIssued) ?></strong>.
        </p>
        <p class="text-center mb-4">
          This certificate is issued upon the request of the student for <strong><?= e($purpose) ?></strong>.
        </p>
        <?php if ($cert['remarks']): ?>
        <div class="alert alert-light border small mb-3"><?= e($cert['remarks']) ?></div>
        <?php endif; ?>
      </div>

      <div class="cert-footer">
        <div class="qr-section">
          <div id="certQr" style="display:inline-block;padding:5px;background:#fff;"></div>
          <div class="qr-text">Scan to Verify</div>
        </div>
        <div class="signature">
          <div class="signature-line"></div>
          <div class="signature-label"><?= e($nurseName ?: 'School Clinic Nurse') ?></div>
          <div class="signature-label" style="font-size:10px;color:#888;">School Clinic Nurse</div>
        </div>
      </div>

      <div class="mt-4 pt-3 border-top" style="font-size:11px; color:#666;">
        <div class="row">
          <div class="col-6">
            <strong>Certificate No.:</strong> <?= e($certNumber) ?><br>
            <strong>Date Issued:</strong> <?= e($dateIssued) ?>
          </div>
          <div class="col-6 text-end">
            <strong>Status:</strong> <span class="badge bg-success">Valid</span><br>
            <strong>Verification:</strong> Scan QR code to verify
          </div>
        </div>
      </div>
    </div>

    <div class="text-center mt-3 no-print">
      <button class="btn btn-success" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print Certificate</button>
      <button class="btn btn-secondary ms-2" onclick="window.close()">Close</button>
    </div>
  </div>

  <script>
    (function () {
      'use strict';
      try {
        new QRCode(document.getElementById('certQr'), {
          text: <?= json_encode($qrData) ?>,
          width: 100,
          height: 100,
          correctLevel: QRCode.CorrectLevel.M,
        });
      } catch (e) {
        console.warn('QR render failed:', e);
      }
    })();
  </script>
<?php include __DIR__ . '/../../components/global_sos.php'; ?>
</body>
</html>
