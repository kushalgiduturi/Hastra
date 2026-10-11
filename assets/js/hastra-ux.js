/* Hastra — shared usability layer, mounted on every themed page by core/theme.js.
 *
 *  1. "Skip to main content" link, and a #main-content target on every page
 *  2. HastraDialog: accessible confirm / alert / prompt dialogs (focus trap,
 *     Escape, focus restored to the trigger) replacing the browser's native
 *     ones. Any element or form with data-confirm="…" asks first.
 *  3. Inline form validation on blur and submit, with messages under each
 *     field linked by aria-describedby (no browser bubbles)
 *  4. Ctrl/Cmd+K (or "/") search palette over the site index and this page's
 *     own navigation
 *  5. Scroll progress line, back-to-top button (after 300px), contact button,
 *     sticky header that hides on scroll down and returns on scroll up
 *  6. Copy buttons on code blocks, tokens and Labs outputs
 *  7. First-party error reporting (scrubbed server-side) and consent-gated,
 *     cookieless analytics with UTM capture (see core/telemetry.php)
 *
 * Opt-outs: <body data-ux-chrome-off> (no floating controls or progress line),
 * <form data-novalidate-ux>, <input data-no-inline-error>, [data-no-copy].
 * Design: boxless. Dialogs and the palette are words over a dimmed, blurred
 * backdrop with fixed light text, so they read in both themes.
 */
(function () {
  'use strict';
  if (window.HastraUX) return;

  var me = document.currentScript;
  var BASE = (me && me.src) ? me.src.replace(/assets\/js\/hastra-ux\.js.*$/, '') : '/';
  var reduceMotion = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var FOCUSABLE = 'a[href], area[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), iframe, [tabindex]:not([tabindex="-1"]), [contenteditable="true"]';
  var uid = 0;
  function nextId(p) { uid += 1; return (p || 'hx') + '-' + uid; }
  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) n.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }
  function visible(n) { return !!(n.offsetWidth || n.offsetHeight || n.getClientRects().length); }
  function focusables(root) { return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), visible); }

  // a polite live region shared by everything here
  var live;
  function announce(msg) {
    if (!live) { live = el('div', { class: 'hx-sr', 'aria-live': 'polite', role: 'status' }); document.body.appendChild(live); }
    live.textContent = '';
    setTimeout(function () { live.textContent = msg; }, 30);
  }

  /* ───────────────────────── 1. Skip link ───────────────────────── */
  function mainTarget() {
    var t = document.getElementById('main-content');
    if (t) return t;
    t = document.querySelector('main, [role="main"], #legal-main, .main, .main-content, .lx-main, .container, .wrap');
    if (!t) { var h = document.querySelector('h1'); t = h ? h.parentElement : null; }
    if (!t) return null;
    if (!t.id) t.id = 'main-content';
    if (!t.hasAttribute('tabindex')) t.setAttribute('tabindex', '-1');
    return t;
  }
  function skipLink() {
    var target = mainTarget();
    if (!target) return;
    var existing = Array.prototype.find.call(document.querySelectorAll('a[href^="#"]'), function (a) { return /skip to/i.test(a.textContent); });
    if (existing) { existing.classList.add('hx-skip'); return; }
    var a = el('a', { class: 'hx-skip', href: '#' + target.id, text: 'Skip to main content' });
    a.addEventListener('click', function (e) { e.preventDefault(); target.focus({ preventScroll: false }); target.scrollIntoView({ block: 'start' }); });
    document.body.insertBefore(a, document.body.firstChild);
  }

  /* ───────────────────────── 2. Dialogs ───────────────────────── */
  var openCount = 0;
  function lockScroll(on) {
    openCount += on ? 1 : -1;
    document.body.classList.toggle('hx-modal-open', openCount > 0);
  }
  function trap(container, e) {
    if (e.key !== 'Tab') return;
    var f = focusables(container);
    if (!f.length) { e.preventDefault(); return; }
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  // opts: {title, message, confirmText, cancelText, danger, kind: 'confirm'|'alert'|'prompt', value, placeholder, validate}
  function dialog(opts) {
    return new Promise(function (resolve) {
      var returnTo = document.activeElement;
      var titleId = nextId('hx-dlg-t'), descId = nextId('hx-dlg-d');
      var kind = opts.kind || 'confirm';
      var input = null, err = null;
      var okBtn = el('button', { type: 'button', class: 'hx-btn' + (opts.danger ? ' hx-btn--danger' : ''), text: opts.confirmText || (kind === 'alert' ? 'OK' : 'Confirm') });
      var cancelBtn = kind === 'alert' ? null : el('button', { type: 'button', class: 'hx-link', text: opts.cancelText || 'Cancel' });
      var body = [el('h2', { id: titleId, class: 'hx-dlg-title', text: opts.title || (kind === 'alert' ? 'Notice' : 'Are you sure?') })];
      if (opts.message) body.push(el('p', { id: descId, class: 'hx-dlg-msg', text: opts.message }));
      if (kind === 'prompt') {
        var inId = nextId('hx-dlg-in'), errId = inId + '-err';
        input = el('input', { id: inId, class: 'hx-dlg-input', type: opts.inputType || 'text', value: opts.value || '', placeholder: opts.placeholder || '', 'aria-labelledby': titleId, 'aria-describedby': errId, autocomplete: 'off' });
        err = el('p', { id: errId, class: 'field-error', role: 'alert', hidden: true });
        body.push(input, err);
      }
      body.push(el('div', { class: 'hx-dlg-actions' }, [okBtn, cancelBtn]));
      var box = el('div', { class: 'hx-dlg', role: kind === 'prompt' ? 'dialog' : 'alertdialog', 'aria-modal': 'true', 'aria-labelledby': titleId, 'aria-describedby': opts.message ? descId : null }, body);
      var shade = el('div', { class: 'hx-shade' }, [box]);

      function close(result) {
        document.removeEventListener('keydown', onKey, true);
        shade.classList.remove('is-open');
        lockScroll(false);
        setTimeout(function () { shade.remove(); }, reduceMotion ? 0 : 160);
        if (returnTo && typeof returnTo.focus === 'function' && document.contains(returnTo)) returnTo.focus();
        resolve(result);
      }
      function accept() {
        if (kind === 'prompt') {
          var v = input.value.trim();
          var problem = opts.validate ? opts.validate(v) : '';
          if (problem) { err.textContent = problem; err.hidden = false; input.setAttribute('aria-invalid', 'true'); input.focus(); return; }
          close(v);
        } else close(true);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(kind === 'prompt' ? null : kind === 'alert'); return; }
        if (e.key === 'Enter' && input && document.activeElement === input) { e.preventDefault(); accept(); return; }
        trap(box, e);
      }
      okBtn.addEventListener('click', accept);
      if (cancelBtn) cancelBtn.addEventListener('click', function () { close(kind === 'prompt' ? null : false); });
      shade.addEventListener('mousedown', function (e) { if (e.target === shade && kind !== 'alert') close(kind === 'prompt' ? null : false); });
      document.addEventListener('keydown', onKey, true);

      document.body.appendChild(shade);
      lockScroll(true);
      requestAnimationFrame(function () { shade.classList.add('is-open'); });
      // destructive: start on the safe choice
      (input || (opts.danger && cancelBtn) || okBtn).focus();
    });
  }
  var HastraDialog = {
    confirm: function (message, o) { o = o || {}; return dialog({ kind: 'confirm', title: o.title, message: message, confirmText: o.confirmText, cancelText: o.cancelText, danger: !!o.danger }); },
    alert: function (message, o) { o = o || {}; return dialog({ kind: 'alert', title: o.title || 'Please check', message: message, confirmText: o.confirmText }); },
    prompt: function (message, o) { o = o || {}; return dialog({ kind: 'prompt', title: message, message: o.message, value: o.value, placeholder: o.placeholder, confirmText: o.confirmText || 'OK', validate: o.validate, inputType: o.inputType }); },
  };
  window.HastraDialog = HastraDialog;

  function confirmOpts(node) {
    return {
      title: node.getAttribute('data-confirm-title') || undefined,
      confirmText: node.getAttribute('data-confirm-action') || undefined,
      danger: node.hasAttribute('data-confirm-danger') || /delete|remove|erase|reject|purge|revoke/i.test(node.getAttribute('data-confirm') || ''),
    };
  }
  // buttons / links with data-confirm (not forms: handled on submit below)
  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-confirm]:not(form)');
    if (!t || t.__hxConfirmed) { if (t) t.__hxConfirmed = false; return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    HastraDialog.confirm(t.getAttribute('data-confirm'), confirmOpts(t)).then(function (ok) {
      if (!ok) return;
      var form = t.form || (t.closest && t.closest('form'));
      if (form && (t.type === 'submit' || t.tagName === 'BUTTON' && !t.type)) {
        form.__hxConfirmed = true;
        if (form.requestSubmit) form.requestSubmit(t); else { t.__hxConfirmed = true; t.click(); }
      } else if (t.tagName === 'A' && t.href) {
        location.href = t.href;
      } else { t.__hxConfirmed = true; t.click(); }
    });
  }, true);
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-confirm]')) return;
    if (f.__hxConfirmed) { f.__hxConfirmed = false; return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    var submitter = e.submitter;
    HastraDialog.confirm(f.getAttribute('data-confirm'), confirmOpts(f)).then(function (ok) {
      if (!ok) return;
      f.__hxConfirmed = true;
      if (f.requestSubmit) f.requestSubmit(submitter && submitter.form === f ? submitter : undefined); else f.submit();
    });
  }, true);

  /* ───────────────────────── 3. Inline validation ───────────────────────── */
  function skipField(f) {
    if (!f.willValidate || f.readOnly || f.closest('[data-novalidate-ux]') || f.hasAttribute('data-no-inline-error')) return true;
    if (/^(hidden|submit|button|reset|image)$/.test(f.type)) return true;
    if (f.maxLength === 1 || f.closest('[data-otp], .otp-inputs, .otp-boxes')) return true; // one-digit code boxes
    return false;
  }
  function labelText(f) {
    var l = (f.labels && f.labels[0]) || (f.id && document.querySelector('label[for="' + CSS.escape(f.id) + '"]'));
    var t = l ? l.textContent : (f.getAttribute('aria-label') || f.placeholder || '');
    return t.replace(/\*|\(optional[^)]*\)/gi, '').replace(/\s+/g, ' ').trim().toLowerCase();
  }
  function message(f) {
    var v = f.validity, name = labelText(f);
    if (f.dataset.errorMessage) return f.dataset.errorMessage;
    if (v.valueMissing) {
      if (f.type === 'checkbox') return 'Tick this box to continue.';
      if (f.tagName === 'SELECT' || f.type === 'radio') return 'Choose an option' + (name ? ' for ' + name : '') + '.';
      return name ? 'Enter your ' + name + '.' : 'This field is required.';
    }
    if (v.typeMismatch && f.type === 'email') return 'Enter a valid email address, like name@example.com.';
    if (v.typeMismatch && f.type === 'url') return 'Enter a full web address, starting with https://.';
    if (v.tooShort) return 'Use at least ' + f.minLength + ' characters (you have ' + f.value.length + ').';
    if (v.tooLong) return 'Use ' + f.maxLength + ' characters or fewer.';
    if (v.rangeUnderflow) return 'Enter ' + f.min + ' or more.';
    if (v.rangeOverflow) return 'Enter ' + f.max + ' or less.';
    if (v.stepMismatch) return 'Enter a value in steps of ' + (f.step || 1) + '.';
    if (v.badInput) return 'Enter a number.';
    if (v.patternMismatch) return f.title || 'Use the format shown.';
    return f.validationMessage || 'Check this field.';
  }
  function anchorFor(f) {
    if (f.type === 'checkbox' || f.type === 'radio') return f.closest('label') || f;
    return f.closest('.input-wrap, .pw-wrap, .password-wrap, .input-group, .iti') || f;
  }
  function errNode(f, create) {
    var id = (f.id || (f.id = nextId('hx-field'))) + '-hxerr';
    var n = document.getElementById(id);
    if (!n && create) {
      n = el('p', { id: id, class: 'field-error hx-field-error', role: 'alert' });
      var a = anchorFor(f);
      a.parentNode.insertBefore(n, a.nextSibling);
    }
    return n;
  }
  function describe(f, id, on) {
    var ids = (f.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
    var has = ids.indexOf(id) !== -1;
    if (on && !has) ids.push(id);
    if (!on && has) ids.splice(ids.indexOf(id), 1);
    if (ids.length) f.setAttribute('aria-describedby', ids.join(' ')); else f.removeAttribute('aria-describedby');
  }
  function showError(f) {
    var n = errNode(f, true);
    n.textContent = message(f);
    n.hidden = false;
    f.setAttribute('aria-invalid', 'true');
    describe(f, n.id, true);
  }
  function clearError(f) {
    var n = errNode(f, false);
    if (n) { n.hidden = true; n.textContent = ''; describe(f, n.id, false); }
    if (f.getAttribute('aria-invalid') === 'true') f.removeAttribute('aria-invalid');
  }
  function check(f) { if (skipField(f)) return true; if (f.checkValidity()) { clearError(f); return true; } showError(f); return false; }

  // native validation still decides; we replace its bubble with inline text
  document.addEventListener('invalid', function (e) {
    var f = e.target;
    if (skipField(f)) return;
    e.preventDefault();
    showError(f);
  }, true);
  document.addEventListener('focusout', function (e) {
    var f = e.target;
    if (!f.form || skipField(f)) return;
    if (f.dataset.hxTouched || f.value) { f.dataset.hxTouched = '1'; check(f); }
  });
  document.addEventListener('input', function (e) {
    var f = e.target;
    if (f.getAttribute && f.getAttribute('aria-invalid') === 'true' && !skipField(f)) check(f);
  });
  document.addEventListener('change', function (e) {
    var f = e.target;
    if (f.getAttribute && f.getAttribute('aria-invalid') === 'true' && !skipField(f)) check(f);
  });
  // forms marked novalidate + data-validate get the same checks on submit
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.matches || !form.matches('form[novalidate][data-validate]')) return;
    var bad = Array.prototype.filter.call(form.elements, function (f) { return !check(f); });
    if (bad.length) { e.preventDefault(); e.stopImmediatePropagation(); bad[0].focus(); announce(bad.length === 1 ? 'One field needs attention.' : bad.length + ' fields need attention.'); }
  }, true);
  // when a native-validated form blocks submit, focus the first problem
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('button[type="submit"], input[type="submit"], button:not([type])');
    if (!b || !b.form || b.form.noValidate || b.formNoValidate) return;
    setTimeout(function () {
      var first = b.form.querySelector('[aria-invalid="true"]');
      if (first && document.activeElement !== first && !b.form.checkValidity()) first.focus();
    }, 0);
  }, true);

  /* ───────────────────────── 4. Ctrl/Cmd+K palette ───────────────────────── */
  var index = null, indexPromise = null;
  function fetchIndex() {
    if (indexPromise) return indexPromise;
    indexPromise = fetch(BASE + 'api/search_index.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : { entries: [] }; })
      .catch(function () { return { entries: [] }; })
      .then(function (j) {
        if (j && j.support) try { sessionStorage.setItem('hastra_support', j.support); } catch (e) {}
        return (j && j.entries) || [];
      });
    return indexPromise;
  }
  function pageEntries() {
    var out = [];
    document.querySelectorAll('nav a[href], .hastra-dock a[href], aside a[href], .topnav a[href], .section-nav a[href]').forEach(function (a) {
      var title = (a.getAttribute('aria-label') || a.textContent || '').replace(/\s+/g, ' ').trim();
      if (!title || title.length > 60 || a.origin !== location.origin || /^javascript:/i.test(a.getAttribute('href'))) return;
      out.push({ title: title, href: a.href, group: 'Navigation', keywords: '' });
    });
    document.querySelectorAll('main h2[id], main h3[id], .legal-doc h2[id]').forEach(function (h) {
      out.push({ title: h.textContent.trim(), href: '#' + h.id, group: 'On this page', keywords: '' });
    });
    return out;
  }
  function score(e, q) {
    if (!q) return 1;
    var t = e.title.toLowerCase(), k = (e.keywords || '').toLowerCase() + ' ' + e.group.toLowerCase();
    var s = 0;
    q.split(/\s+/).forEach(function (w) {
      if (!w) return;
      if (t.indexOf(w) === 0) s += 6; else if (t.indexOf(' ' + w) !== -1) s += 4; else if (t.indexOf(w) !== -1) s += 2; else if (k.indexOf(w) !== -1) s += 1; else s -= 100;
    });
    return s;
  }
  var palette = null;
  function openPalette() {
    if (palette) return;
    track('search_open');
    var returnTo = document.activeElement;
    var listId = nextId('hx-pal-list'), inId = nextId('hx-pal-in');
    var input = el('input', { id: inId, class: 'hx-pal-input', type: 'search', role: 'combobox', 'aria-expanded': 'true', 'aria-controls': listId, 'aria-autocomplete': 'list', placeholder: 'Search pages, tools and settings', autocomplete: 'off', spellcheck: 'false' });
    var list = el('ul', { id: listId, class: 'hx-pal-list', role: 'listbox', 'aria-label': 'Results' });
    var empty = el('p', { class: 'hx-pal-empty', hidden: true, text: 'No matches. Try another word.' });
    var hint = el('p', { class: 'hx-pal-hint', text: '↑ ↓ to move · Enter to open · Esc to close' });
    var label = el('label', { for: inId, class: 'hx-sr', text: 'Search Hastra' });
    var box = el('div', { class: 'hx-dlg hx-pal', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Search Hastra' }, [label, input, list, empty, hint]);
    var shade = el('div', { class: 'hx-shade hx-shade--top' }, [box]);
    var all = [], shown = [], active = 0;

    function render() {
      var q = input.value.trim().toLowerCase();
      shown = all.map(function (e) { return { e: e, s: score(e, q) }; })
        .filter(function (x) { return x.s > 0; })
        .sort(function (a, b) { return b.s - a.s; }).slice(0, 12).map(function (x) { return x.e; });
      list.textContent = '';
      active = Math.min(active, Math.max(shown.length - 1, 0));
      shown.forEach(function (e, i) {
        var li = el('li', { id: listId + '-' + i, role: 'option', class: 'hx-pal-opt', 'aria-selected': i === active ? 'true' : 'false' },
          [el('span', { class: 'hx-pal-title', text: e.title }), el('span', { class: 'hx-pal-group', text: e.group })]);
        li.addEventListener('mousedown', function (ev) { ev.preventDefault(); go(e); });
        li.addEventListener('mousemove', function () { if (active !== i) { active = i; mark(); } });
        list.appendChild(li);
      });
      empty.hidden = shown.length > 0;
      mark();
    }
    function mark() {
      Array.prototype.forEach.call(list.children, function (li, i) { li.setAttribute('aria-selected', i === active ? 'true' : 'false'); });
      if (shown.length) { input.setAttribute('aria-activedescendant', listId + '-' + active); var a = list.children[active]; if (a) a.scrollIntoView({ block: 'nearest' }); }
      else input.removeAttribute('aria-activedescendant');
    }
    function go(e) { track('search_go'); close(); if (e.href.charAt(0) === '#') { var t = document.querySelector(e.href); if (t) { t.setAttribute('tabindex', '-1'); t.focus(); } } else location.href = e.href; }
    function close() {
      document.removeEventListener('keydown', onKey, true);
      shade.remove(); lockScroll(false); palette = null;
      if (returnTo && returnTo.focus && document.contains(returnTo)) returnTo.focus();
    }
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); close(); return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, shown.length - 1); mark(); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); mark(); return; }
      if (e.key === 'Enter' && shown[active]) { e.preventDefault(); go(shown[active]); return; }
      trap(box, e);
    }
    input.addEventListener('input', function () { active = 0; render(); });
    shade.addEventListener('mousedown', function (e) { if (e.target === shade) close(); });
    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(shade);
    lockScroll(true);
    palette = { close: close };
    input.focus();

    var seen = {};
    function merge(entries) {
      entries.forEach(function (e) {
        // one entry per destination: ignore origin, fragment, query, ".php" and a trailing slash
        var key = e.href.charAt(0) === '#' ? e.href : e.href.replace(location.origin, '').replace(/[#?].*$/, '').replace(/\.php$/, '').replace(/\/$/, '');
        if (!seen[key]) { seen[key] = 1; all.push(e); }
      });
      render();
    }
    merge(pageEntries());
    fetchIndex().then(merge);
  }
  function typing(t) { return t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)); }
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey) && !e.altKey) {
      if (e.target && e.target.isContentEditable) return; // editors use Ctrl+K for links
      e.preventDefault(); openPalette();
    } else if (e.key === '/' && !typing(e.target) && !e.ctrlKey && !e.metaKey && !palette && !document.querySelector('.hx-shade')) {
      e.preventDefault(); openPalette();
    }
  });

  /* ───────────────────────── 5. Floating chrome ───────────────────────── */
  function chrome() {
    var off = document.body.hasAttribute('data-ux-chrome-off');
    var bar = null, topBtn = null;
    if (!off) {
      bar = el('div', { class: 'hx-progress', 'aria-hidden': 'true' }, [el('span')]);
      document.body.appendChild(bar);

      topBtn = el('button', { type: 'button', class: 'hx-fab hx-top', 'aria-label': 'Back to top', title: 'Back to top', hidden: true });
      topBtn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5l-7 7 1.4 1.4L11 8.8V20h2V8.8l4.6 4.6L19 12z"/></svg>';
      topBtn.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        var t = mainTarget(); if (t) setTimeout(function () { t.focus({ preventScroll: true }); }, reduceMotion ? 0 : 450);
      });

      var support = null;
      try { support = sessionStorage.getItem('hastra_support'); } catch (e) {}
      var contact = el('a', { class: 'hx-fab hx-contact', href: BASE + 'legal/privacy#contact', 'aria-label': 'Contact support', title: 'Contact support' });
      contact.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 4H4a2 2 0 00-2 2v12a2 2 0 002 2h16a2 2 0 002-2V6a2 2 0 00-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>';
      function setSupport(addr) { if (addr) contact.href = 'mailto:' + addr + '?subject=' + encodeURIComponent('Hastra support'); }
      setSupport(support);
      if (!support) fetchIndex().then(function () { try { setSupport(sessionStorage.getItem('hastra_support')); } catch (e) {} });

      document.body.appendChild(el('div', { class: 'hx-fabs' }, [topBtn, contact]));
    }

    var header = document.querySelector('[data-sticky-header], .topnav, .legal-top, .lx-top');
    if (header && getComputedStyle(header).position !== 'fixed') header.classList.add('hx-sticky');
    if (header) drawer(header);

    var lastY = window.scrollY, ticking = false;
    function onScroll() {
      ticking = false;
      var y = window.scrollY, max = document.documentElement.scrollHeight - window.innerHeight;
      if (bar) {
        var long = max > window.innerHeight * 0.5;
        bar.classList.toggle('is-on', long);
        bar.firstChild.style.transform = 'scaleX(' + (max > 0 ? Math.min(y / max, 1) : 0) + ')';
      }
      if (topBtn) topBtn.hidden = y < 300;
      if (header) {
        header.classList.toggle('hx-compact', y > 8);
        var hide = y > lastY && y > 160 && !header.contains(document.activeElement) && !document.body.classList.contains('hx-modal-open');
        header.classList.toggle('hx-hide', hide);
      }
      lastY = y;
    }
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(onScroll); } }, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    onScroll();
  }

  /* Mobile drawer: on narrow screens a header nav with three or more links
     collapses behind a "Menu" button and opens as a full-screen overlay
     (focus trapped, Escape closes, page scroll locked, focus returned). */
  function drawer(header) {
    var nav = header.querySelector('nav');
    if (!nav || nav.querySelectorAll('a[href]').length < 3 || header.querySelector('.hx-menu-btn')) return;
    var mq = window.matchMedia('(max-width: 760px)');
    if (!nav.id) nav.id = nextId('hx-nav');
    var btn = el('button', { type: 'button', class: 'hx-menu-btn', 'aria-expanded': 'false', 'aria-controls': nav.id });
    btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18v2H3zm0 5h18v2H3zm0 5h18v2H3z"/></svg><span>Menu</span>';
    var closeBtn = el('button', { type: 'button', class: 'hx-link hx-menu-close', text: 'Close menu' });
    nav.appendChild(closeBtn);
    header.appendChild(btn);
    var open = false, returnTo = null;
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); set(false); return; }
      trap(nav, e);
    }
    function set(on) {
      if (on === open) return;
      open = on;
      header.classList.toggle('hx-menu-open', on);
      btn.setAttribute('aria-expanded', String(on));
      lockScroll(on);
      if (on) {
        returnTo = document.activeElement;
        nav.setAttribute('role', 'dialog'); nav.setAttribute('aria-modal', 'true'); nav.setAttribute('aria-label', 'Site menu');
        document.addEventListener('keydown', onKey, true);
        var first = nav.querySelector('a[href]'); if (first) first.focus();
      } else {
        nav.removeAttribute('role'); nav.removeAttribute('aria-modal');
        document.removeEventListener('keydown', onKey, true);
        if (returnTo && returnTo.focus) returnTo.focus();
      }
    }
    btn.addEventListener('click', function () { set(!open); });
    closeBtn.addEventListener('click', function () { set(false); });
    nav.addEventListener('click', function (e) { if (e.target.closest('a[href]')) set(false); });
    function sync() { header.classList.toggle('hx-drawer', mq.matches); if (!mq.matches) set(false); }
    if (mq.addEventListener) mq.addEventListener('change', sync); else mq.addListener(sync);
    sync();
  }

  /* ───────────────────────── 6. Copy buttons ───────────────────────── */
  var COPY_SEL = 'pre, code.block, [data-copy], .lx-out, .token-box, .api-token, .secret-value';
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function (res, rej) {
      var ta = el('textarea', { class: 'hx-sr', 'aria-hidden': 'true' }); ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? res() : rej(); } catch (e) { rej(e); } ta.remove();
    });
  }
  function addCopy(block) {
    if (block.__hxCopy || block.closest('[data-no-copy]') || block.closest('.hx-dlg') || block.closest('pre') !== block && block.tagName !== 'PRE' && block.closest('pre')) return;
    var host = block.parentElement;
    if (!host) return;
    // the block, or its toolbar, already has a copy control
    var already = Array.prototype.some.call(host.querySelectorAll('button'), function (b) { return /copy/i.test(b.textContent + ' ' + (b.getAttribute('aria-label') || '')); });
    if (already) { block.__hxCopy = true; return; }
    var text = function () { return block.getAttribute('data-copy') || block.innerText || block.textContent || ''; };
    if (!text().trim() && !block.matches('.lx-out')) return;
    block.__hxCopy = true;
    var btn = el('button', { type: 'button', class: 'hx-copy', 'aria-label': 'Copy to clipboard', text: 'Copy' });
    btn.addEventListener('click', function () {
      copyText(text().trim()).then(function () {
        btn.textContent = 'Copied!'; btn.classList.add('is-done'); announce('Copied to clipboard');
        setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('is-done'); }, 1600);
      }, function () { btn.textContent = 'Press Ctrl+C'; setTimeout(function () { btn.textContent = 'Copy'; }, 2000); });
    });
    if (getComputedStyle(block).position === 'static') block.style.position = 'relative';
    if (block.tagName === 'PRE' || block.matches('.lx-out')) { btn.classList.add('hx-copy--corner'); block.appendChild(btn); }
    else block.insertAdjacentElement('afterend', btn);
  }
  function scanCopy(root) { (root.querySelectorAll ? root : document).querySelectorAll(COPY_SEL).forEach(addCopy); }

  /* ───────────────────────── 7. Errors & analytics ───────────────────────── */
  var sentErrors = 0;
  function post(path, payload) {
    var body = JSON.stringify(payload);
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(BASE + path, new Blob([body], { type: 'application/json' }))) return;
    } catch (e) {}
    fetch(BASE + path, { method: 'POST', body: body, keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'application/json' } }).catch(function () {});
  }
  function reportError(message, location, stack) {
    if (sentErrors >= 5 || !message || /^Script error\.?$/.test(message) || /ResizeObserver loop/.test(message)) return;
    sentErrors += 1;
    post('api/client_error.php', { message: String(message).slice(0, 500), location: String(location || '').slice(0, 255), stack: String(stack || '').slice(0, 4000), path: window.location.pathname });
  }
  window.addEventListener('error', function (e) {
    if (!e.message) return; // a failed <img>/<script> load, not a code error
    reportError(e.message, (e.filename || '').replace(window.location.origin, '') + ':' + e.lineno + ':' + e.colno, e.error && e.error.stack);
  });
  window.addEventListener('unhandledrejection', function (e) {
    var r = e.reason || {};
    reportError('Unhandled promise rejection: ' + (r.message || String(r)), '', r.stack);
  });

  function analyticsAllowed() {
    if (navigator.globalPrivacyControl || navigator.doNotTrack === '1' || window.doNotTrack === '1') return false;
    if (window.HastraConsent) return window.HastraConsent.allows('analytics');
    try { var s = JSON.parse(localStorage.getItem('hastra_consent_state') || 'null'); return !!(s && s.analytics); } catch (e) { return false; }
  }
  function utm() {
    var keys = ['utm_source', 'utm_medium', 'utm_campaign'], out = {}, have = false;
    try {
      var q = new URLSearchParams(location.search);
      keys.forEach(function (k) { var v = q.get(k); if (v) { out[k] = v.slice(0, 80); have = true; } });
      if (have) sessionStorage.setItem('hastra_utm', JSON.stringify(out)); // first touch wins for the tab session
      else out = JSON.parse(sessionStorage.getItem('hastra_utm') || '{}');
    } catch (e) {}
    return out;
  }
  var UTM = utm();
  function track(event) {
    if (!analyticsAllowed()) return;
    post('api/track.php', { event: event, path: location.pathname, ref: document.referrer, utm: UTM });
  }
  function startAnalytics() {
    var send = function () { track('pageview'); };
    if (window.HastraConsent && window.HastraConsent.whenAllowed) window.HastraConsent.whenAllowed('analytics', function () { if (analyticsAllowed()) send(); });
    else if (analyticsAllowed()) send();
    document.addEventListener('click', function (e) {
      var t = e.target.closest && e.target.closest('[data-track]');
      if (t) track(t.getAttribute('data-track'));
    });
  }

  /* ───────────────────────── boot ───────────────────────── */
  function boot() {
    skipLink();
    chrome();
    scanCopy(document);
    if ('MutationObserver' in window) {
      var pending = false;
      new MutationObserver(function () {
        if (pending) return; pending = true;
        setTimeout(function () { pending = false; scanCopy(document); }, 250);
      }).observe(document.body, { childList: true, subtree: true });
    }
    document.querySelectorAll('[data-autofocus]').forEach(function (n, i) { if (i === 0) n.focus(); });
    startAnalytics();
  }
  window.HastraUX = { track: track, openSearch: openPalette, dialog: HastraDialog, announce: announce };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
