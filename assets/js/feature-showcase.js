// Astra — Feature Showcase: cobe globe init (Card 4, "Global Anti-VPN &
// Geo-Fencing Shield"). Loaded as a native ES module (type="module") so the
// CDN import works with zero build step. Auto-inits on DOMContentLoaded and
// tears the WebGL context down on pagehide/unload — a globe left running
// after navigating away (bfcache restore, SPA-style nav) leaks its
// animation-frame loop and GPU context otherwise.
import createGlobe from 'https://cdn.jsdelivr.net/npm/cobe@0.6.3/+esm';

// Secure gateway nodes plotted on the globe — Astra's anti-VPN/geo-fencing
// reference points, not literal infrastructure locations.
const GATEWAY_MARKERS = [
  { location: [37.7749, -122.4194], size: 0.05 }, // San Francisco
  { location: [51.5074, -0.1278],   size: 0.05 }, // London
  { location: [50.1109, 8.6821],    size: 0.05 }, // Frankfurt
  { location: [1.3521, 103.8198],   size: 0.05 }, // Singapore
  { location: [17.3850, 78.4867],   size: 0.06 }, // Hyderabad
  { location: [12.9716, 77.5946],   size: 0.05 }, // Bengaluru
];

function initShowcaseGlobe() {
  const canvas = document.getElementById('showcaseGlobe');
  if (!canvas || canvas.dataset.globeInit === '1') return;
  canvas.dataset.globeInit = '1';

  let width = canvas.offsetWidth;
  let phi = 0;
  let pointerDown = false;
  let pointerX = 0;
  let dragPhi = 0;

  const globe = createGlobe(canvas, {
    devicePixelRatio: Math.min(window.devicePixelRatio || 1, 2),
    width: width * 2,
    height: width * 2,
    phi: 0,
    theta: 0.28,
    dark: 1,
    diffuse: 1.2,
    mapSamples: 16000,
    mapBrightness: 6,
    // AuthKit palette — deep steel navy base, void-violet markers, faint glow.
    baseColor: [0.1, 0.12, 0.18],
    markerColor: [0.4, 0.23, 0.95],
    glowColor: [0.08, 0.1, 0.16],
    opacity: 0.9,
    markers: GATEWAY_MARKERS,
    onRender: (state) => {
      if (!pointerDown) phi += 0.0032;
      state.phi = phi + dragPhi;
      state.width = width * 2;
      state.height = width * 2;
    },
  });

  canvas.style.opacity = '0';
  requestAnimationFrame(() => { canvas.style.transition = 'opacity 0.6s ease'; canvas.style.opacity = '1'; });

  // Drag-to-rotate — mouse and touch.
  const onPointerDown = (e) => {
    pointerDown = true;
    pointerX = (e.touches ? e.touches[0].clientX : e.clientX);
    canvas.style.cursor = 'grabbing';
  };
  const onPointerUp = () => { pointerDown = false; canvas.style.cursor = 'grab'; };
  const onPointerMove = (e) => {
    if (!pointerDown) return;
    const x = (e.touches ? e.touches[0].clientX : e.clientX);
    const delta = x - pointerX;
    pointerX = x;
    dragPhi += delta / 200;
  };
  canvas.addEventListener('pointerdown', onPointerDown);
  window.addEventListener('pointerup', onPointerUp);
  window.addEventListener('pointermove', onPointerMove);

  const onResize = () => { width = canvas.offsetWidth; };
  window.addEventListener('resize', onResize);

  function teardown() {
    globe.destroy();
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('resize', onResize);
    canvas.removeEventListener('pointerdown', onPointerDown);
  }

  // pagehide covers back/forward-cache navigations that 'unload' misses;
  // 'unload' is kept as a fallback for browsers that don't fire pagehide.
  window.addEventListener('pagehide', teardown, { once: true });
  window.addEventListener('unload', teardown, { once: true });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initShowcaseGlobe);
} else {
  initShowcaseGlobe();
}
