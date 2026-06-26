/* ============================================================
   Quiznosis — Bottom Navigation Bar (pwa-nav.js)
   Mounts a native-app-style bottom tab bar on mobile.
   Call mountBottomNav(activeTab) on each page.

   Active tab values:
     'home', 'quizzes', 'courses', 'dashboard', 'profile'
   ============================================================ */

const NAV_ITEMS = [
  {
    id: 'home',
    label: 'Home',
    href: '/index.html',
    icon: `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
      <polyline points="9 22 9 12 15 12 15 22"/>
    </svg>`,
  },
  {
    id: 'quizzes',
    label: 'Quizzes',
    href: '/quizzes.html',
    icon: `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
      <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
    </svg>`,
  },
  {
    id: 'courses',
    label: 'Courses',
    href: '/courses.html',
    icon: `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
      <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
    </svg>`,
  },
  {
    id: 'dashboard',
    label: 'Dashboard',
    href: '/dashboard.html',
    icon: `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
      <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
    </svg>`,
    authRequired: true,
  },
  {
    id: 'profile',
    label: 'Profile',
    href: '/profile.html',
    icon: `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
      <circle cx="12" cy="7" r="4"/>
    </svg>`,
    authRequired: true,
    loginFallback: '/login.html',
  },
];

/**
 * Mount the bottom navigation bar.
 * @param {string} active  - ID of the active tab
 * @param {object} user    - user object from getUser(), or null
 */
export function mountBottomNav(active = '', user = null) {
  // Only show on mobile widths
  if (!document.querySelector('#qz-bottom-nav')) {
    injectNav(active, user);
  }

  // Watch for resize to show/hide
  const mq = window.matchMedia('(max-width: 640px)');
  mq.addEventListener('change', () => {
    const nav = document.getElementById('qz-bottom-nav');
    if (nav) nav.style.display = mq.matches ? 'block' : 'none';
  });
}

function injectNav(active, user) {
  const nav = document.createElement('nav');
  nav.id = 'qz-bottom-nav';
  nav.setAttribute('aria-label', 'Main navigation');

  const inner = document.createElement('div');
  inner.className = 'qz-bnav-inner';

  NAV_ITEMS.forEach(item => {
    // Skip auth-required items when logged out
    if (item.authRequired && !user) return;

    const a = document.createElement('a');
    a.href = item.authRequired && !user && item.loginFallback
      ? item.loginFallback
      : item.href;
    a.className = 'qz-bnav-item' + (active === item.id ? ' active' : '');
    a.setAttribute('aria-label', item.label);
    if (active === item.id) a.setAttribute('aria-current', 'page');

    a.innerHTML = `
      <span class="qz-bnav-icon">${item.icon}</span>
      <span>${item.label}</span>
    `;

    inner.appendChild(a);
  });

  // If not logged in, show Login instead of auth-required items
  if (!user) {
    const loginItem = {
      id: 'login',
      label: 'Sign in',
      href: '/login.html',
      icon: `<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
        <polyline points="10 17 15 12 10 7"/>
        <line x1="15" y1="12" x2="3" y2="12"/>
      </svg>`,
    };

    const a = document.createElement('a');
    a.href = loginItem.href;
    a.className = 'qz-bnav-item' + (active === loginItem.id ? ' active' : '');
    a.setAttribute('aria-label', loginItem.label);
    a.innerHTML = `
      <span class="qz-bnav-icon">${loginItem.icon}</span>
      <span>${loginItem.label}</span>
    `;
    inner.appendChild(a);
  }

  nav.appendChild(inner);
  document.body.appendChild(nav);
}
