// ── Custom Dropdown component ────────────────────────────────────────────
// Wraps every native <select> on the page with an animated slide-down menu
// while keeping the native <select> (name/id/required/value) intact so
// existing PHP form submissions and inline onchange="" handlers keep working
// unmodified. Selects added to the DOM later (e.g. dynamically injected
// rows) are picked up automatically via a MutationObserver.
(function () {
  'use strict';

  var registry = [];
  var uid = 0;

  function labelFor(select) {
    var opt = select.options[select.selectedIndex];
    return opt ? (opt.textContent || opt.value || '') : '';
  }

  function fillsParent(select) {
    var parent = select.parentElement;
    if (!parent) return false;
    var parentStyle = getComputedStyle(parent);
    var padX = parseFloat(parentStyle.paddingLeft) + parseFloat(parentStyle.paddingRight);
    var parentContentWidth = parent.clientWidth - padX;
    if (parentContentWidth <= 0) return false;
    var selRect = select.getBoundingClientRect();
    return (selRect.width / parentContentWidth) > 0.85;
  }

  function buildMenu(state) {
    var select = state.select, menu = state.menu;
    menu.innerHTML = '';
    state.optionEls = [];
    Array.prototype.forEach.call(select.options, function (opt, idx) {
      var item = document.createElement('div');
      item.className = 'cd-option';
      item.setAttribute('role', 'option');
      item.id = 'cd-opt-' + state.uid + '-' + idx;
      item.textContent = opt.textContent;
      if (opt.disabled) item.classList.add('cd-option-disabled');
      if (idx === select.selectedIndex) {
        item.classList.add('cd-option-active');
        item.setAttribute('aria-selected', 'true');
      } else {
        item.setAttribute('aria-selected', 'false');
      }
      item.addEventListener('mousedown', function (e) {
        e.preventDefault();
        if (opt.disabled) return;
        chooseIndex(state, idx);
        closeDropdown(state);
        state.trigger.focus();
      });
      menu.appendChild(item);
      state.optionEls.push(item);
    });
  }

  function syncTrigger(state) {
    var select = state.select, trigger = state.trigger;
    state.labelEl.textContent = labelFor(select);
    trigger.disabled = select.disabled;
    trigger.classList.toggle('cd-disabled', select.disabled);
  }

  function sync(state) {
    buildMenu(state);
    syncTrigger(state);
  }

  function chooseIndex(state, idx) {
    var select = state.select;
    if (select.selectedIndex === idx) {
      sync(state);
      return;
    }
    select.selectedIndex = idx;
    sync(state);
    select.dispatchEvent(new Event('change', { bubbles: true }));
    select.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function openDropdown(state) {
    if (state.select.disabled) return;
    closeAllExcept(state);
    sync(state);
    state.menu.classList.add('open');
    state.trigger.classList.add('cd-open');
    state.trigger.setAttribute('aria-expanded', 'true');
    state.open = true;
    var activeIdx = state.select.selectedIndex >= 0 ? state.select.selectedIndex : 0;
    highlight(state, activeIdx);
  }

  function closeDropdown(state) {
    state.menu.classList.remove('open');
    state.trigger.classList.remove('cd-open');
    state.trigger.setAttribute('aria-expanded', 'false');
    state.trigger.removeAttribute('aria-activedescendant');
    state.open = false;
  }

  function closeAllExcept(exceptState) {
    registry.forEach(function (state) {
      if (state !== exceptState && state.open) closeDropdown(state);
    });
  }

  function highlight(state, idx) {
    if (idx < 0 || idx >= state.optionEls.length) return;
    state.optionEls.forEach(function (el) { el.classList.remove('cd-option-highlight'); });
    var el = state.optionEls[idx];
    el.classList.add('cd-option-highlight');
    state.trigger.setAttribute('aria-activedescendant', el.id);
    state.highlightIndex = idx;
    el.scrollIntoView({ block: 'nearest' });
  }

  function moveHighlight(state, delta) {
    var n = state.optionEls.length;
    if (!n) return;
    var idx = state.highlightIndex;
    for (var i = 0; i < n; i++) {
      idx = (idx + delta + n) % n;
      if (!state.optionEls[idx].classList.contains('cd-option-disabled')) break;
    }
    highlight(state, idx);
  }

  function wrapSelect(select) {
    if (select.dataset.cdInit || select.multiple) return;
    select.dataset.cdInit = '1';

    var fill = fillsParent(select);

    var wrap = document.createElement('div');
    wrap.className = 'cd-wrap' + (fill ? ' cd-fill' : '');

    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);
    select.classList.add('cd-native-select');
    select.tabIndex = -1;

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'cd-trigger';
    trigger.setAttribute('role', 'combobox');
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.innerHTML = '<span class="cd-trigger-label"></span>' +
      '<svg class="cd-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5z"/></svg>';
    wrap.appendChild(trigger);

    var menu = document.createElement('div');
    var thisUid = ++uid;
    menu.id = 'cd-menu-' + thisUid;
    menu.className = 'cd-menu';
    menu.setAttribute('role', 'listbox');
    wrap.appendChild(menu);
    trigger.setAttribute('aria-controls', menu.id);

    var state = {
      uid: thisUid,
      select: select,
      wrap: wrap,
      trigger: trigger,
      menu: menu,
      labelEl: trigger.querySelector('.cd-trigger-label'),
      optionEls: [],
      open: false,
      highlightIndex: 0
    };

    sync(state);
    registry.push(state);

    trigger.addEventListener('click', function () {
      if (state.open) closeDropdown(state); else openDropdown(state);
    });

    trigger.addEventListener('keydown', function (e) {
      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault();
          if (!state.open) openDropdown(state); else moveHighlight(state, 1);
          break;
        case 'ArrowUp':
          e.preventDefault();
          if (!state.open) openDropdown(state); else moveHighlight(state, -1);
          break;
        case 'Enter':
        case ' ':
          e.preventDefault();
          if (state.open) {
            chooseIndex(state, state.highlightIndex);
            closeDropdown(state);
          } else {
            openDropdown(state);
          }
          break;
        case 'Escape':
          if (state.open) { e.preventDefault(); closeDropdown(state); }
          break;
        case 'Tab':
          if (state.open) closeDropdown(state);
          break;
      }
    });

    // Keep the trigger/menu in sync whenever page scripts mutate the
    // underlying select directly (innerHTML rebuilds, .disabled toggles).
    var mo = new MutationObserver(function () { sync(state); });
    mo.observe(select, { childList: true, attributes: true, attributeFilter: ['disabled'] });

    select.addEventListener('change', function () { syncTrigger(state); });
  }

  function initAll(root) {
    var selects = (root || document).querySelectorAll('select');
    Array.prototype.forEach.call(selects, wrapSelect);
  }

  document.addEventListener('click', function (e) {
    registry.forEach(function (state) {
      if (state.open && !state.wrap.contains(e.target)) closeDropdown(state);
    });
  });

  function boot() {
    initAll(document);
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (m) {
        m.addedNodes.forEach(function (node) {
          if (node.nodeType !== 1) return;
          if (node.matches && node.matches('select')) wrapSelect(node);
          if (node.querySelectorAll) {
            Array.prototype.forEach.call(node.querySelectorAll('select'), wrapSelect);
          }
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
