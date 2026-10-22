importScripts(
  'https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js'
);

importScripts(
  'https://www.gstatic.com/firebasejs/9.23.0/firebase-messaging-compat.js'
);

console.log('[FCM-SW] Service worker loaded.');

var messaging = null;
var backgroundHandlerSetup = false;

self.addEventListener('message', function(event) {
  if (event.data && event.data.type === 'FIREBASE_CONFIG') {
    try {
      console.log('[FCM-SW] Firebase config received.');
      if (!firebase.apps.length) {
        firebase.initializeApp(event.data.config);
      }
      messaging = firebase.messaging();
      console.log('[FCM-SW] Firebase initialized.');
      setupBackgroundMessageHandler();
    } catch (e) {
      console.error('[FCM-SW] Initialize failed:', e);
    }
  }
});

function setupBackgroundMessageHandler() {
  if (!messaging || backgroundHandlerSetup) return;
  backgroundHandlerSetup = true;

  messaging.onBackgroundMessage(function(payload) {
    console.log('[FCM-SW] Background message received.');
    var data = payload.data || {};
    var title = payload.notification ? payload.notification.title : 'Notification';
    var body = payload.notification ? payload.notification.body : '';
    var sosUrl = data.sos_url || '/';
    var sosId = data.sos_id || '';

    var options = {
      body: body,
      icon: '/assets/icons/icon-192.png',
      badge: '/assets/icons/icon-180.png',
      vibrate: [200, 100, 200],
      tag: 'sos-' + sosId,
      requireInteraction: true,
      data: { url: sosUrl },
    };

    self.registration.showNotification(title, options);
  });
}

self.addEventListener('notificationclick', function(event) {
  console.log('[FCM-SW] Notification clicked.');
  event.notification.close();
  var url = event.notification.data && event.notification.data.url ? event.notification.data.url : '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
      for (var i = 0; i < clientList.length; i++) {
        if (clientList[i].url === url && 'focus' in clientList[i]) {
          return clientList[i].focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(url);
      }
    })
  );
});
