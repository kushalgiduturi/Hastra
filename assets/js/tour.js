// assets/js/tour.js
// Zero-dependency spotlight tour engine for Hastra. Reads its step list from
// window.ASTRA_TOUR_CONFIGS (assets/js/tour-config.js), keyed by the current
// page's <body data-tour-page="...">. State is cached in localStorage and
// backed by api/tour_status.php so a finished/skipped tour never runs again
// for that user on that page — until they hit "Restart tour".
(function () {
  'use strict';

  var CACHE_PREFIX = 'astra_tour_v1_';
  var AUTO_START_DELAY = 700;   // let the page settle before an unprompted tour pops up
  var SCROLL_SETTLE_DELAY = 380;
  var SPOTLIGHT_PAD = 8;

  var state = {
    pageKey: null,
    steps: [],       // full config for the page
    active: [],       // steps whose target currently resolves to a visible element
    index: 0,
    els: null,        // { backdropTop, backdropBottom, backdropLeft, backdropRight, ring, popover }
    onResize: null,
    onKeydown: null
  };

  function baseUrl() {
    return window.ASTRA_BASE_URL || '/';
  }

  // ── localStorage cache ───────────────────────────────────────────────────
  function cacheGet(pageKey) {
    try { return window.localStorage.getItem(CACHE_PREFIX + pageKey); } catch (e) { return null; }
  }
  function cacheSet(pageKey, status) {
    try { window.localStorage.setItem(CACHE_PREFIX + pageKey, status); } catch (e) { /* private mode etc. */ }
  }

  // ── Server sync (best-effort — a failed request just means the tour may
  //    show again next visit, which is a harmless default) ─────────────────
  function fetchStatus(pageKey, cb) {
    fetch(baseUrl() + 'api/tour_status.php?page=' + encodeURIComponent(pageKey), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { cb(data && data.ok ? data.status : null); })
      .catch(function () { cb(null); });
  }

  function postStatus(pageKey, status) {
    cacheSet(pageKey, status);
    if (!window.ASTRA_CSRF_TOKEN) return;
    var body = new URLSearchParams({ csrf_token: window.ASTRA_CSRF_TOKEN, page: pageKey, status: status });
    fetch(baseUrl() + 'api/tour_status.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).catch(function () { /* best-effort */ });
  }

  // ── DOM scaffold (built once, reused across steps) ───────────────────────
  function buildScaffold() {
    var wrap = document.createElement('div');
    wrap.className = 'hastra-tour-root';
    wrap.innerHTML =
      '<div class="hastra-tour-mask hastra-tour-mask-top"></div>' +
      '<div class="hastra-tour-mask hastra-tour-mask-bottom"></div>' +
      '<div class="hastra-tour-mask hastra-tour-mask-left"></div>' +
      '<div class="hastra-tour-mask hastra-tour-mask-right"></div>' +
      '<div class="hastra-tour-ring"></div>' +
      '<div class="hastra-tour-popover" role="dialog" aria-modal="true">' +
        '<div class="hastra-tour-popover-head">' +
          '<span class="hastra-tour-step-label"></span>' +
          '<button type="button" class="hastra-tour-close" aria-label="Close tour">&times;</button>' +
        '</div>' +
        '<h3 class="hastra-tour-title"></h3>' +
        '<p class="hastra-tour-text"></p>' +
        '<div class="hastra-tour-actions">' +
          '<button type="button" class="hastra-tour-btn hastra-tour-btn-ghost" data-action="skip">Skip tour</button>' +
          '<div class="hastra-tour-nav-btns">' +
            '<button type="button" class="hastra-tour-btn hastra-tour-btn-secondary" data-action="back">Back</button>' +
            '<button type="button" class="hastra-tour-btn hastra-tour-btn-primary" data-action="next">Next</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    // Appended to <html>, not <body>: core/theme.css runs a permanent
    // `body { animation: pageFadeIn ... both }` page-load transition, whose
    // fill-mode leaves `transform: translateY(0)` sitting on <body> forever.
    // A non-"none" transform on an ancestor creates a new containing block
    // for position:fixed descendants, so anchoring here on <body> made the
    // whole overlay track the document instead of the viewport — correct
    // until the very first scroll, then silently drifting off-screen by
    // however far the page had scrolled. <html> carries no such transform.
    document.documentElement.appendChild(wrap);

    var els = {
      root: wrap,
      top: wrap.querySelector('.hastra-tour-mask-top'),
      bottom: wrap.querySelector('.hastra-tour-mask-bottom'),
      left: wrap.querySelector('.hastra-tour-mask-left'),
      right: wrap.querySelector('.hastra-tour-mask-right'),
      ring: wrap.querySelector('.hastra-tour-ring'),
      popover: wrap.querySelector('.hastra-tour-popover'),
      stepLabel: wrap.querySelector('.hastra-tour-step-label'),
      title: wrap.querySelector('.hastra-tour-title'),
      text: wrap.querySelector('.hastra-tour-text'),
      close: wrap.querySelector('.hastra-tour-close'),
      skipBtn: wrap.querySelector('[data-action="skip"]'),
      backBtn: wrap.querySelector('[data-action="back"]'),
      nextBtn: wrap.querySelector('[data-action="next"]')
    };

    els.close.addEventListener('click', function () { finish('skipped'); });
    els.skipBtn.addEventListener('click', function () { finish('skipped'); });
    els.backBtn.addEventListener('click', goBack);
    els.nextBtn.addEventListener('click', goNext);

    // Fail-safe: clicking anywhere on the dimmed backdrop (not the popover
    // itself) aborts the tour, so a mispositioned or unreachable popover can
    // never trap the page behind an unclickable overlay.
    wrap.addEventListener('click', function (e) {
      if (e.target && e.target.classList && e.target.classList.contains('hastra-tour-mask')) {
        finish('skipped');
      }
    });

    return els;
  }

  // Idempotent — safe to call multiple times, and safe to call even if the
  // scaffold only partially built (e.g. a step handler threw mid-render).
  function teardownScaffold() {
    try {
      if (state.els && state.els.root && state.els.root.parentNode) {
        state.els.root.parentNode.removeChild(state.els.root);
      }
    } catch (e) { /* ignore — best-effort cleanup */ }
    state.els = null;

    try {
      if (state.onResize) { window.removeEventListener('resize', state.onResize); window.removeEventListener('scroll', state.onResize, true); }
      if (state.onKeydown) { document.removeEventListener('keydown', state.onKeydown); }
    } catch (e) { /* ignore */ }
    state.onResize = null;
    state.onKeydown = null;

    document.body.classList.remove('hastra-tour-open');

    // Safety net: nuke any stray overlay nodes even if state.els got out of
    // sync with the DOM (e.g. start() was re-entered mid-teardown).
    try {
      var stray = document.querySelectorAll('.hastra-tour-root');
      for (var i = 0; i < stray.length; i++) {
        if (stray[i].parentNode) stray[i].parentNode.removeChild(stray[i]);
      }
    } catch (e) { /* ignore */ }
  }

  // ── Visibility helper — never throws, a bad/missing selector is just "not
  //    found" so a step targeting it is skipped instead of crashing the tour ──
  function findVisible(selector) {
    try {
      var el = document.querySelector(selector);
      if (!el) return null;
      var rect = el.getBoundingClientRect();
      if (rect.width === 0 && rect.height === 0) return null;
      var style = window.getComputedStyle(el);
      if (style.display === 'none' || style.visibility === 'hidden') return null;
      return el;
    } catch (e) {
      return null;
    }
  }

  // ── Positioning ────────────────────────────────────────────────────────────
  function positionFor(el) {
    var rect = el.getBoundingClientRect();
    var vw = window.innerWidth, vh = window.innerHeight;
    var pad = SPOTLIGHT_PAD;
    var box = {
      top: Math.max(0, rect.top - pad),
      left: Math.max(0, rect.left - pad),
      right: Math.min(vw, rect.right + pad),
      bottom: Math.min(vh, rect.bottom + pad)
    };
    box.width = box.right - box.left;
    box.height = box.bottom - box.top;

    var els = state.els;
    els.top.style.cssText = 'top:0;left:0;right:0;height:' + Math.max(0, box.top) + 'px;';
    els.bottom.style.cssText = 'top:' + box.bottom + 'px;left:0;right:0;bottom:0;';
    els.left.style.cssText = 'top:' + box.top + 'px;left:0;width:' + Math.max(0, box.left) + 'px;height:' + box.height + 'px;';
    els.right.style.cssText = 'top:' + box.top + 'px;left:' + box.right + 'px;right:0;height:' + box.height + 'px;';
    els.ring.style.cssText = 'top:' + box.top + 'px;left:' + box.left + 'px;width:' + box.width + 'px;height:' + box.height + 'px;';

    positionPopover(box, vw, vh);
  }

  function positionPopover(box, vw, vh) {
    var pop = state.els.popover;
    pop.style.visibility = 'hidden';
    pop.style.display = 'block';
    var pw = pop.offsetWidth || 320;
    var ph = pop.offsetHeight || 160;
    var gap = 14;

    var spaceBelow = vh - box.bottom;
    var spaceAbove = box.top;
    var top, placement;
    if (spaceBelow >= ph + gap || spaceBelow >= spaceAbove) {
      top = box.bottom + gap;
      placement = 'bottom';
    } else {
      top = box.top - ph - gap;
      placement = 'top';
    }
    top = Math.max(10, Math.min(top, vh - ph - 10));

    var left = box.left;
    left = Math.max(10, Math.min(left, vw - pw - 10));

    pop.style.top = top + 'px';
    pop.style.left = left + 'px';
    pop.className = 'hastra-tour-popover hastra-tour-popover-' + placement;
    pop.style.visibility = 'visible';
  }

  // ── Step rendering ───────────────────────────────────────────────────────
  // Wrapped end-to-end: a step whose target is missing/broken is skipped, and
  // any unexpected error closes the tour cleanly rather than leaving a
  // half-built overlay stuck on screen.
  function renderStep() {
    try {
      var step = state.active[state.index];
      if (!step) { finish('completed'); return; }

      var el = findVisible(step.selector);
      if (!el) {
        // Target missing or not loaded yet (e.g. an AJAX update, a
        // permission-gated element, an empty table) — drop it and retry the
        // next one instead of breaking the tour.
        state.active.splice(state.index, 1);
        if (state.index >= state.active.length) state.index = state.active.length - 1;
        renderStep();
        return;
      }

      var els = state.els;
      if (!els) { finish('skipped'); return; }
      els.stepLabel.textContent = 'STEP ' + (state.index + 1) + ' OF ' + state.active.length;
      els.title.textContent = step.title;
      els.text.textContent = step.text;
      els.backBtn.style.visibility = state.index === 0 ? 'hidden' : 'visible';
      els.nextBtn.textContent = state.index === state.active.length - 1 ? 'Finish' : 'Next';

      el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
      window.setTimeout(function () {
        try {
          if (!state.els) return; // tour may have been closed during the scroll
          positionFor(el);
        } catch (e) {
          console.warn('Hastra tour: could not position a step, so the tour is closing to avoid getting stuck.', e);
          finish('skipped');
        }
      }, SCROLL_SETTLE_DELAY);
    } catch (e) {
      console.warn('Hastra tour: a step failed to render, so the tour is closing to avoid getting stuck.', e);
      finish('skipped');
    }
  }

  function goNext() {
    if (state.index < state.active.length - 1) { state.index++; renderStep(); }
    else finish('completed');
  }
  function goBack() {
    if (state.index > 0) { state.index--; renderStep(); }
  }

  // Always safe to call — the single choke point that guarantees the mask
  // and body class are gone, however the tour ended (finished, skipped,
  // mask click, Escape, or an internal error).
  function finish(status) {
    try {
      if (state.pageKey) postStatus(state.pageKey, status);
    } catch (e) { /* non-fatal — status just won't be persisted this time */ }
    teardownScaffold();
    state.active = [];
    state.index = 0;
  }

  // ── Public: start a tour right now, regardless of prior status ───────────
  function start(pageKey, steps) {
    try {
      if (!steps || !steps.length) return;
      var active = [];
      for (var i = 0; i < steps.length; i++) {
        if (findVisible(steps[i].selector)) active.push(steps[i]);
      }
      if (!active.length) return; // nothing on this page to point at right now

      if (state.els) teardownScaffold(); // a tour was already open — restart cleanly

      state.pageKey = pageKey;
      state.steps = steps;
      state.active = active;
      state.index = 0;
      state.els = buildScaffold();
      document.body.classList.add('hastra-tour-open');

      state.onResize = debounce(function () {
        try {
          var step = state.active[state.index];
          if (!step) return;
          var el = findVisible(step.selector);
          if (el) positionFor(el);
        } catch (e) { /* non-fatal — keep the last good position */ }
      }, 120);
      window.addEventListener('resize', state.onResize);
      window.addEventListener('scroll', state.onResize, true);

      state.onKeydown = function (e) { if (e.key === 'Escape') finish('skipped'); };
      document.addEventListener('keydown', state.onKeydown);

      renderStep();
    } catch (e) {
      console.warn('Hastra tour: failed to start. Cleaning up so the page stays usable.', e);
      teardownScaffold();
    }
  }

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var args = arguments;
      window.clearTimeout(t);
      t = window.setTimeout(function () { fn.apply(null, args); }, ms);
    };
  }

  // ── Public: restart the current (or given) page's tour on demand ─────────
  function restart(pageKey) {
    try {
      var key = pageKey || document.body.getAttribute('data-tour-page');
      var configs = window.ASTRA_TOUR_CONFIGS || {};
      if (!key || !configs[key]) return; // no tour defined for this page — nothing to do
      cacheSet(key, null);
      start(key, configs[key]);
    } catch (e) {
      console.warn('Hastra tour: restart failed.', e);
    }
  }

  // ── Boot: auto-start an unseen tour, wire up any "?" restart buttons ─────
  // Wrapped so a bad config or missing page hook never blocks the rest of
  // the page's own scripts from running.
  function init() {
    try {
      var key = document.body.getAttribute('data-tour-page');
      var configs = window.ASTRA_TOUR_CONFIGS || {};
      var restartBtns = document.querySelectorAll('.tour-restart-btn');

      if (!key || !configs[key]) {
        // No tour on this page — hide any restart button so it isn't a dead click.
        for (var i = 0; i < restartBtns.length; i++) restartBtns[i].style.display = 'none';
        return;
      }

      for (var j = 0; j < restartBtns.length; j++) {
        restartBtns[j].addEventListener('click', function () { restart(); });
      }

      var cached = cacheGet(key);
      if (cached === 'completed' || cached === 'skipped') return; // already handled, no network call needed

      fetchStatus(key, function (status) {
        if (status === 'completed' || status === 'skipped') { cacheSet(key, status); return; }
        waitForProfileBarrier(function () {
          window.setTimeout(function () {
            try { start(key, configs[key]); } catch (e) { console.warn('Hastra tour: auto-start failed.', e); }
          }, AUTO_START_DELAY);
        });
      });
    } catch (e) {
      console.warn('Hastra tour: init failed. The page works normally without the guided tour.', e);
    }
  }

  // The mandatory profile-completion barrier (core/auth_check.php) also
  // renders unconditionally on every authenticated page, at a lower
  // z-index than the tour (500 vs 9000) — so without this guard, a tour
  // auto-starting for a user who also hasn't set their gender yet would
  // pop up its popover directly on top of that modal, overlapping it.
  // The barrier is unclosable except by completing it (it removes itself
  // from the DOM on success — see assets/js/profile-barrier.js), so wait
  // for that removal before ever starting a tour.
  function waitForProfileBarrier(cb) {
    var overlay = document.getElementById('profileBarrierOverlay');
    if (!overlay) { cb(); return; }
    var observer = new MutationObserver(function () {
      if (!document.getElementById('profileBarrierOverlay')) {
        observer.disconnect();
        cb();
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  window.HastraTour = { start: start, restart: restart, init: init };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
