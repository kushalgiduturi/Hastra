/* Hastra crimson sakura petals: the pointer trail's particle field.

   Replaces the glowing-mote glitter with organic cherry-blossom petals that
   float, flip and drift down, in the Kage sanctuary's crimson and blossom
   pinks. This file only owns the petals (shape, physics, drawing). The pointer
   tracking, the canvas, the frame governor and the touch / reduced-motion
   rules stay with the engines that use it: assets/js/hybrid-hand-cursor.js
   (shared pages) and auth/login.php (the sign-in page's own canvas).

   Never a heart: the petal is a single smooth lobe (no notch, no second lobe).
   The trail can be switched off entirely with
       localStorage.setItem('hastra_cursor_trail', 'off')   (or window.HASTRA_NO_TRAIL = true)
   and back on by removing that key.

   A petal:  7 to 14 px, one of four crimson / rose tints, spawned with a gentle
   outward burst, then pulled down by gravity, slowed by air drag, swayed by a
   sine-wave wind and tumbled in 2D (rotation) and pseudo-3D (a flip that
   squashes it edge-on). It fades in briefly, then linearly to nothing over
   1.2 to 2.0 s, and its pool slot is free the moment it is gone.

   Petals are pre-rendered once to small sprites and stamped with drawImage, so
   the per-frame cost is one transform and one blit per live petal; the pool is
   fixed, so nothing is allocated while the pointer moves.

   Public: window.HastraPetals.create(cap) ->
     { emit(x, y, vx, vy), draw(ctx, dt, dpr, clock) -> live count, setCap(n), live }   (CSS px) */
(function () {
  'use strict';
  if (window.HastraPetals) return;

  const rnd = (a, b) => a + Math.random() * (b - a);
  // the visitor's switch for the whole trail (read on every emit, so it applies at once)
  function trailOff() {
    if (window.HASTRA_NO_TRAIL) return true;
    try { return localStorage.getItem('hastra_cursor_trail') === 'off'; } catch (e) { return false; }
  }

  /* Hastra crimson, blossom pink, deep temple wine, pale sakura */
  const TINTS = [[229, 9, 20, .8], [255, 105, 135, .75], [180, 20, 40, .6], [255, 130, 150, .75]];

  function petalSprite(tint) {
    const S = 48, c = document.createElement('canvas'); c.width = c.height = S;
    const x = c.getContext('2d'), [r, g, b, a] = tint;
    /* ONE smooth lobe, leaning to one side: a curved teardrop with a rounded top and a
       pointed base. The two sides are different curves and there is no dip anywhere, so
       it can never read as a heart when it tumbles. Drawn in a unit box (-.5 to .5). */
    x.translate(S / 2, S / 2); x.scale(S * .86, S * .92);
    x.beginPath();
    x.moveTo(.03, .5);
    x.bezierCurveTo(.38, .3, .44, -.2, .14, -.46);
    x.bezierCurveTo(.05, -.53, -.1, -.5, -.2, -.38);
    x.bezierCurveTo(-.5, -.1, -.28, .34, .03, .5);
    x.closePath();
    /* paler toward the tip, richer toward the broad end */
    const gr = x.createLinearGradient(0, .5, 0, -.45);
    gr.addColorStop(0, `rgba(${Math.min(255, r + 70)},${Math.min(255, g + 90)},${Math.min(255, b + 80)},${a * .8})`);
    gr.addColorStop(1, `rgba(${r},${g},${b},${a})`);
    x.fillStyle = gr; x.fill();
    return c;
  }
  const SPRITES = TINTS.map(petalSprite);

  function create(cap) {
    const list = [];
    for (let i = 0; i < cap; i++) list.push({ life: 1, max: 1 });
    let next = 0, size = cap;
    const field = {
      get cap() { return size; },
      live: 0,
      setCap(n) { size = Math.max(1, Math.min(n, list.length)); next %= size; },
      emit(x, y, vx, vy) {
        if (trailOff()) return;
        const p = list[next]; next = (next + 1) % size;
        const a = rnd(0, Math.PI * 2), burst = rnd(14, 60);        /* the gentle outward burst */
        p.x = x + rnd(-3, 3); p.y = y + rnd(-3, 3);
        p.vx = (vx || 0) + Math.cos(a) * burst; p.vy = (vy || 0) + Math.sin(a) * burst - 10;
        p.life = 0; p.max = rnd(1.2, 2.0);
        p.sz = rnd(7, 14) * rnd(.9, 1.15);                          /* depth: nearer petals read larger */
        p.tint = (Math.random() * SPRITES.length) | 0;
        p.rot = rnd(0, Math.PI * 2); p.spin = rnd(-3, 3);           /* 2D tumble, rad/s */
        p.flip = rnd(0, Math.PI); p.flipV = rnd(2.5, 5);            /* 3D flip, rad/s */
        p.ph = rnd(0, Math.PI * 2);                                 /* wind phase */
      },
      /* advance and paint every live petal; the caller clears the canvas first */
      draw(ctx, dt, dpr, clock) {
        let live = 0;
        for (let i = 0; i < size; i++) {
          const p = list[i];
          if (p.life >= p.max) continue;
          p.life += dt;
          const u = p.life / p.max;
          if (u >= 1) continue;                                     /* dead: the slot is reusable */
          live++;
          p.vx *= 1 - 1.5 * dt; p.vy *= 1 - 1.5 * dt;               /* air drag */
          p.vy += 46 * dt;                                          /* gravity */
          p.vx += Math.sin(clock * 2.2 + p.ph) * 34 * dt;           /* wind turbulence */
          p.x += p.vx * dt; p.y += p.vy * dt;
          p.rot += p.spin * dt; p.flip += p.flipV * dt;
          const a = Math.min(1, u / .08) * (1 - u);                 /* quick fade-in, then linear decay */
          if (a <= .004) continue;
          const sy = .28 + .72 * Math.abs(Math.cos(p.flip));        /* edge-on as it flips */
          const w = p.sz * (1 - .18 * u);
          const c = Math.cos(p.rot) * dpr, s = Math.sin(p.rot) * dpr;
          ctx.globalAlpha = a;
          ctx.setTransform(c, s, -s * sy, c * sy, p.x * dpr, p.y * dpr);
          ctx.drawImage(SPRITES[p.tint], -w / 2, -w / 2, w, w);
        }
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.globalAlpha = 1;
        field.live = live;
        return live;
      },
    };
    return field;
  }

  window.HastraPetals = { create };
})();
