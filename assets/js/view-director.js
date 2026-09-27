/* ═══════════════════════════════════════════════════════════════════════
   View director: ties the atmosphere's camera to where you are in Hastra.

   Every page belongs to one waypoint on the shared scene's rig
   (assets/js/kage-scene.js, the landing world's own camera curve):
   dashboards at the gate, work queues on the approach, the ledger at the
   court, security up at the hall, settings out on the rise, and sign-in
   face on to the hall, where the login hand rises. Crossing between sections is a flight: the last
   page leaves its position on the spline in sessionStorage, and the next
   page's rig starts there and eases along the Catmull-Rom curve to its own
   waypoint. Inside a page, scrolling walks a short way further down the
   same curve.

   It also runs the landing engine's reveal grammar. [data-rv="up"] elements rise in as
   they enter view, and section headers are split into word masks that
   arrive at a reading pace (72 ms apart), with the original text kept as
   the heading's accessible label. Only plain-text headers are split: a
   header holding a badge, icon or link is revealed whole, never rewritten.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__hastraDirector) return;
  window.__hastraDirector = true;
  const REDUCE = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const KEY = 'astra_cam_u';

  /* page → waypoint, matched on the final path segment */
  const MAP = [
    [/^(login|otp|forgot|forgot_otp|reset|register|verify_register|set_password|signin|signup|verify|verify-email|forgot-password|reset-code|reset-password|set-password)$/, 0],
    /* role homes (/workspace/admin/ …) and the workspace index sit at the gate */
    [/^(workspace|admin|client|sysadmin|employee|user)$/, 0],   /* the hall, face on: the hand rises out of it */
    [/security|scan[_-]center|logs$/, 3],
    [/profile|roles|migrate|docs?$|doc[_-]|settings/, 4],
    [/billing|delivery|dossier|terminal|signoff|ledger|audit/, 2],
    [/task|team|requirement|project|testing|deploy|directory|employee$|create[_-]employee|attendance|leave/, 1],
    [/portal|^index$|^$/, 0]
  ];
  function pageWaypoint() {
    const seg = location.pathname.toLowerCase().split('/').filter(Boolean);
    const page = (seg[seg.length - 1] || '').replace(/\.php$/, '');
    for (const [re, u] of MAP) if (re.test(page)) return u;
    return 0;
  }

  /* ------------------------------------------------------------ the flight */
  function fly() {
    const A = window.HastraAtmosphere; if (!A) return;
    const to = pageWaypoint();
    let from = NaN;
    try { from = parseFloat(sessionStorage.getItem(KEY)); } catch (e) { /* private mode */ }
    if (isFinite(from) && Math.abs(from - to) > .01 && !REDUCE) A.flyTo(to, { from, duration: 1.5 + Math.min(Math.abs(from - to), 3) * .25 });
    else A.flyTo(to, { instant: true });
    const save = () => { try { sessionStorage.setItem(KEY, String(A.u)); } catch (e) { /* ignore */ } };
    addEventListener('pagehide', save);
    /* theme.js holds a link for 160 ms while it fades; save at the click */
    document.addEventListener('click', e => { if (e.target.closest && e.target.closest('a[href]')) save(); }, true);
    document.addEventListener('submit', save, true);

    let ticking = false;
    const onScroll = () => {
      if (ticking) return; ticking = true;
      requestAnimationFrame(() => {
        ticking = false;
        const max = document.documentElement.scrollHeight - innerHeight;
        A.setScroll(max > 40 ? (scrollY / max) * .6 : 0);
      });
    };
    addEventListener('scroll', onScroll, { passive: true }); onScroll();
  }

  /* ------------------------------------------------------------ reveals */
  const HEADERS = '.main h1, .main h2, .sc-main h1, .sc-main h2, .page-header h1, .section-header h2, .section-header h3, .auth-card h1, .card-title';

  function splitWords(el) {
    if (el.dataset.wordReady) return;
    /* plain text only: never rewrite a header that carries markup */
    for (const n of el.childNodes) if (n.nodeType === 1) return false;
    const phrase = el.textContent.replace(/\s+/g, ' ').trim();
    if (!phrase || phrase.length > 140) return false;
    el.dataset.wordReady = 'true';
    el.classList.add('word-reveal');
    el.setAttribute('aria-label', phrase);
    el.textContent = '';
    phrase.split(' ').forEach((word, i) => {
      if (i) el.appendChild(document.createTextNode(' '));
      const mask = document.createElement('span'), inner = document.createElement('span');
      mask.className = 'word-mask'; mask.setAttribute('aria-hidden', 'true');
      inner.className = 'word'; inner.textContent = word;
      inner.style.setProperty('--word-delay', (i * 72) + 'ms');
      mask.appendChild(inner); el.appendChild(mask);
    });
    return true;
  }

  function reveals() {
    if (REDUCE) return;
    document.querySelectorAll(HEADERS).forEach(h => {
      if (h.closest('[data-rv-off]') || h.hasAttribute('data-rv')) return;
      splitWords(h);
      h.setAttribute('data-rv', 'up');
    });
    const items = Array.from(document.querySelectorAll('[data-rv]'));
    /* siblings stagger 85 ms apart, as in the engine's wireReveals */
    const groups = new Map();
    items.forEach(el => { const k = el.parentElement, a = groups.get(k) || []; a.push(el); groups.set(k, a); });
    groups.forEach(a => a.forEach((el, i) => { el.dataset.rvd = i * 85; }));
    const io = new IntersectionObserver(es => es.forEach(e => {
      if (!e.isIntersecting) return;
      io.unobserve(e.target);
      setTimeout(() => e.target.classList.add('rv-in'), +e.target.dataset.rvd || 0);
    }), { rootMargin: '0px 0px -8% 0px', threshold: .04 });
    items.forEach(el => io.observe(el));
    document.documentElement.classList.add('hastra-rv');
  }

  function init() {
    reveals();
    if (window.HastraAtmosphere) fly();                 /* the scene queues the flight until it is up */
    else document.addEventListener('hastra:atmosphere-ready', fly, { once: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
