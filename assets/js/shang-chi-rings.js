/* Hastra — the Ten Rings engine.

   Engraved gunmetal rings rendered procedurally in 3-D on Canvas 2D (no
   WebGL, no dependency), in two formations:

     'circle'  ten rings linked in a circle, each woven over one neighbour and
               under the other like real chain links, the whole formation
               turning slowly and tilting in space (the showcase centrepiece)
     'arm'     rings worn as bracelets stacked up a forearm, each precessing
               about the arm; segments behind the arm go to the BACK canvas and
               segments in front to the FRONT one, so with the subject (a hand
               video, an emblem) sandwiched between them the rings pass
               genuinely behind it and around (the login hand)

   At rest the rings are dark engraved metal, lit from the top left. Power
   shows only when they are activated: an energy glow per ring group, a
   travelling charge, spark bursts from the centre, and arcs of lightning
   jumping between neighbouring rings.

     const rings = createPowerRings({ back, front, mode: 'circle', anchor: () => ({ x, y, radius }) });
     rings.highlight(1);   // energise one group (0 defense · 1 threat · 2 knowledge); null clears
     rings.boost();        // 2.5× rotation speed, easing back
     rings.burst();        // spark burst radiating from the centre
     rings.power(true);    // fade the whole formation in / out
     rings.destroy();                                                          */

const TAU = Math.PI * 2;

// Energy colours — no violet (the project's no-purple rule).
export const RING_GROUPS = [
  { key: 'defense',   label: 'Defense matrix',      dark: [0, 242, 254],  light: [3, 105, 161] },
  { key: 'threat',    label: 'Threat intelligence', dark: [255, 59, 59],  light: [185, 28, 28] },
  { key: 'knowledge', label: 'Knowledge flow',      dark: [255, 190, 11], light: [180, 83, 9] },
];
// Gunmetal ramp, shadow → specular.
const METAL = [[16, 20, 26], [30, 37, 46], [48, 58, 70], [70, 82, 96], [96, 110, 126], [124, 138, 154], [154, 167, 182], [186, 197, 210], [214, 222, 232], [240, 244, 249]];
const BUCKETS = METAL.length;
const L = norm([-0.55, -0.7, -0.45]);            // light from the top left, slightly in front

function norm(v) { const m = Math.hypot(...v) || 1; return v.map(x => x / m); }
const rgba = (c, a) => `rgba(${c[0]},${c[1]},${c[2]},${Math.max(0, Math.min(1, a))})`;
const mix = (a, b, t) => a.map((v, i) => Math.round(v + (b[i] - v) * t));
// stable pseudo-random per (ring, index)
const hash = (a, b) => { const s = Math.sin(a * 127.1 + b * 311.7) * 43758.5453; return s - Math.floor(s); };

export function createPowerRings(opts) {
  const { back, front } = opts;
  const mode = opts.mode || 'circle';
  const count = opts.count || (mode === 'circle' ? 10 : 5);
  const bctx = back.getContext('2d'), fctx = front.getContext('2d');
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const isLight = opts.isLight || (() => document.documentElement.getAttribute('data-theme') === 'light' || document.documentElement.classList.contains('light'));
  const groupOf = i => { const t = i / count; return t < 0.3 ? 0 : t < 0.6 ? 1 : 2; };

  const rings = Array.from({ length: count }, (_, i) => ({
    i, group: groupOf(i),
    spin: hash(i, 1) * TAU,                          // engraving phase along the band
    spinSpeed: (i % 2 ? -1 : 1) * (0.35 + hash(i, 2) * 0.3),
    // arm mode: height up the forearm, tilt and precession about the arm
    y: count === 1 ? 0 : i / (count - 1),
    tilt: 0.22 + hash(i, 3) * 0.14,
    prec: hash(i, 4) * TAU,
    precSpeed: (i % 2 ? 1 : -1) * (0.25 + hash(i, 5) * 0.2),
    glow: 0,
  }));

  let dpr = 1, raf = 0, last = 0, running = false, visible = true;
  let speed = 1, speedTarget = 1, boostUntil = 0, active = null;
  let fade = opts.startHidden ? 0 : 1, fadeTarget = 1, charge = 0;
  let formSpin = 0, t = 0;
  const sparks = [], bolts = [];

  function resize() {
    dpr = Math.min(window.HASTRA_PERF && window.HASTRA_PERF.low ? 1 : 2, window.devicePixelRatio || 1);
    for (const c of [back, front]) {
      const r = c.getBoundingClientRect();
      c.width = Math.max(1, Math.round(r.width * dpr)); c.height = Math.max(1, Math.round(r.height * dpr));
    }
    if (reduce || !raf) frame(performance.now(), true);
  }

  // ── geometry ───────────────────────────────────────────────────────────
  // Returns a projector for ring k: θ → { x, y, z, s, n } (n = radial normal in view space).
  function ringFrame(k, A) {
    if (mode === 'circle') {
      const F = A.radius, r = F * 0.47;
      const phi = k.i / count * TAU + formSpin;
      const cx = Math.cos(phi) * F, cy = Math.sin(phi) * F;
      // formation tilt: a slow wobble so the circle is never quite flat to the viewer
      const ax = 0.32 * Math.sin(t * 0.21) + 0.12, ay = 0.28 * Math.sin(t * 0.17 + 1);
      const cax = Math.cos(ax), sax = Math.sin(ax), cay = Math.cos(ay), say = Math.sin(ay);
      const rot = (x, y, z) => { [y, z] = [y * cax - z * sax, y * sax + z * cax]; [x, z] = [x * cay + z * say, -x * say + z * cay]; return [x, y, z]; };
      const f = F * 6;
      return { r, band: r * 0.17, at(th, dr = 0) {
        const R = r + dr;
        const [x, y, z] = rot(cx + Math.cos(th) * R, cy + Math.sin(th) * R, 0);
        const s = f / (f + z);
        return { x: A.x + x * s, y: A.y + y * s, z, s, n: rot(Math.cos(th), Math.sin(th), 0), face: rot(0, 0, -1) };
      } };
    }
    // arm: rings stacked below the palm, precessing about the vertical forearm
    const r = A.radius * (0.9 + k.y * 0.22);
    const baseY = A.y + A.span * (0.18 + k.y * 0.82);
    const ct = Math.cos(k.tilt), st = Math.sin(k.tilt), cp = Math.cos(k.prec), sp = Math.sin(k.prec);
    const rot = (x, y, z) => { [y, z] = [y * ct - z * st, y * st + z * ct]; [x, z] = [x * cp + z * sp, -x * sp + z * cp]; return [x, y, z]; };
    const f = A.radius * 6;
    return { r, band: r * 0.13, at(th, dr = 0) {
      const R = r + dr;
      const [x, y, z] = rot(Math.cos(th) * R, 0, Math.sin(th) * R);
      const s = f / (f + z);
      return { x: A.x + x * s, y: baseY + y * s, z, s, n: rot(Math.cos(th), 0, Math.sin(th)), face: rot(0, -1, 0) };
    } };
  }

  // Arm mode: rings lower down the forearm sink into the mist the arm rises from.
  const ringAlpha = k => mode === 'arm' ? Math.max(0.12, 1 - k.y * (opts.sink ?? 0.8)) : 1;

  // ── one ring, optionally just an arc of it (for the chain-link weave) ──
  function drawRing(k, A, light, from = 0, to = TAU, forceFront = false) {
    const G = ringFrame(k, A);
    const N = Math.max(12, Math.round(150 * (to - from) / TAU));
    const band = G.band;
    // batch segments per canvas and per shade bucket
    const paths = { b: Array.from({ length: BUCKETS }, () => new Path2D()), f: Array.from({ length: BUCKETS }, () => new Path2D()) };
    const rim = { b: new Path2D(), f: new Path2D() }, groove = { b: new Path2D(), f: new Path2D() };
    const engrave = { b: new Path2D(), f: new Path2D() }, glint = { b: new Path2D(), f: new Path2D() };
    const glowP = { b: new Path2D(), f: new Path2D() };
    let prev = G.at(from), prevO = G.at(from, band * 0.36), prevI = G.at(from, -band * 0.36);
    let widthSum = 0;
    for (let n = 1; n <= N; n++) {
      const th = from + (to - from) * n / N;
      const p = G.at(th), pO = G.at(th, band * 0.36), pI = G.at(th, -band * 0.36);
      const side = forceFront || (prev.z + p.z) / 2 <= 0 ? 'f' : 'b';
      // metal shade: diffuse from the band's bevel normal + a specular glint
      const nx = p.n[0] * 0.7 + p.face[0] * 0.7, ny = p.n[1] * 0.7 + p.face[1] * 0.7, nz = p.n[2] * 0.7 + p.face[2] * 0.7;
      const d = Math.max(0, -(nx * L[0] + ny * L[1] + nz * L[2]));
      const spec = Math.pow(d, 12);
      const shade = Math.min(BUCKETS - 1, Math.round((0.1 + d * 0.58 + spec * 0.5) * (BUCKETS - 1)));
      paths[side][shade].moveTo(prev.x, prev.y); paths[side][shade].lineTo(p.x, p.y);
      rim[side].moveTo(prevO.x, prevO.y); rim[side].lineTo(pO.x, pO.y);
      groove[side].moveTo(prevI.x, prevI.y); groove[side].lineTo(pI.x, pI.y);
      glowP[side].moveTo(prev.x, prev.y); glowP[side].lineTo(p.x, p.y);
      widthSum += p.s;
      prev = p; prevO = pO; prevI = pI;
    }
    const scale = widthSum / N;
    // engraving: an irregular filigree of short cuts along the band, turning with the ring
    const marks = 132;
    for (let m = 0; m < marks; m++) {
      const th = k.spin + m / marks * TAU;
      const rel = ((th - from) % TAU + TAU) % TAU;
      if (rel > to - from) continue;
      const h = hash(k.i, m);
      const off = (h - 0.5) * band * 0.42;
      const a = G.at(th, off), b = G.at(th + (0.012 + h * 0.03), off + (hash(m, k.i) - 0.5) * band * 0.3);
      const side = forceFront || (a.z + b.z) / 2 <= 0 ? 'f' : 'b';
      engrave[side].moveTo(a.x, a.y); engrave[side].lineTo(b.x, b.y);
      if (h > 0.72) { glint[side].moveTo(a.x, a.y); glint[side].arc(a.x, a.y, 0.7 * a.s, 0, TAU); }
    }
    for (const side of ['b', 'f']) {
      const ctx = side === 'b' ? bctx : fctx;
      ctx.globalAlpha = fade * ringAlpha(k);
      ctx.lineCap = 'butt';
      // dark outline so linked rings separate cleanly where they cross
      ctx.strokeStyle = light ? 'rgba(10,14,20,.55)' : 'rgba(0,0,0,.75)';
      ctx.lineWidth = band * scale + 2.2;
      ctx.stroke(glowP[side]);
      ctx.lineCap = 'round';
      for (let s = 0; s < BUCKETS; s++) { ctx.strokeStyle = rgba(METAL[s], 1); ctx.lineWidth = band * scale; ctx.stroke(paths[side][s]); }
      ctx.lineWidth = Math.max(0.8, band * scale * 0.12);
      ctx.strokeStyle = 'rgba(232,238,246,.32)'; ctx.stroke(rim[side]);
      ctx.strokeStyle = 'rgba(0,0,0,.45)'; ctx.stroke(groove[side]);
      ctx.lineWidth = Math.max(0.7, band * scale * 0.09);
      ctx.strokeStyle = 'rgba(6,8,12,.62)'; ctx.stroke(engrave[side]);
      ctx.fillStyle = 'rgba(236,242,250,.5)'; ctx.fill(glint[side]);
      // energy: only when this ring's group is powered
      const e = Math.max(k.glow, charge * 0.55);
      if (e > 0.02) {
        const col = RING_GROUPS[k.group][light ? 'light' : 'dark'];
        ctx.globalCompositeOperation = light ? 'source-over' : 'lighter';
        // the halo only on the full-ring pass: the weave's short re-drawn arcs
        // would otherwise stack extra glow at every crossing
        if (to - from > TAU - 1e-6) { ctx.lineCap = 'butt'; ctx.strokeStyle = rgba(col, 0.22 * e); ctx.lineWidth = band * scale * 3.2; ctx.stroke(glowP[side]); ctx.lineCap = 'round'; }
        ctx.strokeStyle = rgba(light ? col : mix(col, [255, 255, 255], 0.35), 0.55 * e); ctx.lineWidth = Math.max(1, band * scale * 0.22); ctx.stroke(rim[side]);
        ctx.globalCompositeOperation = 'source-over';
      }
      ctx.globalAlpha = 1;
    }
    return G;
  }

  // Travelling charge: a bright comet running round an energised ring.
  function drawCharge(k, A, light) {
    const e = Math.max(k.glow, charge * 0.55);
    if (e < 0.05) return;
    const G = ringFrame(k, A), col = RING_GROUPS[k.group][light ? 'light' : 'dark'];
    const head = k.spin * 2.2, len = 1.1, steps = 20;
    for (let s = 0; s < steps; s++) {
      const a = G.at(head - len * s / steps), b = G.at(head - len * (s + 1) / steps);
      const ctx = (a.z + b.z) / 2 > 0 && mode === 'arm' ? bctx : fctx;
      const f = (1 - s / steps) * e * fade * ringAlpha(k);
      ctx.globalCompositeOperation = light ? 'source-over' : 'lighter';
      ctx.strokeStyle = rgba(light ? col : mix(col, [255, 255, 255], 0.6), f);
      ctx.lineWidth = G.band * a.s * (0.25 + f * 0.35);
      ctx.lineCap = 'round';
      ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
      ctx.globalCompositeOperation = 'source-over';
    }
  }

  // Where ring i crosses ring i+1 (circle formation), as angles on each ring.
  function crossings(i, A) {
    const F = A.radius, r = F * 0.47;
    const phiA = i / count * TAU + formSpin, phiB = ((i + 1) % count) / count * TAU + formSpin;
    const ca = [Math.cos(phiA) * F, Math.sin(phiA) * F], cb = [Math.cos(phiB) * F, Math.sin(phiB) * F];
    const d = Math.hypot(cb[0] - ca[0], cb[1] - ca[1]);
    if (d >= 2 * r) return null;
    const mx = (ca[0] + cb[0]) / 2, my = (ca[1] + cb[1]) / 2, h = Math.sqrt(r * r - d * d / 4);
    const ux = -(cb[1] - ca[1]) / d, uy = (cb[0] - ca[0]) / d;
    const outward = ux * mx + uy * my > 0 ? 1 : -1;
    const P = [mx + ux * h * outward, my + uy * h * outward];     // outer crossing
    const Q = [mx - ux * h * outward, my - uy * h * outward];     // inner crossing
    const ang = (c, p) => Math.atan2(p[1] - c[1], p[0] - c[0]);
    return { aOver: ang(ca, P), bOver: ang(cb, Q) };
  }

  function drawCore(A, light) {
    if (mode !== 'circle' || !opts.core) return;
    const R = A.radius * 0.22 * (1 + charge * 0.25);
    const pulse = 0.5 + 0.5 * Math.sin(t * 2.2);
    const e = Math.max(charge, active !== null ? 0.6 : 0.18 + pulse * 0.12);
    const col = active !== null ? RING_GROUPS[active][light ? 'light' : 'dark'] : (light ? [37, 99, 235] : [0, 242, 254]);
    const g = fctx.createRadialGradient(A.x, A.y, 0, A.x, A.y, R * 2.2);
    g.addColorStop(0, rgba(light ? col : [255, 255, 255], 0.9 * e * fade));
    g.addColorStop(0.3, rgba(col, 0.45 * e * fade));
    g.addColorStop(1, rgba(col, 0));
    fctx.globalCompositeOperation = light ? 'source-over' : 'lighter';
    fctx.fillStyle = g; fctx.beginPath(); fctx.arc(A.x, A.y, R * 2.2, 0, TAU); fctx.fill();
    // four-point star
    const s = R * (0.75 + e * 0.35);
    fctx.fillStyle = rgba(light ? [15, 23, 42] : [255, 255, 255], (0.55 + e * 0.45) * fade);
    fctx.beginPath();
    fctx.moveTo(A.x, A.y - s); fctx.quadraticCurveTo(A.x, A.y, A.x + s, A.y); fctx.quadraticCurveTo(A.x, A.y, A.x, A.y + s);
    fctx.quadraticCurveTo(A.x, A.y, A.x - s, A.y); fctx.quadraticCurveTo(A.x, A.y, A.x, A.y - s); fctx.fill();
    fctx.globalCompositeOperation = 'source-over';
  }

  function spawnBurst(A) {
    const n = 54;
    const cy = mode === 'arm' ? A.y : A.y;
    for (let s = 0; s < n; s++) {
      const a = Math.random() * TAU, v = 120 + Math.random() * 300;
      sparks.push({ x: A.x, y: cy, vx: Math.cos(a) * v, vy: Math.sin(a) * v * (mode === 'arm' ? 0.7 : 1),
                    life: 0.5 + Math.random() * 0.55, age: 0, g: Math.floor(Math.random() * 3), behind: mode === 'arm' && Math.random() < 0.35 });
    }
  }
  function spawnBolt() {
    const i = Math.floor(Math.random() * (mode === 'circle' ? count : count - 1));
    bolts.push({ a: rings[i], b: rings[(i + 1) % count], th: Math.random() * TAU, age: 0, life: 0.14 + Math.random() * 0.12, seed: Math.random() * 1000 });
  }
  function drawEffects(dt, A, light) {
    const comp = light ? 'source-over' : 'lighter';
    for (let s = sparks.length - 1; s >= 0; s--) {
      const p = sparks[s];
      p.age += dt;
      if (p.age > p.life) { sparks.splice(s, 1); continue; }
      p.x += p.vx * dt; p.y += p.vy * dt; p.vy += 90 * dt; p.vx *= 0.985;
      const f = 1 - p.age / p.life, col = RING_GROUPS[p.g][light ? 'light' : 'dark'];
      const ctx = p.behind ? bctx : fctx;
      ctx.globalCompositeOperation = comp;
      ctx.strokeStyle = rgba(light ? col : mix(col, [255, 255, 255], 0.5), f);
      ctx.lineWidth = 1.2 + f;
      ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(p.x - p.vx * 0.035, p.y - p.vy * 0.035); ctx.stroke();
    }
    for (let b = bolts.length - 1; b >= 0; b--) {
      const Bo = bolts[b];
      Bo.age += dt;
      if (Bo.age > Bo.life) { bolts.splice(b, 1); continue; }
      const p0 = ringFrame(Bo.a, A).at(Bo.th), p1 = ringFrame(Bo.b, A).at(Bo.th + 0.4);
      const ctx = mode === 'arm' && (p0.z + p1.z) / 2 > 0 ? bctx : fctx;
      const f = 1 - Bo.age / Bo.life, col = RING_GROUPS[Bo.a.group][light ? 'light' : 'dark'];
      ctx.globalCompositeOperation = comp;
      ctx.strokeStyle = rgba(light ? col : mix(col, [255, 255, 255], 0.6), f);
      ctx.lineWidth = 1.4;
      ctx.beginPath(); ctx.moveTo(p0.x, p0.y);
      for (let s = 1; s < 8; s++) {
        const q = s / 8, j = hash(Bo.seed, s) - 0.5, jj = hash(s, Bo.seed) - 0.5;
        ctx.lineTo(p0.x + (p1.x - p0.x) * q + j * 16, p0.y + (p1.y - p0.y) * q + jj * 16);
      }
      ctx.lineTo(p1.x, p1.y); ctx.stroke();
    }
    bctx.globalCompositeOperation = fctx.globalCompositeOperation = 'source-over';
  }

  function frame(now, still = false) {
    const dt = still ? 0 : Math.min(0.05, last ? (now - last) / 1000 : 0.016);
    last = now;
    for (const c of [bctx, fctx]) { c.setTransform(1, 0, 0, 1, 0, 0); c.clearRect(0, 0, c.canvas.width, c.canvas.height); c.setTransform(dpr, 0, 0, dpr, 0, 0); }
    const A = opts.anchor();
    if (!A || A.radius <= 2) return;
    const light = isLight();

    if (dt) {
      if (now > boostUntil) speedTarget = 1;
      speed += (speedTarget - speed) * Math.min(1, dt * 3);
      fade += (fadeTarget - fade) * Math.min(1, dt * 1.5);
      charge = Math.max(0, charge - dt * 0.9);
      t += dt * speed;
      formSpin += dt * 0.09 * speed;
      for (const k of rings) {
        k.spin += k.spinSpeed * dt * speed;
        k.prec += k.precSpeed * dt * speed;
        const want = active !== null && k.group === active ? 1 : 0;
        k.glow += (want - k.glow) * Math.min(1, dt * 5);
      }
      const energy = Math.max(charge, ...rings.map(k => k.glow));
      if (!reduce && Math.random() < dt * energy * 3.5) spawnBolt();
    }

    if (mode === 'circle') {
      // back-to-front by depth of each ring's centre, then weave the crossings
      const order = rings.slice().sort((a, b) => ringFrame(b, A).at(0, -ringFrame(b, A).r).z - ringFrame(a, A).at(0, -ringFrame(a, A).r).z);
      for (const k of order) drawRing(k, A, light, 0, TAU, true);
      for (let i = 0; i < count; i++) {
        const c = crossings(i, A);
        if (!c) continue;
        drawRing(rings[i], A, light, c.aOver - 0.42, c.aOver + 0.42, true);
        drawRing(rings[(i + 1) % count], A, light, c.bOver - 0.42, c.bOver + 0.42, true);
      }
      drawCore(A, light);
    } else {
      for (const k of rings) drawRing(k, A, light);
    }
    for (const k of rings) drawCharge(k, A, light);
    if (dt) drawEffects(dt, A, light);
  }

  function loop(now) {
    raf = 0;
    if (!running || !visible || document.hidden) return;
    frame(now);
    raf = requestAnimationFrame(loop);
  }
  function kick() { if (!raf && running && visible && !document.hidden && !reduce) { last = 0; raf = requestAnimationFrame(loop); } }

  const ro = new ResizeObserver(resize);
  ro.observe(front);
  const io = new IntersectionObserver(es => { visible = es.some(e => e.isIntersecting); kick(); });
  io.observe(front);
  const onVis = () => kick();
  document.addEventListener('visibilitychange', onVis);

  running = true;
  resize();
  kick();

  return {
    highlight(g) { active = g === null || g === undefined ? null : g; if (reduce) { rings.forEach(k => { k.glow = k.group === active ? 1 : 0; }); frame(performance.now(), true); } },
    boost(ms = 1400) { if (reduce) return; speedTarget = 2.5; boostUntil = performance.now() + ms; charge = Math.min(1, charge + 0.6); },
    burst() { if (reduce) return; const A = opts.anchor(); if (A) { spawnBurst(A); charge = 1; } },
    power(on) { fadeTarget = on ? 1 : 0; if (reduce) { fade = fadeTarget; frame(performance.now(), true); } },
    redraw() { frame(performance.now(), true); },
    destroy() { running = false; cancelAnimationFrame(raf); ro.disconnect(); io.disconnect(); document.removeEventListener('visibilitychange', onVis); },
  };
}
