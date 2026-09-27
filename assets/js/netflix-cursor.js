/* Hastra — two-layer precision cursor (CustomCursor.jsx).
   A 12px accent dot, a 48px ring that trails it, and a soft ambient glow that
   follows the pointer across the page. The original drives these with
   gsap.quickTo (dot 0.05s, ring 0.15s); here the same feel comes from
   frame-rate-independent exponential easing, so no animation library ships.
   The system cursor stays visible: this decorates it, never replaces it. */
(function () {
  'use strict';
  const mq = function (q) { return window.matchMedia && matchMedia(q).matches; };
  if (mq('(hover: none) and (pointer: coarse)') || mq('(prefers-reduced-motion: reduce)')) return;
  if (document.getElementById('nf-cursor-dot')) return;

  const DOT = 12, RING = 48, GLOW = 600;
  function el(id) {
    const d = document.createElement('div');
    d.id = id; d.setAttribute('aria-hidden', 'true');
    document.body.appendChild(d);
    return d;
  }
  const glow = el('nf-cursor-glow'), dot = el('nf-cursor-dot'), ring = el('nf-cursor-ring');

  // time constants matching the gsap durations: ~95% of the way in that time
  const TAU_DOT = 0.05 / 3, TAU_RING = 0.15 / 3;
  const target = { x: -100, y: -100 }, d = { x: -100, y: -100 }, r = { x: -100, y: -100 };
  let raf = 0, last = 0, shown = false;

  function place() {
    dot.style.transform = 'translate3d(' + (d.x - DOT / 2) + 'px,' + (d.y - DOT / 2) + 'px,0)';
    ring.style.transform = 'translate3d(' + (r.x - RING / 2) + 'px,' + (r.y - RING / 2) + 'px,0)';
    glow.style.transform = 'translate3d(' + (target.x - GLOW / 2) + 'px,' + (target.y - GLOW / 2) + 'px,0)';
  }
  function tick(now) {
    const dt = Math.min(0.05, (now - (last || now)) / 1000);
    last = now;
    const kd = 1 - Math.exp(-dt / TAU_DOT), kr = 1 - Math.exp(-dt / TAU_RING);
    d.x += (target.x - d.x) * kd; d.y += (target.y - d.y) * kd;
    r.x += (target.x - r.x) * kr; r.y += (target.y - r.y) * kr;
    place();
    const settled = Math.abs(target.x - r.x) < 0.1 && Math.abs(target.y - r.y) < 0.1;
    raf = settled ? 0 : requestAnimationFrame(tick);
    if (!raf) last = 0;
  }
  function show(on) {
    if (shown === on) return;
    shown = on;
    document.documentElement.classList.toggle('nf-cursor-on', on);
  }

  window.addEventListener('pointermove', function (e) {
    if (e.pointerType && e.pointerType !== 'mouse') return;
    target.x = e.clientX; target.y = e.clientY;
    if (!shown) { d.x = r.x = target.x; d.y = r.y = target.y; place(); show(true); }
    if (!raf) raf = requestAnimationFrame(tick);
  }, { passive: true });
  document.addEventListener('mouseleave', function () { show(false); });
  document.addEventListener('mouseenter', function () { show(true); });
  window.addEventListener('blur', function () { show(false); });

  // the ring swells over anything clickable
  const HOT = 'a, button, [role="button"], input, select, textarea, label, summary, .netflix-bento-card';
  document.addEventListener('pointerover', function (e) {
    ring.classList.toggle('is-hot', !!(e.target.closest && e.target.closest(HOT)));
  }, { passive: true });
  document.addEventListener('pointerdown', function () { dot.classList.add('is-down'); }, { passive: true });
  document.addEventListener('pointerup', function () { dot.classList.remove('is-down'); }, { passive: true });
})();
