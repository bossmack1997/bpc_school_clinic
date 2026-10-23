<?php
/**
 * Head partial — included on every page inside <head>
 */
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#0b6b3a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="BPC Clinic">
<meta name="application-name" content="BPC Clinic">
<link rel="manifest" href="<?= APP_URL ?>/manifest.json">
<link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/icons/icon-180.png">
<link rel="icon" type="image/png" sizes="192x192" href="<?= APP_URL ?>/assets/icons/icon-192.png">
<link rel="icon" type="image/png" sizes="512x512" href="<?= APP_URL ?>/assets/icons/icon-512.png">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= APP_NAME ?></title>

<?php if (getenv('FIREBASE_PROJECT_ID')): ?>
<script>
window.FIREBASE_CONFIG = {
  apiKey: "<?= e(getenv('FIREBASE_API_KEY')) ?>",
  authDomain: "<?= e(getenv('FIREBASE_AUTH_DOMAIN') ?: getenv('FIREBASE_PROJECT_ID') . '.firebaseapp.com') ?>",
  projectId: "<?= e(getenv('FIREBASE_PROJECT_ID')) ?>",
  storageBucket: "<?= e(getenv('FIREBASE_STORAGE_BUCKET') ?: getenv('FIREBASE_PROJECT_ID') . '.firebasestorage.app') ?>",
  messagingSenderId: "<?= e(getenv('FIREBASE_MESSAGING_SENDER_ID')) ?>",
  appId: "<?= e(getenv('FIREBASE_APP_ID')) ?>",
  measurementId: "<?= e(getenv('FIREBASE_MEASUREMENT_ID')) ?>",
  vapidKey: "<?= e(getenv('FIREBASE_VAPID_KEY')) ?>"
};
</script>
<?php endif; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<!-- Bootstrap 5 -->
<link rel="stylesheet" href="<?= asset_url('css/bootstrap.min.css', true) ?>">
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="<?= asset_url('css/bootstrap-icons.min.css', true) ?>">
<!-- DataTables -->
<link rel="stylesheet" href="<?= asset_url('css/dataTables.bootstrap5.min.css', true) ?>">
<!-- QR Code + SheetJS (Excel import) — vendored locally for offline reliability -->
<script src="<?= asset_url('js/qrcode.min.js', true) ?>"></script>
<script src="<?= asset_url('js/html5-qrcode.min.js', true) ?>"></script>
<script src="<?= asset_url('js/xlsx.full.min.js', true) ?>"></script>
<!-- SweetAlert2 -->
<link rel="stylesheet" href="<?= asset_url('css/sweetalert2.min.css', true) ?>">
<!-- AOS Animation -->
<link rel="stylesheet" href="<?= asset_url('css/aos.css', true) ?>">
<!-- Legacy page styles (landing / auth / kiosk) — superseded on app pages -->
<link rel="stylesheet" href="<?= asset_url('css/style.css', true) ?>">

<!-- ================================================================
     UNIFIED DESIGN SYSTEM
     The single source of truth for every role (Admin, Nurse,
     Teacher/Adviser, Student). Load order matters: these must come
     after style.css so the tokens and component normalisations win.
       design-system.css  tokens, base, app shell, Bootstrap primitives
       components.css      one component vocabulary for all roles
       responsive.css      one breakpoint contract for all pages
     ================================================================ -->
<link rel="stylesheet" href="<?= asset_url('css/design-system.css', true) ?>">
<link rel="stylesheet" href="<?= asset_url('css/components.css', true) ?>">
<link rel="stylesheet" href="<?= asset_url('css/responsive.css', true) ?>">

<!-- MICA Voice AI Assistant styles -->
<link rel="stylesheet" href="<?= asset_url('css/mica.css', true) ?>">
<?php if (!empty($loadRegistrationStyles)): ?>
<link rel="stylesheet" href="<?= asset_url('css/registration.css', true) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= asset_url('css/theme.css', true) ?>">
<link rel="stylesheet" href="<?= asset_url('css/dashboard-unified.css', true) ?>">
<link rel="stylesheet" href="<?= asset_url('css/notifications-header.css', true) ?>">
<!-- Deliberately last: cross-role accessibility and responsive safeguards. -->
<link rel="stylesheet" href="<?= asset_url('css/ui-overrides.css', true) ?>">
<?php if(!empty($loadMisAdminStyles)): ?><link rel="stylesheet" href="<?= asset_url('css/mis-admin-mobile.css',true) ?>"><?php endif; ?>

<script>const APP_URL = "<?= APP_URL ?>";</script>
<!-- Moment.js for real-time clock -->
<script src="<?= asset_url('js/moment.min.js', true) ?>"></script>

<link rel="stylesheet" href="<?= asset_url('css/workflow-mobile.css', true) ?>">
<link rel="stylesheet" href="<?= asset_url('css/shared-ui.css', true) ?>">
<script defer src="<?= asset_url('js/password-visibility.js', true) ?>"></script>
