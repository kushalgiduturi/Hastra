(function() {
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
    document.dispatchEvent(new CustomEvent('astra:themechange', { detail: { theme: theme } }));
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
    if (form.dataset.noTransition) return;
    document.body.classList.add('fade-out');
  });

  // ── Brand intro: "ASTRA" projects out of the icon once per tab session ──
  // (keyframes + the body.astra-intro-run rules live in core/theme.css).
  // A page with its own reveal choreography (auth/login.php's video/card
  // sequence) sets <body data-intro-manual> and fires the same class
  // itself at the right moment instead of this automatic run.
  const INTRO_KEY = 'astra_intro_played';
  function runBrandIntro() {
    if (document.body.hasAttribute('data-intro-manual')) return;
    let played;
    try { played = sessionStorage.getItem(INTRO_KEY); } catch (e) { played = null; }
    if (played) return;
    document.body.classList.add('astra-intro-run');
    try { sessionStorage.setItem(INTRO_KEY, 'true'); } catch (e) { /* private mode etc. */ }
    window.setTimeout(function() {
      document.body.classList.remove('astra-intro-run');
    }, 1200);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', runBrandIntro);
  } else {
    runBrandIntro();
  }

})();
