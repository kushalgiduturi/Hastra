/* ═══════════════════════════════════════════════════════════════════════
   Cursor motes: a plain, quiet pointer trail — glowing points in Void
   the theme accent (red by night, blue by day) and Frost Glow #d1e4fa, emitted by distance travelled
   (a slow hand lays a continuous drift, a fast one throws the motes
   apart), fading out as they drift and rise. No tumbling shapes, no
   scatter bursts, no orbiting aura — this was simplified back down after
   an earlier, much busier version (dual botanical/crypto particle
   streams, card-brush scatter, a spinning idle ring) read as too much
   motion for a cursor. Cards still tilt slightly toward the pointer
   (flexTick below) — that part stayed, since it's a quiet feel, not a
   graphic.

   Governor: frame times are averaged; under 50 fps the mote budget halves
   and the canvas drops to 0.8x resolution. Touch screens (hover: none /
   pointer: coarse) get no trail at all. Under prefers-reduced-motion
   nothing is emitted.

   Public: window.AstraHand.burst(x, y, opts) for other scripts (the
   handover seal's wake), in CSS pixels.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.AstraHand) return;
  const mq = q => matchMedia(q).matches;
  const TOUCH = mq('(hover: none)') || mq('(pointer: coarse)');
  const REDUCE = mq('(prefers-reduced-motion: reduce)');
  const rnd = (a, b) => a + Math.random() * (b - a);
  const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
  const smooth = (a, b, x) => { const t = clamp((x - a) / (b - a), 0, 1); return t * t * (3 - 2 * t); };

  const API = window.AstraHand = { burst() {}, touch: TOUCH, reduce: REDUCE };

  /* ------------------------------------------------------------ budget */
  const MOTES = 190;
  const G = { cap: MOTES, scale: 1, eco: false, acc: 0, n: 0 };
  const STEP = 14;                                  /* px of travel per emission */

  /* ------------------------------------------------------------ sprite */
  function sprite(S, rgb) {
    const c = document.createElement('canvas'); c.width = c.height = S;
    const x = c.getContext('2d'), g = x.createRadialGradient(S / 2, S / 2, 0, S / 2, S / 2, S / 2);
    const [r, gg, b] = rgb;
    g.addColorStop(0, 'rgba(255,255,255,1)');
    g.addColorStop(.08, `rgba(${r},${gg},${b},.92)`);
    g.addColorStop(.22, `rgba(${r},${gg},${b},.38)`);
    g.addColorStop(.5, `rgba(${r},${gg},${b},.1)`);
    g.addColorStop(1, `rgba(${r},${gg},${b},0)`);
    x.fillStyle = g; x.fillRect(0, 0, S, S);
    return c;
  }
  const INK = {
    night: { motes: [sprite(64, [209, 228, 250]), sprite(64, [224, 46, 60])], blend: 'lighter' },
    day:   { motes: [sprite(64, [2, 101, 220]), sprite(64, [63, 92, 140])], blend: 'source-over' }
  };
  let ink = INK.night;

  /* ------------------------------------------------------------ pool */
  const M = [];
  for (let i = 0; i < MOTES; i++) M.push({ life: 1, max: 1 });
  let mi = 0;

  function emit(x, y, vx, vy, weak) {
    const m = M[mi]; mi = (mi + 1) % G.cap;
    m.x = x + rnd(-3, 3); m.y = y + rnd(-3, 3);
    m.vx = vx + rnd(-16, 16); m.vy = vy + rnd(-16, 16) - 6;
    m.c = Math.random() < .6 ? 0 : 1;
    m.sz = (weak ? 8 : 11) + rnd(0, 8);
    m.life = 0; m.max = (weak ? 1.3 : .9) + rnd(0, .9);
  }
  function burst(x, y, o) {
    o = o || {};
    if (!cx || TOUCH || REDUCE) return;
    const n = o.count || 5, sp = o.speed || 90;
    for (let i = 0; i < n; i++) {
      const a = o.angle !== undefined ? o.angle + rnd(-.7, .7) : rnd(0, Math.PI * 2), v = sp * rnd(.4, 1);
      emit(x, y, Math.cos(a) * v, Math.sin(a) * v, true);
    }
    wake();
  }
  API.burst = burst;

  /* ------------------------------------------------------------ canvas */
  let cv = null, cx = null, dpr = 1, raf = 0, running = false, tPrev = 0;
  const H = { x: 0, y: 0, ex: 0, ey: 0, lx: 0, ly: 0, seen: false, acc: 0, idle: 0 };

  function size() {
    dpr = Math.min(devicePixelRatio || 1, 2) * G.scale;
    cv.width = Math.round(innerWidth * dpr); cv.height = Math.round(innerHeight * dpr);
  }

  function frame(now) {
    const raw = (now - tPrev) / 1000 || 0, dt = Math.min(raw, .05);
    tPrev = now;
    if (!G.eco && raw > 0) {
      G.acc += raw; G.n++;
      if (G.n >= 45) {
        if (G.acc / G.n > 1 / 50) { G.eco = true; G.cap = MOTES / 2; G.scale = .8; mi %= G.cap; size(); }
        G.acc = 0; G.n = 0;
      }
    }

    H.ex += (H.x - H.ex) * Math.min(1, 18 * dt); H.ey += (H.y - H.ey) * Math.min(1, 18 * dt);
    const dx = H.ex - H.lx, dy = H.ey - H.ly, moved = Math.hypot(dx, dy);
    const ang = moved > 1e-5 ? Math.atan2(dy, dx) : 0;
    H.acc += moved;
    let guard = 0;
    while (H.acc >= STEP && guard++ < 16) {
      H.acc -= STEP;
      const t = moved > 1e-3 ? Math.min(1, guard * STEP / moved) : 0;
      emit(H.lx + dx * t, H.ly + dy * t, -Math.cos(ang) * 12, -Math.sin(ang) * 12, false);
    }
    H.idle = moved < .5 ? H.idle + dt : 0;
    if (H.idle > .5 && H.idle - dt <= .5 && H.seen) emit(H.ex, H.ey, 0, -4, true);
    H.lx = H.ex; H.ly = H.ey;

    cx.setTransform(1, 0, 0, 1, 0, 0);
    cx.clearRect(0, 0, cv.width, cv.height);
    cx.globalCompositeOperation = ink.blend;
    let live = 0;
    for (let i = 0; i < G.cap; i++) {
      const m = M[i];
      if (m.life >= m.max) continue;
      live++;
      m.life += dt;
      const u = m.life / m.max;
      m.x += m.vx * dt; m.y += m.vy * dt;
      m.vx *= 1 - 1.6 * dt; m.vy = m.vy * (1 - 1.6 * dt) - 18 * dt;
      const a = smooth(0, .1, u) * (1 - smooth(.4, 1, u));
      if (a < .01) continue;
      const w = m.sz * dpr;
      cx.globalAlpha = a;
      cx.drawImage(ink.motes[m.c], m.x * dpr - w / 2, m.y * dpr - w / 2, w, w);
    }
    cx.globalAlpha = 1;
    cx.globalCompositeOperation = 'source-over';
    if (!live && moved < .05 && H.idle > 1.2) { running = false; return; }
    raf = requestAnimationFrame(frame);
  }
  function wake() {
    if (running || document.hidden || !cx) return;
    running = true; tPrev = performance.now(); raf = requestAnimationFrame(frame);
  }

  /* ------------------------------------------------------------ cards */
  const CARD = '[data-cloth], [data-brush], .card, .section-nav-card, .stat-card, .snap-card, .project-card, ' +
               '.proj-card, .task-card, .bug-card, .req-card, .dash-card, .welcome-card, .kanban-card, .ledger-card';
  const FLEX = new Map();                 /* card → spring state */
  let flexRaf = 0;
  const hasCloth = el => el.classList.contains('cloth-host') || !!el.querySelector('.cloth-out');
  function flexable(el) {
    if (REDUCE || el.closest('[data-no-flex]')) return false;   /* a card that owns its transform opts out */
    if (hasCloth(el) && !TOUCH) return false;                   /* the fabric ripples itself */
    return getComputedStyle(el).position !== 'fixed';           /* never a centred dialog */
  }
  function flexState(el) {
    let f = FLEX.get(el);
    if (!f) { f = { rx: 0, ry: 0, vx: 0, vy: 0, s: 0, vs: 0, tx: 0, ty: 0, on: false }; FLEX.set(el, f); el.classList.add('astra-flex'); }
    return f;
  }
  function flexTick(now) {
    const dt = Math.min(.033, (now - (flexTick.t || now)) / 1000 || .016); flexTick.t = now;
    let busy = false;
    FLEX.forEach((f, el) => {
      /* two damped springs toward the tilt the hand asks for, one for the press */
      const k = 170, c = 15;
      f.vx += ((f.on ? f.tx : 0) - f.rx) * k * dt - f.vx * c * dt; f.rx += f.vx * dt;
      f.vy += ((f.on ? f.ty : 0) - f.ry) * k * dt - f.vy * c * dt; f.ry += f.vy * dt;
      f.vs += (0 - f.s) * 260 * dt - f.vs * 13 * dt; f.s += f.vs * dt;
      const settled = !f.on && Math.abs(f.rx) + Math.abs(f.ry) + Math.abs(f.vx) + Math.abs(f.vy) + Math.abs(f.s) + Math.abs(f.vs) < .004;
      if (settled) { el.style.transform = ''; el.classList.remove('astra-flex'); FLEX.delete(el); return; }
      busy = true;
      /* the portals' own hover lift is kept inside the flex */
      const lift = f.on && el.matches(':hover') ? -2 : 0;
      el.style.transform = `translateY(${lift}px) perspective(900px) rotateX(${f.rx.toFixed(3)}deg) rotateY(${f.ry.toFixed(3)}deg) scale(${(1 + f.s).toFixed(4)})`;
    });
    flexRaf = busy ? requestAnimationFrame(flexTick) : 0;
  }
  const flexGo = () => { if (!flexRaf) { flexTick.t = 0; flexRaf = requestAnimationFrame(flexTick); } };

  function wireCards() {
    let last = null;
    document.addEventListener('pointerover', e => {
      const el = e.target.closest && e.target.closest(CARD);
      if (!el || el === last) return;
      if (last && FLEX.has(last)) FLEX.get(last).on = false;
      last = el;
    }, { passive: true });
    document.addEventListener('pointerout', e => {
      if (!last) return;
      const to = e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest(CARD);
      if (to === last) return;
      if (FLEX.has(last)) { FLEX.get(last).on = false; flexGo(); }
      last = null;
    }, { passive: true });
    document.addEventListener('pointermove', e => {
      if (!last || e.pointerType === 'touch' || !flexable(last)) return;
      const el = last, r = el.getBoundingClientRect();
      const f = flexState(el);
      f.on = true;
      /* a big card tilts less: the corner of a wide panel travels further */
      const lim = clamp(420 / Math.max(r.width, r.height), .6, 4);
      f.tx = -((e.clientY - r.top) / r.height - .5) * 2 * lim;
      f.ty = ((e.clientX - r.left) / r.width - .5) * 2 * lim;
      /* the kick: a fast crossing sets the surface rippling */
      f.vx += clamp(-e.movementY || 0, -30, 30) * .9;
      f.vy += clamp(e.movementX || 0, -30, 30) * .9;
      flexGo();
    }, { passive: true });
    /* press: a touch or a click dips the card and it springs back */
    document.addEventListener('pointerdown', e => {
      const el = e.target.closest && e.target.closest(CARD);
      if (!el || REDUCE) return;
      if (!flexable(el) && !(TOUCH && hasCloth(el))) return;
      const f = flexState(el);
      f.vs -= .55;
      if (TOUCH) { const r = el.getBoundingClientRect(); f.vx += ((e.clientY - r.top) / r.height - .5) * -40; f.vy += ((e.clientX - r.left) / r.width - .5) * 40; }
      flexGo();
    }, { passive: true });
  }

  /* ------------------------------------------------------------ boot */
  function readTheme() {
    const r = document.documentElement;
    ink = r.getAttribute('data-theme') === 'light' || r.classList.contains('light') ? INK.day : INK.night;
  }
  function init() {
    wireCards();
    if (TOUCH) return;                          /* no trail on touch screens */
    cv = document.createElement('canvas');
    cv.id = 'astra-hand'; cv.setAttribute('aria-hidden', 'true');
    cv.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;z-index:2147483000;pointer-events:none;display:block;';
    document.body.appendChild(cv);
    cx = cv.getContext('2d');
    readTheme(); size();
    new MutationObserver(readTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
    addEventListener('resize', size, { passive: true });
    if (REDUCE) return;
    addEventListener('pointermove', e => {
      if (e.pointerType && e.pointerType !== 'mouse' && e.pointerType !== 'pen') return;
      H.x = e.clientX; H.y = e.clientY;
      if (!H.seen) { H.ex = H.lx = H.x; H.ey = H.ly = H.y; H.seen = true; }
      H.idle = 0;
      wake();
    }, { passive: true });
    document.addEventListener('visibilitychange', () => { if (document.hidden) { running = false; cancelAnimationFrame(raf); } });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
