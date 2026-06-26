/* ============================================================
   Quiznosis PWA — pwa.js
   Drop this script on every page. It handles:
     1. Service worker registration
     2. Install prompt (Android/Chrome — beforeinstallprompt)
     3. iOS Safari "Add to Home Screen" banner
     4. Standalone mode detection (hide install UI when already installed)
     5. Update notification (new SW available)
     6. Network status toast (online/offline indicator)
   ============================================================ */

const PWA_DISMISSED_KEY  = 'qz-pwa-install-dismissed';
const PWA_INSTALL_DELAY  = 3000;   // ms before showing install banner
const SW_PATH            = '/sw.js';
const SW_SCOPE           = '/';

/* ── 1. Service worker registration ──────────────────────── */
let swRegistration = null;

export async function registerServiceWorker() {
  if (!('serviceWorker' in navigator)) return null;

  try {
    swRegistration = await navigator.serviceWorker.register(SW_PATH, {
      scope: SW_SCOPE,
      updateViaCache: 'none',
    });

    // Listen for a new SW waiting → show update toast
    swRegistration.addEventListener('updatefound', () => {
      const newWorker = swRegistration.installing;
      if (!newWorker) return;
      newWorker.addEventListener('statechange', () => {
        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
          showUpdateToast();
        }
      });
    });

    // Periodic update check (every 30 min while page is open)
    setInterval(() => swRegistration?.update(), 30 * 60 * 1000);

    return swRegistration;
  } catch (err) {
    console.warn('[PWA] SW registration failed:', err);
    return null;
  }
}

/* ── 2. Install prompt handling ──────────────────────────── */
let deferredPrompt = null;

export function initInstallPrompt() {
  // Already installed / running standalone — do nothing
  if (isRunningStandalone()) return;

  // Dismissed too recently
  if (wasRecentlyDismissed()) return;

  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  const isInWebAppiOS = window.navigator.standalone === true;

  if (isIOS && !isInWebAppiOS) {
    // iOS Safari: no beforeinstallprompt, show manual guide
    setTimeout(showIOSBanner, PWA_INSTALL_DELAY);
    return;
  }

  // Android/Chrome — capture beforeinstallprompt
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    setTimeout(showAndroidBanner, PWA_INSTALL_DELAY);
  });

  // Fired when installed via browser dialog
  window.addEventListener('appinstalled', () => {
    hideBanner();
    deferredPrompt = null;
    try { localStorage.setItem(PWA_DISMISSED_KEY, 'installed'); } catch {}
  });
}

/* ── 3. Banner UI ─────────────────────────────────────────── */
function injectBannerCSS() {
  if (document.getElementById('qz-pwa-css')) return;
  const s = document.createElement('style');
  s.id = 'qz-pwa-css';
  s.textContent = `
    /* Install banner */
    #qz-pwa-banner {
      position: fixed;
      bottom: 0; left: 0; right: 0;
      bottom: env(safe-area-inset-bottom, 0px);
      z-index: 9999;
      background: var(--paper, #F7F3EC);
      border-top: 1.5px solid var(--paper-edge, #E4DCCD);
      padding: 14px 20px calc(14px + env(safe-area-inset-bottom));
      box-shadow: 0 -8px 32px rgba(0,0,0,0.12);
      transform: translateY(110%);
      transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
      will-change: transform;
      display: flex;
      align-items: center;
      gap: 14px;
    }
    /* On mobile, sit above the bottom nav bar */
    @media (max-width: 640px) {
      #qz-pwa-banner {
        bottom: calc(58px + env(safe-area-inset-bottom, 0px));
      }
    }
    #qz-pwa-banner.visible { transform: translateY(0); }
    [data-theme="dark"] #qz-pwa-banner {
      background: #16203A;
      border-top-color: #2A3A5C;
      box-shadow: 0 -8px 32px rgba(0,0,0,0.4);
    }
    .qz-pwa-icon {
      width: 48px; height: 48px;
      border-radius: 12px;
      overflow: hidden; flex-shrink: 0;
      background: var(--accent-soft, #F3E4D6);
    }
    .qz-pwa-icon img { width: 100%; height: 100%; object-fit: cover; }
    .qz-pwa-text { flex: 1; min-width: 0; }
    .qz-pwa-text strong {
      display: block;
      font-size: 0.92rem;
      font-weight: 600;
      color: var(--ink, #1A1814);
      font-family: var(--font-body, 'DM Sans', system-ui, sans-serif);
    }
    .qz-pwa-text span {
      font-size: 0.78rem;
      color: var(--ink-muted, #8C8473);
      font-family: var(--font-body, 'DM Sans', system-ui, sans-serif);
    }
    .qz-pwa-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .qz-pwa-install-btn {
      background: var(--accent, #B8531C);
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 9px 18px;
      font-size: 0.88rem;
      font-weight: 600;
      font-family: var(--font-body, 'DM Sans', system-ui, sans-serif);
      cursor: pointer;
      white-space: nowrap;
      transition: opacity .15s;
    }
    .qz-pwa-install-btn:hover { opacity: .88; }
    .qz-pwa-close-btn {
      background: transparent;
      border: none;
      cursor: pointer;
      color: var(--ink-muted, #8C8473);
      padding: 6px;
      border-radius: 6px;
      display: flex; align-items: center; justify-content: center;
      transition: color .15s;
    }
    .qz-pwa-close-btn:hover { color: var(--ink, #1A1814); }

    /* iOS steps sheet */
    #qz-pwa-ios-sheet {
      position: fixed;
      bottom: 0; left: 0; right: 0;
      z-index: 9999;
      background: var(--paper, #F7F3EC);
      border-top: 1.5px solid var(--paper-edge, #E4DCCD);
      border-radius: 20px 20px 0 0;
      padding: 20px 24px calc(24px + env(safe-area-inset-bottom));
      box-shadow: 0 -8px 40px rgba(0,0,0,0.18);
      transform: translateY(110%);
      transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    #qz-pwa-ios-sheet.visible { transform: translateY(0); }
    [data-theme="dark"] #qz-pwa-ios-sheet {
      background: #16203A;
      border-top-color: #2A3A5C;
    }
    .qz-ios-handle {
      width: 40px; height: 4px; border-radius: 2px;
      background: var(--paper-edge, #E4DCCD);
      margin: 0 auto 18px; display: block;
    }
    .qz-ios-title {
      font-family: var(--font-display, 'Instrument Serif', Georgia, serif);
      font-size: 1.25rem;
      font-weight: 400;
      color: var(--ink, #1A1814);
      margin-bottom: 6px;
      text-align: center;
    }
    .qz-ios-sub {
      font-size: 0.83rem;
      color: var(--ink-muted, #8C8473);
      text-align: center;
      margin-bottom: 22px;
      font-family: var(--font-body, 'DM Sans', system-ui, sans-serif);
    }
    .qz-ios-steps { list-style: none; }
    .qz-ios-steps li {
      display: flex; align-items: center; gap: 14px;
      padding: 12px 0;
      border-bottom: 1px solid var(--paper-edge, #E4DCCD);
      font-size: 0.9rem;
      color: var(--ink-soft, #4A453C);
      font-family: var(--font-body, 'DM Sans', system-ui, sans-serif);
    }
    .qz-ios-steps li:last-child { border-bottom: none; }
    .qz-ios-step-num {
      width: 30px; height: 30px; border-radius: 50%;
      background: var(--accent, #B8531C);
      color: #fff;
      font-weight: 700;
      font-size: 0.85rem;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      font-family: var(--font-body, 'DM Sans', system-ui, sans-serif);
    }
    .qz-ios-dismiss {
      margin-top: 18px;
      width: 100%;
      background: transparent;
      border: 1.5px solid var(--paper-edge, #E4DCCD);
      border-radius: 10px;
      padding: 12px;
      font-size: 0.9rem;
      color: var(--ink-soft, #4A453C);
      cursor: pointer;
      font-family: var(--font-body, 'DM Sans', system-ui, sans-serif);
      transition: border-color .15s;
    }
    .qz-ios-dismiss:hover { border-color: var(--accent, #B8531C); }

    /* Sheet backdrop */
    #qz-pwa-backdrop {
      position: fixed; inset: 0;
      background: rgba(0,0,0,0);
      z-index: 9998;
      display: none;
      transition: background .3s;
    }
    #qz-pwa-backdrop.visible {
      display: block;
      background: rgba(0,0,0,0.4);
    }

    /* Update toast */
    #qz-sw-update-toast {
      position: fixed;
      top: calc(72px + env(safe-area-inset-top));
      left: 50%;
      transform: translateX(-50%) translateY(-120px);
      z-index: 9997;
      background: var(--ink, #1A1814);
      color: var(--paper, #F7F3EC);
      padding: 12px 20px;
      border-radius: 10px;
      font-size: 0.88rem;
      font-family: var(--font-body, 'DM Sans', system-ui, sans-serif);
      display: flex; align-items: center; gap: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.25);
      white-space: nowrap;
      transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    #qz-sw-update-toast.visible { transform: translateX(-50%) translateY(0); }
    .qz-update-reload {
      background: var(--accent, #B8531C);
      color: #fff; border: none;
      border-radius: 6px; padding: 6px 14px;
      font-size: 0.82rem; font-weight: 600;
      cursor: pointer;
      font-family: var(--font-body, 'DM Sans', system-ui, sans-serif);
    }

    /* Network status pill */
    #qz-network-pill {
      position: fixed;
      bottom: calc(20px + env(safe-area-inset-bottom));
      left: 50%;
      transform: translateX(-50%) translateY(60px);
      z-index: 9996;
      padding: 8px 18px;
      border-radius: 100px;
      font-size: 0.82rem;
      font-weight: 600;
      font-family: var(--font-body, 'DM Sans', system-ui, sans-serif);
      display: flex; align-items: center; gap: 7px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.2);
      transition: transform 0.35s ease, opacity .3s;
      opacity: 0;
      pointer-events: none;
    }
    #qz-network-pill.show { transform: translateX(-50%) translateY(0); opacity: 1; }
    #qz-network-pill.offline { background: #9B2C2C; color: #fff; }
    #qz-network-pill.online  { background: #5C7A47; color: #fff; }
    .qz-net-dot {
      width: 7px; height: 7px; border-radius: 50%;
      background: currentColor; opacity: .85;
    }
  `;
  document.head.appendChild(s);
}

function showAndroidBanner() {
  if (!deferredPrompt || isRunningStandalone()) return;
  injectBannerCSS();

  const banner = document.createElement('div');
  banner.id = 'qz-pwa-banner';
  banner.innerHTML = `
    <div class="qz-pwa-icon">
      <img src="/assets/icons/icon-192x192.png" alt="Quiznosis">
    </div>
    <div class="qz-pwa-text">
      <strong>Install Quiznosis</strong>
      <span>Fast, offline access to all your quizzes</span>
    </div>
    <div class="qz-pwa-actions">
      <button class="qz-pwa-install-btn" id="qz-pwa-install-btn">Install</button>
      <button class="qz-pwa-close-btn" id="qz-pwa-close-btn" aria-label="Dismiss">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
  `;
  document.body.appendChild(banner);

  requestAnimationFrame(() => {
    requestAnimationFrame(() => banner.classList.add('visible'));
  });

  document.getElementById('qz-pwa-install-btn')?.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    hideBanner();
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    deferredPrompt = null;
    if (outcome === 'dismissed') {
      setDismissed();
    }
  });

  document.getElementById('qz-pwa-close-btn')?.addEventListener('click', () => {
    hideBanner();
    setDismissed();
  });
}

function showIOSBanner() {
  if (isRunningStandalone()) return;
  injectBannerCSS();

  const backdrop = document.createElement('div');
  backdrop.id = 'qz-pwa-backdrop';
  document.body.appendChild(backdrop);

  const sheet = document.createElement('div');
  sheet.id = 'qz-pwa-ios-sheet';
  sheet.innerHTML = `
    <span class="qz-ios-handle"></span>
    <div class="qz-ios-title">Add to Home Screen</div>
    <div class="qz-ios-sub">Install Quiznosis for the best mobile experience</div>
    <ol class="qz-ios-steps">
      <li>
        <span class="qz-ios-step-num">1</span>
        <span>Tap the <strong>Share</strong> button <svg style="display:inline;vertical-align:-3px" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg> at the bottom of your browser</span>
      </li>
      <li>
        <span class="qz-ios-step-num">2</span>
        <span>Scroll down and tap <strong>"Add to Home Screen"</strong></span>
      </li>
      <li>
        <span class="qz-ios-step-num">3</span>
        <span>Tap <strong>Add</strong> to install the app</span>
      </li>
    </ol>
    <button class="qz-ios-dismiss" id="qz-ios-dismiss">Maybe later</button>
  `;
  document.body.appendChild(sheet);

  requestAnimationFrame(() => requestAnimationFrame(() => {
    sheet.classList.add('visible');
    backdrop.classList.add('visible');
  }));

  const dismiss = () => {
    sheet.classList.remove('visible');
    backdrop.classList.remove('visible');
    setTimeout(() => { sheet.remove(); backdrop.remove(); }, 400);
    setDismissed();
  };

  document.getElementById('qz-ios-dismiss')?.addEventListener('click', dismiss);
  backdrop.addEventListener('click', dismiss);
}

function hideBanner() {
  const banner = document.getElementById('qz-pwa-banner');
  if (banner) {
    banner.classList.remove('visible');
    setTimeout(() => banner.remove(), 400);
  }
}

/* ── 4. Update toast ─────────────────────────────────────── */
function showUpdateToast() {
  injectBannerCSS();
  if (document.getElementById('qz-sw-update-toast')) return;

  const toast = document.createElement('div');
  toast.id = 'qz-sw-update-toast';
  toast.innerHTML = `
    <span>🎉 New version available</span>
    <button class="qz-update-reload" id="qz-update-reload">Update now</button>
  `;
  document.body.appendChild(toast);
  requestAnimationFrame(() => requestAnimationFrame(() => toast.classList.add('visible')));

  document.getElementById('qz-update-reload')?.addEventListener('click', () => {
    toast.classList.remove('visible');
    if (swRegistration?.waiting) {
      swRegistration.waiting.postMessage({ type: 'SKIP_WAITING' });
      navigator.serviceWorker.addEventListener('controllerchange', () => location.reload());
    } else {
      location.reload();
    }
  });

  // Auto-hide after 15s
  setTimeout(() => toast?.classList.remove('visible'), 15000);
}

/* ── 5. Network status pill ──────────────────────────────── */
let networkPillTimer = null;

export function initNetworkStatus() {
  injectBannerCSS();

  function showPill(type) {
    let pill = document.getElementById('qz-network-pill');
    if (!pill) {
      pill = document.createElement('div');
      pill.id = 'qz-network-pill';
      document.body.appendChild(pill);
    }
    pill.className = `qz-network-pill ${type}`;
    pill.innerHTML = `<span class="qz-net-dot"></span>${type === 'offline' ? 'You\'re offline' : 'Back online'}`;

    clearTimeout(networkPillTimer);
    requestAnimationFrame(() => requestAnimationFrame(() => pill.classList.add('show')));

    networkPillTimer = setTimeout(() => {
      pill?.classList.remove('show');
    }, type === 'offline' ? 0 : 3000); // offline stays until online
  }

  window.addEventListener('offline', () => showPill('offline'));
  window.addEventListener('online',  () => showPill('online'));

  // Show immediately if offline at page load
  if (!navigator.onLine) showPill('offline');
}

/* ── Helpers ─────────────────────────────────────────────── */
function isRunningStandalone() {
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true ||
    document.referrer.includes('android-app://')
  );
}

function wasRecentlyDismissed() {
  try {
    const val = localStorage.getItem(PWA_DISMISSED_KEY);
    if (!val || val === 'installed') return val === 'installed';
    const ts = parseInt(val, 10);
    // Re-prompt after 7 days
    return Date.now() - ts < 7 * 24 * 60 * 60 * 1000;
  } catch { return false; }
}

function setDismissed() {
  try { localStorage.setItem(PWA_DISMISSED_KEY, String(Date.now())); } catch {}
}

/* ── Auto-init ───────────────────────────────────────────── */
export async function initPWA() {
  await registerServiceWorker();
  initInstallPrompt();
  initNetworkStatus();
}

// Auto-run on DOMContentLoaded
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPWA);
} else {
  initPWA();
}
