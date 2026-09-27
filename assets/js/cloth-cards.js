/* ═══════════════════════════════════════════════════════════════════════
   Cloth cards: the landing engine's WebGL2 fabric, hung behind Astra's deliverable cards.

   The simulation, both shader passes and the option set are the landing engine's
   createCloth(), itself Canvas UI's Cloth, unchanged: a 96 x 96 height
   field on a damped wave equation, driven by a gusting wind, pinned along
   its top edge, with the pointer pressing a gaussian brush into it. The
   normals, fold shading, specular sheen and the crease shadows are
   recomputed from that field every step.

   The one departure is what the fabric carries. The landing cloth carries a
   still, and hides the card's own painting. These cards carry live HTML
   (forms, tables, buttons), so nothing is rasterised. The fabric is a
   generated AuthKit plate hung at z-index -1 inside the card's own stacking
   context, below the content and above the card's cleared background. The
   type stays crisp DOM and the cloth moves under it.

   Opt in with [data-cloth] on the card. Each fabric is its own WebGL2
   context and browsers cap those at about sixteen, so a page gets at most
   CLOTH_MAX live fabrics and the rest keep their ordinary card surface.
   Each one hard-stops off screen and again once the wind dies and the
   fabric has settled.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__astraCloth) return;
  window.__astraCloth = true;
  const REDUCE = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const COARSE = matchMedia('(hover: none)').matches;
  const CLOTH_MAX = 8;

const CLOTH_VERT = `#version 300 es
precision highp float;
layout(location = 0) in vec2 aGrid;
layout(location = 1) in vec4 aData;
layout(location = 2) in vec2 aOffset;
uniform vec2 uRes; uniform vec2 uOut; uniform float uBleed; uniform float uFocal;
out vec2 vUv; out vec3 vNormal; out float vFold; out vec2 vLocal;
void main () {
  vUv = aGrid;
  float z = aData.x;
  vec2 nxy = aData.yz;
  vNormal = vec3(nxy, sqrt(max(1.0 - dot(nxy, nxy), 0.04)));
  vFold = aData.w;
  vLocal = aGrid * uRes;
  vec2 px = vLocal + aOffset + vec2(uBleed);
  vec2 ndc = (px / uOut) * 2.0 - 1.0;
  ndc.y = -ndc.y;
  float w = (uFocal - z) / uFocal;
  gl_Position = vec4(ndc, -z / uFocal, w);
}`;
const CLOTH_SDF = `
float fabricDist (vec2 p, vec2 size, float radius) {
  vec2 half_ = size * 0.5;
  float r = min(radius, min(half_.x, half_.y));
  vec2 q = abs(p - half_) - (half_ - vec2(r));
  return length(max(q, vec2(0.0))) + min(max(q.x, q.y), 0.0) - r;
}`;
const CLOTH_FRAG = `#version 300 es
precision highp float;
in vec2 vUv; in vec3 vNormal; in float vFold; in vec2 vLocal;
out vec4 outColor;
uniform sampler2D uContent; uniform float uMaxX; uniform float uLight;
uniform float uSheen; uniform vec3 uBacking; uniform vec2 uRes;
uniform float uRadius; uniform float uDark; uniform float uEdge;
${CLOTH_SDF}
void main () {
  vec2 uv = clamp(vUv, vec2(0.001), vec2(uMaxX - 0.001, 0.999));
  vec4 tex = texture(uContent, uv);
  vec3 fabric = mix(uBacking, tex.rgb, tex.a);
  vec3 n = normalize(vNormal);
  vec3 lightDir = normalize(vec3(-0.3, 0.42, 0.86));
  float diffFlat = 0.58 + 0.42 * lightDir.z;
  float diff = 0.58 + 0.42 * dot(n, lightDir);
  float shade = mix(1.0, (diff / diffFlat) * vFold, uLight);
  vec3 lit = fabric * shade;
  vec3 halfway = normalize(lightDir + vec3(0.0, 0.0, 1.0));
  float specFlat = pow(halfway.z, 34.0);
  float spec = max(pow(max(dot(n, halfway), 0.0), 34.0) - specFlat, 0.0) / (1.0 - specFlat);
  lit += uSheen * spec * mix(vec3(1.0), fabric, 0.35);
  float broadFlat = pow(halfway.z, 6.0);
  float broad = max(pow(max(dot(n, halfway), 0.0), 6.0) - broadFlat, 0.0) / (1.0 - broadFlat);
  lit += uDark * uLight * 0.3 * broad * vec3(1.0);
  float d = fabricDist(vLocal, uRes, uRadius);
  float hemT = smoothstep(0.0, 6.0, -d);
  lit *= mix(1.0, mix(0.93, 1.0, hemT), uLight * (1.0 - uDark));
  lit += vec3(uDark * uLight * 0.08 * (1.0 - hemT));
  float rim = smoothstep(1.05, 0.2, abs(d + 0.7));
  lit += rim * uEdge * vec3(0.874, 0.906, 0.878);

  float alpha = clamp(0.5 - d, 0.0, 1.0);
  outColor = vec4(clamp(lit, 0.0, 1.0), 1.0) * alpha;
}`;
const CLOTH_SHADOW_VERT = `#version 300 es
precision highp float;
layout(location = 0) in vec2 aGrid;
layout(location = 1) in vec4 aData;
layout(location = 2) in vec2 aOffset;
uniform vec2 uRes; uniform vec2 uOut; uniform float uBleed;
out vec2 vLocal; out float vLift;
void main () {
  float z = aData.x;
  vLift = z;
  vLocal = aGrid * uRes;
  vec2 px = vLocal + aOffset + vec2(uBleed) + vec2(10.0, 14.0) + vec2(0.3, 0.42) * z;
  vec2 ndc = (px / uOut) * 2.0 - 1.0;
  ndc.y = -ndc.y;
  gl_Position = vec4(ndc, 0.0, 1.0);
}`;
const CLOTH_SHADOW_FRAG = `#version 300 es
precision highp float;
in vec2 vLocal; in float vLift;
out vec4 outColor;
uniform float uShadow; uniform vec2 uRes; uniform float uRadius; uniform float uDark;
${CLOTH_SDF}
void main () {
  float d = fabricDist(vLocal, uRes, uRadius);
  float a = uShadow * smoothstep(0.0, 30.0, -d);
  a *= mix(1.0, 0.55, clamp(vLift / 50.0, 0.0, 1.0));
  a *= mix(1.0, 0.55, uDark);
  outColor = vec4(vec3(uDark) * a, a);
}`;

const CL_SEG = 96, CL_NODES = CL_SEG + 1, CL_DT = 1 / 120;
const CL_WAVE = 30, CL_STIFF = 0.55, CL_GAIN = 5.0, CL_BLEED = 48;
const CLOTH_DEFAULTS = {
  pin: 'top', wind: 3, speed: .5, amplitude: 30, drape: 40, brush: 2.05,
  brushSize: 150, damping: 1, light: .5, sheen: .1, shadow: .25,
  cornerRadius: 20, backing: 'auto', perspective: 1200
};

function createCloth(output, plate, options) {
  const config = Object.assign({}, CLOTH_DEFAULTS, options || {});
  const wrapper = output.parentElement || output;
  output.style.top = output.style.left = -CL_BLEED + 'px';
  output.style.width = 'calc(100% + ' + CL_BLEED * 2 + 'px)';
  output.style.height = 'calc(100% + ' + CL_BLEED * 2 + 'px)';

  const gl = output.getContext('webgl2', { alpha: true, depth: false, stencil: false,
    antialias: true, premultipliedAlpha: true });
  if (!gl || gl.isContextLost()) return null;

  const compile = (type, text) => {
    const sh = gl.createShader(type);
    gl.shaderSource(sh, text); gl.compileShader(sh);
    if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) console.error('Cloth:', gl.getShaderInfoLog(sh));
    return sh;
  };
  const link = (v, f) => {
    const prog = gl.createProgram();
    const vs = compile(gl.VERTEX_SHADER, v), fs = compile(gl.FRAGMENT_SHADER, f);
    gl.attachShader(prog, vs); gl.attachShader(prog, fs); gl.linkProgram(prog);
    const u = {}, n = gl.getProgramParameter(prog, gl.ACTIVE_UNIFORMS);
    for (let i = 0; i < n; i++) { const info = gl.getActiveUniform(prog, i);
      u[info.name] = gl.getUniformLocation(prog, info.name); }
    return { program: prog, vert: vs, frag: fs, uniforms: u };
  };
  const cloth = link(CLOTH_VERT, CLOTH_FRAG);
  const shadow = link(CLOTH_SHADOW_VERT, CLOTH_SHADOW_FRAG);

  const gridVerts = new Float32Array(CL_NODES * CL_NODES * 2);
  for (let y = 0; y < CL_NODES; y++) for (let x = 0; x < CL_NODES; x++) {
    const i = (y * CL_NODES + x) * 2;
    gridVerts[i] = x / CL_SEG; gridVerts[i + 1] = y / CL_SEG;
  }
  const idx = new Uint32Array(CL_SEG * CL_SEG * 6);
  let o = 0;
  for (let y = 0; y < CL_SEG; y++) for (let x = 0; x < CL_SEG; x++) {
    const a = y * CL_NODES + x, b = a + 1, c = a + CL_NODES, d = c + 1;
    idx[o++] = a; idx[o++] = c; idx[o++] = b; idx[o++] = b; idx[o++] = c; idx[o++] = d;
  }
  const vao = gl.createVertexArray();
  gl.bindVertexArray(vao);
  const gridBuf = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, gridBuf);
  gl.bufferData(gl.ARRAY_BUFFER, gridVerts, gl.STATIC_DRAW);
  gl.enableVertexAttribArray(0); gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
  const dataBuf = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, dataBuf);
  gl.bufferData(gl.ARRAY_BUFFER, CL_NODES * CL_NODES * 16, gl.DYNAMIC_DRAW);
  gl.enableVertexAttribArray(1); gl.vertexAttribPointer(1, 4, gl.FLOAT, false, 0, 0);
  const offBuf = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, offBuf);
  gl.bufferData(gl.ARRAY_BUFFER, CL_NODES * CL_NODES * 8, gl.DYNAMIC_DRAW);
  gl.enableVertexAttribArray(2); gl.vertexAttribPointer(2, 2, gl.FLOAT, false, 0, 0);
  const idxBuf = gl.createBuffer();
  gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, idxBuf);
  gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, idx, gl.STATIC_DRAW);
  gl.bindVertexArray(null);

  const tex = gl.createTexture();
  gl.bindTexture(gl.TEXTURE_2D, tex);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
  gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, 1, 1, 0, gl.RGBA, gl.UNSIGNED_BYTE,
    new Uint8Array([0, 0, 0, 0]));

  function upload() {
    const c = plate();
    if (!c || !c.width) return;
    gl.bindTexture(gl.TEXTURE_2D, tex);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, false);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, c);
  }

  function syncSize() {
    const dpr = Math.min(devicePixelRatio || 1, 2);
    const w = Math.max(1, Math.round(output.clientWidth * dpr));
    const h = Math.max(1, Math.round(output.clientHeight * dpr));
    if (output.width !== w || output.height !== h) { output.width = w; output.height = h; }
  }

  let hCur = new Float32Array(CL_NODES * CL_NODES);
  let hPrev = new Float32Array(CL_NODES * CL_NODES);
  let hNext = new Float32Array(CL_NODES * CL_NODES);
  const vData = new Float32Array(CL_NODES * CL_NODES * 4);
  const oData = new Float32Array(CL_NODES * CL_NODES * 2);
  const zF = new Float32Array(CL_NODES * CL_NODES);
  const rowF = new Float32Array(CL_NODES), colF = new Float32Array(CL_NODES);
  const hang = new Float32Array(CL_NODES);
  for (let a = 0; a < CL_NODES; a++) hang[a] = Math.pow(a / CL_SEG, 1.3);

  let simTime = Math.random() * 60, gust = .5, energy = 1;
  let edge = .048, edgeTo = .048;
  const ptr = { x: -1e5, y: -1e5, inside: false };
  const touch = { x: -1e5, y: -1e5, vx: 0, vy: 0, s: 0 };
  const axisA = (x, y) => config.pin === 'top' ? y : config.pin === 'bottom' ? CL_SEG - y
    : config.pin === 'left' ? x : CL_SEG - x;
  const axisB = (x, y) => (config.pin === 'top' || config.pin === 'bottom') ? x : y;

  function stepSim(dt) {
    simTime += dt * Math.max(config.speed, 0);
    const t = simTime, windAmp = CL_GAIN * Math.max(config.wind, 0) * gust;
    const kb1 = (Math.PI * 2) / (CL_SEG / 1.5), kb2 = (Math.PI * 2) / (CL_SEG / 3.8);
    const ka = (Math.PI * 2) / (CL_SEG / 2.2);
    const w1 = CL_WAVE * kb1, w2 = CL_WAVE * kb2, drift = 1.8 * Math.sin(.23 * t);
    for (let b = 0; b < CL_NODES; b++)
      rowF[b] = Math.sin(kb1 * b - w1 * t + drift) + .45 * Math.sin(kb2 * b + w2 * t * .8 + 3);
    for (let a = 0; a < CL_NODES; a++)
      colF[a] = (.7 + .3 * Math.sin(ka * a - 1.7 * t)) * hang[a];
    const c2 = CL_WAVE * CL_WAVE, dt2 = dt * dt;
    const decay = Math.exp(-Math.min(Math.max(config.damping, .05), 8) * dt);
    for (let y = 0; y < CL_NODES; y++) {
      const up = Math.max(y - 1, 0) * CL_NODES, down = Math.min(y + 1, CL_SEG) * CL_NODES;
      const row = y * CL_NODES;
      for (let x = 0; x < CL_NODES; x++) {
        const i = row + x;
        const h = hCur[i];
        const lap = hCur[row + Math.max(x - 1, 0)] + hCur[row + Math.min(x + 1, CL_SEG)]
          + hCur[up + x] + hCur[down + x] - 4 * h;
        const force = windAmp * rowF[axisB(x, y)] * colF[axisA(x, y)];
        const next = 2 * h - hPrev[i] + dt2 * (c2 * lap - CL_STIFF * h + force);
        let v = h + (next - h) * decay;
        if (v > 3.5) v = 3.5; else if (v < -3.5) v = -3.5;
        hNext[i] = v;
      }
    }
    for (let b = 0; b < CL_NODES; b++) {
      let x = b, y = 0;
      if (config.pin === 'bottom') y = CL_SEG;
      else if (config.pin === 'left') { x = 0; y = b; }
      else if (config.pin === 'right') { x = CL_SEG; y = b; }
      hNext[y * CL_NODES + x] = 0;
    }
    const spent = hPrev; hPrev = hCur; hCur = hNext; hNext = spent;
  }

  function imprint(delta, width, height) {
    if (config.brush <= 0 || touch.s < .01) return;
    const cw = width / CL_SEG, ch = height / CL_SEG;
    const rx = Math.max(config.brushSize, 12) / cw, ry = Math.max(config.brushSize, 12) / ch;
    const gx = touch.x / cw, gy = touch.y / ch;
    const x0 = Math.max(Math.ceil(gx - 2.5 * rx), 0), x1 = Math.min(Math.floor(gx + 2.5 * rx), CL_SEG);
    const y0 = Math.max(Math.ceil(gy - 2.5 * ry), 0), y1 = Math.min(Math.floor(gy + 2.5 * ry), CL_SEG);
    const lift = 1.1 * Math.min(config.brush, 3) * touch.s, rate = Math.min(delta * 4, 1);
    for (let y = y0; y <= y1; y++) {
      const oy = (y - gy) / ry, row = y * CL_NODES;
      for (let x = x0; x <= x1; x++) {
        const ox = (x - gx) / rx, g = Math.exp(-(ox * ox + oy * oy));
        if (g < .02) continue;
        const i = row + x, pull = rate * g, goal = lift * g;
        hCur[i] += (goal - hCur[i]) * pull;
        hPrev[i] += (goal - hPrev[i]) * pull;
      }
    }
  }

  function foreshorten(stride, lineStride, ds, anchor, comp) {
    const ds2 = ds * ds;
    for (let l = 0; l < CL_NODES; l++) {
      const base = l * lineStride;
      oData[(base + anchor * stride) * 2 + comp] = 0;
      let cum = 0;
      for (let k = anchor + 1; k < CL_NODES; k++) {
        const i = base + k * stride, dz = zF[i] - zF[i - stride];
        cum += ds - Math.sqrt(Math.max(ds2 - dz * dz, 0));
        oData[i * 2 + comp] = -cum;
      }
      cum = 0;
      for (let k = anchor - 1; k >= 0; k--) {
        const i = base + k * stride, dz = zF[i] - zF[i + stride];
        cum += ds - Math.sqrt(Math.max(ds2 - dz * dz, 0));
        oData[i * 2 + comp] = cum;
      }
    }
  }

  function compose(width, height) {
    const amp = Math.max(config.amplitude, 0);
    const drape = config.drape * (.3 + .7 * gust);
    const cw = width / CL_SEG, ch = height / CL_SEG;
    let e = 0;
    for (let y = 0; y < CL_NODES; y++) {
      const row = y * CL_NODES;
      for (let x = 0; x < CL_NODES; x++) {
        const i = row + x, h = hCur[i];
        if (Math.abs(h) > e) e = Math.abs(h);
        zF[i] = amp * Math.tanh(h) + drape * hang[axisA(x, y)];
      }
    }
    energy = e;
    for (let y = 0; y < CL_NODES; y++) {
      const up = Math.max(y - 1, 0) * CL_NODES, down = Math.min(y + 1, CL_SEG) * CL_NODES;
      const row = y * CL_NODES;
      for (let x = 0; x < CL_NODES; x++) {
        const i = row + x;
        const l = row + Math.max(x - 1, 0), r = row + Math.min(x + 1, CL_SEG);
        const dzdx = (zF[r] - zF[l]) / (2 * cw), dzdy = (zF[down + x] - zF[up + x]) / (2 * ch);
        const inv = 1 / Math.hypot(dzdx, dzdy, 1);
        const curve = zF[l] + zF[r] + zF[up + x] + zF[down + x] - 4 * zF[i];
        let fold = 1 - curve * .01;
        if (fold < .86) fold = .86; else if (fold > 1.06) fold = 1.06;
        const q = i * 4;
        vData[q] = zF[i]; vData[q + 1] = -dzdx * inv; vData[q + 2] = -dzdy * inv; vData[q + 3] = fold;
      }
    }
    const mid = CL_SEG >> 1;
    if (config.pin === 'top' || config.pin === 'bottom') {
      foreshorten(CL_NODES, 1, ch, config.pin === 'top' ? 0 : CL_SEG, 1);
      foreshorten(1, CL_NODES, cw, mid, 0);
    } else {
      foreshorten(1, CL_NODES, cw, config.pin === 'left' ? 0 : CL_SEG, 0);
      foreshorten(CL_NODES, 1, ch, mid, 1);
    }
  }

  /* Astra: the backing follows the theme, so it is read per draw */
  const backingNow = () => typeof config.backing === 'function' ? config.backing()
    : config.backing === 'auto' ? [.02, .026, .035] : config.backing;

  function draw() {
    const backing = backingNow();
    const resW = Math.max(wrapper.clientWidth, 1), resH = Math.max(wrapper.clientHeight, 1);
    const outW = Math.max(output.clientWidth, 1), outH = Math.max(output.clientHeight, 1);
    const light = Math.min(Math.max(config.light, 0), 1);
    const radius = Math.max(config.cornerRadius, 0);
    const lum = .299 * backing[0] + .587 * backing[1] + .114 * backing[2];
    const dark = Math.min(Math.max((.5 - lum) / .35, 0), 1);

    gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    gl.viewport(0, 0, output.width, output.height);
    gl.clearColor(0, 0, 0, 0); gl.clear(gl.COLOR_BUFFER_BIT);
    gl.enable(gl.BLEND); gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);

    gl.bindVertexArray(vao);
    gl.bindBuffer(gl.ARRAY_BUFFER, dataBuf); gl.bufferSubData(gl.ARRAY_BUFFER, 0, vData);
    gl.bindBuffer(gl.ARRAY_BUFFER, offBuf); gl.bufferSubData(gl.ARRAY_BUFFER, 0, oData);

    gl.useProgram(shadow.program);
    gl.uniform2f(shadow.uniforms.uRes, resW, resH);
    gl.uniform2f(shadow.uniforms.uOut, outW, outH);
    gl.uniform1f(shadow.uniforms.uBleed, CL_BLEED);
    gl.uniform1f(shadow.uniforms.uShadow, Math.min(Math.max(config.shadow, 0), 1));
    gl.uniform1f(shadow.uniforms.uRadius, radius);
    gl.uniform1f(shadow.uniforms.uDark, dark);
    gl.drawElements(gl.TRIANGLES, idx.length, gl.UNSIGNED_INT, 0);

    gl.useProgram(cloth.program);
    gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, tex);
    gl.uniform1i(cloth.uniforms.uContent, 0);
    gl.uniform2f(cloth.uniforms.uRes, resW, resH);
    gl.uniform2f(cloth.uniforms.uOut, outW, outH);
    gl.uniform1f(cloth.uniforms.uBleed, CL_BLEED);
    gl.uniform1f(cloth.uniforms.uFocal, Math.max(config.perspective, 200));
    gl.uniform1f(cloth.uniforms.uMaxX, 1);
    gl.uniform1f(cloth.uniforms.uLight, light);
    gl.uniform1f(cloth.uniforms.uSheen, Math.max(config.sheen, 0));
    gl.uniform1f(cloth.uniforms.uRadius, radius);
    gl.uniform1f(cloth.uniforms.uDark, dark);
    gl.uniform1f(cloth.uniforms.uEdge, edge);
    gl.uniform3f(cloth.uniforms.uBacking, backing[0], backing[1], backing[2]);
    gl.drawElements(gl.TRIANGLES, idx.length, gl.UNSIGNED_INT, 0);
    gl.bindVertexArray(null);
  }

  let raf = 0, last = performance.now(), debt = 0, running = false, visible = false, dead = false;
  function frame(now) {
    if (dead) return;
    if (!visible) { running = false; return; }
    const delta = Math.min((now - last) / 1000, 1 / 20);
    last = now;
    const width = Math.max(wrapper.clientWidth, 1), height = Math.max(wrapper.clientHeight, 1);
    if (!REDUCE) {
      const t = simTime;
      const target = Math.max(.55 + .35 * Math.sin(t * .31 + 1.3)
        + .25 * Math.sin(t * .83) * (.5 + .5 * Math.sin(t * .17)), .15);
      gust += (target - gust) * Math.min(delta * 2, 1);
      const sT = ptr.inside && config.brush > 0 ? 1 : 0;
      touch.s += (sT - touch.s) * Math.min(delta * (ptr.inside ? 8 : 2.5), 1);
      const om = 14;
      touch.vx += ((ptr.x - touch.x) * om * om - 2 * om * touch.vx) * delta;
      touch.vy += ((ptr.y - touch.y) * om * om - 2 * om * touch.vy) * delta;
      touch.x += touch.vx * delta; touch.y += touch.vy * delta;
      imprint(delta, width, height);
      edge += (edgeTo - edge) * Math.min(delta * 5, 1);
      debt = Math.min(debt + delta, CL_DT * 5);
      while (debt >= CL_DT) { stepSim(CL_DT); debt -= CL_DT; }
    }
    compose(width, height);
    draw();
    if (REDUCE || (config.wind <= .001 && energy < .004 && touch.s < .01)) { running = false; return; }
    raf = requestAnimationFrame(frame);
  }
  function start() {
    if (dead || running || !visible) return;
    running = true; last = performance.now(); raf = requestAnimationFrame(frame);
  }

  const ro = new ResizeObserver(() => { syncSize(); upload(); start(); });
  ro.observe(output);
  const io = new IntersectionObserver(es => {
    visible = es[es.length - 1] ? es[es.length - 1].isIntersecting : false;
    if (visible) start();
  });
  io.observe(output);
  const onMove = e => {
    const r = wrapper.getBoundingClientRect();
    const x = e.clientX - r.left, y = e.clientY - r.top;
    if (touch.s < .01) { touch.x = x; touch.y = y; touch.vx = touch.vy = 0; }
    ptr.x = x; ptr.y = y; ptr.inside = true; start();
  };
  const onLeave = () => { ptr.inside = false; };
  wrapper.addEventListener('pointermove', onMove, { passive: true });
  wrapper.addEventListener('pointerleave', onLeave, { passive: true });
  const onHidden = () => { if (document.hidden) { running = false; cancelAnimationFrame(raf); } else start(); };
  document.addEventListener('visibilitychange', onHidden);

  syncSize(); upload(); compose(Math.max(wrapper.clientWidth, 1), Math.max(wrapper.clientHeight, 1));
  return { refresh(){ syncSize(); upload(); if (REDUCE) { compose(Math.max(wrapper.clientWidth, 1), Math.max(wrapper.clientHeight, 1)); draw(); } start(); },
           wake: start, setEdge(v){ edgeTo = v; start(); } };
}

  /* ------------------------------------------------------ the AuthKit plate
     What the fabric is woven from. Night: deep glass with a frost-lit crown
     and an accent thread along the top hem. Day: bright paper with a faint
     blue cast. A fine twill is drawn in at a few percent so the folds have
     texture to catch; without it the shading reads as a gradient, not cloth. */
  const isDay = () => document.documentElement.getAttribute('data-theme') === 'light';
  const accent = () => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || (isDay() ? '#0265dc' : '#e02e3c');
  const accentRgb = () => getComputedStyle(document.documentElement).getPropertyValue('--accent-rgb').trim() || (isDay() ? '2,101,220' : '224,46,60');

  function fabricPlate(w, h, kind) {
    const c = document.createElement('canvas');
    c.width = Math.max(1, w | 0); c.height = Math.max(1, h | 0);
    const x = c.getContext('2d'), day = isDay();
    let g = x.createLinearGradient(0, 0, 0, c.height);
    if (day) { g.addColorStop(0, '#ffffff'); g.addColorStop(1, '#eef2f9'); }
    else { g.addColorStop(0, '#0d1222'); g.addColorStop(.55, '#080b17'); g.addColorStop(1, '#05060f'); }
    x.fillStyle = g; x.fillRect(0, 0, c.width, c.height);
    /* the crown: light gathered at the pinned edge */
    g = x.createRadialGradient(c.width * .3, 0, 0, c.width * .3, 0, Math.max(c.width, c.height) * .8);
    g.addColorStop(0, day ? 'rgba(' + accentRgb() + ',.07)' : 'rgba(168,216,245,.10)'); g.addColorStop(1, 'rgba(0,0,0,0)');
    x.fillStyle = g; x.fillRect(0, 0, c.width, c.height);
    /* twill */
    x.globalAlpha = day ? .05 : .045; x.strokeStyle = day ? '#1e293b' : '#d1e4fa'; x.lineWidth = 1;
    const step = Math.max(3, Math.round(c.width / 360));
    x.beginPath();
    for (let i = -c.height; i < c.width; i += step) { x.moveTo(i, 0); x.lineTo(i + c.height, c.height); }
    x.stroke(); x.globalAlpha = 1;
    /* the hem thread: theme accent for certificates, frost for everything else */
    const band = Math.max(2, Math.round(c.height * .006));
    g = x.createLinearGradient(0, 0, c.width, 0);
    const a = accent();
    const hue = kind === 'certificate' ? [a, '#d1e4fa'] : kind === 'report' ? ['#d1e4fa', a] : ['#98c0ef', a];
    g.addColorStop(0, hue[0]); g.addColorStop(1, hue[1]);
    x.globalAlpha = day ? .55 : .75; x.fillStyle = g; x.fillRect(0, 0, c.width, band); x.globalAlpha = 1;
    return c;
  }

  function hang(el, i) {
    const out = document.createElement('canvas');
    out.className = 'cloth-out'; out.setAttribute('aria-hidden', 'true');
    el.insertBefore(out, el.firstChild);
    el.classList.add('cloth-host');
    const dpr = Math.min(devicePixelRatio || 1, 2);
    let plate = null, pw = 0, ph = 0, pday = null;
    const get = () => {
      const w = Math.round(el.clientWidth * dpr), h = Math.round(el.clientHeight * dpr), day = isDay();
      if (!plate || w !== pw || h !== ph || day !== pday) { plate = fabricPlate(w, h, el.dataset.cloth); pw = w; ph = h; pday = day; }
      return plate;
    };
    const radius = parseFloat(getComputedStyle(el).borderTopLeftRadius) || 16;
    /* big panels billow less: amplitude and drape are pixels, not fractions */
    const scale = Math.min(1, 420 / Math.max(el.clientHeight, 1));
    const inst = createCloth(out, get, { wind: 3, speed: .5, amplitude: 30 * Math.max(scale, .35),
      drape: 40 * Math.max(scale, .35), brush: COARSE ? 0 : 2.05, brushSize: 150, damping: 1,
      light: .5, sheen: .1, shadow: .25, cornerRadius: radius, perspective: 1200, pin: 'top',
      backing: () => isDay() ? [.97, .98, 1] : [.02, .026, .035] });
    if (!inst) { out.remove(); el.classList.remove('cloth-host'); return null; }
    el.classList.add('on-cloth');
    el.addEventListener('pointerenter', () => inst.setEdge(.185), { passive: true });
    el.addEventListener('pointerleave', () => inst.setEdge(.048), { passive: true });
    return inst;
  }

  function init() {
    const live = [];
    document.querySelectorAll('[data-cloth]').forEach((el, i) => {
      if (live.length >= CLOTH_MAX) { el.classList.add('cloth-rest'); return; }
      const inst = hang(el, i); if (inst) live.push(inst);
    });
    if (!live.length) return;
    new MutationObserver(() => live.forEach(c => c.refresh()))
      .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    window.AstraCloth = { count: live.length };
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
