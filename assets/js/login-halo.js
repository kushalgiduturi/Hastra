/* Hastra — sign-in ring halo (auth/login.php).

   A standalone WebGL scene mirroring the ten-ring halo behind the HASTRA
   wordmark on the landing page (landing-pages/hastra.html: buildRingHalo /
   updateHalo / applyHaloTheme), scaled down to frame the sign-in card's
   .brand mark instead of the full hero wordmark, with its own small
   renderer/scene/camera rather than sharing the temple scene. Same ring
   geometry, PBR metal material, per-ring tilt/spin/pulse and the same
   dark-metal/cyan (dark theme) vs brass/amber (light theme) palette.
   Simplified from the landing version: no lightning arcs or sparks — those
   are the most tightly bound to the temple scene's shared globals, and the
   rings alone already carry the visual identity at this small size.

   three.js is loaded as a classic global script just before this module
   (auth/login.php), matching the landing page's own vendored r149 build.
   ?rings=0 (same flag login-rings.js honours) turns this off too. */

const THREE = window.THREE;
const RING_COUNT = 10;

function isDay() {
  return document.documentElement.getAttribute('data-theme') === 'light';
}

function cvs(w, h) {
  const c = document.createElement('canvas');
  c.width = w; c.height = h;
  return c;
}

// A small studio-style reflection map so the metal reads as metal — the
// same approach as the landing page's haloEnvironment(), inlined here so
// this module has no dependency on the temple scene's helpers.
function buildEnvironment(renderer) {
  const c = cvs(256, 128), x = c.getContext('2d');
  const g = x.createLinearGradient(0, 0, 0, 128);
  g.addColorStop(0, '#dfe8f0'); g.addColorStop(.35, '#56606c'); g.addColorStop(.5, '#141820'); g.addColorStop(1, '#05070a');
  x.fillStyle = g; x.fillRect(0, 0, 256, 128);
  x.fillStyle = 'rgba(255,255,255,.9)'; x.fillRect(40, 18, 60, 10); x.fillRect(170, 24, 40, 8);
  const t = new THREE.CanvasTexture(c);
  t.mapping = THREE.EquirectangularReflectionMapping;
  const pm = new THREE.PMREMGenerator(renderer);
  const env = pm.fromEquirectangular(t).texture;
  pm.dispose(); t.dispose();
  return env;
}

export function createLoginHalo(container) {
  if (!window.THREE || document.body.hasAttribute('data-rings-off') ||
      new URLSearchParams(location.search).get('rings') === '0') return null;
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return null;

  const canvas = document.createElement('canvas');
  canvas.setAttribute('aria-hidden', 'true');
  canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;';
  container.prepend(canvas);

  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true, powerPreference: 'low-power' });
  renderer.setPixelRatio(Math.min(devicePixelRatio || 1, 2));
  renderer.outputEncoding = THREE.sRGBEncoding;

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(30, 1, .1, 20);
  camera.position.set(0, 0, 6);

  scene.add(new THREE.HemisphereLight(0xbfd4ff, 0x0a0c10, .55));
  const key = new THREE.DirectionalLight(0xffffff, 1.1);
  key.position.set(2, 3, 4);
  scene.add(key);

  const env = buildEnvironment(renderer);
  const geo = new THREE.TorusGeometry(1, .11, 14, 72);
  const group = new THREE.Group();
  const rings = [];
  for (let i = 0; i < RING_COUNT; i++) {
    const mat = new THREE.MeshStandardMaterial({
      color: 0x111622, metalness: .92, roughness: .3, envMap: env, envMapIntensity: 1.7,
      emissive: 0x00f2fe, emissiveIntensity: .5, transparent: true, opacity: 0,
    });
    const pivot = new THREE.Group();
    const mesh = new THREE.Mesh(geo, mat);
    pivot.add(mesh);
    group.add(pivot);
    const a = i / RING_COUNT * Math.PI * 2;
    rings.push({ pivot, mesh, a, tilt: (i % 2 ? 1 : -1) * .38, spin: Math.random() * 6 });
  }
  scene.add(group);

  // Camera fov 30 at z=6 shows ~1.6 world units of half-height regardless of
  // the canvas's CSS pixel size (fov/distance set the frustum, not the box);
  // R + the ring's own half-extent must stay under that or the top/bottom of
  // the circle gets clipped by the camera, not just the HTML box.
  function layout() {
    const R = 1.0;
    rings.forEach(k => {
      k.pivot.position.set(Math.cos(k.a) * R, Math.sin(k.a) * R * .85, 0);
      k.pivot.setRotationFromAxisAngle(new THREE.Vector3(Math.cos(k.a), Math.sin(k.a) * .85, 0).normalize(), k.tilt);
      k.mesh.scale.setScalar(.5);
    });
  }
  layout();

  function applyTheme() {
    const d = isDay();
    rings.forEach(k => {
      k.mesh.material.color.set(d ? 0xc49b39 : 0x111622);
      k.mesh.material.emissive.set(d ? 0xf59e0b : 0x00f2fe);
      k.mesh.material.roughness = d ? .22 : .3;
      k.mesh.material.needsUpdate = true;
    });
  }
  applyTheme();
  const themeObserver = new MutationObserver(applyTheme);
  themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  let opacity = 0, target = 0, spin = 0, pulseT = 0, raf = null, running = true;
  function resize() {
    const w = container.clientWidth, h = container.clientHeight;
    if (!w || !h) return;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }
  const ro = new ResizeObserver(resize);
  ro.observe(container);
  resize();

  let last = performance.now();
  function tick(now) {
    if (!running) return;
    const dt = Math.min((now - last) / 1000, .05);
    last = now;
    opacity += (target - opacity) * Math.min(dt * 4, 1);
    spin += dt * .24;
    pulseT += dt;
    group.rotation.z = spin * .6;
    rings.forEach((k, i) => {
      k.spin += dt * .9;
      k.mesh.rotation.z = k.spin;
      const pulse = Math.sin(pulseT * 3.5 + i * .6) * .5 + .5;
      k.mesh.material.emissiveIntensity = (isDay() ? .5 : 1) * (1.2 + pulse * 1.8);
      k.mesh.material.opacity = opacity;
    });
    renderer.render(scene, camera);
    raf = requestAnimationFrame(tick);
  }
  raf = requestAnimationFrame(tick);

  return {
    power(on) { target = on ? 1 : 0; },
    destroy() {
      running = false;
      if (raf) cancelAnimationFrame(raf);
      ro.disconnect();
      themeObserver.disconnect();
      geo.dispose();
      rings.forEach(k => k.mesh.material.dispose());
      env.dispose();
      renderer.dispose();
      canvas.remove();
    },
  };
}
