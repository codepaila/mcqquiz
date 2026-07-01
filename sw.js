/* ============================================================
   Quiznosis — Service Worker
   Strategy:
     • Shell (HTML/CSS/JS/fonts) → Cache-first, network fallback
     • API requests → Network-first, short-lived cache fallback
     • Images/icons → Cache-first, long-lived
     • Offline fallback page for unresolvable navigations
   ============================================================ */

// Change this version number when you deploy updates
const CACHE_VERSION = 'v3'; // Increment this for new deployments
const SHELL_CACHE     = `qz-shell-${CACHE_VERSION}`;
const API_CACHE       = `qz-api-${CACHE_VERSION}`;
const IMAGE_CACHE     = `qz-img-${CACHE_VERSION}`;
const OFFLINE_URL     = 'pwa/offline.html';

// Assets to precache on install — the app "shell"
const SHELL_ASSETS = [
    // HTML files
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
    // CSS & JS
    'assets/styles.css',
    'assets/components.js',
    'assets/api.js',
    // Icons & manifest
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

// Helper to check if response is fresh
function isResponseFresh(response, maxAgeSeconds) {
    const dateHeader = response.headers.get('date');
    if (!dateHeader) return false;
    const age = (Date.now() - new Date(dateHeader).getTime()) / 1000;
    return age < maxAgeSeconds;
}

// Helper to add cache-busting to a URL
function addCacheBust(url) {
    const urlObj = new URL(url, self.location.origin);
    urlObj.searchParams.set('sw-cache', CACHE_VERSION);
    urlObj.searchParams.set('t', Date.now());
    return urlObj.toString();
}

/* ── Install: precache shell ──────────────────────────────── */
self.addEventListener('install', event => {
  console.log('[SW] Installing version:', CACHE_VERSION);
  event.waitUntil(
    (async () => {
      const cache = await caches.open(SHELL_CACHE);
      
      // Cache assets with cache-busting
      const cachePromises = SHELL_ASSETS.map(async (url) => {
        try {
          // Fetch with cache-busting to ensure fresh content
          const response = await fetch(addCacheBust(url), {
            headers: {
              'Cache-Control': 'no-cache, no-store, must-revalidate'
            }
          });
          if (response.ok) {
            await cache.put(url, response);
            console.log(`[SW] Cached: ${url}`);
          } else {
            console.warn(`[SW] Failed to cache ${url}: ${response.status}`);
          }
        } catch (err) {
          console.warn(`[SW] Error caching ${url}:`, err);
          // Try to cache from existing if available
          try {
            const existingCache = await caches.match(url);
            if (existingCache) {
              await cache.put(url, existingCache);
              console.log(`[SW] Using existing cache for: ${url}`);
            }
          } catch (e) {
            console.warn(`[SW] No existing cache for: ${url}`);
          }
        }
      });

      await Promise.allSettled(cachePromises);
      console.log('[SW] Install complete, skipping waiting...');
      await self.skipWaiting();
    })()
  );
});

/* ── Activate: prune old caches ───────────────────────────── */
self.addEventListener('activate', event => {
  const currentCaches = [SHELL_CACHE, API_CACHE, IMAGE_CACHE];
  console.log('[SW] Activating version:', CACHE_VERSION);
  console.log('[SW] Current caches:', currentCaches);
  
  event.waitUntil(
    (async () => {
      // Delete old caches
      const keys = await caches.keys();
      console.log('[SW] Existing caches:', keys);
      
      const deletePromises = keys
        .filter(k => !currentCaches.includes(k))
        .map(k => {
          console.log(`[SW] Deleting old cache: ${k}`);
          return caches.delete(k);
        });
      
      await Promise.all(deletePromises);
      console.log('[SW] Activation complete, claiming clients...');
      await self.clients.claim();
    })()
  );
});

/* ── Fetch: routing logic ─────────────────────────────────── */
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') return;
  
  // Skip browser extensions
  if (url.protocol === 'chrome-extension:' || url.protocol === 'chrome:') return;
  
  // Skip internal Chrome requests
  if (url.pathname.startsWith('/chrome/')) return;

  // 1. Google Fonts — cache-first, long-lived
  if (FONT_ORIGINS.some(o => url.origin === new URL(o).origin || request.url.startsWith(o))) {
    event.respondWith(handleGoogleFonts(request));
    return;
  }

  // 2. API requests — network-first, 30s stale cache fallback
  if (url.pathname.includes('/api/')) {
    event.respondWith(handleAPIRequest(request));
    return;
  }

  // 3. Images — cache-first with long lifetime
  if (/\.(png|jpe?g|gif|webp|svg|ico)(\?.*)?$/.test(url.pathname)) {
    event.respondWith(handleImageRequest(request));
    return;
  }

  // 4. Navigation requests — network-first with offline fallback
  if (request.mode === 'navigate') {
    event.respondWith(handleNavigation(request));
    return;
  }

  // 5. CSS & JS — network-first with cache fallback (ensures updates)
  if (url.pathname.endsWith('.css') || url.pathname.endsWith('.js')) {
    event.respondWith(handleCSSJS(request));
    return;
  }

  // 6. Everything else — stale-while-revalidate
  event.respondWith(staleWhileRevalidate(request, SHELL_CACHE));
});

/* ── Request Handlers ──────────────────────────────────────── */

// Handle Google Fonts
async function handleGoogleFonts(request) {
  try {
    const cache = await caches.open(SHELL_CACHE);
    const cached = await cache.match(request);
    
    if (cached && isResponseFresh(cached, 60 * 60 * 24 * 30)) { // 30 days
      return cached;
    }
    
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response || cached;
  } catch (error) {
    const cached = await caches.match(request);
    return cached || new Response('', { status: 404 });
  }
}

// Handle API requests
async function handleAPIRequest(request) {
  const cache = await caches.open(API_CACHE);
  
  try {
    // Try network first with cache-busting
    const response = await fetch(request, {
      headers: {
        'Cache-Control': 'no-cache, no-store, must-revalidate'
      }
    });
    
    if (response.ok) {
      cache.put(request, response.clone());
      return response;
    }
    throw new Error('API response not ok');
  } catch (error) {
    // Offline - serve cached if available
    const cached = await cache.match(request);
    if (cached) {
      return cached;
    }
    
    // Return offline JSON
    return new Response(JSON.stringify({
      ok: false,
      error: 'You are offline. Please check your connection.'
    }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' }
    });
  }
}

// Handle image requests
async function handleImageRequest(request) {
  const cache = await caches.open(IMAGE_CACHE);
  const cached = await cache.match(request);
  
  // Return cached image if available (images rarely change)
  if (cached) {
    return cached;
  }
  
  try {
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
      return response;
    }
    throw new Error('Image fetch failed');
  } catch (error) {
    // Return a 1x1 transparent pixel as fallback
    return new Response(
      'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
      { 
        status: 200, 
        headers: { 'Content-Type': 'image/gif' } 
      }
    );
  }
}

// Handle navigation requests
async function handleNavigation(request) {
  try {
    // Try network first with no-cache
    const response = await fetch(request, {
      cache: 'no-store',
      headers: {
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache'
      }
    });
    
    if (response.ok) {
      // Update cache with fresh HTML
      const clone = response.clone();
      const cache = await caches.open(SHELL_CACHE);
      cache.put(request, clone);
      console.log('[SW] Updated cache for:', request.url);
      return response;
    }
    throw new Error('Navigation fetch failed');
  } catch (error) {
    console.log('[SW] Network failed, serving from cache');
    // Try cache
    const cached = await caches.match(request);
    if (cached) {
      return cached;
    }
    // Fallback to offline page
    const offline = await caches.match(OFFLINE_URL);
    return offline || new Response('Offline', { status: 503 });
  }
}

// Handle CSS & JS - ensures updates
async function handleCSSJS(request) {
  const cache = await caches.open(SHELL_CACHE);
  
  try {
    // Try network with no-cache
    const response = await fetch(request, {
      cache: 'no-store',
      headers: {
        'Cache-Control': 'no-cache, no-store, must-revalidate'
      }
    });
    
    if (response.ok) {
      // Cache the fresh version
      const clone = response.clone();
      cache.put(request, clone);
      console.log('[SW] Updated CSS/JS:', request.url);
      return response;
    }
    throw new Error('Network failed');
  } catch (error) {
    // Fallback to cache
    const cached = await cache.match(request);
    if (cached) {
      return cached;
    }
    // Return empty response as last resort
    return new Response('', { status: 404 });
  }
}

// Stale-while-revalidate for other assets
async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  
  try {
    // Check cache first
    const cached = await cache.match(request);
    if (cached) {
      // Return cached response immediately
      // Update cache in background
      fetch(request, {
        headers: {
          'Cache-Control': 'no-cache, no-store, must-revalidate'
        }
      })
      .then(response => {
        if (response.ok) {
          cache.put(request, response);
          console.log('[SW] Background update for:', request.url);
        }
      })
      .catch(err => console.warn('[SW] Background update failed:', err));
      
      return cached;
    }
    
    // No cache, fetch from network
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
      return response;
    }
    
    throw new Error('No cache and network failed');
  } catch (error) {
    console.warn('[SW] Failed to handle request:', request.url, error);
    return new Response('', { status: 404 });
  }
}

/* ── Push notifications ───────────────────────────────────── */
self.addEventListener('push', event => {
  if (!event.data) return;
  
  let data;
  try {
    data = event.data.json();
  } catch {
    data = { title: 'Quiznosis', body: event.data.text() };
  }

  const options = {
    body: data.body || 'New notification from Quiznosis',
    icon: 'assets/icons/icon-192x192.png',
    badge: 'assets/icons/icon-72x72.png',
    vibrate: [200, 100, 200],
    data: { url: data.url || '/dashboard.html' },
    actions: [
      { action: 'open', title: 'Open', icon: 'assets/icons/icon-96x96.png' },
      { action: 'dismiss', title: 'Dismiss' }
    ]
  };

  event.waitUntil(
    self.registration.showNotification(data.title || 'Quiznosis', options)
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  
  if (event.action === 'dismiss') return;
  
  const url = event.notification.data?.url || '/dashboard.html';
  
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(windowClients => {
        const existing = windowClients.find(c => c.url.includes(url) && 'focus' in c);
        if (existing) return existing.focus();
        return clients.openWindow(url);
      })
  );
});

/* ── Skip waiting on message ──────────────────────────────── */
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    console.log('[SW] Received skip waiting message');
    self.skipWaiting();
  }
});

// Log service worker events for debugging
self.addEventListener('error', event => {
  console.error('[SW] Error:', event.error);
});

console.log('[SW] Service worker initialized, version:', CACHE_VERSION);