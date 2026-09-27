// Hastra — Gooey Search component behavior (vanilla JS, no build step).
//
// Wires every `.gooey-search-root` on the page: click/focus expands the pill
// and focuses its real <input>, blur collapses it back down if it's empty.
// The `.gooey-input` inside each root IS the page's real search/filter
// input (same id, same value) — not a decorative proxy in front of a hidden
// original — so existing `oninput="filterReqs()"` etc. handlers on it keep
// firing as ordinary native `input` events. Nothing here needs to synthesize
// or forward events between two separate elements.
(function () {
  function initGooeySearch(root) {
    const trigger = root.querySelector('.gooey-trigger');
    const input   = root.querySelector('.gooey-input');
    const iconBtn = root.querySelector('.gooey-icon-btn');
    if (!trigger || !input) return;
    if (root.dataset.gooeyInit === '1') return; // idempotent if called twice
    root.dataset.gooeyInit = '1';

    function expand() {
      trigger.classList.add('expanded');
    }

    function collapseIfEmpty() {
      if (input.value.trim() !== '') return; // keep it open while a filter is active
      trigger.classList.remove('expanded');
    }

    trigger.addEventListener('click', function () {
      expand();
      input.focus();
    });
    if (iconBtn) {
      iconBtn.addEventListener('click', function (e) {
        e.stopPropagation(); // don't double-fire the trigger's own click handler
        expand();
        input.focus();
      });
    }
    input.addEventListener('focus', expand);
    input.addEventListener('blur', function () {
      // Deferred so a click on something inside the same pill (e.g. the icon
      // button) doesn't collapse the field out from under that click.
      setTimeout(collapseIfEmpty, 120);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.blur();
      }
    });

    // A page that pre-fills the field (e.g. a filter restored from the URL
    // or session) should render already expanded, not clipped inside a
    // 115px chip.
    if (input.value.trim() !== '') expand();
  }

  function init() {
    document.querySelectorAll('.gooey-search-root').forEach(initGooeySearch);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Exposed so a page that injects a gooey search root dynamically (e.g.
  // after an AJAX re-render) can wire it up without a full re-init.
  window.hastraInitGooeySearch = initGooeySearch;
})();
