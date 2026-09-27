// ── Slide-out sidebar drawer controller ──────────────────────────────────
// Wires #sidebar-toggle / #hastra-sidebar / #sidebar-overlay if present on
// the page. Safe no-op on pages that don't include the sidebar markup.
(function () {
  'use strict';

  function init() {
    var toggle   = document.getElementById('sidebar-toggle');
    var sidebar  = document.getElementById('hastra-sidebar');
    var overlay  = document.getElementById('sidebar-overlay');
    var closeBtn = document.getElementById('sidebar-close');
    if (!toggle || !sidebar || !overlay) return;

    function open() {
      sidebar.classList.add('open');
      overlay.classList.add('open');
      toggle.setAttribute('aria-expanded', 'true');
      document.body.classList.add('sidebar-locked');
    }
    function close() {
      sidebar.classList.remove('open');
      overlay.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('sidebar-locked');
    }
    function toggleOpen() {
      if (sidebar.classList.contains('open')) close(); else open();
    }

    toggle.addEventListener('click', toggleOpen);
    overlay.addEventListener('click', close);
    if (closeBtn) closeBtn.addEventListener('click', close);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && sidebar.classList.contains('open')) close();
    });

    // Closing on link click keeps the drawer from staying open across the
    // fade-out page transition (core/theme.js adds body.fade-out on nav).
    sidebar.querySelectorAll('.sidebar-link').forEach(function (link) {
      link.addEventListener('click', close);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
