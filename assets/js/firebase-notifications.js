/* =====================================================================
   BPC Clinic System — Firebase Cloud Messaging (Web Push)
   ===================================================================== */

(function () {
  'use strict';

  if (!window.FIREBASE_CONFIG) {
    console.warn('[FCM] Firebase config not found. Skipping FCM init.');
    return;
  }

  var firebaseAppLoaded = false;
  var firebaseMessagingLoaded = false;
  var firebaseInitInProgress = false;
  var firebaseInitialized = false;

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = reject;
      document.head.appendChild(s);
    });
  }

  function loadAppScript() {
    if (firebaseAppLoaded) {
      return Promise.resolve();
    }
    return loadScript(
      'https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js'
    ).then(function () {
      firebaseAppLoaded = true;
    });
  }

  function loadMessagingScript() {
    if (firebaseMessagingLoaded) {
      return Promise.resolve();
    }
    return loadScript(
      'https://www.gstatic.com/firebasejs/9.23.0/firebase-messaging-compat.js'
    ).then(function () {
      firebaseMessagingLoaded = true;
    });
  }

  function initFCM() {
    if (firebaseInitInProgress || firebaseInitialized) {
      return Promise.resolve();
    }
    firebaseInitInProgress = true;

    return loadAppScript()
      .then(loadMessagingScript)
      .then(setupFCM)
      .catch(function (err) {
        firebaseInitInProgress = false;
        console.error('[FCM] Failed to load Firebase SDK:', err);
      });
  }

  function setupFCM() {
    try {
      firebase.initializeApp(window.FIREBASE_CONFIG);
    } catch (e) {
      firebaseInitInProgress = false;
      console.error('[FCM] Initialize failed:', e);
      return;
    }

    firebaseInitialized = true;
    firebaseInitInProgress = false;
    console.log('[FCM] Firebase initialized.');

    const messaging = firebase.messaging();

    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      console.warn('[FCM] Service Worker / Push not supported.');
      return;
    }

    var swRegistration = null;
    var tokenRequestStarted = false;

    navigator.serviceWorker.register(APP_URL + '/firebase-messaging-sw.js')
      .then(function (registration) {
        console.log('[FCM] Service worker registered.');
        swRegistration = registration;
        return navigator.serviceWorker.ready;
      })
      .then(function (readyRegistration) {
        console.log('[FCM] Service worker active.');
        if (readyRegistration.active) {
          readyRegistration.active.postMessage({
            type: 'FIREBASE_CONFIG',
            config: window.FIREBASE_CONFIG
          });
          console.log('[FCM] Firebase config sent to service worker.');
        } else {
          console.warn('[FCM] Service worker active is null after ready.');
        }
        return Notification.requestPermission();
      })
      .then(function (permission) {
        if (permission !== 'granted') {
          console.warn('[FCM] Notification permission not granted:', permission);
          return;
        }
        console.log('[FCM] Notification permission granted.');
        console.log('[FCM] Getting FCM token...');
        tokenRequestStarted = true;
        return messaging.getToken({
          vapidKey: window.FIREBASE_CONFIG.vapidKey,
          serviceWorkerRegistration: swRegistration
        });
      })
      .then(function (token) {
        if (!token) {
          console.warn('[FCM] No registration token available.');
          return;
        }
        console.log('[FCM] Token obtained.');
        return saveToken(token);
      })
      .catch(function (err) {
        if (tokenRequestStarted) {
          console.error('[FCM] FCM token error:', err);
        } else if (err.name === 'SecurityError' || err.message.indexOf('ServiceWorker') !== -1 || err.message.indexOf('script evaluation failed') !== -1) {
          console.error('[FCM] Service worker registration failed:', err);
        } else {
          console.error('[FCM] Permission / token error:', err);
        }
      });

    messaging.onMessage(function (payload) {
      var data = payload.data || {};
      var title = payload.notification ? payload.notification.title : 'Notification';
      var body = payload.notification ? payload.notification.body : '';
      var url = data.sos_url || '/';

      if (Notification.permission === 'granted') {
        new Notification(title, {
          body: body,
          icon: '/assets/images/icon-192x192.png',
          badge: '/assets/images/badge-72x72.png',
          data: { url: url },
        });
      }
    });
  }

  function getCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function saveToken(token) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', APP_URL + '/api/notifications/save-fcm-token.php', true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function () {
      var parsed = null;
      try {
        parsed = JSON.parse(xhr.responseText);
      } catch (e) {
        parsed = null;
      }
      if (parsed && parsed.success === true) {
        console.log('[FCM] Token saved to server.');
      } else {
        var msg = parsed && parsed.message ? parsed.message : xhr.responseText;
        console.error('[FCM] Failed to save token:', msg);
      }
    };
    xhr.onerror = function () {
      console.error('[FCM] Network error while saving token.');
    };
    xhr.send('token=' + encodeURIComponent(token) + '&device_type=web&csrf_token=' + encodeURIComponent(getCsrfToken()));
  }

  window.enableFCMNotifications = function () {
    initFCM();
  };

  if (window.Notification && window.Notification.permission === 'granted') {
    initFCM();
  }
})();
