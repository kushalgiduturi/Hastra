/* assets/js/spatial-fx.js: the spatial UI's small motion layer.

   1. Pointer light: writes --sp-mx / --sp-my on the hovered glass surface
      (.sp-glass, or anything with [data-sp-glow]) so its ::after highlight
      follows the cursor.
   2. Tilt: a gentle 3-D tilt for [data-sp-tilt] elements, capped by the CSS
      token --sp-tilt-max (3deg; 0 under reduced motion, coarse pointers and
      low-perf mode, so the CSS fallbacks switch it off too).
   3. Indicators: positions the sliding pill of .sp-seg and the underline of
      .sp-tabs under the selected button (layout, not decoration, so it runs
      everywhere; the motion itself collapses under reduced motion).

   One delegated pointermove listener, passive, throttled to one update per
   animation frame. Pointer light and tilt are skipped entirely under
   prefers-reduced-motion, (pointer: coarse) and html.low-perf-mode. */
(function () {
  'use strict';
  if (window.__spatialFx) return;
  window.__spatialFx = true;

  var root = document.documentElement;
  var mqMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var mqCoarse = window.matchMedia('(pointer: coarse)');

  function still() {
    return mqMotion.matches || mqCoarse.matches || root.classList.contains('low-perf-mode');
  }

  // ── Indicators ────────────────────────────────────────────────────────────
  function selectedIn(group) {
    return group.querySelector(':scope > [aria-selected="true"], :scope > [aria-pressed="true"]');
  }
  function place(group) {
    var b = selectedIn(group);
    if (!b) { group.style.setProperty('--sp-ind-w', '0px'); return; }
    group.style.setProperty('--sp-ind-w', b.offsetWidth + 'px');
    group.style.setProperty('--sp-ind-x', b.offsetLeft + 'px');
  }
  function placeAll() {
    document.querySelectorAll('.sp-seg, .sp-tabs').forEach(place);
  }
  // keep indicators in step with whatever script flips aria-selected
  var mo = new MutationObserver(function (records) {
    var seen = new Set();
    records.forEach(function (r) {
      var g = r.target.parentElement;
      if (g && g.matches && g.matches('.sp-seg, .sp-tabs') && !seen.has(g)) { seen.add(g); place(g); }
    });
  });
  function watch() {
    document.querySelectorAll('.sp-seg, .sp-tabs').forEach(function (g) {
      mo.observe(g, { subtree: true, attributes: true, attributeFilter: ['aria-selected', 'aria-pressed'] });
    });
    placeAll();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch);
  else watch();
  window.addEventListener('resize', placeAll, { passive: true });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(placeAll);
  document.addEventListener('hastra:themechange', placeAll);
  window.HastraSpatial = { refresh: function () { watch(); } };

  // ── Pointer light and tilt ───────────────────────────────────────────────
  var raf = 0, last = null, tilted = null;

  function untilt() {
    if (tilted) { tilted.style.transform = ''; tilted = null; }
  }

  function frame() {
    raf = 0;
    var e = last;
    if (!e || still()) { untilt(); return; }
    var t = e.target && e.target.closest ? e.target : null;
    if (!t) return;

    var glass = t.closest('[data-sp-glow], .sp-glass');
    if (glass) {
      var r = glass.getBoundingClientRect();
      glass.style.setProperty('--sp-mx', (e.clientX - r.left).toFixed(0) + 'px');
      glass.style.setProperty('--sp-my', (e.clientY - r.top).toFixed(0) + 'px');
    }

    var k = t.closest('[data-sp-tilt]');
    if (tilted && tilted !== k) untilt();
    if (k) {
      var max = parseFloat(getComputedStyle(k).getPropertyValue('--sp-tilt-max')) || 0;
      if (!max) return;
      var c = k.getBoundingClientRect();
      var x = (e.clientX - c.left) / c.width - 0.5;
      var y = (e.clientY - c.top) / c.height - 0.5;
      k.style.transform = 'perspective(700px) rotateX(' + (-y * max).toFixed(2) + 'deg) rotateY(' + (x * max).toFixed(2) + 'deg)';
      tilted = k;
    }
  }

  document.addEventListener('pointermove', function (e) {
    if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return;
    last = e;
    if (!raf) raf = requestAnimationFrame(frame);
  }, { passive: true });
  document.addEventListener('pointerleave', untilt, { passive: true });
  window.addEventListener('blur', untilt);
  mqMotion.addEventListener && mqMotion.addEventListener('change', untilt);
})();
