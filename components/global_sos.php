<?php
// Render-only include. Authorization and headers belong to the authenticated page/API.
if (!defined('APP_URL') || defined('GLOBAL_SOS_RENDERED')) return;
$globalSosUser=current_user();
if (!$globalSosUser || !in_array($globalSosUser['role'] ?? '',['admin','nurse'],true)) return;
if(!admin_can_view_emergencies($globalSosUser))return;
define('GLOBAL_SOS_RENDERED',true);
$globalSosManagement=APP_URL.'/pages/'.$globalSosUser['role'].'/emergencies.php';
?>
<link rel="stylesheet" href="<?= asset_url('css/global-sos.css') . '?v=' . filemtime(__DIR__.'/../assets/css/global-sos.css') ?>">
<div id="globalSos" data-read-only="<?= admin_can_respond_sos($globalSosUser) ? '0' : '1' ?>" data-csrf="<?= e(csrf_token()) ?>" data-endpoint="<?= e(APP_URL.'/api/global_sos.php') ?>" data-response-endpoint="<?= e(APP_URL.'/api/emergencies.php') ?>" data-management="<?= e($globalSosManagement) ?>">
  <button type="button" id="globalSosIndicator" class="visually-hidden" tabindex="-1" aria-haspopup="dialog" aria-controls="globalSosDialog">SOS <span id="globalSosCount">Checking...</span></button>
  <dialog id="globalSosDialog" role="alertdialog" aria-labelledby="globalSosTitle" aria-describedby="globalSosMessage">
    <section class="global-sos-card">
      <div class="global-sos-symbol" aria-hidden="true">&#127384;</div>
      <h2 id="globalSosTitle">EMERGENCY SOS ALERT</h2>
      <p id="globalSosMessage">A new emergency has been reported. Immediate attention may be required.</p>
      <p id="globalSosSummary" role="status"></p>
      <div class="global-sos-actions"><button type="button" id="globalSosView">View Emergency Details</button><button type="button" id="globalSosAcknowledge">Acknowledge screen notification</button><a id="globalSosManagement" href="<?= e($globalSosManagement) ?>">Open Emergency Management (acknowledge screen notice)</a><button type="button" id="globalSosClose" hidden>Close details</button></div>
      <p class="global-sos-note">Screen acknowledgment only stops this notification. It does not acknowledge or resolve an emergency report.</p>
      <p id="globalSosStatus" role="status" aria-live="polite"></p>
      <div id="globalSosList" hidden></div>
    </section>
  </dialog>
  <span id="globalSosConnection" class="global-sos-connection" role="status" hidden></span>
</div>
<script src="<?= asset_url('js/global-sos.js') . '?v=' . filemtime(__DIR__.'/../assets/js/global-sos.js') ?>" defer></script>
