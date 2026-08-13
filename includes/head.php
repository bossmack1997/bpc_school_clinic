<?php
/**
 * Head partial — included on every page inside <head>
 */
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= APP_NAME ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<!-- QR Code + SheetJS (Excel import) — vendored locally for offline reliability -->
<script src="<?= app_url('assets/js/qrcode.min.js') ?>"></script>
<script src="<?= app_url('assets/js/html5-qrcode.min.js') ?>"></script>
<script src="<?= app_url('assets/js/xlsx.full.min.js') ?>"></script>
<!-- SweetAlert2 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<!-- AOS Animation -->
<link rel="stylesheet" href="https://unpkg.com/aos@2.3.1/dist/aos.css">
<!-- App styles -->
<link rel="stylesheet" href="<?= app_url('assets/css/style.css') ?>">
<!-- MICA Voice AI Assistant styles -->
<link rel="stylesheet" href="<?= app_url('assets/css/mica.css') ?>">

<script>const APP_URL = "<?= APP_URL ?>";</script>
<!-- Moment.js for real-time clock -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
