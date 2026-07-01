/* ============================================================
   Quiznosis — shared UI components & helpers
   ============================================================ */

import { auth, me } from './api.js';

let cachedUser = undefined;  // undefined = unknown, null = anon, object = signed in

/* --- Auth state ---------------------------------------------------- */
export async function getUser({ force = false } = {}) {
  if (cachedUser !== undefined && !force) return cachedUser;
  try {
    const r = await auth.me();
    cachedUser = r.authenticated ? r.user : null;
  } catch {
    cachedUser = null;
  }
  return cachedUser;
}

export async function requireAuth(redirect = 'login.html') {
  const u = await getUser();
  if (!u) {
    const next = encodeURIComponent(window.location.pathname + window.location.search);
    window.location.href = redirect + '?next=' + next;
    throw new Error('redirecting-to-login');
  }
  return u;
}

export async function requireRole(role, redirect = 'dashboard.html') {
  const u = await requireAuth();
  if (u.role !== role) {
    window.location.href = redirect;
    throw new Error('redirecting-wrong-role');
  }
  return u;
}

export function clearUserCache() { cachedUser = undefined; }

/* --- Header -------------------------------------------------------- */
/* --- Global theme (dark / light, persisted) ------------------------ */
const THEME_KEY = 'quiznosis-theme';
export function getTheme() {
  try { return localStorage.getItem(THEME_KEY) || 'light'; } catch { return 'light'; }
}
export function applyTheme(t) {
  document.documentElement.setAttribute('data-theme', t);
  try { localStorage.setItem(THEME_KEY, t); } catch {}
}
applyTheme(getTheme());   // apply early so pages don't flash

/* Header-specific CSS, injected once — covers the icon row, bell, theme toggle */
function injectHeaderCss() {
  if (document.getElementById('qz-hdr-css')) return;
  const s = document.createElement('style');
  s.id = 'qz-hdr-css';
  s.textContent = `
    .hdr-icons { display:flex; align-items:center; gap:8px; }
    .hdr-ico {
      width:36px; height:36px; border-radius:8px; cursor:pointer;
      border:1.5px solid #e2dfd8; background:#ffffff; color:#1a1814;
      display:flex; align-items:center; justify-content:center; font-size:1rem;
      position:relative; text-decoration:none;
    }
    [data-theme="dark"] .hdr-ico { background:#16203a; border-color:#2a3a5c; color:#e8ecf4; }
    .hdr-ico:hover { border-color:#b8531c; }
    .hdr-ico-active { border-color:#b8531c !important; color:#b8531c !important; }
    .hdr-bell-dot {
      position:absolute; top:-6px; right:-6px; background:#e84b2b;
      color:#fff; font-size:0.6rem; font-weight:700; min-width:17px; height:17px;
      border-radius:9px; display:flex; align-items:center; justify-content:center;
      padding:0 4px; border:2px solid #ffffff;
    }
    [data-theme="dark"] .hdr-bell-dot { border-color:#16203a; }
    .nav-ico-img { margin-right:5px; }

    /* ── Notification panel: fixed-position, never clips off screen ── */
    .hdr-bell-backdrop {
      display:none; position:fixed; inset:0; z-index:9998;
    }
    .hdr-bell-backdrop.open { display:block; }

    .hdr-bell-panel {
      display:none;
      position:fixed;
      width:min(360px, calc(100vw - 24px));
      max-height:min(480px, calc(100vh - 80px));
      background:var(--paper, #ffffff);
      border:1px solid var(--paper-edge, #e2dfd8);
      border-radius:14px;
      box-shadow:0 8px 40px rgba(0,0,0,0.18), 0 2px 8px rgba(0,0,0,0.08);
      z-index:9999;
      flex-direction:column;
      overflow:hidden;
      font-family:var(--font-body, 'Inter', system-ui, sans-serif);
    }
    [data-theme="dark"] .hdr-bell-panel { background:var(--paper, #16203a); border-color:var(--paper-edge, #2a3a5c); }
    .hdr-bell-panel.open { display:flex; }

    /* ── Header row ── */
    .hdr-bell-head {
      display:flex; align-items:center; justify-content:space-between;
      padding:13px 16px 11px;
      border-bottom:1px solid var(--paper-edge, #e2dfd8);
      flex-shrink:0;
    }
    [data-theme="dark"] .hdr-bell-head { border-color:var(--paper-edge, #2a3a5c); }
    .hdr-bell-head-left { display:flex; align-items:center; gap:9px; }
    /* Title: matches notifications.html h1 weight/size scaled down */
    .hdr-bell-head-title {
      font-weight:700; font-size:0.96rem;
      color:var(--ink, #1a1814);
      font-family:var(--font-body, inherit);
    }
    [data-theme="dark"] .hdr-bell-head-title { color:var(--ink, #e8ecf4); }
    /* "N new" badge — matches .unread-badge pill */
    .hdr-bell-new-badge {
      background:var(--accent, #b8531c); color:#fff;
      font-size:0.72rem; font-weight:700;
      font-family:var(--font-mono, 'JetBrains Mono', monospace);
      padding:3px 10px; border-radius:100px; white-space:nowrap;
    }
    /* "Mark all read" — matches notifications.html ghost btn style */
    .hdr-bell-mark-btn {
      background:none; border:none;
      color:var(--ink-muted, #8c8473);
      font-size:0.74rem; font-weight:600; cursor:pointer;
      font-family:var(--font-body, inherit); padding:0; white-space:nowrap;
    }
    .hdr-bell-mark-btn:hover { color:var(--accent, #b8531c); text-decoration:underline; }
    /* Close (×) button */
    .hdr-bell-close {
      width:28px; height:28px; border-radius:7px;
      border:1.5px solid var(--paper-edge, #e2dfd8);
      background:var(--paper, #fff); color:var(--ink-muted, #8c8473);
      display:flex; align-items:center; justify-content:center;
      cursor:pointer; font-size:1rem; line-height:1;
      flex-shrink:0; transition:border-color .14s, color .14s;
      font-family:inherit; padding:0;
    }
    .hdr-bell-close:hover { border-color:var(--accent, #b8531c); color:var(--accent, #b8531c); }
    [data-theme="dark"] .hdr-bell-close { background:var(--paper, #16203a); border-color:var(--paper-edge, #2a3a5c); color:var(--ink-muted, #6b7794); }

    .hdr-notif-scroll { flex:1; overflow-y:auto; padding:4px 0; }

    /* ── Individual notification row — matches .notif-card ── */
    .hdr-notif {
      display:flex; align-items:flex-start; gap:10px;
      padding:12px 14px;
      border-bottom:1px solid var(--paper-edge, #f0ede6);
      cursor:pointer; color:var(--ink, #1a1814);
      transition:border-color .14s, box-shadow .14s, background .14s;
      border-left:3px solid transparent;
    }
    [data-theme="dark"] .hdr-notif { border-color:var(--paper-edge, #1e2d47); color:var(--ink, #e8ecf4); }
    .hdr-notif:last-child { border-bottom:none; }
    .hdr-notif:hover { background:var(--paper-soft, #f7f3ec); box-shadow:0 2px 12px rgba(0,0,0,.06); }
    [data-theme="dark"] .hdr-notif:hover { background:var(--paper-soft, #0e1626); }
    /* Unread: accent left border + slightly lighter bg — matches .notif-card.unread */
    .hdr-notif.unread { border-left:3px solid var(--accent, #b8531c); background:var(--paper, #ffffff); }
    [data-theme="dark"] .hdr-notif.unread { background:rgba(224,116,47,0.07); }
    .hdr-notif.unread:hover { background:var(--paper-soft, #f7ece4); }

    /* ── Icon — matches .notif-ico-wrap (square rounded, not circle) ── */
    .hdr-notif-icon {
      width:36px; height:36px; border-radius:9px;
      background:var(--paper-soft, #f0ede6); color:var(--accent, #b8531c);
      display:flex; align-items:center; justify-content:center;
      flex-shrink:0; font-size:1.3rem; margin-top:1px;
    }
    [data-theme="dark"] .hdr-notif-icon { background:var(--paper-soft, #1e2d47); color:var(--accent, #e0742f); }

    .hdr-notif-body { flex:1; min-width:0; }

    /* Title — matches .notif-card-title: 0.92rem 600 */
    .hdr-notif-title {
      font-size:0.92rem; font-weight:600;
      color:var(--ink, #1a1814);
      line-height:1.3; margin-bottom:2px;
      overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
      font-family:var(--font-body, inherit);
    }
    [data-theme="dark"] .hdr-notif-title { color:var(--ink, #e8ecf4); }

    /* Body — matches .notif-card-body: 0.82rem ink-soft */
    .hdr-notif-msg {
      font-size:0.82rem; color:var(--ink-soft, #6b6a65); line-height:1.45;
      font-family:var(--font-body, inherit);
      display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    }
    [data-theme="dark"] .hdr-notif-msg { color:var(--ink-soft, #7886a8); }

    .hdr-notif-footer { display:flex; align-items:center; gap:7px; margin-top:4px; }

    /* Timestamp — matches .notif-card-time: 0.72rem font-mono ink-muted */
    .hdr-notif-date {
      font-size:0.72rem; color:var(--ink-muted, #9ca3af);
      font-family:var(--font-mono, 'JetBrains Mono', monospace);
    }

    /* Unread pill — matches .notif-type-pill */
    .hdr-notif-unread-badge {
      font-size:0.65rem; font-weight:700; letter-spacing:.05em;
      padding:1px 7px; border-radius:100px;
      background:var(--paper-edge, #e2dfd8); color:var(--ink-muted, #8c8473);
    }
    [data-theme="dark"] .hdr-notif-unread-badge { background:var(--paper-soft, #1e2d47); color:var(--ink-muted, #6b7794); }

    /* Unread dot */
    .hdr-notif-dot {
      width:8px; height:8px; border-radius:50%; background:var(--accent, #b8531c);
      flex-shrink:0; margin-top:5px; align-self:flex-start;
    }

    /* Empty state */
    .hdr-notif-empty {
      padding:2rem 1rem; text-align:center;
      color:var(--ink-muted, #8c8473); font-size:0.95rem;
      font-family:var(--font-body, inherit);
    }

    /* ── Footer link — matches accent colour used throughout notifications.html ── */
    .hdr-bell-footer {
      border-top:1px solid var(--paper-edge, #e2dfd8); padding:11px 16px;
      text-align:center; flex-shrink:0;
    }
    [data-theme="dark"] .hdr-bell-footer { border-color:var(--paper-edge, #2a3a5c); }
    .hdr-bell-footer a {
      font-size:0.82rem; font-weight:700;
      color:var(--accent, #b8531c);
      font-family:var(--font-body, inherit);
      text-decoration:none;
    }
    .hdr-bell-footer a:hover { text-decoration:underline; }

  `;
  document.head.appendChild(s);
}

/* --- Site header --------------------------------------------------- */
export async function mountHeader(active = '') {
  injectHeaderCss();
  const u = await getUser();
  const themeIcon = () => getTheme() === 'dark'
    ? '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><line x1="12" y1="2" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22"/><line x1="4.93" y1="4.93" x2="6.34" y2="6.34"/><line x1="17.66" y1="17.66" x2="19.07" y2="19.07"/><line x1="2" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22" y2="12"/><line x1="4.93" y1="19.07" x2="6.34" y2="17.66"/><line x1="17.66" y1="6.34" x2="19.07" y2="4.93"/></svg>'
    : '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';

  const header = document.createElement('header');
  header.className = 'site-header';

  /* --- Avatar initials helper --- */
  function avatarInitials(user) {
    if (user.firstName || user.lastName) {
      return ((user.firstName || '').charAt(0) + (user.lastName || '').charAt(0)).toUpperCase() || '?';
    }
    return (user.email || '?').charAt(0).toUpperCase();
  }
  function displayName(user) {
    if (user.firstName || user.lastName) {
      return [user.firstName, user.lastName].filter(Boolean).join(' ');
    }
    return user.email || 'Account';
  }

  /* --- Build header --- */
  header.innerHTML = `
    <div class="container header-inner">
      <a href="index.html" class="brand" style="display:inline-flex;align-items:center;padding:0;"><img src="assets/icon.png" alt="Quiznosis" style="height:36px;width:auto;display:block;"></a>

      <!-- Desktop nav (hidden on mobile) -->
      <nav class="site-nav" id="site-nav">
        <a href="quizzes.html" class="${active === 'quizzes' ? 'active' : ''}"><span class="nav-ico-img">\u270e</span>Quizzes</a>
        <a href="courses.html" class="${active === 'courses' ? 'active' : ''}"><span class="nav-ico-img">\u{1f4da}</span>Courses</a>
        <a href="contact.html" class="${active === 'contact' ? 'active' : ''}"><span class="nav-ico-img">\u{1f4ec}</span>Contact</a>
        ${u ? `<a href="dashboard.html" class="${active === 'dashboard' ? 'active' : ''}"><span class="nav-ico-img">\u25a6</span>Dashboard</a>` : ''}
        ${u && u.role === 'ADMIN' ? `<a href="admin.html" class="${active === 'admin' ? 'active' : ''}"><span class="nav-ico-img">\u2699</span>Admin</a>` : ''}
        ${u
          ? `<a href="profile.html" class="hdr-ico${active === 'profile' ? ' hdr-ico-active' : ''}" title="My Profile" style="text-decoration:none;">
               <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
             </a>
             <button class="btn btn-ghost btn-sm" id="logout-btn">Sign out</button>`
          : `<a href="login.html" class="btn btn-ghost btn-sm">Sign in</a>
             <a href="register.html" class="btn btn-accent btn-sm">Get started</a>`}
      </nav>

      <!-- Right-side group: icons + hamburger — always visible, glued together -->
      <div class="hdr-right">
        <div class="hdr-icons" id="hdr-icons-bar">
          <button class="hdr-ico" id="theme-toggle" title="Toggle dark mode">${themeIcon()}</button>
          ${u ? `<a href="announcements.html" class="hdr-ico" title="Announcements" style="text-decoration:none;">\u{1f4e2}</a>` : ''}
          ${u ? `
            <button class="hdr-ico" id="hdr-bell" title="Notifications" aria-haspopup="true" aria-expanded="false">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
              <span class="hdr-bell-dot" id="hdr-bell-dot" style="display:none;">0</span>
            </button>` : ''}
        </div>
        <button class="nav-toggle" id="nav-toggle" aria-label="Open menu">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
      </div>
    </div>`;
  document.body.prepend(header);

  /* --- Build slide-in drawer --- */
  const drawerOverlay = document.createElement('div');
  drawerOverlay.id = 'nav-drawer-overlay';
  drawerOverlay.className = 'nav-drawer-overlay';

  // ── Public nav links (always visible) ─────────────────────────────────
  const navLinks = [
    { href: 'quizzes.html',   label: 'Quizzes',   key: 'quizzes',   icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>' },
    { href: 'courses.html',   label: 'Courses',   key: 'courses',   icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>' },
    { href: 'contact.html',   label: 'Contact',   key: 'contact',   icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>' },
  ];

  // ── User-specific links (shown below a divider when logged in) ──────────
  const userLinks = [];
  if (u) {
    userLinks.push({ href: 'dashboard.html',    label: 'Dashboard',   key: 'dashboard',    icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>' });
    userLinks.push({ href: 'my-quiz-sets.html', label: 'My Quizzes',  key: 'my-quiz-sets', icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>' });
    userLinks.push({ href: 'my-courses.html',   label: 'My Courses',  key: 'my-courses',   icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>' });
    userLinks.push({ href: 'attempts.html',     label: 'My Attempts', key: 'attempts',     icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>' });
    if (u.role === 'ADMIN') userLinks.push({ href: 'admin.html', label: 'Admin', key: 'admin', icon: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' });
  }

  const initials = u ? avatarInitials(u) : '';
  const name     = u ? displayName(u) : '';
  const email    = u ? (u.email || '') : '';

  drawerOverlay.innerHTML = `
    <aside class="nav-drawer" id="nav-drawer" role="dialog" aria-modal="true" aria-label="Navigation menu" style="padding-bottom:env(safe-area-inset-bottom,0px);">
      <!-- Close button -->
      <button class="nav-drawer-close" id="nav-drawer-close" aria-label="Close menu">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>

      <!-- Welcome header — always shown -->
      <div class="nav-drawer-welcome">
        <span class="nav-drawer-welcome-title">${u ? `Welcome, ${name.split(' ')[0]}` : 'Welcome'}</span>
        <span class="nav-drawer-welcome-sub">${u ? (email && name !== email ? email : 'Manage your account') : 'Sign in to access all features'}</span>
      </div>
      <div class="nav-drawer-divider"></div>

      <!-- Nav links — public -->
      <nav class="nav-drawer-links">
        ${navLinks.map(l => `
          <a href="${l.href}" class="nav-drawer-link${active === l.key ? ' active' : ''}">
            <span class="nav-drawer-link-icon">${l.icon}</span>
            ${l.label}
          </a>`).join('')}
      </nav>

      <!-- User links — logged-in section with divider -->
      ${userLinks.length ? `
      <div class="nav-drawer-divider"></div>
      <nav class="nav-drawer-links">
        ${userLinks.map(l => `
          <a href="${l.href}" class="nav-drawer-link${active === l.key ? ' active' : ''}">
            <span class="nav-drawer-link-icon">${l.icon}</span>
            ${l.label}
          </a>`).join('')}
      </nav>` : ''}

      <!-- Footer: filled auth buttons (logged-out) or logout (logged-in) -->
      <div class="nav-drawer-footer">
        ${!u ? `
          <a href="login.html" class="nav-drawer-btn nav-drawer-btn-green">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            Sign in
          </a>
          <a href="register.html" class="nav-drawer-btn nav-drawer-btn-orange">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            Get started
          </a>` : `
          <button class="nav-drawer-btn nav-drawer-btn-red" id="drawer-logout-btn">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
          </button>`}
      </div>
    </aside>`;

  // Append to <html> (documentElement), NOT <body> — prevents any stacking
  // context created by backdrop-filter on the sticky header, transform, or
  // will-change from trapping position:fixed children and making them scroll
  // with the page instead of the viewport. Same pattern as the bell panel.
  document.documentElement.appendChild(drawerOverlay);

  /* --- Drawer open/close logic --- */
  let _scrollY = 0;
  function openDrawer() {
    // Freeze page scroll at current position without losing scroll offset
    _scrollY = window.scrollY;
    document.body.style.position  = 'fixed';
    document.body.style.top       = `-${_scrollY}px`;
    document.body.style.width     = '100%';
    document.body.style.overflowY = 'scroll'; // keep scrollbar gutter stable
    document.documentElement.classList.add('nav-drawer-open');
    drawerOverlay.classList.add('open');
    document.getElementById('hdr-bell-panel')?.classList.remove('open');
    document.getElementById('hdr-bell-backdrop')?.classList.remove('open');
  }
  function closeDrawer() {
    drawerOverlay.classList.remove('open');
    document.documentElement.classList.remove('nav-drawer-open');
    // Restore body and scroll position without visual jump
    document.body.style.position  = '';
    document.body.style.top       = '';
    document.body.style.width     = '';
    document.body.style.overflowY = '';
    window.scrollTo({ top: _scrollY, behavior: 'instant' });
  }

  document.getElementById('nav-toggle')?.addEventListener('click', openDrawer);
  document.getElementById('nav-drawer-close')?.addEventListener('click', closeDrawer);
  drawerOverlay.addEventListener('click', e => { if (e.target === drawerOverlay) closeDrawer(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

  document.getElementById('logout-btn')?.addEventListener('click', async () => {
    try { await auth.logout(); } catch {}
    clearUserCache();
    window.location.href = 'index.html';
  });
  document.getElementById('drawer-logout-btn')?.addEventListener('click', async () => {
    try { await auth.logout(); } catch {}
    clearUserCache();
    window.location.href = 'index.html';
  });

  // Theme toggle — apply immediately, persist, swap icon, broadcast change.
  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const next = getTheme() === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    const btn = document.getElementById('theme-toggle');
    if (btn) {
      btn.innerHTML = themeIcon();
      btn.classList.toggle('is-dark', next === 'dark');
      btn.setAttribute('aria-label', next === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
    }
    // Broadcast for any page-level listeners that want to refresh visuals
    window.dispatchEvent(new CustomEvent('themechange', { detail: { theme: next } }));
  });

  // notification bell — logged-in only
  if (u) initHeaderBell();
}

/* notification bell wiring */
async function initHeaderBell() {
  const bell = document.getElementById('hdr-bell');
  const dot  = document.getElementById('hdr-bell-dot');
  if (!bell) return;

  // Create backdrop + panel directly on <body> so they are never clipped by nav
  const backdrop = document.createElement('div');
  backdrop.id        = 'hdr-bell-backdrop';
  backdrop.className = 'hdr-bell-backdrop';
  backdrop.style.cssText = 'position:fixed !important; z-index:9998 !important;';
  document.documentElement.appendChild(backdrop);

  const panel = document.createElement('div');
  panel.id        = 'hdr-bell-panel';
  panel.className = 'hdr-bell-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Notifications');
  // Force fixed positioning inline — prevents any ancestor stacking context
  // (backdrop-filter, transform, will-change) from trapping the panel.
  panel.style.cssText = 'position:fixed !important; z-index:9999 !important;';
  panel.innerHTML = `
    <div class="hdr-bell-head">
      <div class="hdr-bell-head-left">
        <span class="hdr-bell-head-title">Notifications</span>
        <span class="hdr-bell-new-badge" id="hdr-bell-new-badge" style="display:none;"></span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <button class="hdr-bell-mark-btn" id="hdr-mark-all" type="button">Mark all read</button>
        <button class="hdr-bell-close" id="hdr-bell-close-btn" type="button" aria-label="Close notifications">&times;</button>
      </div>
    </div>
    <div class="hdr-notif-scroll" id="hdr-notif-list">
      <div class="hdr-notif-empty">Loading…</div>
    </div>
    <div class="hdr-bell-footer"><a href="notifications.html">View all notifications</a></div>
  `;
  document.documentElement.appendChild(panel);

  const newBadge = panel.querySelector('#hdr-bell-new-badge');
  let notifs = [];
  let isOpen = false;

  /* Position panel anchored to the bell button, clamped inside viewport */
  function positionPanel() {
    // Re-assert position:fixed every call — prevents any stacking context
    // (backdrop-filter, transform) from trapping the panel in the page flow.
    panel.style.position = 'fixed';
    panel.style.bottom = '';

    const br  = bell.getBoundingClientRect();
    const vw  = window.innerWidth;
    const vh  = window.innerHeight;

    // Panel width: up to 360px, but never wider than viewport minus a 12px gutter each side
    const pw  = Math.min(360, vw - 24);

    // Horizontal: right-align with bell button, clamped inside viewport
    let left = br.right - pw;
    if (left < 12)            left = 12;
    if (left + pw > vw - 12) left = vw - pw - 12;

    // Vertical: prefer below bell; flip above if not enough room
    const ph  = panel.offsetHeight || 480;
    let top   = br.bottom + 8;
    if (top + ph > vh - 12 && br.top - ph - 8 > 12) {
      top = br.top - ph - 8;   // flip above
    }
    if (top < 8) top = 8;

    panel.style.left  = left + 'px';
    panel.style.top   = top  + 'px';
    panel.style.width = pw   + 'px';
  }

  function open() {
    if (isOpen) return;
    isOpen = true;
    // Close mobile nav if open, to avoid layering confusion
    document.getElementById('site-nav')?.classList.remove('open');
    positionPanel();
    panel.classList.add('open');
    backdrop.classList.add('open');
    bell.setAttribute('aria-expanded', 'true');
    if (!notifs.length) refresh();
  }
  function close() {
    if (!isOpen) return;
    isOpen = false;
    panel.classList.remove('open');
    backdrop.classList.remove('open');
    bell.setAttribute('aria-expanded', 'false');
  }

  bell.addEventListener('click', e => { e.stopPropagation(); isOpen ? close() : open(); });
  panel.querySelector('#hdr-bell-close-btn')?.addEventListener('click', close);

  // Auto-poll every 30s for new notifications (updates bell badge without opening panel)
  async function silentPoll() {
    try {
      const r = await me.notifications({ limit: 1 });
      updateBadges(r.unreadCount || 0);
    } catch { /* silent */ }
  }
  setInterval(silentPoll, 30_000);
  backdrop.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && isOpen) close(); });
  window.addEventListener('resize', () => { if (isOpen) positionPanel(); }, { passive: true });
  window.addEventListener('scroll', () => { if (isOpen) positionPanel(); }, { passive: true });

  function updateBadges(unreadCount) {
    if (unreadCount > 0) {
      dot.style.display  = 'flex';
      dot.textContent    = unreadCount > 99 ? '99+' : String(unreadCount);
      if (newBadge) { newBadge.style.display = ''; newBadge.textContent = unreadCount + ' new'; }
    } else {
      dot.style.display  = 'none';
      if (newBadge) newBadge.style.display = 'none';
    }
  }

  async function refresh() {
    const list = panel.querySelector('#hdr-notif-list');
    try {
      const r = await me.notifications({ limit: 20 });
      notifs   = r.data || [];
      const uc = notifs.filter(n => n.status === 'UNREAD' || !n.read_at).length;
      updateBadges(uc);
      render();
    } catch {
      if (list) list.innerHTML = '<div class="hdr-notif-empty">Couldn’t load notifications.</div>';
    }
  }

  function render() {
    const list = panel.querySelector('#hdr-notif-list');
    if (!list) return;
    if (!notifs.length) {
      list.innerHTML = '<div class="hdr-notif-empty">No notifications yet.</div>';
      return;
    }
    const BELL_SVG =
      '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>' +
      '<path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>';

    list.innerHTML = notifs.map(n => {
      const isUnread = n.status === 'UNREAD' || !n.read_at;
      const ago      = formatRelative(n.sent_at || n.created_at || '');
      return (
        '<div class="hdr-notif' + (isUnread ? ' unread' : '') + '" data-id="' + escapeHtml(String(n.id)) + '">' +
          '<div class="hdr-notif-icon">' + BELL_SVG + '</div>' +
          '<div class="hdr-notif-body">' +
            '<div class="hdr-notif-title">' + escapeHtml(n.title || 'Notification') + '</div>' +
            (n.message || n.body
              ? '<div class="hdr-notif-msg">' + escapeHtml(n.message || n.body || '') + '</div>'
              : '') +
            '<div class="hdr-notif-footer">' +
              '<span class="hdr-notif-date">' + escapeHtml(ago) + '</span>' +
              (isUnread ? '<span class="hdr-notif-unread-badge">Unread</span>' : '') +
            '</div>' +
          '</div>' +
          (isUnread ? '<span class="hdr-notif-dot"></span>' : '') +
        '</div>'
      );
    }).join('');

    list.querySelectorAll('.hdr-notif').forEach(el => {
      el.addEventListener('click', async () => {
        const n = notifs.find(x => String(x.id) === el.dataset.id);
        if (n && (n.status === 'UNREAD' || !n.read_at)) {
          el.classList.remove('unread');
          el.querySelector('.hdr-notif-dot')?.remove();
          el.querySelector('.hdr-notif-unread-badge')?.remove();
          n.read_at = new Date().toISOString();
          n.status  = 'READ';
          const uc = notifs.filter(x => x.status === 'UNREAD' || !x.read_at).length;
          updateBadges(uc);
          try { await me.notificationAction(n.id, 'mark_read'); } catch {}
        }
      });
    });
  }

  panel.querySelector('#hdr-mark-all')?.addEventListener('click', async () => {
    panel.querySelectorAll('.hdr-notif.unread').forEach(el => {
      el.classList.remove('unread');
      el.querySelector('.hdr-notif-dot')?.remove();
      el.querySelector('.hdr-notif-unread-badge')?.remove();
    });
    notifs.forEach(n => { n.read_at = new Date().toISOString(); n.status = 'READ'; });
    updateBadges(0);
    try { await me.notificationAction(null, 'mark_all_read'); } catch {
      for (const n of notifs) {
        try { await me.notificationAction(n.id, 'mark_read'); } catch {}
      }
    }
  });

  // Initial badge load (don't open panel, just count)
  refresh();
}

/* --- Footer -------------------------------------------------------- */
export function mountFooter() {
  const footer = document.createElement('footer');
  footer.className = 'site-footer';
  footer.innerHTML = `
    <div class="container footer-inner">
      <div>© ${new Date().getFullYear()} Quiznosis — practice that means business.</div>
      <div class="footer-links">
        <a href="quizzes.html">Quizzes</a>
        <a href="courses.html">Courses</a>
        <a href="login.html">Sign in</a>
      </div>
    </div>`;
  document.body.append(footer);
}

/* --- Toast --------------------------------------------------------- */
let toastBox = null;
export function toast(message, type = 'info') {
  if (!toastBox) {
    toastBox = document.createElement('div');
    toastBox.className = 'toast-box';
    document.body.append(toastBox);
  }
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = message;
  toastBox.append(el);
  requestAnimationFrame(() => el.classList.add('show'));
  setTimeout(() => {
    el.classList.remove('show');
    setTimeout(() => el.remove(), 300);
  }, type === 'error' ? 5000 : 3200);
}

/* --- Modal helper -------------------------------------------------- */
export function modal(innerHtml, { onClose } = {}) {
  const back = document.createElement('div');
  back.className = 'modal-backdrop';
  // Inline fixed positioning so no ancestor stacking context (backdrop-filter,
  // transform, will-change) can trap it and make it scroll with the page.
  back.style.cssText = 'position:fixed !important; inset:0; z-index:9000 !important;';
  back.innerHTML = `<div class="modal" role="dialog">${innerHtml}</div>`;

  // Append to <html>, not <body> — same trick used by the bell panel and drawer
  // so that body.style.position='fixed' (scroll-freeze) never clips the modal.
  document.documentElement.appendChild(back);

  // Freeze page scroll without losing scroll offset (no jump on close)
  const _scrollY = window.scrollY;
  document.body.style.position  = 'fixed';
  document.body.style.top       = `-${_scrollY}px`;
  document.body.style.width     = '100%';
  document.body.style.overflowY = 'scroll';

  const close = () => {
    back.remove();
    // Restore scroll position
    document.body.style.position  = '';
    document.body.style.top       = '';
    document.body.style.width     = '';
    document.body.style.overflowY = '';
    window.scrollTo({ top: _scrollY, behavior: 'instant' });
    onClose?.();
  };
  back.addEventListener('click', (e) => { if (e.target === back) close(); });
  back.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', close));
  return { el: back, close };
}

/**
 * Post-purchase confirmation modal.
 *
 * Shows "Purchase Request Pending" with WhatsApp and Telegram buttons
 * pre-filled with the order details so the student can quickly forward
 * the payment screenshot to the admin.
 *
 * Reads contact numbers from /api/payment-settings (whatsapp_number,
 * telegram_username). Buttons are hidden individually if the corresponding
 * setting is missing.
 *
 * @param {Object} opts
 * @param {string} opts.title            "Quiz Set" / "Course" / etc — shown in the message body
 * @param {string} opts.itemName         e.g. "NMCLE 2071 Chaitra"
 * @param {string} opts.orderId          The purchase row id (returned by purchases.request)
 * @param {string} opts.userEmail        Logged-in user's email
 * @param {Function} [opts.onClose]      Callback when the user dismisses the modal
 */
export async function showPurchasePendingModal(opts) {
  const { title, itemName, orderId, userEmail, onClose } = opts;

  // Fetch contact numbers (best-effort — modal still renders if this fails)
  let waNum = '';
  let tgUser = '';
  try {
    const r = await fetch('/api/payment-settings.php', { credentials: 'include' });
    if (r.ok) {
      const j = await r.json();
      waNum  = (j.data?.whatsapp_number || '').replace(/\D/g, '');
      tgUser = (j.data?.telegram_username || '').replace(/^@/, '');
    }
  } catch {}

  // The plain-text body that goes into both WhatsApp and Telegram.
  // Each app encodes it differently: WhatsApp uses single encoding,
  // Telegram needs double-encoding because their text= param is itself URL-decoded once.
  const msgText =
    '\nHello, I made the payment.\n' +
    '\n' + escapeHtml(title) + ': ' + itemName +
    '\nOrder ID: ' + orderId +
    '\nUser: ' + userEmail + '\n';

  const waHref = waNum
    ? `https://wa.me/${waNum}?text=${encodeURIComponent(msgText)}`
    : '';
  const tgHref = tgUser
    ? `https://t.me/${tgUser}?text=${encodeURIComponent(msgText)}`
    : '';

  const waBtn = waHref ? `
    <a class="pq-btn pq-btn-wa" href="${waHref}" target="_blank" rel="noopener">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.5 14.4c-.3-.1-1.7-.8-2-.9s-.5-.1-.7.2-.7.9-.9 1.1c-.2.2-.3.2-.6.1-.3-.1-1.2-.5-2.3-1.4-.8-.7-1.4-1.7-1.6-1.9-.2-.3 0-.4.1-.6.1-.1.3-.3.4-.5.1-.1.2-.3.3-.4.1-.2.1-.3 0-.5-.1-.1-.7-1.5-.9-2.1-.2-.5-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4s-1 1-1 2.5 1.1 2.9 1.2 3.1 2.1 3.3 5.2 4.7c.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.7-.7 2-1.4.2-.7.2-1.2.2-1.4-.1-.1-.3-.2-.6-.3zM12 2C6.5 2 2 6.5 2 12c0 1.8.5 3.4 1.3 4.9L2 22l5.3-1.4c1.4.8 3 1.2 4.7 1.2 5.5 0 10-4.5 10-10S17.5 2 12 2zm0 18.3c-1.5 0-3-.4-4.3-1.2l-.3-.2-3.2.8.9-3.1-.2-.3c-.9-1.4-1.3-3-1.3-4.6 0-4.6 3.7-8.3 8.3-8.3s8.3 3.7 8.3 8.3-3.7 8.6-8.2 8.6z"/></svg>
      <span>Send on WhatsApp</span>
    </a>` : '';
  const tgBtn = tgHref ? `
    <a class="pq-btn pq-btn-tg" href="${tgHref}" target="_blank" rel="noopener">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.6 0 12 0zm5.5 8.2l-1.8 8.6c-.1.6-.5.8-1.1.5l-3-2.2-1.4 1.4c-.2.2-.3.3-.6.3l.2-3 5.4-4.9c.2-.2 0-.3-.3-.1L8.2 13l-2.9-.9c-.6-.2-.6-.6.1-.9l11.4-4.4c.5-.2 1 .1.8.9z"/></svg>
      <span>Send on Telegram</span>
    </a>` : '';

  const noContactsWarning = (!waHref && !tgHref) ? `
    <p class="pq-warning">Contact numbers haven't been configured yet. Please reach out to the admin directly.</p>` : '';

  // The modal body. Note: we don't use the standard .modal helper here
  // because we want a custom card style for the success/pending state.
  const html = `
    <div class="pq-wrap">
      <div class="pq-icon">
        <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </div>
      <h2 class="pq-title">Purchase Request Pending</h2>
      <p class="pq-msg">Your purchase request is waiting for admin approval.</p>

      <div class="pq-order-card">
        <div class="pq-order-row"><span class="pq-order-l">${escapeHtml(title)}</span><span class="pq-order-v">${escapeHtml(itemName)}</span></div>
        <div class="pq-order-row"><span class="pq-order-l">Order ID</span><span class="pq-order-v pq-mono">${escapeHtml(orderId)}</span></div>
        <div class="pq-order-row"><span class="pq-order-l">User</span><span class="pq-order-v">${escapeHtml(userEmail)}</span></div>
      </div>

      <p class="pq-subhead">Send Payment Screenshot</p>
      <div class="pq-actions">
        ${waBtn}${tgBtn}
      </div>
      ${noContactsWarning}

      <button class="pq-close-btn" data-close>Done</button>
    </div>
  `;

  // Inject styles once (idempotent — re-mounting is fine)
  if (!document.querySelector('style[data-pq-styles]')) {
    document.head.insertAdjacentHTML('beforeend', `
      <style data-pq-styles>
        .pq-wrap { text-align:center; padding:8px 4px; }
        .pq-icon {
          width:72px; height:72px; margin:0 auto 14px;
          display:inline-flex; align-items:center; justify-content:center;
          background:rgba(255,179,46,0.18); color:#d99814;
          border-radius:50%;
        }
        [data-theme="dark"] .pq-icon { background:rgba(255,179,46,0.22); color:#ffc14d; }
        .pq-title {
          font-family: var(--font-display, 'Source Serif Pro', Georgia, serif);
          font-size:1.7rem; font-weight:400; color:var(--ink); margin:0 0 8px;
        }
        .pq-msg {
          font-size:0.95rem; color:var(--ink-muted, var(--ink));
          margin:0 0 18px;
        }
        .pq-order-card {
          background:var(--paper-soft); border:1px solid var(--paper-edge);
          border-radius:10px; padding:14px 16px; margin:0 0 22px;
          text-align:left;
        }
        .pq-order-row {
          display:flex; justify-content:space-between; align-items:flex-start; gap:10px;
          padding:7px 0; border-bottom:1px dashed var(--paper-edge);
        }
        .pq-order-row:last-child { border-bottom:none; }
        .pq-order-l {
          font-size:0.78rem; text-transform:uppercase; letter-spacing:0.06em;
          color:var(--ink-muted); font-weight:600; flex-shrink:0;
        }
        .pq-order-v {
          font-size:0.9rem; color:var(--ink); text-align:right;
          word-break:break-word;
        }
        .pq-mono { font-family:'IBM Plex Mono', monospace; font-size:0.82rem; }
        .pq-subhead {
          font-size:0.78rem; text-transform:uppercase; letter-spacing:0.08em;
          color:var(--ink-muted); font-weight:700; margin:0 0 10px;
        }
        .pq-actions {
          display:flex; flex-wrap:wrap; gap:10px; justify-content:center; margin-bottom:18px;
        }
        .pq-btn {
          display:inline-flex; align-items:center; gap:9px;
          padding:11px 22px;
          border-radius:10px; font-family:'Inter', sans-serif;
          font-size:0.95rem; font-weight:600;
          text-decoration:none; transition:transform .12s, box-shadow .12s, opacity .12s;
          color:#fff;
        }
        .pq-btn:hover { transform:translateY(-1px); opacity:0.93; }
        .pq-btn-wa { background:#25d366; box-shadow:0 4px 12px rgba(37,211,102,0.30); }
        .pq-btn-tg { background:#0088cc; box-shadow:0 4px 12px rgba(0,136,204,0.30); }
        .pq-warning {
          background:rgba(216,118,58,0.12); border:1px solid rgba(216,118,58,0.30);
          color:var(--accent, #b8531c); border-radius:8px;
          padding:10px 14px; font-size:0.85rem; margin:0 0 16px;
        }
        .pq-close-btn {
          background:transparent; border:1px solid var(--paper-edge);
          color:var(--ink-muted);
          padding:8px 22px; border-radius:8px; font-family:'Inter', sans-serif;
          font-size:0.88rem; cursor:pointer; transition:background .12s, color .12s;
        }
        .pq-close-btn:hover { background:var(--paper-soft); color:var(--ink); }
      </style>
    `);
  }

  return modal(html, { onClose });
}

/* --- Formatting ---------------------------------------------------- */
export function escapeHtml(s) {
  if (s == null) return '';
  return String(s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

export function formatSeconds(sec) {
  sec = Math.max(0, parseInt(sec) || 0);
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  if (h > 0) return `${h}h ${m}m`;
  if (m > 0) return `${m}m ${s}s`;
  return `${s}s`;
}

export function formatClock(sec) {
  sec = Math.max(0, parseInt(sec) || 0);
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  const mm = String(m).padStart(2, '0');
  const ss = String(s).padStart(2, '0');
  return h > 0 ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
}

export function formatDate(d) {
  if (!d) return '—';
  const date = new Date(typeof d === 'string' ? d.replace(' ', 'T') : d);
  if (isNaN(date)) return '—';
  return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export function formatRelative(d) {
  if (!d) return '';
  const date = new Date(typeof d === 'string' ? d.replace(' ', 'T') : d);
  if (isNaN(date)) return '';
  const diff = (Date.now() - date.getTime()) / 1000;
  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  if (diff < 86400 * 7) return Math.floor(diff / 86400) + 'd ago';
  return formatDate(d);
}

/* --- Misc helpers -------------------------------------------------- */
export function queryParam(name) {
  return new URLSearchParams(window.location.search).get(name);
}
export function $(sel, root = document) { return root.querySelector(sel); }
export function $$(sel, root = document) { return Array.from(root.querySelectorAll(sel)); }

export function spinner(label = 'Loading…') {
  return `<div class="loading"><div class="spinner"></div><p>${escapeHtml(label)}</p></div>`;
}
export function emptyState(title, body = '') {
  return `<div class="empty"><h3>${escapeHtml(title)}</h3>${body ? `<p>${escapeHtml(body)}</p>` : ''}</div>`;
}
export function errorBox(msg) {
  return `<div class="alert alert-error">${escapeHtml(msg)}</div>`;
}

/* --- Admin sidebar ------------------------------------------------- */
/**
 * Injects a collapsible admin sidebar that floats over the page as a
 * slide-in drawer — same pattern as the user-side nav drawer.
 * On desktop: triggered by the ☰ button injected into the header.
 * On mobile:  same button, same overlay behaviour.
 * Does NOT push page content (no padding-left on body).
 * `active` highlights the current section.
 */
export function mountAdminSidebar(active = '') {
  if (document.getElementById('admin-side')) return;

  // ── CSS — injected once ──────────────────────────────────────────────────
  if (!document.getElementById('admin-side-css')) {
    const css = document.createElement('style');
    css.id = 'admin-side-css';
    css.textContent = `
      /* ── Overlay backdrop ── */
      .admin-side-overlay {
        position: fixed;
        inset: 0;
        z-index: 1100;
        background: rgba(26,24,20,0);
        visibility: hidden;
        pointer-events: none;
        transition: background 0.26s ease, visibility 0s linear 0.26s;
      }
      .admin-side-overlay.open {
        background: rgba(26,24,20,0.46);
        visibility: visible;
        pointer-events: auto;
        transition: background 0.26s ease, visibility 0s linear 0s;
      }

      /* ── Drawer panel ── */
      .admin-side {
        position: fixed;
        top: 0; left: 0; bottom: 0;
        width: 240px;
        max-width: 85vw;
        z-index: 1101;
        background: var(--paper-soft, #fbf8f2);
        border-right: 1px solid var(--paper-edge, #e4dccd);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        transform: translateX(-100%);
        transition: transform 0.26s cubic-bezier(0.4,0,0.2,1);
        padding-top: 0;
      }
      .admin-side-overlay.open .admin-side {
        transform: translateX(0);
      }

      /* ── Drawer header ── */
      .admin-side-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 14px 14px 18px;
        border-bottom: 1px solid var(--paper-edge, #e4dccd);
        flex-shrink: 0;
        min-height: 64px;
      }
      .admin-side-head-label {
        font-size: 0.66rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--ink-muted, #8c8473);
      }
      .admin-side-close {
        width: 32px; height: 32px;
        border-radius: 7px;
        border: 1.5px solid var(--paper-edge, #e4dccd);
        background: none;
        cursor: pointer;
        color: var(--ink-muted, #8c8473);
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; line-height: 1;
        transition: border-color .14s, color .14s;
        padding: 0;
      }
      .admin-side-close:hover {
        border-color: var(--accent, #b8531c);
        color: var(--accent, #b8531c);
      }

      /* ── Nav links ── */
      .admin-side-links {
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 8px 0;
        min-height: 0;
      }
      .admin-side-label-group {
        font-size: 0.62rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--ink-muted, #8c8473);
        padding: 10px 18px 4px;
        white-space: nowrap;
      }
      .admin-link {
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 10px 18px;
        font-size: 0.88rem;
        font-weight: 600;
        color: var(--ink, #1a1814);
        text-decoration: none;
        white-space: nowrap;
        border-left: 3px solid transparent;
        transition: background 0.14s, color 0.14s, border-color 0.14s;
      }
      .admin-link:hover {
        background: var(--paper, #f7f3ec);
        text-decoration: none;
      }
      .admin-link.active {
        background: var(--accent-soft, #f3e4d6);
        border-left-color: var(--accent, #b8531c);
        color: var(--accent, #b8531c);
      }
      .admin-link .ai {
        width: 22px;
        text-align: center;
        font-size: 1.05rem;
        flex-shrink: 0;
      }
      /* Badge counts */
      .admin-link .ab {
        margin-left: auto;
        background: var(--accent, #b8531c);
        color: #fff;
        font-size: 0.62rem;
        font-weight: 700;
        font-family: var(--font-mono, 'IBM Plex Mono', monospace);
        min-width: 18px;
        height: 18px;
        padding: 0 5px;
        border-radius: 100px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        flex-shrink: 0;
      }

      /* ── The ☰ admin menu button (injected into header) ── */
      #admin-menu-btn {
        display: flex;
        align-items: center;
        gap: 6px;
        background: var(--accent-soft, #f3e4d6);
        border: 1.5px solid var(--accent, #b8531c);
        color: var(--accent, #b8531c);
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 0.8rem;
        font-weight: 700;
        font-family: var(--font-body, 'Inter', sans-serif);
        letter-spacing: 0.06em;
        text-transform: uppercase;
        cursor: pointer;
        white-space: nowrap;
        transition: background .14s, color .14s;
        flex-shrink: 0;
      }
      #admin-menu-btn:hover {
        background: var(--accent, #b8531c);
        color: #fff;
      }

      /* Prevent html scroll while sidebar open */
      html.admin-side-open { overflow: hidden; }

      /* Dark mode */
      [data-theme="dark"] .admin-side {
        background: var(--paper-soft, #16203a);
        border-color: var(--paper-edge, #2a3a5c);
      }
      [data-theme="dark"] .admin-link:hover { background: var(--paper, #0e1626); }
      [data-theme="dark"] .admin-side-head  { border-color: var(--paper-edge, #2a3a5c); }
      [data-theme="dark"] .admin-side-close { border-color: var(--paper-edge, #2a3a5c); }
    `;
    document.head.appendChild(css);
  }

  // ── Items: [key, href, icon, label, badgeKey?] ───────────────────────────
  const items = [
    ['overview',         'admin.html',                    '\u25a6',       'Overview',         'new_users'],
    ['content',          'admin-content.html',            '\u270e',       'Content'],
    ['ai-settings',      'admin-ai-settings.html',        '\u{1f916}',   'AI Settings'],
    ['text-content',     'admin-text-content.html',       '\u{1f4dd}',   'Text Content'],
    ['courses',          'admin-courses.html',            '\u{1f4da}',   'Courses'],
    ['taxonomy',         'admin-taxonomy.html',           '\u{1f3f7}',   'Taxonomy'],
    ['monetization',     'admin-monetization.html',       '\u{1f4b3}',   'Monetization',     'purchases'],
    ['announcements',    'admin-announcements.html',      '\u{1f4e2}',   'Announcements'],
    ['contact',          'admin-contact.html',            '\u{1f4ec}',   'Contact',           'contact'],
    ['contact-settings', 'admin-contact-settings.html',  '\u2699',      'Contact Settings'],
  ];

  // ── Overlay (backdrop) ───────────────────────────────────────────────────
  const overlay = document.createElement('div');
  overlay.className = 'admin-side-overlay';
  overlay.id = 'admin-side-overlay';

  // ── Sidebar panel ────────────────────────────────────────────────────────
  const side = document.createElement('aside');
  side.className = 'admin-side';
  side.id = 'admin-side';
  side.setAttribute('role', 'dialog');
  side.setAttribute('aria-modal', 'true');
  side.setAttribute('aria-label', 'Admin navigation');
  side.innerHTML = `
    <div class="admin-side-head">
      <span class="admin-side-head-label">Admin</span>
      <button class="admin-side-close" id="admin-side-close" aria-label="Close admin menu">&#x2715;</button>
    </div>
    <div class="admin-side-links" id="admin-side-links">
      ${items.map(([key, href, ico, label, badgeKey]) => `
        <a href="${href}" class="admin-link ${active === key ? 'active' : ''}" data-badge-key="${badgeKey || ''}">
          <span class="ai">${ico}</span>
          <span class="at">${label}</span>
          ${badgeKey ? `<span class="ab" id="admin-badge-${badgeKey}" style="display:none"></span>` : ''}
        </a>`).join('')}
    </div>`;

  overlay.appendChild(side);
  document.documentElement.appendChild(overlay);

  // ── Inject ☰ Admin button into header ───────────────────────────────────
  // Place it in .hdr-icons bar so it sits alongside the theme/bell buttons.
  let placed = false;
  const hdrIcons = document.getElementById('hdr-icons-bar');
  if (hdrIcons) {
    const btn = document.createElement('button');
    btn.id = 'admin-menu-btn';
    btn.setAttribute('aria-label', 'Open admin menu');
    btn.innerHTML = `
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
        <line x1="3" y1="6" x2="21" y2="6"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="18" x2="21" y2="18"/>
      </svg>
      Admin`;
    hdrIcons.prepend(btn);
    placed = true;
  }

  // ── Open / close logic ───────────────────────────────────────────────────
  let _scrollY = 0;
  function openSidebar() {
    _scrollY = window.scrollY;
    document.body.style.position  = 'fixed';
    document.body.style.top       = `-${_scrollY}px`;
    document.body.style.width     = '100%';
    document.body.style.overflowY = 'scroll';
    document.documentElement.classList.add('admin-side-open');
    overlay.classList.add('open');
  }
  function closeSidebar() {
    overlay.classList.remove('open');
    document.documentElement.classList.remove('admin-side-open');
    document.body.style.position  = '';
    document.body.style.top       = '';
    document.body.style.width     = '';
    document.body.style.overflowY = '';
    window.scrollTo({ top: _scrollY, behavior: 'instant' });
  }

  // Toggle button (in header)
  document.getElementById('admin-menu-btn')?.addEventListener('click', openSidebar);
  // Close button inside drawer
  document.getElementById('admin-side-close')?.addEventListener('click', closeSidebar);
  // Click outside drawer (on overlay)
  overlay.addEventListener('click', e => { if (e.target === overlay) closeSidebar(); });
  // Escape key
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && overlay.classList.contains('open')) closeSidebar();
  });

  // ── Live badge polling ───────────────────────────────────────────────────
  async function pollAdminBadges() {
    try {
      const res  = await fetch('/api/admin/badges.php', { credentials: 'include' });
      const data = await res.json();
      if (!data.ok) return;
      const counts = data.data || {};
      Object.entries(counts).forEach(([key, count]) => {
        const el = document.getElementById('admin-badge-' + key);
        if (!el) return;
        if (count > 0) {
          el.textContent   = count > 99 ? '99+' : String(count);
          el.style.display = '';
        } else {
          el.style.display = 'none';
        }
      });
    } catch { /* silent */ }
  }
  pollAdminBadges();
  setInterval(pollAdminBadges, 30_000);
}


/* ---------------------------------------------------------------------------
 * renderMarkdown — small, dependency-free markdown -> HTML for explanations.
 * Supports: # to ###### headings, double-asterisk / double-underscore bold,
 *           single-asterisk / single-underscore italic, triple-asterisk bold-italic,
 *           ~~strike, > blockquote (and nested >>), -/*+ and 1. lists,
 *           [text](url) links, ![alt](src) images, `inline code`, ```fenced```,
 *           --- horizontal rule, paragraphs, hard line breaks.
 * Inputs are HTML-escaped before any tags are inserted, so markdown source
 * can never inject scripts. Returns a safe HTML string.
 * ---------------------------------------------------------------------------*/
export function renderMarkdown(src) {
  if (src == null) return '';
  let text = String(src).replace(/\r\n/g, '\n').replace(/\r/g, '\n');

  // 1) Extract code fences first so their contents are never touched by the
  //    other rules. Replace with placeholders we sub back in at the end.
  const codeBlocks = [];
  text = text.replace(/```([a-zA-Z0-9_-]*)\n([\s\S]*?)```/g, (_m, lang, body) => {
    codeBlocks.push({ lang: lang || '', body });
    return `\u0000CODEBLOCK${codeBlocks.length - 1}\u0000`;
  });

  // 1b) Extract tables next, before paragraph collection, so they don't get
  //     merged into surrounding text. Detected formats:
  //       (a) Standard markdown table: header row | sep row (---) | data rows
  //       (b) Pipe table without separator: ≥2 consecutive lines with ≥2 pipes
  //       (c) Tab-separated table: ≥2 consecutive lines with ≥2 tabs each
  //     We DO NOT try to detect space-aligned tables (too many false positives).
  const tables = [];

  function isSepRow(s) {
    // a row like | --- | :---: | ---: | (alignment markers, dashes, pipes only)
    const trimmed = s.trim().replace(/^\||\|$/g, '').trim();
    if (!trimmed) return false;
    const cells = trimmed.split('|').map(c => c.trim());
    if (cells.length < 2) return false;
    return cells.every(c => /^:?-{2,}:?$/.test(c));
  }

  function splitPipeRow(s) {
    // Strip optional leading/trailing pipes, split, trim each cell.
    let t = s.replace(/^\s*\||\|\s*$/g, '');
    return t.split('|').map(c => c.trim());
  }

  
  function pipeCount(s) {
    // Count pipes that look like column separators (not escaped \|).
    return (s.replace(/\\\|/g, '').match(/\|/g) || []).length;
  }

  {
    const srcLines = text.split('\n');
    const outLines = [];
    let k = 0;
    while (k < srcLines.length) {
      const l = srcLines[k];
      const lTrim = l.trim();

      // --- (a) standard markdown: pipe row + separator row + data rows ---
      // Detect a header line (≥1 pipe) immediately followed by a separator row.
      if (pipeCount(l) >= 1 && k + 1 < srcLines.length && isSepRow(srcLines[k + 1])) {
        const header = splitPipeRow(l);
        const rows = [];
        let j = k + 2;
        while (j < srcLines.length && pipeCount(srcLines[j]) >= 1 && srcLines[j].trim() !== '') {
          rows.push(splitPipeRow(srcLines[j]));
          j++;
        }
        tables.push({ header, rows });
        outLines.push(`\u0000TABLE${tables.length - 1}\u0000`);
        k = j;
        continue;
      }

      // --- (b) pipe rows WITHOUT a separator: ≥2 lines with same column count ≥2 ---
      // Only kick in when 2+ consecutive lines have pipes and at least 3 cells each.
      if (pipeCount(l) >= 2 && lTrim !== '') {
        // Look at the run of pipe-bearing non-blank lines.
        const run = [];
        let j = k;
        while (j < srcLines.length && pipeCount(srcLines[j]) >= 2 && srcLines[j].trim() !== '') {
          // Skip separator rows in the middle silently
          if (isSepRow(srcLines[j])) { j++; continue; }
          run.push(splitPipeRow(srcLines[j]));
          j++;
        }
        if (run.length >= 2) {
          // Need consistent enough column counts — at least the first 2 rows agree
          const cc = run[0].length;
          if (cc >= 2 && run[1].length === cc) {
            const header = run[0];
            const rows = run.slice(1).map(r => {
              // Pad / truncate to the header width so the table doesn't break.
              if (r.length < cc) return r.concat(new Array(cc - r.length).fill(''));
              if (r.length > cc) return r.slice(0, cc);
              return r;
            });
            tables.push({ header, rows });
            outLines.push(`\u0000TABLE${tables.length - 1}\u0000`);
            k = j;
            continue;
          }
        }
      }

      // --- (c) tab-separated: ≥2 consecutive lines with ≥2 tabs each ---
      if (l.indexOf('\t') >= 0 && (l.match(/\t/g) || []).length >= 2 && lTrim !== '') {
        const run = [];
        let j = k;
        while (j < srcLines.length && srcLines[j].indexOf('\t') >= 0 &&
               (srcLines[j].match(/\t/g) || []).length >= 2 && srcLines[j].trim() !== '') {
          run.push(srcLines[j].split('\t').map(c => c.trim()));
          j++;
        }
        if (run.length >= 2) {
          const cc = run[0].length;
          if (run[1].length === cc) {
            const header = run[0];
            const rows = run.slice(1).map(r => {
              if (r.length < cc) return r.concat(new Array(cc - r.length).fill(''));
              if (r.length > cc) return r.slice(0, cc);
              return r;
            });
            tables.push({ header, rows });
            outLines.push(`\u0000TABLE${tables.length - 1}\u0000`);
            k = j;
            continue;
          }
        }
      }

      outLines.push(l);
      k++;
    }
    text = outLines.join('\n');
  }

  // 2) Escape HTML on the remaining text so user content can't break out.
  text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
             .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

  // 3) Re-introduce blockquote markers (we escaped > earlier).
  //    Turn lines beginning with one or more &gt; into nested <blockquote>.
  const lines = text.split('\n');
  const out = [];
  let i = 0;

  // Helper: render inline tokens (bold, italic, code, links, images, strike).
  function inline(s) {
    // Image first (so its alt text doesn't get parsed as a link)
    s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/g,
      (_m, alt, src) => `<img src="${src}" alt="${alt}" loading="lazy">`);
    // Link
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g,
      (_m, t, u) => `<a href="${u}" target="_blank" rel="noopener">${t}</a>`);
    // Inline code
    s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
    // Bold-italic ***/___ (must come before bold and italic)
    s = s.replace(/\*\*\*([^*\n]+)\*\*\*/g, '<strong><em>$1</em></strong>');
    s = s.replace(/___([^_\n]+)___/g, '<strong><em>$1</em></strong>');
    // Bold ** / __
    s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/__([^_\n]+)__/g, '<strong>$1</strong>');
    // Italic * / _ (single, not inside words)
    s = s.replace(/(^|[\s(])\*([^\s*][^*\n]*?)\*(?=[\s).,;:!?]|$)/g, '$1<em>$2</em>');
    s = s.replace(/(^|[\s(])_([^\s_][^_\n]*?)_(?=[\s).,;:!?]|$)/g, '$1<em>$2</em>');
    // Strikethrough
    s = s.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');
    return s;
  }

  while (i < lines.length) {
    const line = lines[i];

    // Blank line -> close paragraph naturally
    if (line.trim() === '') { i++; continue; }

    // Horizontal rule
    if (/^\s*(?:---|\*\*\*|___)\s*$/.test(line)) { out.push('<hr>'); i++; continue; }

    // Code block placeholder on its own line — emit directly as block
    if (/^\u0000CODEBLOCK\d+\u0000$/.test(line.trim())) {
      out.push(line.trim()); i++; continue;
    }

    // Table placeholder on its own line — emit directly as block
    if (/^\u0000TABLE\d+\u0000$/.test(line.trim())) {
      out.push(line.trim()); i++; continue;
    }

    // Heading
    const h = line.match(/^(#{1,6})\s+(.+)$/);
    if (h) { out.push(`<h${h[1].length}>${inline(h[2])}</h${h[1].length}>`); i++; continue; }

    // Tables are extracted in step 3a (above) into \u0000TABLE_n_\u0000 placeholders,
    // which are re-emitted as block-level tokens in the code-block-placeholder
    // branch above. Nothing to do here.

    // Blockquote (one or more leading &gt;)
    if (/^\s*&gt;/.test(line)) {
      // Gather consecutive blockquote lines
      const buf = [];
      while (i < lines.length && /^\s*&gt;/.test(lines[i])) {
        buf.push(lines[i]); i++;
      }
      // Strip ONE level of &gt;, recurse for any remaining (nested) markers.
      const inner = buf.map(l => l.replace(/^\s*&gt;\s?/, '')).join('\n');
      out.push(`<blockquote>${renderMarkdownInner(inner)}</blockquote>`);
      continue;
    }

    // Unordered list
    if (/^\s*[-*+]\s+/.test(line)) {
      const items = [];
      while (i < lines.length && /^\s*[-*+]\s+/.test(lines[i])) {
        items.push(lines[i].replace(/^\s*[-*+]\s+/, ''));
        i++;
      }
      out.push('<ul>' + items.map(it => `<li>${inline(it)}</li>`).join('') + '</ul>');
      continue;
    }

    // Ordered list
    if (/^\s*\d+[.)]\s+/.test(line)) {
      const items = [];
      while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) {
        items.push(lines[i].replace(/^\s*\d+[.)]\s+/, ''));
        i++;
      }
      out.push('<ol>' + items.map(it => `<li>${inline(it)}</li>`).join('') + '</ol>');
      continue;
    }

    // Paragraph — accumulate consecutive non-empty, non-special lines, join
    // them with <br> so a single \n becomes a soft line break.
    const buf = [line];
    i++;
    while (i < lines.length) {
      const l = lines[i];
      if (l.trim() === '') break;
      if (/^(#{1,6})\s+/.test(l)) break;
      if (/^\s*&gt;/.test(l)) break;
      if (/^\s*[-*+]\s+/.test(l)) break;
      if (/^\s*\d+[.)]\s+/.test(l)) break;
      if (/^\s*(?:---|\*\*\*|___)\s*$/.test(l)) break;
      buf.push(l);
      i++;
    }
    out.push('<p>' + buf.map(inline).join('<br>') + '</p>');
  }

  let html = out.join('');

  // 4) Sub code blocks back in. The body is already plain text (was never
  //    escaped) — escape it now and wrap in <pre><code>.
  html = html.replace(/\u0000CODEBLOCK(\d+)\u0000/g, (_m, idx) => {
    const cb = codeBlocks[+idx];
    const escaped = cb.body
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const cls = cb.lang ? ` class="language-${cb.lang}"` : '';
    return `<pre><code${cls}>${escaped}</code></pre>`;
  });

  // 4b) Sub tables back in. Header + each cell are HTML-escaped, then run
  //     through the inline renderer so cells can contain bold/links/code.
  function escCell(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  html = html.replace(/\u0000TABLE(\d+)\u0000/g, (_m, idx) => {
    const t = tables[+idx];
    if (!t || !t.header || !t.header.length) return '';
    const head = '<thead><tr>' + t.header.map(h => `<th>${inline(escCell(h))}</th>`).join('') + '</tr></thead>';
    const body = t.rows.length
      ? '<tbody>' + t.rows.map(r =>
          '<tr>' + r.map(c => `<td>${inline(escCell(c))}</td>`).join('') + '</tr>'
        ).join('') + '</tbody>'
      : '';
    return `<div class="md-table-wrap"><table class="md-table">${head}${body}</table></div>`;
  });
  return html;
}
function renderMarkdownInner(src) { return renderMarkdown(src); }

/* ---------------------------------------------------------------------------
 * renderRichText — display helper for fields edited with rich-editor.js
 * (mountRichEditor), which is a contenteditable surface. Its saved value is
 * always HTML, but an admin can also just type Markdown-looking text
 * directly (e.g. "**bold**" typed by hand instead of clicking the Bold
 * button) instead of, or mixed in with, using the toolbar. This function
 * handles three cases so either way of writing "just works" on display:
 *
 *   1. Plain text with no HTML tags at all (someone wrote pure Markdown,
 *      or pasted Markdown source) -> runs the full block-level
 *      renderMarkdown() so headings/lists/tables/quotes/fences all work.
 *   2. HTML containing real tags (typical rich-editor output, or pasted
 *      HTML) -> the existing tags are kept as-is; only the *text* inside
 *      them is scanned for literal Markdown inline-syntax ("**x**", "*x*",
 *      "~~x~~", "`x`", "[text](url)") and that text is converted in place.
 *      Block-level Markdown (headings, lists, tables) is intentionally NOT
 *      applied inside existing HTML, since "# " inside an existing <p> is
 *      ambiguous and rich-editor already has real heading/list buttons.
 *   3. Either way, the result is sanitized: <script>/<style>/<iframe> and
 *      similar are dropped, every on* event-handler attribute is removed,
 *      and href/src values are restricted to safe schemes.
 *
 * Returns a safe HTML string suitable for innerHTML.
 * --------------------------------------------------------------------- */
const RICH_TEXT_BLOCKED_TAGS = new Set([
  'script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'base', 'form'
]);

function isSafeUrl(url) {
  const u = (url || '').trim();
  if (!u) return false;
  // Allow relative/anchor links and a known-safe scheme allowlist.
  if (/^(https?:|mailto:|tel:|#|\/)/i.test(u)) return true;
  if (!/^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(u)) return true; // no scheme = relative path
  return false;
}

function sanitizeNode(node) {
  // Walk depth-first; mutate in place. `node` is an Element or Document fragment root.
  const children = Array.from(node.children || []);
  for (const child of children) {
    const tag = child.tagName.toLowerCase();
    if (RICH_TEXT_BLOCKED_TAGS.has(tag)) {
      child.remove();
      continue;
    }
    // Strip event-handler attributes and unsafe URLs.
    for (const attr of Array.from(child.attributes)) {
      const name = attr.name.toLowerCase();
      if (name.startsWith('on')) { child.removeAttribute(attr.name); continue; }
      if ((name === 'href' || name === 'src') && !isSafeUrl(attr.value)) {
        child.removeAttribute(attr.name);
        continue;
      }
      if (name === 'style' && /url\s*\(\s*['"]?\s*javascript:/i.test(attr.value)) {
        child.removeAttribute('style');
      }
    }
    if (tag === 'a') child.setAttribute('rel', 'noopener noreferrer');
    sanitizeNode(child);
  }
}

// Inline-only Markdown -> HTML, applied to a single text run (no block syntax).
function inlineMarkdownToFragment(text) {
  if (!text) return document.createDocumentFragment();
  // Reuse the inline rules from renderMarkdown by escaping then converting,
  // then parsing that small HTML snippet back into nodes.
  const escaped = text
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  let s = escaped;
  s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (_m, alt, src) =>
    isSafeUrl(src) ? `<img src="${src}" alt="${alt}" loading="lazy">` : alt);
  s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_m, t, u) =>
    isSafeUrl(u) ? `<a href="${u}" target="_blank" rel="noopener noreferrer">${t}</a>` : t);
  s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
  s = s.replace(/\*\*\*([^*\n]+)\*\*\*/g, '<strong><em>$1</em></strong>');
  s = s.replace(/___([^_\n]+)___/g, '<strong><em>$1</em></strong>');
  s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/__([^_\n]+)__/g, '<strong>$1</strong>');
  s = s.replace(/(^|[\s(])\*([^\s*][^*\n]*?)\*(?=[\s).,;:!?]|$)/g, '$1<em>$2</em>');
  s = s.replace(/(^|[\s(])_([^\s_][^_\n]*?)_(?=[\s).,;:!?]|$)/g, '$1<em>$2</em>');
  s = s.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');

  const tpl = document.createElement('template');
  tpl.innerHTML = s;
  return tpl.content;
}

function looksLikeMarkdownInline(text) {
  return /\*\*[^*\n]+\*\*|~~[^~\n]+~~|`[^`\n]+`|\[[^\]]+\]\([^)\s]+\)|(^|[\s(])\*[^\s*][^*\n]*?\*(?=[\s).,;:!?]|$)|(^|[\s(])_[^\s_][^_\n]*?_(?=[\s).,;:!?]|$)/.test(text);
}

function convertInlineMarkdownInTextNodes(root) {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  const textNodes = [];
  let n;
  while ((n = walker.nextNode())) textNodes.push(n);
  for (const textNode of textNodes) {
    const parent = textNode.parentNode;
    if (!parent) continue;
    // Don't touch text that's already inside <code>/<pre> — treat as literal.
    if (parent.closest && parent.closest('code, pre')) continue;
    const value = textNode.nodeValue;
    if (!looksLikeMarkdownInline(value)) continue;
    const frag = inlineMarkdownToFragment(value);
    parent.replaceChild(frag, textNode);
  }
}

export function renderRichText(src) {
  if (src == null || src === '') return '';
  const str = String(src);
  const hasHtmlTags = /<[a-z][\s\S]*?>/i.test(str);

  if (!hasHtmlTags) {
    // Pure text — could be plain prose or hand-typed Markdown. The full
    // block-level renderer handles both (plain prose just becomes <p> tags).
    return renderMarkdown(str);
  }

  // Mixed/HTML case: parse, sanitize, then convert literal Markdown inside
  // existing text nodes (skipping code/pre), without disturbing real tags.
  const tpl = document.createElement('template');
  tpl.innerHTML = str;
  sanitizeNode(tpl.content);
  convertInlineMarkdownInTextNodes(tpl.content);
  return tpl.innerHTML;
}
