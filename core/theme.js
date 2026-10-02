(function() {
  // ── Global AX preloader ─────────────────────────────────────────────────
  // Injected via document.write() so it lands the instant this (synchronous,
  // <head>-loaded) script runs — before the rest of the page has painted —
  // and removed on window 'load' so it never outlives real content or
  // blocks a click. A page can opt out entirely by adding
  // data-loader-manual to this very <script core/theme.js> tag (body isn't
  // parsed yet at this point, so a <body> attribute can't be read here).
  (function initPageLoader() {
    if (document.currentScript && document.currentScript.hasAttribute('data-loader-manual')) return;

    // Matched against just the final path segment (e.g. "login.php" or
    // "project_portal") — NOT the full pathname, which always contains
    // "login" here since the whole app is served under an /Hastra/ base path.
    const LOADER_TEXT_MAP = [
      [/^(login|otp|forgot|signin|verify$|reset)/, 'Initializing defense-grade workspace…'],
      [/^(register|verify_register|signup|verify-email|set-password)/, 'Provisioning your Hastra workspace…'],
      [/project|requirement/,           'Fetching SDLC project pipeline…'],
      [/team|roster|directory/,         'Loading enterprise team matrix…'],
      [/deliver/,                       'Decrypting delivery dossier…'],
      [/attendance/,                    'Syncing attendance records…'],
      [/leave_management/,              'Loading leave policy console…'],
      [/testing_portal|bug/,            'Pulling QA bug queue…'],
      [/deployment/,                    'Preparing deployment pipeline…'],
      [/billing|invoice/,               'Reconciling billing ledger…'],
      [/security/,                      'Running security diagnostics…'],
      [/doc/,                           'Loading documentation…'],
    ];
    function contextualText() {
      const segments = window.location.pathname.toLowerCase().split('/').filter(Boolean);
      const page = (segments[segments.length - 1] || '').replace(/\.php$/, '');
      for (const [re, text] of LOADER_TEXT_MAP) {
        if (re.test(page)) return text;
      }
      return 'Loading…';
    }

    document.write(
      '<div id="hastra-page-loader">' +
        '<div class="hastra-loader-spinner"></div>' +
        '<p id="hastra-loader-text" class="loader-status-text">' + contextualText() + '</p>' +
      '</div>'
    );

    function hidePageLoader() {
      const el = document.getElementById('hastra-page-loader');
      if (!el) return;
      el.style.opacity = '0';
      el.style.pointerEvents = 'none';
      setTimeout(function() { el.remove(); }, 260);
    }
    window.addEventListener('load', hidePageLoader);
    // Fail-safe: never let a stuck asset (slow font, video, etc.) hold the
    // loader up indefinitely — the rest of the page is interactive either way.
    setTimeout(hidePageLoader, 4000);
  })();

  const THEME_KEY = 'astra_theme';
  const LEGACY_THEME_KEY = 'cycops_theme'; // pre-rename key, read once for migration

  function sunSVG() {
    return '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 17a5 5 0 100-10 5 5 0 000 10zm0-13a1 1 0 011 1v1a1 1 0 11-2 0V5a1 1 0 011-1zm0 14a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zm9-7a1 1 0 010 2h-1a1 1 0 110-2h1zM4 12a1 1 0 010 2H3a1 1 0 110-2h1zm14.95 5.364a1 1 0 010 1.414l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 0zM6.343 6.343a1 1 0 010 1.414l-.707.707A1 1 0 014.22 7.05l.707-.707a1 1 0 011.414 0zm12.02 0a1 1 0 011.414 1.414l-.707.707a1 1 0 01-1.414-1.414l.707-.707zM6.343 17.657a1 1 0 011.414 1.414l-.707.707a1 1 0 01-1.414-1.414l.707-.707z"/></svg>';
  }

  function moonSVG() {
    return '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M21 12.79A9 9 0 1111.21 3a7 7 0 109.79 9.79z"/></svg>';
  }

  function updateButton(theme) {
    const btn = document.getElementById('themeToggleBtn');
    if (!btn) return;
    const icon  = btn.querySelector('.theme-icon');
    const label = btn.querySelector('.theme-label');
    if (!icon || !label) return;
    if (theme === 'light') {
      icon.innerHTML  = moonSVG();
      label.textContent = 'Dark';
    } else {
      icon.innerHTML  = sunSVG();
      label.textContent = 'Light';
    }
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem(THEME_KEY, theme);
    updateButton(theme);
    document.dispatchEvent(new CustomEvent('hastra:themechange', { detail: { theme: theme } }));
  }

  window.toggleTheme = function() {
    const current = document.documentElement.getAttribute('data-theme') || 'dark';
    applyTheme(current === 'dark' ? 'light' : 'dark');
  };

  // Apply saved theme immediately (before DOM ready) to avoid flash
  const saved = localStorage.getItem(THEME_KEY) || localStorage.getItem(LEGACY_THEME_KEY) || 'dark';
  document.documentElement.setAttribute('data-theme', saved);

  // Update button once DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      updateButton(saved);
    });
  } else {
    updateButton(saved);
  }

  // ── Keyboard parity for custom controls: anything given a button/radio/tab
  // role without being a native control answers Enter and Space like one.
  document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    const el = e.target;
    if (!el || !el.matches || el.isContentEditable) return;
    if (!el.matches('[role="button"], [role="radio"], [role="tab"], [role="switch"], [role="menuitem"]')) return;
    if (el.matches('button, a[href], input, select, textarea, summary')) return;
    e.preventDefault();
    el.click();
  });

  // ── Smooth page-to-page transitions ──────────────────────────────────────
  document.addEventListener('click', function(e) {
    const link = e.target.closest('a');
    if (!link) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:')) return;
    try {
      const url = new URL(href, window.location.href);
      if (url.origin !== window.location.origin) return;
    } catch (err) { return; }

    e.preventDefault();
    document.body.classList.add('fade-out');
    setTimeout(function() { window.location.href = href; }, 160);
  });

  // Forms: fade out on submit too (skip AJAX-only forms that already use fetch)
  document.addEventListener('submit', function(e) {
    const form = e.target;
    if (e.defaultPrevented || form.dataset.noTransition) return;
    document.body.classList.add('fade-out');
  });

  // ── Brand intro: "HASTRA" projects out of the icon once per tab session ──
  // (keyframes + the body.hastra-intro-run rules live in core/theme.css).
  // A page with its own reveal choreography (auth/login.php's video/card
  // sequence) sets <body data-intro-manual> and fires the same class
  // itself at the right moment instead of this automatic run.
  const INTRO_KEY = 'astra_intro_played';
  function runBrandIntro() {
    if (document.body.hasAttribute('data-intro-manual')) return;
    let played;
    try { played = sessionStorage.getItem(INTRO_KEY); } catch (e) { played = null; }
    if (played) return;
    document.body.classList.add('hastra-intro-run');
    try { sessionStorage.setItem(INTRO_KEY, 'true'); } catch (e) { /* private mode etc. */ }
    window.setTimeout(function() {
      document.body.classList.remove('hastra-intro-run');
    }, 1200);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', runBrandIntro);
  } else {
    runBrandIntro();
  }

  // ── Atmosphere stack: the shared 3-D scene, the pointer engine, cloth
  // cards, the handover seal and the view director ──
  // Mounted from here because this is the one script every page loads, so
  // no portal has to list more tags. The version query is read off this
  // script's own URL, so the stack busts caches with ASSET_VERSION.
  //   <body data-atmosphere-off>        nothing at all (the landing page host)
  //   <body data-atmosphere-scene-off>  the pointer engine and reveals, no scene
  (function mountAtmosphere() {
    const me = document.currentScript;
    if (!me || !me.src || window.top !== window.self) return;
    let url;
    try { url = new URL(me.src, location.href); } catch (err) { return; }
    const base = url.href.replace(/core\/theme\.js.*$/, '');
    const v = url.searchParams.get('v');
    const q = v ? '?v=' + encodeURIComponent(v) : '';
    const css = document.createElement('link');
    css.rel = 'stylesheet'; css.href = base + 'assets/css/hastra-atmosphere.css' + q;
    document.head.appendChild(css);
    // Signed-in portals wear the Netflix design system (theme-netflix.css +
    // netflix-bento.css): a boxless UI over the temple world with its cyber
    // layer, laser-beam controls and the dot-plus-ring cursor.
    // Loaded here, in <head>, so the first paint is already themed.
    const nf = /\/(portals|workspace|labs)\/|\/dashboard(\.php)?$/.test(location.pathname);
    if (nf) {
      document.documentElement.classList.add('nf-portal');
      ['theme-netflix', 'netflix-bento'].forEach(function(name) {
        const l = document.createElement('link');
        l.rel = 'stylesheet'; l.href = base + 'assets/css/' + name + '.css' + q;
        document.head.appendChild(l);
      });
    }
    function go() {
      const b = document.body;
      if (!b || b.hasAttribute('data-atmosphere-off')) return;
      const noScene = b.hasAttribute('data-atmosphere-scene-off');
      // portals: the temple world behind a boxless UI, with the cyber layer
      // (kage-cyber) added to it and the Netflix spotlight + cursor on top.
      // No cloth-cards: its fabric plates are container surfaces.
      const stack = nf
        ? ['landing-host', 'kage-scene', 'kage-cyber', 'netflix-spotlight', 'netflix-cursor', 'handover-seal', 'view-director']
        : ['landing-host', 'kage-scene', 'sakura-petals-cursor', 'hybrid-hand-cursor', 'cloth-cards', 'handover-seal', 'view-director'];
      stack.forEach(function(name) {
        if ((name === 'landing-host' || name === 'kage-scene' || name === 'kage-cyber') && noScene) return;
        // a page carrying its own dedicated cursor engine (auth/login.php's
        // richer camera-space wisp trail) opts out of the shared simple one
        if ((name === 'hybrid-hand-cursor' || name === 'sakura-petals-cursor') && b.hasAttribute('data-cursor-off')) return;
        if (name === 'cloth-cards' && !document.querySelector('[data-cloth]')) return;
        if (name === 'handover-seal' && !document.querySelector('[data-handover-seal]')) return;
        const s = document.createElement('script');
        s.src = base + 'assets/js/' + name + '.js' + q;
        s.async = false;                 // keep order: the scene needs the host, the director the rig
        b.appendChild(s);
      });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go);
    else go();
  })();

})();
