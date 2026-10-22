<?php
require_once __DIR__ . '/../../config/config.php';
require_primary_admin();

$pageTitle = 'PWA Diagnostics & Device Readiness';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . '/../../includes/head.php'; ?>
</head>
<body>
<div class="app-wrapper">
  <?php include __DIR__ . '/../../components/sidebar.php'; ?>
  <div class="main-content">
    <?php include __DIR__ . '/../../components/navbar.php'; ?>
    <div class="content-area page-enter">

      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
          <h5 class="fw-bold mb-1"><i class="bi bi-cpu text-success me-2"></i> PWA Diagnostics &amp; Device Capabilities</h5>
          <p class="text-muted small mb-0">Live status of Progressive Web App features, offline cache, hardware permissions, and clinic APIs.</p>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-outline-success btn-sm fw-semibold" onclick="runDiagnostics()">
            <i class="bi bi-arrow-clockwise me-1"></i> Re-run Checks
          </button>
          <button class="btn btn-primary btn-sm fw-semibold" onclick="window.BPC_PWA && window.BPC_PWA.promptInstall()">
            <i class="bi bi-download me-1"></i> Install App
          </button>
        </div>
      </div>

      <!-- Diagnostic Grid -->
      <div class="row g-3" id="diagGrid">
        <!-- 1. Secure Context & HTTPS -->
        <div class="col-md-6 col-lg-4">
          <div class="app-card h-100">
            <div class="d-flex align-items-start justify-content-between mb-3">
              <div class="app-stat__icon bg-success-light text-success"><i class="bi bi-shield-lock-fill"></i></div>
              <div id="statusHttps" class="app-badge app-badge--info"><span class="app-dot app-dot--info"></span> Checking...</div>
            </div>
            <h6 class="fw-bold mb-1">Secure Context (HTTPS / Localhost)</h6>
            <p class="text-muted small mb-2" id="descHttps">Checking if the current origin is a valid secure context for PWA features.</p>
            <div class="small text-muted font-monospace" id="detailHttps">—</div>
          </div>
        </div>

        <!-- 2. Web App Manifest -->
        <div class="col-md-6 col-lg-4">
          <div class="app-card h-100">
            <div class="d-flex align-items-start justify-content-between mb-3">
              <div class="app-stat__icon bg-primary-light text-primary"><i class="bi bi-file-earmark-code"></i></div>
              <div id="statusManifest" class="app-badge app-badge--info"><span class="app-dot app-dot--info"></span> Checking...</div>
            </div>
            <h6 class="fw-bold mb-1">Web App Manifest</h6>
            <p class="text-muted small mb-2" id="descManifest">Verifying manifest.json configuration, icons, and theme color.</p>
            <div class="small text-muted font-monospace" id="detailManifest">—</div>
          </div>
        </div>

        <!-- 3. Service Worker -->
        <div class="col-md-6 col-lg-4">
          <div class="app-card h-100">
            <div class="d-flex align-items-start justify-content-between mb-3">
              <div class="app-stat__icon bg-success-light text-success"><i class="bi bi-gear-wide-connected"></i></div>
              <div id="statusSw" class="app-badge app-badge--info"><span class="app-dot app-dot--info"></span> Checking...</div>
            </div>
            <h6 class="fw-bold mb-1">Service Worker &amp; Offline Cache</h6>
            <p class="text-muted small mb-2" id="descSw">Verifying service worker registration and static cache version.</p>
            <div class="small text-muted font-monospace" id="detailSw">—</div>
          </div>
        </div>

        <!-- 4. PWA Display Mode -->
        <div class="col-md-6 col-lg-4">
          <div class="app-card h-100">
            <div class="d-flex align-items-start justify-content-between mb-3">
              <div class="app-stat__icon bg-info-light text-info"><i class="bi bi-phone-fill"></i></div>
              <div id="statusDisplay" class="app-badge app-badge--info"><span class="app-dot app-dot--info"></span> Checking...</div>
            </div>
            <h6 class="fw-bold mb-1">Display Mode / Installation</h6>
            <p class="text-muted small mb-2" id="descDisplay">Checks whether the app is currently running in standalone PWA mode.</p>
            <div class="small text-muted font-monospace" id="detailDisplay">—</div>
          </div>
        </div>

        <!-- 5. Geolocation API (SOS Emergency) -->
        <div class="col-md-6 col-lg-4">
          <div class="app-card h-100">
            <div class="d-flex align-items-start justify-content-between mb-3">
              <div class="app-stat__icon bg-danger-light text-danger"><i class="bi bi-geo-alt-fill"></i></div>
              <div id="statusGps" class="app-badge app-badge--info"><span class="app-dot app-dot--info"></span> Checking...</div>
            </div>
            <h6 class="fw-bold mb-1">Geolocation API (SOS GPS)</h6>
            <p class="text-muted small mb-2" id="descGps">Checks browser GPS capability for emergency location transmission.</p>
            <div class="small text-muted font-monospace mb-2" id="detailGps">—</div>
            <button class="btn btn-outline-danger btn-sm w-100" onclick="testGps()"><i class="bi bi-crosshair me-1"></i> Test GPS Capture</button>
          </div>
        </div>

        <!-- 6. Camera API (QR Scanner) -->
        <div class="col-md-6 col-lg-4">
          <div class="app-card h-100">
            <div class="d-flex align-items-start justify-content-between mb-3">
              <div class="app-stat__icon bg-primary-light text-primary"><i class="bi bi-camera-video-fill"></i></div>
              <div id="statusCamera" class="app-badge app-badge--info"><span class="app-dot app-dot--info"></span> Checking...</div>
            </div>
            <h6 class="fw-bold mb-1">Camera Stream (QR Scanner)</h6>
            <p class="text-muted small mb-2" id="descCamera">Checks webcam/rear camera video stream for student QR check-in.</p>
            <div class="small text-muted font-monospace mb-2" id="detailCamera">—</div>
            <button class="btn btn-outline-primary btn-sm w-100" onclick="testCamera()"><i class="bi bi-camera me-1"></i> Test Camera Stream</button>
          </div>
        </div>

        <!-- 7. Microphone API (MICA Voice AI) -->
        <div class="col-md-6 col-lg-4">
          <div class="app-card h-100">
            <div class="d-flex align-items-start justify-content-between mb-3">
              <div class="app-stat__icon bg-success-light text-success"><i class="bi bi-mic-fill"></i></div>
              <div id="statusMic" class="app-badge app-badge--info"><span class="app-dot app-dot--info"></span> Checking...</div>
            </div>
            <h6 class="fw-bold mb-1">Microphone &amp; Speech (MICA AI)</h6>
            <p class="text-muted small mb-2" id="descMic">Checks audio input &amp; Web Speech Recognition for voice assistance.</p>
            <div class="small text-muted font-monospace mb-2" id="detailMic">—</div>
            <button class="btn btn-outline-success btn-sm w-100" onclick="testMicrophone()"><i class="bi bi-mic me-1"></i> Test Microphone</button>
          </div>
        </div>

        <!-- 8. Push Notifications API -->
        <div class="col-md-6 col-lg-4">
          <div class="app-card h-100">
            <div class="d-flex align-items-start justify-content-between mb-3">
              <div class="app-stat__icon bg-warning-light text-warning"><i class="bi bi-bell-fill"></i></div>
              <div id="statusPush" class="app-badge app-badge--info"><span class="app-dot app-dot--info"></span> Checking...</div>
            </div>
            <h6 class="fw-bold mb-1">Notification Permission</h6>
            <p class="text-muted small mb-2" id="descPush">Checks browser permissions for clinic alert push notifications.</p>
            <div class="small text-muted font-monospace mb-2" id="detailPush">—</div>
            <button class="btn btn-outline-warning btn-sm w-100 text-dark" onclick="requestNotification()"><i class="bi bi-bell me-1"></i> Request Permission</button>
          </div>
        </div>

        <!-- 9. Network Status -->
        <div class="col-md-6 col-lg-4">
          <div class="app-card h-100">
            <div class="d-flex align-items-start justify-content-between mb-3">
              <div class="app-stat__icon bg-secondary text-white"><i class="bi bi-wifi"></i></div>
              <div id="statusNetwork" class="app-badge app-badge--info"><span class="app-dot app-dot--info"></span> Checking...</div>
            </div>
            <h6 class="fw-bold mb-1">Network Connectivity</h6>
            <p class="text-muted small mb-2" id="descNetwork">Live connection status and effective connection type.</p>
            <div class="small text-muted font-monospace" id="detailNetwork">—</div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<?php include __DIR__ . '/../../components/footer.php'; ?>
<script>
(function () {
  'use strict';

  function setPill(id, type, text) {
    const el = document.getElementById(id);
    if (!el) return;
    el.className = 'app-badge app-badge--' + type;
    el.innerHTML = '<span class="app-dot app-dot--' + type + '"></span> ' + text;
  }

  window.runDiagnostics = function () {
    // 1. Secure Context
    const isSecure = window.isSecureContext || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
    if (isSecure) {
      setPill('statusHttps', 'pass', 'Active');
      document.getElementById('detailHttps').textContent = 'Origin: ' + window.location.origin + ' (Secure Context: OK)';
    } else {
      setPill('statusHttps', 'fail', 'Insecure');
      document.getElementById('detailHttps').textContent = 'Insecure origin. PWA requires HTTPS in production.';
    }

    // 2. Manifest
    fetch(APP_URL + '/manifest.json')
      .then(res => res.json())
      .then(data => {
        setPill('statusManifest', 'pass', 'Valid');
        document.getElementById('detailManifest').textContent = data.short_name + ' | ' + data.display + ' | ' + data.icons.length + ' icons';
      })
      .catch(err => {
        setPill('statusManifest', 'fail', 'Error');
        document.getElementById('detailManifest').textContent = 'Failed to load manifest: ' + err.message;
      });

    // 3. Service Worker
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistration(APP_URL + '/').then(reg => {
        if (reg) {
          setPill('statusSw', 'pass', 'Active');
          document.getElementById('detailSw').textContent = 'Scope: ' + reg.scope + ' (Active: ' + (reg.active ? 'Yes' : 'Waiting') + ')';
        } else {
          setPill('statusSw', 'warn', 'Registering');
          document.getElementById('detailSw').textContent = 'Service Worker not yet registered on this scope. Reloading may activate it.';
        }
      });
    } else {
      setPill('statusSw', 'fail', 'Unsupported');
      document.getElementById('detailSw').textContent = 'Browser does not support Service Workers.';
    }

    // 4. Display Mode
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (isStandalone) {
      setPill('statusDisplay', 'pass', 'Standalone');
      document.getElementById('detailDisplay').textContent = 'Running as installed PWA application.';
    } else {
      setPill('statusDisplay', 'info', 'Browser Mode');
      document.getElementById('detailDisplay').textContent = 'Running in web browser window (Installable).';
    }

    // 5. Geolocation
    if ('geolocation' in navigator) {
      setPill('statusGps', 'pass', 'Available');
      document.getElementById('detailGps').textContent = 'navigator.geolocation supported for SOS coordinates.';
    } else {
      setPill('statusGps', 'fail', 'Unsupported');
      document.getElementById('detailGps').textContent = 'Geolocation API not supported on this browser.';
    }

    // 6. Camera
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
      setPill('statusCamera', 'pass', 'Supported');
      document.getElementById('detailCamera').textContent = 'MediaDevices API ready for QR barcode scanning.';
    } else {
      setPill('statusCamera', 'fail', 'Unsupported');
      document.getElementById('detailCamera').textContent = 'MediaDevices API unavailable.';
    }

    // 7. Microphone & Speech
    const hasSpeech = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
    const hasMic = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    if (hasSpeech && hasMic) {
      setPill('statusMic', 'pass', 'Full Support');
      document.getElementById('detailMic').textContent = 'SpeechRecognition + Audio Stream available for MICA.';
    } else if (hasMic) {
      setPill('statusMic', 'warn', 'Audio Only');
      document.getElementById('detailMic').textContent = 'Microphone stream supported; SpeechRecognition unavailable.';
    } else {
      setPill('statusMic', 'fail', 'Unsupported');
      document.getElementById('detailMic').textContent = 'Audio input not supported.';
    }

    // 8. Push Notifications
    if ('Notification' in window) {
      const perm = Notification.permission;
      if (perm === 'granted') {
        setPill('statusPush', 'pass', 'Granted');
      } else if (perm === 'denied') {
        setPill('statusPush', 'fail', 'Denied');
      } else {
        setPill('statusPush', 'warn', 'Default');
      }
      document.getElementById('detailPush').textContent = 'Permission state: ' + perm;
    } else {
      setPill('statusPush', 'fail', 'Unsupported');
      document.getElementById('detailPush').textContent = 'Notification API not supported.';
    }

    // 9. Network Status
    if (navigator.onLine) {
      setPill('statusNetwork', 'pass', 'Online');
      const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      const type = conn ? (conn.effectiveType || conn.type || 'Connected') : 'Active Connection';
      document.getElementById('detailNetwork').textContent = 'Status: Online (' + type + ')';
    } else {
      setPill('statusNetwork', 'fail', 'Offline');
      document.getElementById('detailNetwork').textContent = 'Status: Disconnected (Fallback active)';
    }
  };

  window.testGps = function () {
    if (!('geolocation' in navigator)) {
      Swal.fire({ icon: 'error', title: 'Unsupported', text: 'Geolocation is not supported.' });
      return;
    }
    Swal.fire({
      title: 'Testing GPS Location',
      text: 'Requesting current GPS coordinates from device...',
      allowOutsideClick: false,
      didOpen: () => { Swal.showLoading(); }
    });
    navigator.geolocation.getCurrentPosition(
      pos => {
        Swal.fire({
          icon: 'success',
          title: 'GPS Coordinates Captured!',
          html: 'Latitude: <strong>' + pos.coords.latitude + '</strong><br>' +
                'Longitude: <strong>' + pos.coords.longitude + '</strong><br>' +
                'Accuracy: ±' + Math.round(pos.coords.accuracy) + ' meters'
        });
      },
      err => {
        Swal.fire({
          icon: 'error',
          title: 'GPS Capture Failed',
          text: 'Error Code ' + err.code + ': ' + err.message
        });
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
  };

  window.testCamera = function () {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      Swal.fire({ icon: 'error', title: 'Unsupported', text: 'Camera access is not supported.' });
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
      .then(stream => {
        Swal.fire({
          icon: 'success',
          title: 'Camera Stream Active!',
          text: 'Camera permission granted and video stream acquired successfully.'
        });
        stream.getTracks().forEach(t => t.stop());
      })
      .catch(err => {
        Swal.fire({
          icon: 'error',
          title: 'Camera Access Denied',
          text: err.name + ': ' + err.message
        });
      });
  };

  window.testMicrophone = function () {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      Swal.fire({ icon: 'error', title: 'Unsupported', text: 'Microphone access is not supported.' });
      return;
    }
    navigator.mediaDevices.getUserMedia({ audio: true })
      .then(stream => {
        Swal.fire({
          icon: 'success',
          title: 'Microphone Active!',
          text: 'Audio input stream acquired successfully for MICA AI.'
        });
        stream.getTracks().forEach(t => t.stop());
      })
      .catch(err => {
        Swal.fire({
          icon: 'error',
          title: 'Microphone Access Denied',
          text: err.name + ': ' + err.message
        });
      });
  };

  window.requestNotification = function () {
    if (!('Notification' in window)) {
      Swal.fire({ icon: 'error', title: 'Unsupported', text: 'Notifications are not supported.' });
      return;
    }
    Notification.requestPermission().then(permission => {
      runDiagnostics();
      if (permission === 'granted') {
        new Notification('BPC Clinic System', {
          body: 'Push notifications are enabled successfully!',
          icon: APP_URL + '/assets/icons/icon-192.png'
        });
      }
    });
  };

  // Run on page load
  $(document).ready(function () {
    runDiagnostics();
  });
})();
</script>
</body>
</html>

