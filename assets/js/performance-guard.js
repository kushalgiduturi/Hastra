/* Hastra performance guard.

   Decides, once and as early as possible, whether this device should run the
   light version of the heavy visuals, and lets the scene, the rings and the
   petal trail read the answer. Classic script, no dependencies, safe to load
   twice (the landing scene runs in an iframe and loads its own copy).

   "Low" when any of these is true:
     - 4 or fewer logical CPU cores        (navigator.hardwareConcurrency)
     - 4 GB of memory or less              (navigator.deviceMemory)
     - a phone or tablet user agent
     - the browser's data-saver flag is on (navigator.connection.saveData)
     - measured: after the page has loaded, the median frame takes longer
       than 33 ms (under 30 fps). This catches slow GPUs that report strong
       hardware, and it is the only signal that can switch the mode on late.

   Effect, read by the consumers:
     window.HASTRA_PERF = { low, reasons[], petalCap, petalStep }
     html.low-perf-mode            (CSS drops the glass blur)
     a "hastra:perf" event on window when the mode flips after start-up
   The landing scene drops to a quarter of its leaves and embers and renders at
   device-pixel-ratio 1; the petal trail keeps fewer petals and spawns them
   less often; Labs panels lose their backdrop blur. */
(function () {
  'use strict';
  if (window.HASTRA_PERF) return;
  const nav = navigator;
  const reasons = [];
  const cores = nav.hardwareConcurrency || 0;
  const mem = nav.deviceMemory || 0;
  if (cores && cores <= 4) reasons.push('cpu:' + cores);
  if (mem && mem <= 4) reasons.push('memory:' + mem);
  const mobile = (nav.userAgentData && nav.userAgentData.mobile) || /Mobi|Android|iPhone|iPad/i.test(nav.userAgent);
  if (mobile) reasons.push('mobile');
  if (nav.connection && nav.connection.saveData) reasons.push('save-data');

  const P = window.HASTRA_PERF = { low: false, reasons: reasons, petalCap: 120, petalStep: 26 };
  function apply(late) {
    P.low = true; P.petalCap = 50; P.petalStep = 44;
    document.documentElement.classList.add('low-perf-mode');
    if (late) { try { window.dispatchEvent(new Event('hastra:perf')); } catch (e) { /* old browser */ } }
  }
  if (reasons.length) apply(false);

  // Measured fallback: only when the hardware looked fine.
  if (P.low || !window.requestAnimationFrame || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  function sample() {
    const dts = [];
    let last = 0;
    (function tick(now) {
      if (document.hidden) { last = 0; return void requestAnimationFrame(tick); }   // a hidden tab proves nothing
      if (last) { const dt = now - last; if (dt < 500) dts.push(dt); }
      last = now;
      if (dts.length < 45) return void requestAnimationFrame(tick);
      dts.sort(function (a, b) { return a - b; });
      const median = dts[dts.length >> 1];
      if (median > 33 && !P.low) { P.reasons.push('slow-frames:' + Math.round(median) + 'ms'); apply(true); }
    })(performance.now());
  }
  // start after load, once the page's own start-up work has settled
  function arm() { setTimeout(sample, 1500); }
  if (document.readyState === 'complete') arm(); else window.addEventListener('load', arm, { once: true });
})();
