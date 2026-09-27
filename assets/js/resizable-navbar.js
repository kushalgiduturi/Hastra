// Hastra — Resizable Scroll-Reactive Floating Navbar (vanilla JS, no build
// step). Toggles `.scrolled-navbar` on <nav class="hastra-resizable-nav">
// past 100px of scroll, throttled to one check per animation frame so a
// fast scroll gesture doesn't fire dozens of redundant class toggles.
(function () {
  const nav = document.getElementById('hastraNav');
  if (!nav) return;

  let ticking = false;
  function applyScrollState() {
    const scrolled = window.scrollY > 100;
    nav.classList.toggle('scrolled-navbar', scrolled);
    ticking = false;
  }
  function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(applyScrollState);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  applyScrollState(); // correct state on load if the page opens mid-scroll (e.g. #anchor)

  // ── Mobile off-canvas drawer ──────────────────────────────────────────
  const drawer = document.getElementById('arnMobileDrawer');
  const openBtn = document.getElementById('arnMobileOpen');
  const closeBtn = document.getElementById('arnMobileClose');
  if (drawer && openBtn) {
    const open = () => { drawer.classList.add('open'); document.body.style.overflow = 'hidden'; };
    const close = () => { drawer.classList.remove('open'); document.body.style.overflow = ''; };
    openBtn.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', close);
    drawer.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', close); });
    window.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  }
})();
