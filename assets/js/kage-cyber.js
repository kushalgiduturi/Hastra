/* ═══════════════════════════════════════════════════════════════════════
   Kage × cyber: the signed-in portals' version of the temple world.

   assets/js/kage-scene.js hangs the landing page's three.js world behind the
   page (#hastra-kage-scene). On portal pages this module reaches into that
   same-origin frame once it reports ready, and adds a cybernetic layer to
   the real 3-D scene, so it is lit, fogged, depth-tested and bloomed with
   the temple rather than painted over it:

     grid     a floor grid on the court with a scanline sweeping toward the
              stair, and a radar (rings + rotating sweep) around its centre
     packets  square data nodes rising past the gate and the stair cheeks
     wisps    soft particles drifting sideways between the columns
     lanterns every stone lantern pulses neon: crimson by night, blue by day

   All motion runs on the GPU from one time uniform; the only per-frame CPU
   work is re-asserting the lantern colour (the daylight theme records and
   restores lantern colours, so a one-off recolour would not survive a theme
   toggle). The landing page's own frame never gets any of this.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__hastraCyber) return;
  window.__hastraCyber = true;

  const NIGHT = [229 / 255, 9 / 255, 20 / 255];      /* #E50914 */
  const DAY   = [37 / 255, 99 / 255, 235 / 255];     /* #2563eb */
  const isDay = () => document.documentElement.getAttribute('data-theme') === 'light';

  function install() {
    const frame = document.getElementById('hastra-kage-scene');
    let win;
    try { win = frame && frame.contentWindow; } catch (e) { return false; }
    const K = win && win.__hastraScene, T = win && win.THREE;
    if (!K || !T || !K.scene || K.scene.getObjectByName('hastra-cyber')) return !!(K && K.fallback);

    const U = { uT: { value: 0 }, uCol: { value: new T.Vector3().fromArray(NIGHT) }, uDay: { value: 0 } };
    const group = new T.Group();
    group.name = 'hastra-cyber';

    /* ── ground: grid, scanline, radar ────────────────────────────────────
       The same holographic floor shader laid on the three grounds the portal
       cameras actually see: the court (hidden behind the hills from some
       views), a ramp floating just above the stair flight, and the podium in
       front of the hall. Each has its own radar centre and reach. */
    const holoVS = `
      varying vec2 vP;
      void main(){ vec4 w = modelMatrix * vec4(position, 1.0); vP = w.xz;
        gl_Position = projectionMatrix * viewMatrix * w; }`;
    const holoFS = `
      uniform float uT, uDay, uR; uniform vec2 uC; uniform vec3 uCol; varying vec2 vP;
      float lines(vec2 p, float s){ vec2 g = abs(fract(p / s - .5) - .5) * s; vec2 w = fwidth(p) * 1.2;
        vec2 l = 1.0 - smoothstep(vec2(0.0), w, g); return max(l.x, l.y); }
      void main(){
        float d = length(vP - uC);
        float fade = 1.0 - smoothstep(uR * .6, uR * 1.8, d);
        float g = lines(vP, 1.6) * .32 + lines(vP, 8.0) * .25;
        /* a scanline walking the ground toward the hall */
        float sz = mod(uT * 3.2, 60.0) - 45.0;
        float scan = exp(-pow((vP.y - sz) / .55, 2.0)) * .9 + exp(-pow((vP.y - sz) / 3.2, 2.0)) * .18;
        /* radar: rings every 3 m and a sweep turning once every 6 s */
        float inR = step(d, uR);
        float ring = (1.0 - smoothstep(0.0, fwidth(d) * 1.5, abs(fract(d / 3.0 - .5) - .5) * 3.0)) * inR * .55;
        float sw = mod(atan(vP.y - uC.y, vP.x - uC.x) - uT * 1.047, 6.28318);
        float sweep = (1.0 - smoothstep(0.0, 1.1, sw)) * inR * (0.35 + 0.65 * (1.0 - d / uR));
        float v = (g * (0.55 + scan * 1.2) + scan * .35 + ring + sweep * .55) * fade;
        gl_FragColor = vec4(uCol * v * mix(1.0, .75, uDay), v * mix(.9, .55, uDay));
      }`;
    const holos = [];
    function holo(w, h, pos, tilt, cx, cz, r) {
      const m = new T.Mesh(new T.PlaneGeometry(w, h), new T.ShaderMaterial({
        uniforms: Object.assign({}, U, { uC: { value: new T.Vector2(cx, cz) }, uR: { value: r } }),
        vertexShader: holoVS, fragmentShader: holoFS,
        transparent: true, depthWrite: false, toneMapped: false, blending: T.AdditiveBlending
      }));
      m.rotation.x = -Math.PI / 2 + tilt;
      m.position.set(pos[0], pos[1], pos[2]);
      m.renderOrder = 3; m.frustumCulled = false;
      group.add(m); holos.push(m);
      return m;
    }
    /* the court */
    holo(64, 56, [0, .035, 2], 0, 0, 1.5, 15);
    /* the stair: 22 m of run rising 7 m, from z = -11 to z = -33 */
    const rise = Math.atan2(7, 22);
    holo(9.4, Math.hypot(22, 7), [0, 3.5 + .32, -22], rise, 0, -22, 11);
    /* the podium before the hall */
    holo(30, 12, [0, 7.06, -39], 0, 0, -38, 9);
    /* ── data packets: square nodes rising in lanes ───────────────────── */
    function lanes(n, seed) {
      let s = seed;
      const rnd = () => (s = (s * 16807) % 2147483647) / 2147483647;
      /* typed arrays must come from the frame's realm: three.js checks
         `instanceof Float32Array` against its own window's constructor */
      const F32 = win.Float32Array;
      const base = new F32(n * 3), meta = new F32(n * 3);
      for (let i = 0; i < n; i++) {
        /* lanes hug the gate posts and both stair cheeks, plus a loose field */
        const lane = i % 5;
        const x = lane === 0 ? -5.2 : lane === 1 ? 5.2 : lane === 2 ? -3.6 : lane === 3 ? 3.6 : (rnd() - .5) * 22;
        const z = lane < 2 ? -3 + (rnd() - .5) * 2 : lane < 4 ? -12 - rnd() * 10 : 6 - rnd() * 20;
        base[i * 3] = x + (rnd() - .5) * .6; base[i * 3 + 1] = rnd() * 9; base[i * 3 + 2] = z;
        meta[i * 3] = .35 + rnd() * .9;       /* rise speed  */
        meta[i * 3 + 1] = rnd() * 6.283;      /* phase       */
        meta[i * 3 + 2] = .6 + rnd() * .9;    /* size        */
      }
      const g = new T.BufferGeometry();
      g.setAttribute('position', new T.BufferAttribute(base, 3));
      g.setAttribute('aMeta', new T.BufferAttribute(meta, 3));
      return g;
    }
    const packets = new T.Points(lanes(170, 91), new T.ShaderMaterial({
      uniforms: U, transparent: true, depthWrite: false, toneMapped: false, blending: T.AdditiveBlending,
      vertexShader: `
        uniform float uT; attribute vec3 aMeta; varying float vA;
        void main(){
          vec3 p = position;
          p.y = mod(p.y + uT * aMeta.x, 9.0);
          p.x += sin(uT * .7 + aMeta.y) * .18;
          vA = smoothstep(0.0, 1.2, p.y) * (1.0 - smoothstep(6.5, 9.0, p.y)) * (.55 + .45 * step(.5, fract(uT * 1.7 + aMeta.y)));
          vec4 mv = modelViewMatrix * vec4(p, 1.0);
          gl_PointSize = clamp(aMeta.z * 150.0 / -mv.z, 2.0, 11.0);
          gl_Position = projectionMatrix * mv; }`,
      fragmentShader: `
        uniform vec3 uCol; uniform float uDay; varying float vA;
        void main(){
          vec2 q = abs(gl_PointCoord - .5);
          float m = max(q.x, q.y);
          float core = 1.0 - smoothstep(.20, .24, m);
          float frame = (1.0 - smoothstep(.40, .46, m)) * smoothstep(.33, .37, m);
          float a = (core * .9 + frame * .7) * vA;
          if (a < .01) discard;
          vec3 c = mix(uCol, vec3(1.0), core * .35);
          gl_FragColor = vec4(c * mix(1.4, .9, uDay), a * mix(1.0, .8, uDay));
        }`
    }));
    packets.frustumCulled = false;
    packets.renderOrder = 4;
    group.add(packets);

    /* ── wisps: soft motes drifting across the columns ─────────────────── */
    const wg = lanes(220, 7);
    const wisps = new T.Points(wg, new T.ShaderMaterial({
      uniforms: U, transparent: true, depthWrite: false, toneMapped: false, blending: T.AdditiveBlending,
      vertexShader: `
        uniform float uT; attribute vec3 aMeta; varying float vA;
        void main(){
          vec3 p = position;
          float t = uT * (.25 + aMeta.x * .3) + aMeta.y;
          p.x = mod(p.x + t * 2.2 + 16.0, 32.0) - 16.0;
          p.y = .6 + mod(position.y, 5.5) + sin(t * 1.3) * .5;
          p.z += cos(t * .8) * .9;
          vA = .5 + .5 * sin(t * 2.1);
          vec4 mv = modelViewMatrix * vec4(p, 1.0);
          gl_PointSize = clamp(aMeta.z * 260.0 / -mv.z, 3.0, 40.0);
          gl_Position = projectionMatrix * mv; }`,
      fragmentShader: `
        uniform vec3 uCol; uniform float uDay; varying float vA;
        void main(){
          float r = length(gl_PointCoord - .5);
          float a = (exp(-r * r * 38.0) * .9 + exp(-r * r * 9.0) * .25) * vA * mix(.55, .4, uDay);
          if (a < .01) discard;
          gl_FragColor = vec4(mix(uCol, vec3(1.0), .25) * a, a);
        }`
    }));
    wisps.frustumCulled = false;
    wisps.renderOrder = 4;
    group.add(wisps);

    K.scene.add(group);

    /* ── neon lanterns ─────────────────────────────────────────────────── */
    const W = K.WORLD;
    const halo = (() => {
      const c = win.document.createElement('canvas'); c.width = c.height = 128;
      const x = c.getContext('2d'), g = x.createRadialGradient(64, 64, 0, 64, 64, 64);
      g.addColorStop(0, 'rgba(255,255,255,1)'); g.addColorStop(.25, 'rgba(255,255,255,.55)'); g.addColorStop(1, 'rgba(255,255,255,0)');
      x.fillStyle = g; x.fillRect(0, 0, 128, 128);
      const t = new T.CanvasTexture(c); t.needsUpdate = true; return t;
    })();
    (W.lanternGlows || []).forEach(g => { g.material.map = halo; g.material.needsUpdate = true; });
    /* every lantern builds its own pane material (WORLD keeps only the
       first), so collect them from each lantern group: the small lit planes */
    const panes = [];
    (W.lanternLights || []).forEach(l => (l.parent ? l.parent.children : []).forEach(o => {
      const p = o.isMesh && o.geometry && o.geometry.parameters;
      if (p && p.width === .34 && p.height === .34 && o.material && panes.indexOf(o.material) < 0) panes.push(o.material);
    }));

    const start = performance.now();
    const prev = K.scene.onBeforeRender;
    K.scene.onBeforeRender = function () {
      if (prev) prev.apply(this, arguments);
      const t = (performance.now() - start) / 1000, day = isDay();
      const col = day ? DAY : NIGHT;
      U.uT.value = t;
      U.uDay.value = day ? 1 : 0;
      U.uCol.value.set(col[0], col[1], col[2]);
      /* the temple keeps its night palette in both themes, so light always adds */
      const bl = T.AdditiveBlending;
      holos.forEach(h => { h.material.blending = bl; });
      packets.material.blending = wisps.material.blending = bl;
      (W.lanternLights || []).forEach((l, i) => {
        const pulse = .55 + .45 * Math.pow(.5 + .5 * Math.sin(t * 2.4 + i * 1.3), 3);
        l.color.setRGB(col[0], col[1] + .05, col[2] + .05);
        /* the engine rewrites intensity every frame; boost that value once,
           even if the scene is drawn more than once in a frame */
        if (l.intensity !== l.userData.cyberOut) {
          l.intensity *= (day ? .7 : 1.25) * (.7 + pulse);
          l.userData.cyberOut = l.intensity;
        }
      });
      const hot = .55 + .45 * Math.pow(.5 + .5 * Math.sin(t * 2.4), 3);
      panes.forEach(m => m.color.setRGB(col[0] * 3 * hot, col[1] * 3 * hot + .06, col[2] * 3 * hot + .06));
      (W.lanternGlows || []).forEach((g, i) => {
        const p = .55 + .45 * Math.pow(.5 + .5 * Math.sin(t * 2.4 + i * 1.3), 3);
        g.material.color.setRGB(col[0], col[1], col[2]);
        g.material.opacity = (day ? .45 : .8) * p;
      });
    };
    if (K.kick) K.kick();
    return true;
  }

  function attempt() {
    if (install()) return;
    let n = 0;
    const id = setInterval(() => { if (install() || ++n > 80) clearInterval(id); }, 250);
  }
  document.addEventListener('hastra:atmosphere-ready', attempt);
  if (window.HastraAtmosphere && window.HastraAtmosphere.ready) attempt();
})();
