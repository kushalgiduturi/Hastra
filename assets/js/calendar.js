// Astra — AuthKit calendar (vanilla port of the Lightswind/shadcn calendar;
// no React, no build step). Styles: assets/css/calendar-authkit.css.
//
// Usage, declarative:
//   <div class="astra-calendar-root" data-mode="range"
//        data-bind-start="#start_date" data-bind-end="#end_date"></div>
// or imperative:
//   AstraCalendar.mount(el, { mode: 'single', bind: '#day', onSelect(d) {} });
//
// Options (data-* attribute equivalents in brackets):
//   mode      'single' | 'range'                     [data-mode]
//   bind      input kept in sync in single mode      [data-bind]
//   bindStart / bindEnd  inputs for a range          [data-bind-start/-end]
//   min / max ISO dates (YYYY-MM-DD) outside of which days are disabled
//   month     initial view, YYYY-MM                  [data-month]
//   onSelect  callback(detail)
//
// On every selection the bound inputs are updated and get real `input` and
// `change` events, so existing handlers on them (e.g. onchange="…") keep
// working. The root also dispatches `astra-calendar:change` with
// detail { mode, date, start, end } (ISO strings, or null).
// Typing into a bound input updates the calendar in the other direction.
(function () {
  'use strict';

  const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
  const CHEVRON_L = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>';
  const CHEVRON_R = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>';

  // ── Date helpers: work on local calendar dates, never UTC ──
  const pad = (n) => String(n).padStart(2, '0');
  const toISO = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  function fromISO(s) {
    if (!s || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return null;
    const [y, m, d] = s.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    return dt.getMonth() === m - 1 ? dt : null; // rejects 2026-02-31
  }
  const sameDay = (a, b) => !!a && !!b && a.getTime() === b.getTime();
  const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
  const today = () => { const t = new Date(); return new Date(t.getFullYear(), t.getMonth(), t.getDate()); };
  const q = (sel) => (typeof sel === 'string' ? document.querySelector(sel) : sel) || null;

  function AstraCalendar(root, opts) {
    this.root = root;
    this.mode = opts.mode === 'range' ? 'range' : 'single';
    this.inSingle = q(opts.bind);
    this.inStart = q(opts.bindStart);
    this.inEnd = q(opts.bindEnd);
    this.min = fromISO(opts.min);
    this.max = fromISO(opts.max);
    this.onSelect = typeof opts.onSelect === 'function' ? opts.onSelect : null;
    this.date = null; this.start = null; this.end = null;
    this.syncing = false;

    this.readInputs();
    const anchor = this.date || this.start || fromISO((opts.month || '') + '-01') || today();
    this.view = new Date(anchor.getFullYear(), anchor.getMonth(), 1);
    this.focusDate = this.date || this.start || (this.inView(today()) ? today() : this.view);

    this.build();
    this.render();
    this.listenInputs();
  }

  AstraCalendar.prototype.inView = function (d) {
    return d.getFullYear() === this.view.getFullYear() && d.getMonth() === this.view.getMonth();
  };
  AstraCalendar.prototype.isDisabled = function (d) {
    return (this.min && d < this.min) || (this.max && d > this.max);
  };

  AstraCalendar.prototype.readInputs = function () {
    if (this.mode === 'single') {
      this.date = this.inSingle ? fromISO(this.inSingle.value) : this.date;
    } else {
      if (this.inStart) this.start = fromISO(this.inStart.value);
      if (this.inEnd) this.end = fromISO(this.inEnd.value);
      if (this.start && this.end && this.end < this.start) this.end = null;
    }
  };

  AstraCalendar.prototype.build = function () {
    const r = this.root;
    r.classList.add('astra-calendar-root');
    r.innerHTML =
      '<div class="astra-cal-head">' +
        '<button type="button" class="astra-cal-nav" data-nav="-1" aria-label="Previous month">' + CHEVRON_L + '</button>' +
        '<div class="astra-cal-caption" aria-live="polite"></div>' +
        '<button type="button" class="astra-cal-nav" data-nav="1" aria-label="Next month">' + CHEVRON_R + '</button>' +
      '</div>' +
      '<div class="astra-cal-grid" role="grid"></div>' +
      (this.mode === 'range' ? '<div class="astra-cal-foot"></div>' : '') +
      '<div class="astra-cal-sr" aria-live="polite"></div>';
    this.caption = r.querySelector('.astra-cal-caption');
    this.grid = r.querySelector('.astra-cal-grid');
    this.foot = r.querySelector('.astra-cal-foot');
    this.sr = r.querySelector('.astra-cal-sr');

    r.querySelectorAll('.astra-cal-nav').forEach((b) =>
      b.addEventListener('click', () => this.shiftMonth(Number(b.dataset.nav), false)));
    this.grid.addEventListener('click', (e) => {
      const b = e.target.closest('.astra-cal-day');
      if (b && !b.disabled) this.pick(fromISO(b.dataset.date));
    });
    this.grid.addEventListener('keydown', (e) => this.onKey(e));
  };

  AstraCalendar.prototype.shiftMonth = function (n, keepFocus) {
    this.view = new Date(this.view.getFullYear(), this.view.getMonth() + n, 1);
    if (!keepFocus) this.focusDate = this.view;
    this.render();
  };

  AstraCalendar.prototype.render = function () {
    const v = this.view;
    this.caption.textContent = v.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

    const first = new Date(v.getFullYear(), v.getMonth(), 1);
    const lead = (first.getDay() + 6) % 7; // Monday-first week
    const gridStart = addDays(first, -lead);
    const t = today();
    const lo = this.start, hi = this.end;

    let html = WEEKDAYS.map((w) => '<div class="astra-cal-weekday" role="columnheader">' + w + '</div>').join('');
    for (let i = 0; i < 42; i++) {
      const d = addDays(gridStart, i);
      const cls = ['astra-cal-day'];
      if (!this.inView(d)) cls.push('is-outside');
      if (sameDay(d, t)) cls.push('is-today');
      let selected = false;
      if (this.mode === 'single' && sameDay(d, this.date)) { cls.push('is-selected'); selected = true; }
      if (this.mode === 'range') {
        if (sameDay(d, lo)) { cls.push('range-start'); selected = true; }
        if (sameDay(d, hi)) { cls.push('range-end'); selected = true; }
        if (lo && hi && d > lo && d < hi) cls.push('in-range');
      }
      const focusable = sameDay(d, this.focusDate) || (!this.inView(this.focusDate) && d.getTime() === first.getTime());
      html += '<button type="button" role="gridcell" class="' + cls.join(' ') + '"' +
        ' data-date="' + toISO(d) + '"' +
        ' tabindex="' + (focusable ? '0' : '-1') + '"' +
        ' aria-label="' + d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + '"' +
        ' aria-selected="' + selected + '"' +
        (sameDay(d, t) ? ' aria-current="date"' : '') +
        (this.isDisabled(d) ? ' disabled' : '') +
        '>' + d.getDate() + '</button>';
    }
    this.grid.innerHTML = html;

    const prev = this.root.querySelector('[data-nav="-1"]');
    const next = this.root.querySelector('[data-nav="1"]');
    prev.disabled = !!this.min && new Date(v.getFullYear(), v.getMonth(), 0) < this.min;
    next.disabled = !!this.max && new Date(v.getFullYear(), v.getMonth() + 1, 1) > this.max;

    if (this.foot) {
      const fmt = (d) => d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
      if (lo && hi) {
        const n = Math.round((hi - lo) / 86400000) + 1;
        this.foot.textContent = fmt(lo) + ' to ' + fmt(hi) + ' · ' + n + ' day' + (n === 1 ? '' : 's');
      } else if (lo) {
        this.foot.textContent = 'Start ' + fmt(lo) + '. Pick an end date.';
      } else {
        this.foot.textContent = 'Pick a start date.';
      }
    }
  };

  AstraCalendar.prototype.pick = function (d) {
    if (!d) return;
    if (this.mode === 'single') {
      this.date = d;
    } else if (!this.start || this.end || d < this.start) {
      // First click, a click after a completed range, or a click before
      // the current start all begin a new range.
      this.start = d; this.end = null;
    } else {
      this.end = d;
    }
    this.focusDate = d;
    if (!this.inView(d)) this.view = new Date(d.getFullYear(), d.getMonth(), 1);
    this.render();
    this.emit();
    const btn = this.grid.querySelector('[data-date="' + toISO(d) + '"]');
    if (btn) btn.focus();
  };

  AstraCalendar.prototype.emit = function () {
    const iso = (d) => (d ? toISO(d) : null);
    const detail = { mode: this.mode, date: iso(this.date), start: iso(this.start), end: iso(this.end) };

    this.syncing = true;
    const write = (input, val) => {
      if (!input || input.value === (val || '')) return;
      input.value = val || '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    };
    if (this.mode === 'single') write(this.inSingle, detail.date);
    else { write(this.inStart, detail.start); write(this.inEnd, detail.end); }
    this.syncing = false;

    this.sr.textContent = this.mode === 'single'
      ? (detail.date ? 'Selected ' + this.date.toDateString() : '')
      : (this.foot ? this.foot.textContent : '');
    this.root.dispatchEvent(new CustomEvent('astra-calendar:change', { bubbles: true, detail }));
    if (this.onSelect) this.onSelect(detail);
  };

  // Typing a date into a bound input moves the calendar to match.
  AstraCalendar.prototype.listenInputs = function () {
    const sync = () => {
      if (this.syncing) return;
      this.readInputs();
      const a = this.mode === 'single' ? this.date : this.start;
      if (a && !this.inView(a)) this.view = new Date(a.getFullYear(), a.getMonth(), 1);
      if (a) this.focusDate = a;
      this.render();
    };
    [this.inSingle, this.inStart, this.inEnd].forEach((i) => i && i.addEventListener('change', sync));
  };

  // Grid keyboard support, per the WAI-ARIA date picker pattern.
  AstraCalendar.prototype.onKey = function (e) {
    const cur = fromISO((e.target.closest('.astra-cal-day') || {}).dataset?.date);
    if (!cur) return;
    const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
    let next = null;
    if (moves[e.key] !== undefined) next = addDays(cur, moves[e.key]);
    else if (e.key === 'Home') next = addDays(cur, -((cur.getDay() + 6) % 7));
    else if (e.key === 'End') next = addDays(cur, 6 - ((cur.getDay() + 6) % 7));
    else if (e.key === 'PageUp' || e.key === 'PageDown') {
      const step = (e.key === 'PageUp' ? -1 : 1) * (e.shiftKey ? 12 : 1);
      next = new Date(cur.getFullYear(), cur.getMonth() + step, 1);
      const lastDay = new Date(next.getFullYear(), next.getMonth() + 1, 0).getDate();
      next.setDate(Math.min(cur.getDate(), lastDay));
    } else if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      if (!e.target.disabled) this.pick(cur);
      return;
    } else return;

    e.preventDefault();
    this.focusDate = next;
    if (!this.inView(next)) this.view = new Date(next.getFullYear(), next.getMonth(), 1);
    this.render();
    const btn = this.grid.querySelector('[data-date="' + toISO(next) + '"]');
    if (btn) btn.focus();
  };

  // ── Public API ──
  const instances = new WeakMap();
  window.AstraCalendar = {
    mount(el, opts) {
      el = q(el);
      if (!el) return null;
      if (instances.has(el)) return instances.get(el);
      const cal = new AstraCalendar(el, opts || {});
      instances.set(el, cal);
      return cal;
    },
    get(el) { return instances.get(q(el)) || null; },
  };

  function autoInit() {
    document.querySelectorAll('.astra-calendar-root[data-mode], .astra-calendar-root[data-bind], .astra-calendar-root[data-bind-start]').forEach((el) => {
      const ds = el.dataset;
      window.AstraCalendar.mount(el, {
        mode: ds.mode, bind: ds.bind, bindStart: ds.bindStart, bindEnd: ds.bindEnd,
        min: ds.min, max: ds.max, month: ds.month,
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', autoInit);
  else autoInit();
})();
