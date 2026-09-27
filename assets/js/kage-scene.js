/* ═══════════════════════════════════════════════════════════════════════
   The shared scene: the landing page's full 3-D world, hung behind every
   portal, dashboard and auth page as #hastra-kage-scene.

   It is the same document the landing page shows (landing-pages/hastra.html)
   loaded with ?scene=bg, which keeps the render and drops everything else:
   no copy, no preloader, no wordmark, no card windows. The frame is
   same-origin, so this script drives it directly:

     camera   window.HastraAtmosphere (flyTo / setScroll / u) is kept as the
              contract view-director.js already speaks, and forwards to the
              frame's rig. u is a waypoint index 0…5 on the engine's curve.
     pointer  the frame is pointer-transparent, so the hand-held drift is fed
              from this page's pointer.
     theme    assets/js/landing-host.js lays daylight over it exactly as it
              does on the landing page: the moon becomes the sun, the night a
              golden afternoon, the lanterns go cold.

   Layering. The frame is fixed at z-index 0 and sits outside <body>, as
   the root's first child; <body> is lifted to its own stacking layer at
   z-index 1. A z-index 0 layer inside <body> would paint over every block
   that is not itself positioned, and most of Hastra's pages have those.

   Cost. The world is built in the frame's own time slices after this page
   has loaded, so the page's first paint and first input are never behind
   it; the three.js runtime and the document come from the HTTP cache after
   the first visit. The engine's governor drops to half the leaves and
   embers and 0.8x pixels under 50 fps, and parks the loop entirely under
   prefers-reduced-motion once the frame is composed.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__hastraKage || window.top !== window.self) return;
  window.__hastraKage = true;
  const b = document.body;
  if (!b || b.hasAttribute('data-atmosphere-off') || b.hasAttribute('data-atmosphere-scene-off')) return;

  const me = document.currentScript;
  const base = me && me.src ? me.src.replace(/assets\/js\/kage-scene\.js.*$/, '') : '/';
  /* this script's own ?v= (theme.js appends it) busts hastra.html's cache too:
     the iframe has no other signal to re-fetch it after a deploy */
  let v = '';
  try { v = new URL(me.src, location.href).searchParams.get('v') || ''; } catch (e) { /* ignore */ }
  const root = document.documentElement;

  /* ------------------------------------------ the camera contract */
  let bg = null, target = 0, scroll = 0, pending = null;
  const A = window.HastraAtmosphere = {
    flyTo(u, o) {
      target = u;
      if (bg) bg.flyTo(u, o); else pending = [u, o];
    },
    setScroll(v) { scroll = v; if (bg) bg.setScroll(v); },
    get u() { return bg ? bg.u : target + scroll; },
    get ready() { return !!bg; }
  };

  /* ------------------------------------------ the frame */
  const frame = document.createElement('iframe');
  frame.id = 'hastra-kage-scene';
  frame.title = '';
  frame.setAttribute('aria-hidden', 'true');
  frame.setAttribute('tabindex', '-1');
  frame.setAttribute('loading', 'eager');
  root.classList.add('hastra-kage', 'hastra-atmos');
  root.insertBefore(frame, b);
  if (window.HastraSceneHost) HastraSceneHost.attach(frame, null, { skyOnly: root.classList.contains('nf-portal') });

  addEventListener('message', e => {
    if (e.source !== frame.contentWindow || !e.data) return;
    if (e.data.hastra === 'scene-ready') {
      bg = frame.contentWindow.__hastraBg;
      if (!bg) return;
      if (pending) { bg.flyTo(pending[0], pending[1]); pending = null; }
      else bg.flyTo(target, { instant: true });
      bg.setScroll(scroll);
      frame.classList.add('is-live');
      document.dispatchEvent(new CustomEvent('hastra:atmosphere-ready'));
    } else if (e.data.hastra === 'scene-fallback') {
      /* no WebGL: the flat sky in CSS stands in */
      frame.remove();
      root.classList.remove('hastra-kage', 'hastra-atmos');
      root.classList.add('hastra-atmos-static');
    }
  });

  /* the pointer drives the rig's drift; the frame never sees it itself */
  if (!matchMedia('(hover: none)').matches) {
    let nx = 0, ny = 0, queued = false;
    addEventListener('pointermove', e => {
      nx = (e.clientX / innerWidth) * 2 - 1; ny = -((e.clientY / innerHeight) * 2 - 1);
      if (queued) return; queued = true;
      requestAnimationFrame(() => { queued = false; if (bg) bg.pointer(nx, ny); });
    }, { passive: true });
  }

  /* build after this page has painted and settled */
  const go = () => { frame.src = base + 'landing-pages/hastra.html?scene=bg' + (v ? '&v=' + encodeURIComponent(v) : ''); };
  const later = () => (window.requestIdleCallback ? requestIdleCallback(go, { timeout: 600 }) : setTimeout(go, 120));
  if (document.readyState === 'complete') later(); else addEventListener('load', later, { once: true });
})();
