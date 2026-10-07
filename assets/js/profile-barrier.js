// ── Mandatory profile-completion barrier controller ──────────────────────
// Deliberately has no close/dismiss path — the overlay only disappears once
// the server confirms the gender was saved. No Escape handler, no backdrop
// click-to-close, unlike every other modal in this app.
(function () {
  'use strict';

  function init() {
    var overlay = document.getElementById('profileBarrierOverlay');
    if (!overlay) return;

    var csrf     = overlay.dataset.csrf;
    var endpoint = overlay.dataset.endpoint;
    var error    = document.getElementById('pbError');
    var submit   = document.getElementById('pbSubmit');
    var opts     = overlay.querySelectorAll('.pb-opt');
    var needsFocus = overlay.dataset.needsFocus === '1';   // people outside an organization pick a role
    var selected = null;      // gender
    var focus    = null;      // role label (independent people only)

    function ready() { return !!selected && (!needsFocus || !!focus); }
    function setBusy(busy) {
      opts.forEach(function (b) { b.disabled = busy; });
      submit.disabled = busy || !ready();
    }
    // a row of text choices behaves as one radio group
    function choose(btn) {
      btn.parentNode.querySelectorAll('.pb-opt').forEach(function (b) {
        b.classList.remove('selected'); b.setAttribute('aria-checked', 'false');
      });
      btn.classList.add('selected'); btn.setAttribute('aria-checked', 'true');
    }

    function showError(message) {
      error.textContent = message;
      error.classList.add('show');
    }

    opts.forEach(function (btn) {
      btn.addEventListener('click', function () {
        choose(btn);
        if (btn.dataset.gender) selected = btn.dataset.gender;
        if (btn.dataset.focus) focus = btn.dataset.focus;
        error.classList.remove('show');
        submit.disabled = !ready();
      });
    });

    submit.addEventListener('click', function () {
      if (!ready()) return;
      error.classList.remove('show');
      setBusy(true);

      var body = new URLSearchParams();
      body.set('csrf_token', csrf);
      body.set('gender', selected);
      if (needsFocus) body.set('focus', focus);

      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            overlay.classList.add('pb-done');
            setTimeout(function () { overlay.remove(); }, 320);
          } else {
            setBusy(false);
            showError(data.message || 'Something went wrong. Please try again.');
          }
        })
        .catch(function () {
          setBusy(false);
          showError('Network error. Please try again.');
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
