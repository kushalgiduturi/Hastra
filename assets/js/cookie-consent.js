/* Hastra — cookie consent and third-party embed guard.
 *
 * State lives in localStorage under hastra_consent_state:
 *   { v: 1, essential: true, analytics: bool, thirdparty: bool, ts: ISO }
 *
 * Nothing non-essential runs before a stored "yes":
 *   <script type="text/plain" data-consent="analytics" data-src="…">  activated on consent
 *   <img|iframe data-consent="thirdparty" data-consent-src="…">       loaded on consent, or
 *       replaced by a "Click to load external asset" placeholder that loads that one item
 *   HastraConsent.whenAllowed('thirdparty', fn)                           for script-driven lookups
 */
(function () {
  'use strict';
  var KEY = 'hastra_consent_state';
  var LEGACY_KEY = 'astra_consent_state'; // pre-rename key, read once for migration
  var CATS = ['analytics', 'thirdparty'];
  var listeners = [];

  function read() {
    try {
      var s = JSON.parse(localStorage.getItem(KEY) || localStorage.getItem(LEGACY_KEY) || 'null');
      return s && s.v === 1 ? s : null;
    } catch (e) { return null; }
  }
  function write(choice) {
    var s = { v: 1, essential: true, analytics: !!choice.analytics, thirdparty: !!choice.thirdparty, ts: new Date().toISOString() };
    try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) { /* storage blocked: choice lasts this page only */ }
    state = s;
    return s;
  }
  var state = read();

  function allows(cat) { return cat === 'essential' || !!(state && state[cat]); }

  function apply() {
    document.querySelectorAll('script[type="text/plain"][data-consent]').forEach(function (old) {
      if (!allows(old.dataset.consent)) return;
      var s = document.createElement('script');
      if (old.dataset.src) s.src = old.dataset.src; else s.text = old.text;
      old.replaceWith(s);
    });
    document.querySelectorAll('[data-consent-src]').forEach(function (el) {
      if (allows(el.dataset.consent || 'thirdparty')) load(el); else guard(el);
    });
    listeners.slice().forEach(function (l) { if (allows(l.cat)) { l.fn(); listeners.splice(listeners.indexOf(l), 1); } });
  }

  function load(el) {
    var ph = el.previousElementSibling;
    if (ph && ph.classList.contains('consent-ph')) ph.remove();
    el.hidden = false;
    el.src = el.dataset.consentSrc;
    el.removeAttribute('data-consent-src');
  }

  function guard(el) {
    if (el.hidden) return;
    el.hidden = true;
    var host = '';
    try { host = new URL(el.dataset.consentSrc, location.href).hostname; } catch (e) { /* relative */ }
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'consent-ph';
    b.textContent = 'Click to load external asset' + (host ? ' from ' + host : '');
    b.addEventListener('click', function () { load(el); });
    el.before(b);
  }

  /* ── banner ── */
  var bar;
  function syncToggles() {
    if (!bar) return;
    bar.querySelectorAll('[data-consent-cat]').forEach(function (i) { i.checked = allows(i.dataset.consentCat); });
  }
  function show() { if (!bar) return; syncToggles(); bar.hidden = false; }
  function choose(action) {
    var c = {};
    CATS.forEach(function (k) {
      var i = bar.querySelector('[data-consent-cat="' + k + '"]');
      c[k] = action === 'accept' ? true : action === 'reject' ? false : !!(i && i.checked);
    });
    write(c);
    bar.hidden = true;
    apply();
    document.dispatchEvent(new CustomEvent('hastra:consent', { detail: state }));
  }

  function init() {
    bar = document.getElementById('hastra-consent');
    if (bar) {
      bar.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-consent-action]');
        if (btn) choose(btn.dataset.consentAction);
      });
      if (!state) show();
    }
    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-consent-open]')) { e.preventDefault(); show(); if (bar) bar.querySelector('button, input:not([disabled])').focus(); }
    });
    apply();
  }

  window.HastraConsent = {
    get: function () { return state; },
    allows: allows,
    open: show,
    whenAllowed: function (cat, fn) { if (allows(cat)) fn(); else listeners.push({ cat: cat, fn: fn }); },
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
