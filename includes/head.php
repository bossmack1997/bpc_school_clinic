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
<link rel="stylesheet" href="<?= asset_url('css/bootstrap.min.css') ?>">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="<?= asset_url('css/bootstrap-icons.min.css') ?>">
<!-- DataTables -->
<link rel="stylesheet" href="<?= asset_url('css/dataTables.bootstrap5.min.css') ?>">
<!-- QR Code + SheetJS (Excel import) — vendored locally for offline reliability -->
<script src="<?= asset_url('js/qrcode.min.js') ?>"></script>
<script src="<?= asset_url('js/html5-qrcode.min.js') ?>"></script>
<script src="<?= asset_url('js/xlsx.full.min.js') ?>"></script>
<!-- SweetAlert2 -->
<link rel="stylesheet" href="<?= asset_url('css/sweetalert2.min.css') ?>">
<!-- AOS Animation -->
<link rel="stylesheet" href="<?= asset_url('css/aos.css') ?>">
<!-- App styles -->
<link rel="stylesheet" href="<?= asset_url('css/style.css') ?>">
<!-- MICA Voice AI Assistant styles -->
<link rel="stylesheet" href="<?= asset_url('css/mica.css') ?>">

<script>const APP_URL = "<?= APP_URL ?>";</script>
<!-- Moment.js for real-time clock -->
<script src="<?= asset_url('js/moment.min.js') ?>"></script>
