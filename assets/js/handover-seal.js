/* ═══════════════════════════════════════════════════════════════════════
   The kinetic handover seal: a floating, inspectable seal on the delivery
   and milestone verification pages (signoff, dossier).

   Its content is the page's own record, from a hidden [data-handover-seal]
   element: what was handed over, its state, and (for a sign-off) the
   signature hash, which the seal's ring carries in fragment.

   Physics. Three damped springs: two tilt the seal toward the hand (and,
   while it is carried, into the direction it is moving), and one is its
   position, which trails the pointer with a little inertia rather than
   being glued to it. Let go mid-swing and it keeps the throw, slides to
   rest under friction, and bounces off the viewport edge. While it is
   carried it sheds a fading wake of petals and sparks through
   window.AstraHand.

   A click without a drag inspects it. Enter or Space does the same, the
   arrow keys nudge it, and Escape closes the panel. Under reduced motion it
   stays flat and still. Its resting place is remembered for the tab.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  const src = document.querySelector('[data-handover-seal]');
  if (!src || window.__astraSeal) return;
  window.__astraSeal = true;
  const REDUCE = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const KEY = 'astra_seal_pos:' + location.pathname;
  const SIZE = 118, PAD = 18;
  const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
  const esc = v => String(v).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  const D = src.dataset;
  let rows = [];
  try { rows = JSON.parse(D.sealRows || '[]'); } catch (e) { rows = []; }
  const hash = (D.sealHash || '').trim();
  const ring = ('ASTRA · VERIFIED HANDOVER · ' + (hash ? 'SHA-256 ' + hash.slice(0, 12) + ' · ' : (D.sealKind || '').toUpperCase() + ' · '));

  /* ------------------------------------------------------------ markup */
  const seal = document.createElement('button');
  seal.type = 'button';
  seal.className = 'astra-seal';
  seal.setAttribute('aria-haspopup', 'dialog');
  seal.setAttribute('aria-label', 'Handover seal: ' + (D.sealTitle || '') + '. Press to inspect; drag or use the arrow keys to move it.');
  seal.innerHTML =
    '<span class="astra-seal-body">' +
      '<svg viewBox="0 0 120 120" aria-hidden="true">' +
        '<defs>' +
          '<radialGradient id="sealFace" cx="38%" cy="32%" r="75%"><stop offset="0" stop-color="#ff6a4d"/><stop offset=".55" stop-color="#e0231c"/><stop offset="1" stop-color="#7d0f0b"/></radialGradient>' +
          '<linearGradient id="sealMark" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#ffe9e2"/><stop offset="1" stop-color="#d1e4fa"/></linearGradient>' +
          '<path id="sealRing" d="M60 60 m-43 0 a43 43 0 1 1 86 0 a43 43 0 1 1 -86 0"/>' +
        '</defs>' +
        /* a wax pool: an irregular rim under a round face */
        '<path fill="#9a130e" d="M60 4c9 0 12 5 20 7s15 1 19 8 2 12 6 19 9 11 9 22-6 14-8 21-1 14-8 19-13 3-20 7-10 9-18 9-12-5-20-7-15-1-19-8-2-12-6-19S5 71 5 60s6-14 8-21 1-14 8-19 13-3 20-7 10-9 19-9z"/>' +
        '<circle cx="60" cy="60" r="50" fill="url(#sealFace)"/>' +
        '<circle cx="60" cy="60" r="36" fill="none" stroke="rgba(255,233,226,.55)" stroke-width="1"/>' +
        '<text class="astra-seal-ring"><textPath href="#sealRing" textLength="268">' + esc(ring) + '</textPath></text>' +
        '<g transform="translate(60 61) scale(.62) translate(-24 -24)">' +
          '<path fill="url(#sealMark)" d="M24 3 L45 45 H34.4 L24 24.2 L13.6 45 H3 Z"/>' +
          '<path fill="url(#sealMark)" d="M24 14.5 L27.7 22 L35 25.5 L27.7 29 L24 36.5 L20.3 29 L13 25.5 L20.3 22 Z"/>' +
        '</g>' +
      '</svg>' +
      '<span class="astra-seal-sheen"></span>' +
    '</span>' +
    (D.sealVerified === '1' ? '<span class="astra-seal-tick" aria-hidden="true">✓</span>' : '');
  document.body.appendChild(seal);

  const panel = document.createElement('div');
  panel.className = 'astra-seal-panel';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Handover seal details');
  panel.hidden = true;
  panel.innerHTML =
    '<div class="astra-seal-k">' + esc(D.sealKind || 'Handover') + '</div>' +
    '<div class="astra-seal-t">' + esc(D.sealTitle || '') + '</div>' +
    '<dl>' + rows.map(r => '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>').join('') + '</dl>' +
    (hash ? '<div class="astra-seal-h"><span>Signature hash</span><code>' + esc(hash) + '</code></div>' : '') +
    '<button type="button" class="astra-seal-close">Close</button>';
  document.body.appendChild(panel);

  /* ------------------------------------------------------------ state */
  const S = { x: 0, y: 0, tx: 0, ty: 0, vx: 0, vy: 0, rx: 0, ry: 0, vrx: 0, vry: 0,
              drag: false, down: null, moved: 0, hover: 0, px: -1, py: -1, lastWake: 0 };
  function home() {
    try { const p = JSON.parse(sessionStorage.getItem(KEY)); if (p) return p; } catch (e) { /* private mode */ }
    return { x: innerWidth - SIZE - 28, y: innerHeight - SIZE - 28 };
  }
  function bounds() { return [PAD, innerWidth - SIZE - PAD, PAD + 56, innerHeight - SIZE - PAD]; }
  const h0 = home(), bd = bounds();
  S.x = S.tx = clamp(h0.x, bd[0], bd[1]); S.y = S.ty = clamp(h0.y, bd[2], bd[3]);

  function place() {
    seal.style.transform = `translate3d(${S.x.toFixed(1)}px, ${S.y.toFixed(1)}px, 0)`;
    const body = seal.firstChild;
    body.style.transform = `perspective(520px) rotateX(${S.rx.toFixed(2)}deg) rotateY(${S.ry.toFixed(2)}deg) scale(${(1 + S.hover * .06 + (S.drag ? .08 : 0)).toFixed(3)})`;
    /* the sheen slides against the tilt, like light across a raised face */
    body.style.setProperty('--sx', (50 - S.ry * 2.2).toFixed(1) + '%');
    body.style.setProperty('--sy', (40 + S.rx * 2.2).toFixed(1) + '%');
  }
  function save() { try { sessionStorage.setItem(KEY, JSON.stringify({ x: Math.round(S.x), y: Math.round(S.y) })); } catch (e) { /* ignore */ } }

  /* ------------------------------------------------------------ physics */
  let raf = 0, tPrev = 0, settle = 0;
  function tick(now) {
    const dt = Math.min(.033, (now - tPrev) / 1000 || .016); tPrev = now;
    const [x0, x1, y0, y1] = bounds();
    if (S.drag) {
      /* carried: the seal trails the hand on a stiff spring */
      const ax = (S.tx - S.x) * 260 - S.vx * 22, ay = (S.ty - S.y) * 260 - S.vy * 22;
      S.vx += ax * dt; S.vy += ay * dt;
    } else {
      /* thrown: it slides to rest under friction and bounces off the edges */
      S.vx *= Math.exp(-3.2 * dt); S.vy *= Math.exp(-3.2 * dt);
    }
    S.x += S.vx * dt; S.y += S.vy * dt;
    if (S.x < x0) { S.x = x0; S.vx = Math.abs(S.vx) * .45; } else if (S.x > x1) { S.x = x1; S.vx = -Math.abs(S.vx) * .45; }
    if (S.y < y0) { S.y = y0; S.vy = Math.abs(S.vy) * .45; } else if (S.y > y1) { S.y = y1; S.vy = -Math.abs(S.vy) * .45; }

    /* tilt: toward the hand, and into the motion while it moves */
    let trx = 0, try_ = 0;
    if (S.px >= 0) {
      const cx = S.x + SIZE / 2, cy = S.y + SIZE / 2;
      const dx = clamp((S.px - cx) / 260, -1, 1), dy = clamp((S.py - cy) / 260, -1, 1);
      const near = 1 - clamp(Math.hypot(S.px - cx, S.py - cy) / 520, 0, 1);
      trx = -dy * 16 * near; try_ = dx * 16 * near;
    }
    trx += clamp(-S.vy / 60, -14, 14); try_ += clamp(S.vx / 60, -14, 14);
    S.vrx += ((trx - S.rx) * 140 - S.vrx * 11) * dt; S.rx += S.vrx * dt;
    S.vry += ((try_ - S.ry) * 140 - S.vry * 11) * dt; S.ry += S.vry * dt;

    /* the wake: a few petals and sparks for every stretch it is carried */
    const sp = Math.hypot(S.vx, S.vy);
    if ((S.drag || sp > 120) && window.AstraHand && now - S.lastWake > 45 && sp > 40) {
      S.lastWake = now;
      AstraHand.burst(S.x + SIZE / 2, S.y + SIZE / 2, { count: 2, speed: 50 + sp * .08, angle: Math.atan2(-S.vy, -S.vx) });
    }
    place();
    const quiet = !S.drag && sp < 2 && Math.abs(S.vrx) + Math.abs(S.vry) < .05 && Math.abs(S.rx - trx) + Math.abs(S.ry - try_) < .05;
    settle = quiet ? settle + dt : 0;
    if (settle > .3) { raf = 0; save(); return; }
    raf = requestAnimationFrame(tick);
  }
  function go() { if (REDUCE) { place(); return; } if (!raf) { tPrev = performance.now(); raf = requestAnimationFrame(tick); } }

  /* ------------------------------------------------------------ input */
  seal.addEventListener('pointerdown', e => {
    if (e.button !== 0) return;
    seal.setPointerCapture(e.pointerId);
    S.down = { x: e.clientX, y: e.clientY, ox: e.clientX - S.x, oy: e.clientY - S.y };
    S.hvx = S.hvy = 0;
    S.moved = 0;
  });
  seal.addEventListener('pointermove', e => {
    if (!S.down) return;
    S.moved = Math.max(S.moved, Math.hypot(e.clientX - S.down.x, e.clientY - S.down.y));
    if (!S.drag && S.moved > 5) { S.drag = true; seal.classList.add('is-carried'); closePanel(); }
    if (S.drag) {
      /* the hand's own velocity, for the throw on release */
      /* smoothed, so one jumpy event is not read as a hurl */
      const t = performance.now(), ddt = Math.max(.016, (t - (S.down.t || t)) / 1000);
      const ivx = S.down.t ? (e.clientX - S.down.lx) / ddt : 0, ivy = S.down.t ? (e.clientY - S.down.ly) / ddt : 0;
      S.hvx = (S.hvx || 0) * .5 + ivx * .5; S.hvy = (S.hvy || 0) * .5 + ivy * .5;
      S.down.t = t; S.down.lx = e.clientX; S.down.ly = e.clientY;
      S.tx = e.clientX - S.down.ox; S.ty = e.clientY - S.down.oy;
      if (REDUCE) { S.x = S.tx; S.y = S.ty; }
      go();
    }
  });
  const release = () => {
    if (!S.down) return;
    const was = S.drag;
    S.down = null; S.drag = false; seal.classList.remove('is-carried');
    if (was) {
      /* let go: the seal glides the rest of the way to where the hand
         released it (friction 3.2/s means a speed of d * 3.2 covers exactly
         d), and keeps a share of the hand's throw on top */
      S.vx = (S.tx - S.x) * 3.2 + clamp(S.hvx || 0, -1600, 1600) * .22;
      S.vy = (S.ty - S.y) * 3.2 + clamp(S.hvy || 0, -1600, 1600) * .22;
      S.hvx = S.hvy = 0;
      S.suppress = true; go(); save();
    }
  };
  seal.addEventListener('pointerup', release);
  seal.addEventListener('pointercancel', release);
  seal.addEventListener('click', e => {
    if (S.suppress) { S.suppress = false; e.preventDefault(); return; }
    panel.hidden ? openPanel() : closePanel();
  });
  seal.addEventListener('pointerenter', () => { S.hover = 1; go(); });
  seal.addEventListener('pointerleave', () => { S.hover = 0; go(); });
  seal.addEventListener('keydown', e => {
    const k = { ArrowLeft: [-24, 0], ArrowRight: [24, 0], ArrowUp: [0, -24], ArrowDown: [0, 24] }[e.key];
    if (!k) return;
    e.preventDefault();
    const [x0, x1, y0, y1] = bounds();
    S.x = clamp(S.x + k[0], x0, x1); S.y = clamp(S.y + k[1], y0, y1);
    place(); save();
  });
  addEventListener('pointermove', e => { S.px = e.clientX; S.py = e.clientY; if (!REDUCE) go(); }, { passive: true });
  addEventListener('resize', () => { const [x0, x1, y0, y1] = bounds(); S.x = clamp(S.x, x0, x1); S.y = clamp(S.y, y0, y1); place(); }, { passive: true });

  /* ------------------------------------------------------------ panel */
  function openPanel() {
    panel.hidden = false;
    const w = panel.offsetWidth, h = panel.offsetHeight;
    const left = S.x + SIZE / 2 > innerWidth / 2 ? S.x - w - 14 : S.x + SIZE + 14;
    panel.style.left = clamp(left, 12, innerWidth - w - 12) + 'px';
    panel.style.top = clamp(S.y + SIZE / 2 - h / 2, 12, innerHeight - h - 12) + 'px';
    seal.setAttribute('aria-expanded', 'true');
    panel.querySelector('.astra-seal-close').focus({ preventScroll: true });
  }
  function closePanel() {
    if (panel.hidden) return;
    panel.hidden = true;
    seal.setAttribute('aria-expanded', 'false');
  }
  panel.querySelector('.astra-seal-close').addEventListener('click', () => { closePanel(); seal.focus({ preventScroll: true }); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) { closePanel(); seal.focus({ preventScroll: true }); } });
  document.addEventListener('pointerdown', e => { if (!panel.hidden && !panel.contains(e.target) && !seal.contains(e.target)) closePanel(); });

  place();
})();
