/* ═══════════════════════════════════════════════════════════════════════
   Landing host: the landing page's side of ThreeUI's LandingPageFrame.

   landing-pages/hastra.html is Hastra's own document, forked from the ThreeUI
   landing engine: its WebGL world, camera rig, cursor wisps and cloth are
   the packaged code, its content is Hastra's. This script reaches into it
   after load through the same two seams ThreeUI's frame uses:

     1. the typography recipe: one stylesheet appended last to the frame's
        <head>, restating the page's selectors with the configured values
        (Onest 400 / 300, 46 / 17, -0.012em).

     2. the scene API the document publishes: window.__hastraScene, with
        { WORLD, WORD, POST, renderer, scene }. Daylight is written through
        it: the blood moon becomes the sun, the night sky a day sky, the fog
        a morning haze, the lanterns go cold and the key and rim lights come
        up. Every value it touches is recorded first, so night is restored
        to exactly what the document built.

   The document is authored light-on-dark, so daylight also appends a skin
   stylesheet that inverts its ink, and mirrors the theme onto the frame's
   root so the document's own light rules and baked textures follow.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ------------------------------------------ 1 · the typography recipe */
  const ONEST = "'Onest', system-ui, -apple-system, 'Helvetica Neue', sans-serif";
  const TYPE = { heading: ONEST, body: ONEST, headingWeight: '400', bodyWeight: '300', primary: '#e0231c',
    headingSize: 46, bodySize: 17, headingLetterSpacing: -0.012 };
  const px = v => Number(v.toFixed(3)) + 'px';
  const RECIPE_CSS = `
:root {
  --vermilion: ${TYPE.primary};
  --ember: #ff5a3c;
}
body { font-family: ${TYPE.body}; }
body, .body, .body-lg, .num { font-weight: ${TYPE.bodyWeight}; }
h1:not(.jp), h2:not(.jp), h3:not(.jp), .display:not(.jp) {
  font-family: ${TYPE.heading};
  font-weight: ${TYPE.headingWeight};
}
.display { letter-spacing: ${TYPE.headingLetterSpacing}em; }
.h-hero { font-size: clamp(26px, 3.05vw, ${px(TYPE.headingSize)}); }
.h-sec { font-size: clamp(30px, 4vw, ${px((TYPE.headingSize * 60) / 46)}); }
.body-lg { font-size: clamp(14px, 1.02vw, ${px(TYPE.bodySize)}); }
.body { font-size: ${px(Math.max(11, TYPE.bodySize - 3))}; }
`;

  /* -------------------------------------------- the daylight skin (DOM) */
  const DAY_CSS = `
html[data-hastra-day] {
  --ink:#d3dfe5; --ink-2:#c6d4db; --bone:#121c24; --bone-dim:#34444d; --muted:#5a6a72;
  --line:rgba(18,28,36,.16); --line-soft:rgba(18,28,36,.09);
}
html[data-hastra-day] body { background:#f6ecd2; color:#121c24; }
html[data-hastra-day] #gl { background:#f6ecd2; }
html[data-hastra-day] #pre { background:#fbf3de; }
html[data-hastra-day] .pre-bar { background:rgba(18,28,36,.14); }
html[data-hastra-day] #vignette { background:radial-gradient(125% 95% at 50% 42%, transparent 52%, rgba(120,84,30,.14) 100%); }
html[data-hastra-day] #grain { opacity:.03; }
html[data-hastra-day] .eyebrow, html[data-hastra-day] .chip b { text-shadow:0 1px 14px rgba(255,251,238,.85); }
html[data-hastra-day] .display { text-shadow:0 2px 30px rgba(255,251,238,.7); }
html[data-hastra-day] .body-lg { color:#26343c; text-shadow:0 1px 18px rgba(255,251,238,.9); }
html[data-hastra-day] .body { color:#33424a; text-shadow:0 1px 16px rgba(255,251,238,.9); }
html[data-hastra-day] .gate-copy .lead { color:#1e2c34; text-shadow:0 1px 18px rgba(255,251,238,.9); }
html[data-hastra-day] .hero-sub { text-shadow:0 1px 22px rgba(255,251,238,.95); }
html[data-hastra-day] .chip p, html[data-hastra-day] .les p, html[data-hastra-day] .les .t,
html[data-hastra-day] .foot li a, html[data-hastra-day] .foot-brand p { color:#3d4c54; text-shadow:none; }
html[data-hastra-day] .hero::before { background:linear-gradient(rgba(255,244,214,.70), rgba(255,244,214,.30) 46%, transparent); }
html[data-hastra-day] .sec::before { background:radial-gradient(110% 62% at 30% 50%, rgba(255,248,230,.86), rgba(255,248,230,.60) 42%, rgba(255,248,230,.16) 74%, rgba(255,248,230,0)); }
html[data-hastra-day] #security::before { background:radial-gradient(108% 64% at 50% 50%, rgba(255,248,230,.66), rgba(255,248,230,.40) 50%, rgba(255,248,230,0)); }
html[data-hastra-day] #ledger::before { background:radial-gradient(82% 58% at 50% 46%, rgba(255,248,230,.24), rgba(255,248,230,.62) 56%, rgba(255,248,230,.88) 82%, rgba(255,248,230,0)); }
html[data-hastra-day] .foot::before { background:linear-gradient(rgba(255,248,230,.55), rgba(255,248,230,.94) 40%, rgba(255,248,230,.98)); }
html[data-hastra-day] .nav::before { background:rgba(255,248,230,.66); }
html[data-hastra-day] .hero-side .v { color:rgba(18,28,36,.55); }
html[data-hastra-day] svg path[stroke="#dfe7e0"] { stroke:#15202a; }
html[data-hastra-day] .peek-play svg path[fill="#dfe7e0"] { fill:#f2f5f3; }
html[data-hastra-day] .peek-cap b { color:#121c24; }
html[data-hastra-day] .nav-actions .nav-signin:hover { background:rgba(18,28,36,.08); }
html[data-hastra-day] .cta:hover { color:#eef3f5; }
html[data-hastra-day] .cta:hover svg path { stroke:#eef3f5; }
html[data-hastra-day] .arrowlink:hover .ar svg path { stroke:#eef3f5; }
html[data-hastra-day] .rail i { background:rgba(18,28,36,.26); }
html[data-hastra-day] .cur-dot { border-color:rgba(18,28,36,.42); }
html[data-hastra-day] .cur-dot.act { background:rgba(18,28,36,.06); border-color:rgba(18,28,36,.6); }
html[data-hastra-day] body[data-layout-curriculum="b"] .les { background:rgba(255,251,238,.84); }
html[data-hastra-day] .fg-el > img { filter:saturate(1) brightness(1.06); }
@media (max-width:820px){ html[data-hastra-day] .nav-links { background:rgba(255,249,234,.98); } }
`;

  function injectStyle(doc, id, css) {
    let s = doc.getElementById(id);
    if (!s) { s = doc.createElement('style'); s.id = id; }
    if (s.textContent !== css) s.textContent = css;
    doc.head.appendChild(s);               /* last in <head>: wins at equal specificity */
  }

  /* ------------------------------------------ 2 · the scene, by daylight
     One attach() per frame: the landing page's own, and the background
     scene assets/js/kage-scene.js hangs behind every other page. Each keeps
     its own record of what daylight changed. */
  /* opts.skyOnly (the signed-in portals): daylight changes the sky, the sun
     and the background only; the temple keeps its night palette */
  function attach(frame, onLoad, opts) {
  opts = opts || {};
  const records = [];
  let dayBuilt = null, dayOn = false;

  function rec(obj, key) {
    if (!obj || records.some(r => r.obj === obj && r.key === key)) return;
    const v = obj[key];
    records.push({ obj, key, val: v && typeof v.clone === 'function' && !v.isTexture ? v.clone() : v });
  }
  function put(obj, key, v) { rec(obj, key); obj[key] = v; }
  function tint(obj, r, g, b) { if (!obj || !obj.color) return; rec(obj, 'color'); obj.color = obj.color.clone().setRGB(r, g, b); }

  function restore() {
    for (let i = records.length - 1; i >= 0; i--) {
      const r = records[i];
      r.obj[r.key] = r.val && typeof r.val.clone === 'function' && !r.val.isTexture ? r.val.clone() : r.val;
      if (r.obj.isMaterial) r.obj.needsUpdate = true;
    }
    records.length = 0;
  }

  function canvasTex(win, draw, w, h) {
    const T = win.THREE, c = win.document.createElement('canvas');
    c.width = w; c.height = h; draw(c.getContext('2d'), w, h);
    const t = new T.CanvasTexture(c); t.encoding = T.sRGBEncoding; t.needsUpdate = true;
    return t;
  }

  function buildDay(win) {
    const sky = canvasTex(win, (x, W, H) => {
      let g = x.createLinearGradient(0, 0, 0, H);
      /* a sun-drenched afternoon: soft blue zenith, golden middle air,
         a luminous amber wash along the horizon */
      g.addColorStop(0, '#7eb4e8');
      g.addColorStop(0.45, '#fde68a');
      g.addColorStop(0.85, '#fef08a');
      g.addColorStop(1, '#fffbeb');
      x.fillStyle = g; x.fillRect(0, 0, W, H);
      /* long soft cloud banks, and the warm lift where the sun sits */
      for (let i = 0; i < 26; i++) {
        const cx = Math.random() * W, cy = H * (.12 + Math.random() * .5), r = 40 + Math.random() * 140;
        g = x.createRadialGradient(cx, cy, 0, cx, cy, r);
        g.addColorStop(0, 'rgba(255,255,255,.30)'); g.addColorStop(1, 'rgba(255,255,255,0)');
        x.save(); x.translate(cx, cy); x.scale(2.6, .55); x.translate(-cx, -cy);
        x.fillStyle = g; x.beginPath(); x.arc(cx, cy, r, 0, Math.PI * 2); x.fill(); x.restore();
      }
      /* the sun flare: radial-gradient(circle, rgba(255,245,180,.95) 0%,
         rgba(254,215,102,.45) 45%, transparent 75%) */
      const fr = W * .5;
      g = x.createRadialGradient(W * .75, H * .22, 0, W * .75, H * .22, fr);
      g.addColorStop(0, 'rgba(255,245,180,.95)'); g.addColorStop(.45, 'rgba(254,215,102,.45)'); g.addColorStop(.75, 'rgba(254,215,102,0)');
      x.fillStyle = g; x.fillRect(0, 0, W, H);
    }, 512, 512);
    const sun = canvasTex(win, (x, S) => {
      const g = x.createRadialGradient(S / 2, S / 2, 0, S / 2, S / 2, S / 2);
      g.addColorStop(0, 'rgba(255,255,252,1)'); g.addColorStop(.55, 'rgba(255,252,238,1)');
      g.addColorStop(.86, 'rgba(255,238,196,.95)'); g.addColorStop(1, 'rgba(255,226,170,0)');
      x.fillStyle = g; x.fillRect(0, 0, S, S);
    }, 256, 256);
    const corona = canvasTex(win, (x, S) => {
      const g = x.createRadialGradient(S / 2, S / 2, 0, S / 2, S / 2, S / 2);
      g.addColorStop(0, 'rgba(255,248,226,.95)'); g.addColorStop(.18, 'rgba(255,236,190,.42)');
      g.addColorStop(.5, 'rgba(255,226,170,.10)'); g.addColorStop(1, 'rgba(255,226,170,0)');
      x.fillStyle = g; x.fillRect(0, 0, S, S);
    }, 256, 256);
    return { sky, sun, corona, ridge: new Map() };
  }

  /* the black ridge silhouettes become far hills in haze: same alpha, new ink */
  function hazeRidge(win, mesh, ink) {
    const src = mesh.material.map && mesh.material.map.image;
    if (!src) return null;
    if (dayBuilt.ridge.has(mesh)) return dayBuilt.ridge.get(mesh);
    const t = canvasTex(win, (x, W, H) => {
      x.drawImage(src, 0, 0, W, H);
      x.globalCompositeOperation = 'source-in'; x.fillStyle = ink; x.fillRect(0, 0, W, H);
    }, src.width, src.height);
    t.wrapS = mesh.material.map.wrapS; t.repeat.copy(mesh.material.map.repeat);
    dayBuilt.ridge.set(mesh, t);
    return t;
  }

  function day(win) {
    const K = win.__hastraScene;
    if (!K || !K.WORLD || !win.THREE) return false;
    const T = win.THREE, W = K.WORLD, scene = K.scene;
    if (!dayBuilt) dayBuilt = buildDay(win);
    restore();

    if (opts.skyOnly) {
      rec(scene, 'background'); scene.background = new T.Color(0x9cc6ef);
      put(W.sky.material, 'map', dayBuilt.sky); tint(W.sky.material, 1.05, 1.05, 1.05); W.sky.material.needsUpdate = true;
      put(W.moon.material, 'map', dayBuilt.sun); tint(W.moon.material, 5.2, 4.9, 4.3); W.moon.material.needsUpdate = true;
      put(W.moonHalo.material, 'map', dayBuilt.corona); tint(W.moonHalo.material, 1.9, 1.6, 1.15); W.moonHalo.material.needsUpdate = true;
      return true;
    }

    /* air */
    rec(scene.fog, 'color'); scene.fog.color = new T.Color(0xf3e3b0);
    put(scene.fog, 'density', 0.0082);
    rec(scene, 'background'); scene.background = new T.Color(0xf3e3b0);
    /* sky and the body in it */
    put(W.sky.material, 'map', dayBuilt.sky); tint(W.sky.material, 1.12, 1.12, 1.12); W.sky.material.needsUpdate = true;
    put(W.moon.material, 'map', dayBuilt.sun); tint(W.moon.material, 5.2, 4.9, 4.3); W.moon.material.needsUpdate = true;
    put(W.moonHalo.material, 'map', dayBuilt.corona); tint(W.moonHalo.material, 1.9, 1.6, 1.15); W.moonHalo.material.needsUpdate = true;
    /* the ridges and every other flat silhouette plane */
    scene.traverse(o => {
      if (!o.isMesh || !o.material || !o.material.isMeshBasicMaterial || o === W.sky || o === W.moon) return;
      const g = o.geometry && o.geometry.parameters;
      if (g && (g.width === 300 || g.width === 210) && o.renderOrder === 1) {
        const t = hazeRidge(win, o, g.width === 300 ? '#c9b98a' : '#8f8a64');
        if (t) { put(o.material, 'map', t); tint(o.material, 1, 1, 1); o.material.needsUpdate = true; }
      }
    });
    /* lights: sun where the moon hung, the sky fill up, every lamp cold */
    scene.traverse(o => {
      if (!o.isLight) return;
      if (o.isHemisphereLight) { tint(o, 1, .95, .82); rec(o, 'groundColor'); o.groundColor = new T.Color(0x6b5a3a); put(o, 'intensity', 1.15); }
      /* the key: warm sunlight, 0xfff2cc at 1.6, across the roofs and stairs */
      else if (o === W.key) { rec(o, 'color'); o.color = new T.Color(0xfff2cc); put(o, 'intensity', 1.6); }
      else if (o.isDirectionalLight) { tint(o, 1, .9, .7); put(o, 'intensity', 1.2); }
      else if (o.isPointLight) {
        /* point intensities are rewritten every frame, so the lamps are
           dimmed through their colour instead */
        rec(o, 'color'); o.color = o.color.clone().multiplyScalar(.14);
      }
    });
    /* the lit paper and the lantern panes stop glowing in daylight */
    if (W.paper) tint(W.paper, .86, .8, .7);
    if (W.lanternPane) tint(W.lanternPane, .32, .22, .14);
    if (W.hallHalo) tint(W.hallHalo.material, .12, .12, .12);
    (W.lanternGlows || []).forEach(g => tint(g.material, .15, .15, .15));
    /* the near garden: the cut-outs are painted for night, lift them */
    (W.fg || []).forEach(m => tint(m.material, 2.3, 2.3, 2.15));
    if (W.leaves) put(W.leaves.mesh.material, 'emissiveIntensity', .22);
    if (W.rain) put(W.rain, 'visible', false);
    /* the wordmark swaps to its baked titanium-navy ink inside the document */
    /* embers become drifting pollen */
    if (W.embers) {
      const m = W.embers.material;
      put(m, 'fragmentShader', m.fragmentShader.replace('vec3(1.6,0.78,0.42)', 'vec3(1.0,0.97,0.86)').replace('t.a*vA*0.75', 't.a*vA*0.42'));
      m.needsUpdate = true;
    }
    /* grade */
    const C = K.POST && K.POST.comp && K.POST.comp.uniforms;
    if (C) {
      rec(C.uExp, 'value'); C.uExp.value = .9;
      rec(C.uBloom, 'value'); C.uBloom.value = .16;
      rec(C.uVig, 'value'); C.uVig.value = .42;
      rec(C.uGrain, 'value'); C.uGrain.value = .012;
    }
    if (W.key && W.key.shadow) W.key.shadow.needsUpdate = true;
    return true;
  }

  function night() { restore(); }

  /* ---------------------------------------------------------- wiring */
  const wantDay = () => document.documentElement.getAttribute('data-theme') === 'light';
  let poll = 0;

  function sync() {
    let win, doc;
    try { win = frame.contentWindow; doc = frame.contentDocument; } catch (e) { return; }
    if (!doc || !doc.head) return;
    const d = wantDay();
    injectStyle(doc, 'threeui-page-typography', RECIPE_CSS);
    injectStyle(doc, 'hastra-landing-daylight', DAY_CSS);
    /* mirrored three ways: the skin keys on data-hastra-day, the document's
       own light rules on data-theme="light" and .light */
    doc.documentElement.toggleAttribute('data-hastra-day', d);
    doc.documentElement.setAttribute('data-theme', d ? 'light' : 'dark');
    doc.documentElement.classList.toggle('light', d);
    clearInterval(poll);
    /* the scene publishes itself only once its eleven build jobs are done */
    const go = () => {
      const K = win.__hastraScene;
      if (!K) return false;
      if (K.fallback) return true;              /* no WebGL: the CSS skin is all there is */
      if (d && !dayOn) { dayOn = day(win); }
      else if (!d && dayOn) { night(); dayOn = false; }
      if (K.kick) K.kick();
      return true;
    };
    if (!go()) poll = setInterval(() => { if (go()) clearInterval(poll); }, 150);
  }

  frame.addEventListener('load', () => {
    records.length = 0; dayBuilt = null; dayOn = false;   /* a fresh document */
    sync();
    if (onLoad) onLoad();
  });
  new MutationObserver(sync).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  return { sync, get day() { return dayOn; } };
  }

  window.HastraSceneHost = { attach };
  const landing = document.getElementById('landingFrame');
  if (landing) window.__landingHost = attach(landing, () => { landing.parentElement.dataset.state = 'ready'; });
})();
