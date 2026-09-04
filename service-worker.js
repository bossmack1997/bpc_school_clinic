/**
 * AI-Enhanced School Clinic Management System for Bulacan Polytechnic College Healthcare Service Improvement — Service Worker
 * Version: bpc-clinic-static-v1
 *
 * Safe PWA caching strategy:
 * - Pre-caches static assets and offline fallback page.
 * - Dynamic / sensitive routes (API endpoints, PHP pages, auth, medical data, SOS)
 *   are NEVER stored in cache.
 * - Navigation requests follow Network-First with graceful fallback to offline.html.
 */

const CACHE_NAME = 'bpc-clinic-static-v1';
const OFFLINE_URL = 'offline.html';

// Safe static assets to pre-cache
const STATIC_ASSETS = [
  'offline.html',
  'manifest.json',
  'assets/icons/icon-180.png',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'assets/images/bpc_logo.png',
  'assets/css/bootstrap.min.css',
  'assets/css/bootstrap-icons.min.css',
  'assets/css/style.css',
  'assets/css/mica.css',
  'assets/css/sweetalert2.min.css',
  'assets/css/aos.css',
  'assets/css/dataTables.bootstrap5.min.css',
  'assets/js/jquery.min.js',
  'assets/js/bootstrap.bundle.min.js',
  'assets/js/sweetalert2.all.min.js',
  'assets/js/aos.js',
  'assets/js/main.js',
  'assets/js/qrcode.min.js',
  'assets/js/html5-qrcode.min.js',
  'assets/js/moment.min.js'
];

// URLs that must NEVER be cached (private/dynamic data)
const NEVER_CACHE_PATTERNS = [
  /\/api\//i,
  /\/auth\//i,
  /\.php(\?.*)?$/i,
  /\/emergencies/i,
  /\/medical_records/i,
  /\/ai_assistant/i,
  /\/mica_tts/i,
  /\/notifications/i,
  /\/profile/i,
  /\/settings/i
];

// Helper: determine if request is sensitive/dynamic
function isNeverCache(url) {
  return NEVER_CACHE_PATTERNS.some((pattern) => pattern.test(url));
}

// ====================================================================
// Install Event: Pre-cache static assets
// ====================================================================
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      // Add assets safely one by one so single 404 does not break entire installation
      for (const asset of STATIC_ASSETS) {
        try {
          await cache.add(asset);
        } catch (err) {
          console.warn('[SW] Could not pre-cache asset:', asset, err);
        }
      }
      return self.skipWaiting();
    })
  );
});

// ====================================================================
// Activate Event: Clean up outdated caches
// ====================================================================
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            console.log('[SW] Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// ====================================================================
// Fetch Event: Routing & Caching Strategy
// ====================================================================
self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  // Only handle GET requests; never cache POST, PUT, DELETE
  if (request.method !== 'GET') {
    return;
  }

  // Check if target is in the same origin
  const isSameOrigin = url.origin === self.location.origin;

  // 1. Navigation requests (HTML pages / PHP navigation) -> Network First with offline.html fallback
  if (request.mode === 'navigate' || request.headers.get('accept')?.includes('text/html')) {
    event.respondWith(
      fetch(request)
        .catch(async () => {
          const cache = await caches.open(CACHE_NAME);
          const cachedOffline = await cache.match(OFFLINE_URL);
          return cachedOffline || new Response('Offline - No connection available', {
            status: 503,
            statusText: 'Service Unavailable',
            headers: { 'Content-Type': 'text/plain' }
          });
        })
    );
    return;
  }

  // 2. Sensitive / dynamic API requests -> Network Only (never cache)
  if (isNeverCache(url.pathname + url.search)) {
    return; // allow standard network fetch without SW caching
  }

  // 3. Static Assets (CSS, JS, Images, Fonts, Icons) -> Cache First / Stale-While-Revalidate
  if (isSameOrigin && (
    url.pathname.includes('/assets/') ||
    url.pathname.endsWith('.png') ||
    url.pathname.endsWith('.jpg') ||
    url.pathname.endsWith('.svg') ||
    url.pathname.endsWith('.css') ||
    url.pathname.endsWith('.js') ||
    url.pathname.endsWith('.woff') ||
    url.pathname.endsWith('.woff2') ||
    url.pathname.endsWith('.json')
  )) {
    event.respondWith(
      caches.match(request).then((cachedResponse) => {
        const fetchPromise = fetch(request).then((networkResponse) => {
          if (networkResponse && networkResponse.status === 200 && networkResponse.type === 'basic') {
            const responseToCache = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => {
              cache.put(request, responseToCache);
            });
          }
          return networkResponse;
        }).catch(() => cachedResponse);

        return cachedResponse || fetchPromise;
      })
    );
  }
});

