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
    var selected = null;

    function setBusy(busy) {
      opts.forEach(function (b) { b.disabled = busy; });
      submit.disabled = busy || !selected;
    }

    function showError(message) {
      error.textContent = message;
      error.classList.add('show');
    }

    opts.forEach(function (btn) {
      btn.addEventListener('click', function () {
        opts.forEach(function (b) { b.classList.remove('selected'); });
        btn.classList.add('selected');
        selected = btn.dataset.gender;
        error.classList.remove('show');
        submit.disabled = false;
      });
    });

    submit.addEventListener('click', function () {
      if (!selected) return;
      error.classList.remove('show');
      setBusy(true);

      var body = new URLSearchParams();
      body.set('csrf_token', csrf);
      body.set('gender', selected);

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
