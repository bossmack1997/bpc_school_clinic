/**
 * BPC School Clinic Management System — PWA Module
 * Handles Service Worker registration, install prompt banner, and offline detection.
 */
(function () {
  'use strict';

  var deferredPrompt = null;
  var DISMISS_KEY = 'bpc_pwa_dismissed_at';
  var DISMISS_DURATION_MS = 7 * 24 * 60 * 60 * 1000; // 7 days

  // Determine base application URL
  var baseUrl = typeof APP_URL !== 'undefined' && APP_URL ? APP_URL : '';
  if (!baseUrl) {
    baseUrl = window.location.origin + (window.location.pathname.includes('/bpc_clinic_system') ? '/bpc_clinic_system' : '');
  }

  // 1. Service Worker Registration
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      var swUrl = baseUrl + '/service-worker.js';
      navigator.serviceWorker.register(swUrl, { scope: baseUrl + '/' })
        .then(function (registration) {
          console.log('[PWA] Service Worker registered successfully with scope:', registration.scope);

          // Check for updates
          registration.addEventListener('updatefound', function () {
            var newWorker = registration.installing;
            if (newWorker) {
              newWorker.addEventListener('statechange', function () {
                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                  console.log('[PWA] New version of BPC Clinic available.');
                }
              });
            }
          });
        })
        .catch(function (error) {
          console.warn('[PWA] Service Worker registration failed:', error);
        });
    });
  }

  // 2. Check standalone state
  function isStandalone() {
    return (
      window.matchMedia('(display-mode: standalone)').matches ||
      window.matchMedia('(display-mode: fullscreen)').matches ||
      window.navigator.standalone === true ||
      document.referrer.includes('android-app://')
    );
  }

  // 3. Check if user recently dismissed install prompt
  function isDismissed() {
    var dismissedAt = localStorage.getItem(DISMISS_KEY);
    if (!dismissedAt) return false;
    var timeElapsed = Date.now() - parseInt(dismissedAt, 10);
    return timeElapsed < DISMISS_DURATION_MS;
  }

  // 4. Create and show install prompt banner
  function showInstallBanner() {
    if (isStandalone() || isDismissed()) return;
    if (document.getElementById('pwaInstallBanner')) return;

    var banner = document.createElement('div');
    banner.id = 'pwaInstallBanner';
    banner.className = 'pwa-install-banner';
    banner.innerHTML =
      '<img src="' + baseUrl + '/assets/icons/icon-192.png" alt="BPC Clinic" class="pwa-banner-icon" onerror="this.src=\'' + baseUrl + '/assets/images/bpc_logo.png\'">' +
      '<div class="pwa-banner-content">' +
        '<div class="pwa-banner-title">Install BPC Clinic App</div>' +
        '<p class="pwa-banner-desc">Fast access, health pass &amp; offline support.</p>' +
      '</div>' +
      '<div class="pwa-banner-actions">' +
        '<button type="button" class="btn btn-sm btn-primary fw-semibold px-3" id="pwaInstallBtn">Install</button>' +
        '<button type="button" class="btn-close btn-close-sm" id="pwaDismissBtn" aria-label="Close"></button>' +
      '</div>';

    document.body.appendChild(banner);

    // Bind install button
    document.getElementById('pwaInstallBtn').addEventListener('click', function () {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (choiceResult) {
          if (choiceResult.outcome === 'accepted') {
            console.log('[PWA] User accepted the install prompt');
          } else {
            console.log('[PWA] User dismissed the install prompt');
          }
          deferredPrompt = null;
          hideInstallBanner();
        });
      }
    });

    // Bind dismiss button
    document.getElementById('pwaDismissBtn').addEventListener('click', function () {
      localStorage.setItem(DISMISS_KEY, Date.now().toString());
      hideInstallBanner();
    });
  }

  function hideInstallBanner() {
    var banner = document.getElementById('pwaInstallBanner');
    if (banner) {
      banner.classList.add('hiding');
      setTimeout(function () {
        if (banner.parentNode) banner.parentNode.removeChild(banner);
      }, 300);
    }
  }

  // 5. Capture beforeinstallprompt
  window.addEventListener('beforeinstallprompt', function (e) {
    // Prevent immediate automatic browser prompt
    e.preventDefault();
    deferredPrompt = e;
    console.log('[PWA] beforeinstallprompt event captured');

    // Show custom installation UI
    showInstallBanner();
  });

  // 6. Listen for appinstalled
  window.addEventListener('appinstalled', function () {
    console.log('[PWA] BPC Clinic app was installed successfully');
    deferredPrompt = null;
    localStorage.removeItem(DISMISS_KEY);
    hideInstallBanner();
  });

  // 7. Expose public helper methods
  window.BPC_PWA = {
    isInstalled: isStandalone,
    canInstall: function () {
      return !!deferredPrompt;
    },
    promptInstall: function () {
      if (deferredPrompt) {
        deferredPrompt.prompt();
      } else if (isStandalone()) {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'info',
            title: 'Already Installed',
            text: 'You are already using the installed BPC Clinic app.',
            confirmButtonColor: '#0E7A3E'
          });
        } else {
          alert('BPC Clinic is already installed.');
        }
      } else {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'info',
            title: 'Install BPC Clinic App',
            html: 'To install on iOS/Safari, tap the <strong>Share</strong> button <i class="bi bi-box-arrow-up"></i> and select <strong>"Add to Home Screen"</strong>.<br><br>On Chrome/Edge, click the install icon in the address bar.',
            confirmButtonColor: '#0E7A3E'
          });
        } else {
          alert('To install on iOS/Safari, tap Share and select "Add to Home Screen".');
        }
      }
    }
  };
})();

