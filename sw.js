/* ============================================================
   Quiznosis — Service Worker
   Strategy:
     • Shell (HTML/CSS/JS/fonts) → Cache-first, network fallback
     • API requests → Network-first, short-lived cache fallback
     • Images/icons → Cache-first, long-lived
     • Offline fallback page for unresolvable navigations
   ============================================================ */

const CACHE_VERSION   = 'v24.01';
const SHELL_CACHE     = `qz-shell-${CACHE_VERSION}`;
const API_CACHE       = `qz-api-${CACHE_VERSION}`;
const IMAGE_CACHE     = `qz-img-${CACHE_VERSION}`;
const OFFLINE_URL     = 'pwa/offline.html';

/* Assets to precache on install — the app "shell" */
const SHELL_ASSETS = [
  'index.html',
  'login.html',
  'register.html',
  'dashboard.html',
  'quizzes.html',
  'courses.html',
  'bookmarks.html',
  'attempts.html',
  'stats.html',
  'profile.html',
  'announcements.html',
  'notifications.html',
  'my-courses.html',
  'my-quiz-sets.html',
  'forgot.html',
  'contact.html',
  'admin-contact.html',
  'assets/styles.css',
  'assets/components.js',
  'assets/api.js',
  'assets/icon.png',
  'assets/icons/icon-192x192.png',
  'assets/icons/icon-512x512.png',
  'manifest.json',
  OFFLINE_URL,
];

/* Google Fonts origins to cache */
const FONT_ORIGINS = [
  'https://fonts.googleapis.com',
  'https://fonts.gstatic.com',
];

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);

  const cached = await cache.match(request);

  const networkFetch = fetch(request)
    .then(response => {
      if (response && response.ok) {
        cache.put(request, response.clone());
      }
      return response;
    })
    .catch(() => null);

  return cached || networkFetch;
}

/* ── Install: precache shell ──────────────────────────────── */
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(cache => {
      // Cache individually so one failure doesn't break everything
      return Promise.allSettled(
        SHELL_ASSETS.map(url =>
          cache.add(url).catch(err =>
            console.warn(`[SW] Failed to cache ${url}:`, err)
          )
        )
      );
    }).then(() => self.skipWaiting())
  );
});

/* ── Activate: prune old caches ───────────────────────────── */
self.addEventListener('activate', event => {
  const currentCaches = [SHELL_CACHE, API_CACHE, IMAGE_CACHE];
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys
          .filter(k => !currentCaches.includes(k))
          .map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

/* ── Fetch: routing logic ─────────────────────────────────── */
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET and chrome-extension requests
  if (request.method !== 'GET') return;
  if (url.protocol === 'chrome-extension:') return;

  // 1. Google Fonts — cache-first, long-lived
  if (FONT_ORIGINS.some(o => url.origin === new URL(o).origin || request.url.startsWith(o))) {
    event.respondWith(cacheFirst(request, SHELL_CACHE, 60 * 60 * 24 * 365));
    return;
  }

  // 2. API requests — network-first, 30s stale cache fallback
  if (url.pathname.includes('/api/')) {
    event.respondWith(networkFirst(request, API_CACHE, 30));
    return;
  }

  // 3. Images (icons, PNGs, JPEGs) — cache-first
  if (/\.(png|jpe?g|gif|webp|svg|ico)(\?.*)?$/.test(url.pathname)) {
    event.respondWith(cacheFirst(request, IMAGE_CACHE));
    return;
  }

  // 4. Navigation requests (HTML pages) — network-first, offline fallback
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then(response => {
          if (response.ok) {
            const clone = response.clone();
            caches.open(SHELL_CACHE).then(cache => cache.put(request, clone));
          }
          return response;
        })
        .catch(() =>
          caches.match(request)
            .then(cached => cached || caches.match(OFFLINE_URL))
        )
    );
    return;
  }

  // 5. Everything else (CSS, JS) — cache-first
 event.respondWith(staleWhileRevalidate(request, SHELL_CACHE));
});

/* ── Helpers ──────────────────────────────────────────────── */

/**
 * Cache-first: serve from cache, fall back to network, store in cache.
 * Optional maxAge in seconds (doesn't affect stored entry, just a hint).
 */
async function cacheFirst(request, cacheName) {
  const cache  = await caches.open(cacheName);
  const cached = await cache.match(request);
  if (cached) return cached;

  try {
    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
  } catch (err) {
    // Return a minimal fallback for images
    if (/\.(png|jpe?g|gif|webp|svg|ico)(\?.*)?$/.test(new URL(request.url).pathname)) {
      return new Response('', { status: 200, headers: { 'Content-Type': 'image/png' } });
    }
    throw err;
  }
}

/**
 * Network-first: try network, if offline serve stale cache.
 * maxAge = seconds to consider cached API response fresh enough.
 */
async function networkFirst(request, cacheName, maxAge = 30) {
  const cache = await caches.open(cacheName);

  try {
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    // Offline — serve stale if not too old
    const cached = await cache.match(request);
    if (cached) {
      const date = cached.headers.get('date');
      if (date) {
        const age = (Date.now() - new Date(date).getTime()) / 1000;
        if (age < maxAge * 60) return cached; // within maxAge minutes
      }
      return cached; // serve stale regardless in offline mode
    }
    // No cache — return structured offline JSON for API
    return new Response(JSON.stringify({
      ok: false,
      error: 'You are offline. Please check your connection.'
    }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' }
    });
  }
}

/* ── Push notifications (future-ready) ───────────────────── */
self.addEventListener('push', event => {
  if (!event.data) return;
  let data;
  try { data = event.data.json(); } catch { data = { title: 'Quiznosis', body: event.data.text() }; }

  event.waitUntil(
    self.registration.showNotification(data.title || 'Quiznosis', {
      body:    data.body    || '',
      icon:    'assets/icons/icon-192x192.png',
      badge:   'assets/icons/icon-72x72.png',
      vibrate: [200, 100, 200],
      data:    { url: data.url || '/dashboard.html' },
      actions: [
        { action: 'open',    title: 'Open',    icon: 'assets/icons/icon-96x96.png' },
        { action: 'dismiss', title: 'Dismiss' },
      ]
    })
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  if (event.action === 'dismiss') return;
  const url = event.notification.data?.url || '/dashboard.html';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(windowClients => {
        const existing = windowClients.find(c => c.url === url && 'focus' in c);
        if (existing) return existing.focus();
        return clients.openWindow(url);
      })
  );
});

/* ── Skip waiting on message (triggered by update toast) ─── */
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
