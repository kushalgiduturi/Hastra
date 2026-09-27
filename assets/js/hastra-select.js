/* Hastra — slide-down select and slider bars.
 *
 * <select data-ax-select> becomes a button + slide-down listbox (WAI-ARIA
 * listbox pattern). The native <select> stays in the form, visually hidden,
 * and is kept in sync, so submission and server validation are unchanged.
 * A disabled first option with value "" is treated as the placeholder.
 *
 * <input type="range" class="ax-range"> gets its filled track and the
 * <output for="id"> readout kept up to date.
 */
(function () {
  'use strict';
  let uid = 0;

  function enhanceSelect(sel) {
    if (sel.dataset.axReady) return;
    sel.dataset.axReady = '1';
    const id = sel.id || ('axsel' + (++uid));
    const label = sel.id ? document.querySelector('label[for="' + sel.id + '"]') : null;
    if (label && !label.id) label.id = id + '-label';

    const wrap = document.createElement('div');
    wrap.className = 'ax-select';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ax-select-btn';
    btn.id = id + '-btn';
    btn.setAttribute('aria-haspopup', 'listbox');
    btn.setAttribute('aria-expanded', 'false');
    if (label) btn.setAttribute('aria-labelledby', label.id + ' ' + btn.id);
    btn.innerHTML = '<span class="ax-select-value"></span><svg class="ax-select-chev" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    const list = document.createElement('ul');
    list.className = 'ax-select-list';
    list.id = id + '-list';
    list.setAttribute('role', 'listbox');
    list.tabIndex = -1;
    if (label) list.setAttribute('aria-labelledby', label.id);
    btn.setAttribute('aria-controls', list.id);

    const opts = [];
    let placeholder = '';
    Array.from(sel.options).forEach(function (o, i) {
      if (o.value === '' && o.disabled) { placeholder = o.textContent; return; }
      const li = document.createElement('li');
      li.id = id + '-opt' + i;
      li.setAttribute('role', 'option');
      li.dataset.value = o.value;
      li.textContent = o.textContent;
      list.appendChild(li);
      opts.push(li);
    });

    sel.classList.add('ax-native');
    sel.tabIndex = -1;
    sel.setAttribute('aria-hidden', 'true');
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(btn);
    wrap.appendChild(list);
    wrap.appendChild(sel);

    let active = -1;
    const valueEl = btn.querySelector('.ax-select-value');

    function render() {
      const cur = opts.find(function (li) { return li.dataset.value === sel.value && sel.value !== ''; });
      valueEl.textContent = cur ? cur.textContent : (placeholder || 'Select');
      btn.classList.toggle('is-placeholder', !cur);
      opts.forEach(function (li) { li.setAttribute('aria-selected', String(li === cur)); });
      btn.disabled = sel.disabled;
    }
    function setActive(i) {
      if (!opts.length) return;
      active = (i + opts.length) % opts.length;
      opts.forEach(function (li, k) { li.classList.toggle('is-active', k === active); });
      list.setAttribute('aria-activedescendant', opts[active].id);
      opts[active].scrollIntoView({ block: 'nearest' });
    }
    function open() {
      if (btn.disabled || wrap.classList.contains('open')) return;
      document.querySelectorAll('.ax-select.open').forEach(function (w) { if (w !== wrap) w.__axClose(false); });
      wrap.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
      const cur = opts.findIndex(function (li) { return li.dataset.value === sel.value; });
      setActive(cur >= 0 ? cur : 0);
      list.focus({ preventScroll: true });
    }
    function close(refocus) {
      if (!wrap.classList.contains('open')) return;
      wrap.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      list.removeAttribute('aria-activedescendant');
      if (refocus !== false) btn.focus();
    }
    wrap.__axClose = close;
    function choose(i) {
      const v = opts[i].dataset.value;
      if (sel.value !== v) {
        sel.value = v;
        sel.dispatchEvent(new Event('input', { bubbles: true }));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      render();
      close();
    }

    btn.addEventListener('click', function () { wrap.classList.contains('open') ? close() : open(); });
    btn.addEventListener('keydown', function (e) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].indexOf(e.key) !== -1) { e.preventDefault(); open(); }
    });
    list.addEventListener('keydown', function (e) {
      switch (e.key) {
        case 'ArrowDown': e.preventDefault(); setActive(active + 1); break;
        case 'ArrowUp':   e.preventDefault(); setActive(active - 1); break;
        case 'Home':      e.preventDefault(); setActive(0); break;
        case 'End':       e.preventDefault(); setActive(opts.length - 1); break;
        case 'Enter': case ' ': e.preventDefault(); if (active >= 0) choose(active); break;
        case 'Escape':    e.preventDefault(); close(); break;
        case 'Tab':       close(false); break;
        default:
          if (e.key.length === 1) {
            const k = e.key.toLowerCase();
            const hit = opts.findIndex(function (li, n) { return n > active && li.textContent.trim().toLowerCase().startsWith(k); });
            const any = hit >= 0 ? hit : opts.findIndex(function (li) { return li.textContent.trim().toLowerCase().startsWith(k); });
            if (any >= 0) setActive(any);
          }
      }
    });
    list.addEventListener('mousemove', function (e) {
      const li = e.target.closest('[role="option"]');
      if (li) setActive(opts.indexOf(li));
    });
    list.addEventListener('click', function (e) {
      const li = e.target.closest('[role="option"]');
      if (li) choose(opts.indexOf(li));
    });
    document.addEventListener('pointerdown', function (e) { if (!wrap.contains(e.target)) close(false); });
    sel.addEventListener('change', render);
    // the registration form enables/disables fields as the track changes
    new MutationObserver(render).observe(sel, { attributes: true, attributeFilter: ['disabled'] });
    // a validation message on the hidden native select should point at the button
    sel.addEventListener('invalid', function () { btn.classList.add('is-invalid'); btn.focus(); });
    sel.addEventListener('change', function () { btn.classList.remove('is-invalid'); });
    render();
  }

  function enhanceRange(r) {
    if (r.dataset.axReady) return;
    r.dataset.axReady = '1';
    const out = r.id ? document.querySelector('output[for="' + r.id + '"]') : null;
    const paint = function () {
      const min = +r.min || 0, max = +r.max || 100;
      r.style.setProperty('--pct', ((r.value - min) / (max - min) * 100) + '%');
      if (out) out.textContent = r.value;
    };
    r.addEventListener('input', paint);
    paint();
  }

  function init() {
    document.querySelectorAll('select[data-ax-select]').forEach(enhanceSelect);
    document.querySelectorAll('input[type="range"].ax-range').forEach(enhanceRange);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
