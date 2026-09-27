/* Hastra — bento card spotlight (About.jsx / Expertise.jsx).
   One delegated pointer listener writes the pointer position into the hovered
   card's --mouse-x / --mouse-y; netflix-bento.css paints the radial glow there.
   Delegation means cards rendered later (modals, AJAX lists) work too. */
(function () {
  'use strict';
  if (window.matchMedia && matchMedia('(hover: none) and (pointer: coarse)').matches) return;

  const CARDS = '.netflix-bento-card, .main .stat-card, .main .section, .main .bug-card, ' +
                '.main .section-nav-card, .main .ledger, .main .card, .dash-card';

  // tag the existing portal cards so anything keyed on the class (and tests) sees them
  function tag() { document.querySelectorAll(CARDS).forEach(function (el) { el.classList.add('netflix-bento-card'); }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tag); else tag();

  let pending = null, frame = 0;
  document.addEventListener('pointermove', function (e) {
    const card = e.target.closest && e.target.closest(CARDS);
    if (!card) return;
    pending = { card: card, x: e.clientX, y: e.clientY };
    if (!frame) frame = requestAnimationFrame(function () {
      frame = 0;
      const p = pending, rect = p.card.getBoundingClientRect();
      p.card.style.setProperty('--mouse-x', (p.x - rect.left) + 'px');
      p.card.style.setProperty('--mouse-y', (p.y - rect.top) + 'px');
    });
  }, { passive: true });
})();

/* the button that submitted a form traces a faster laser beam while the
   request is in flight (netflix-bento.css, button.is-processing) */
document.addEventListener('submit', function (e) {
  if (e.defaultPrevented) return;
  const b = e.submitter || (e.target.querySelector && e.target.querySelector('button[type="submit"]'));
  if (b) b.classList.add('is-processing');
});
window.addEventListener('pageshow', function () {
  document.querySelectorAll('button.is-processing').forEach(function (b) { b.classList.remove('is-processing'); });
});
