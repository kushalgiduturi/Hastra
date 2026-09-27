/* Hastra Labs — shared browser helpers (ES module).
   Everything here is dependency-free. DOM is built with textContent, never
   innerHTML, so topic names, feed values and file names from untrusted input
   can't inject markup. */

export const labsBase = document.querySelector('meta[name="labs-base"]')?.content || './';
export const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── DOM ──────────────────────────────────────────────────────────────────────
export function el(tag, attrs = {}, ...children) {
  const node = document.createElementNS(attrs.ns || 'http://www.w3.org/1999/xhtml', tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'ns' || v === undefined || v === null || v === false) continue;
    if (k === 'class') node.setAttribute('class', v);
    else if (k === 'text') node.textContent = v;
    else if (k === 'dataset') Object.assign(node.dataset, v);
    else if (k === 'style' && typeof v === 'object') Object.assign(node.style, v);
    else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
    else node.setAttribute(k, v === true ? '' : String(v));
  }
  for (const c of children.flat()) {
    if (c === null || c === undefined || c === false) continue;
    node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return node;
}
export const svg = (tag, attrs = {}, ...kids) => el(tag, { ns: 'http://www.w3.org/2000/svg', ...attrs }, ...kids);
export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

// ── Feedback ─────────────────────────────────────────────────────────────────
let toastTimer = 0;
export function toast(msg, ms = 2600) {
  const t = document.getElementById('lx-toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('is-on');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('is-on'), ms);
}

// Marks a button busy (fast laser beam) for the duration of an async task.
export async function busy(btn, fn) {
  if (btn) { btn.classList.add('is-processing'); btn.disabled = true; btn.setAttribute('aria-busy', 'true'); }
  try { return await fn(); }
  finally { if (btn) { btn.classList.remove('is-processing'); btn.disabled = false; btn.removeAttribute('aria-busy'); } }
}

// ── Bytes & encodings ────────────────────────────────────────────────────────
export const enc = new TextEncoder();
export const dec = new TextDecoder();
export function toHex(u8) { return Array.from(u8, b => b.toString(16).padStart(2, '0')).join(''); }
export function fromHex(h) {
  const s = h.replace(/\s+/g, '');
  if (!/^[0-9a-fA-F]*$/.test(s) || s.length % 2) throw new Error('Not valid hex.');
  const out = new Uint8Array(s.length / 2);
  for (let i = 0; i < out.length; i++) out[i] = parseInt(s.substr(i * 2, 2), 16);
  return out;
}
export function toB64(u8) {
  let s = '';
  for (let i = 0; i < u8.length; i += 0x8000) s += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000));
  return btoa(s);
}
export function fromB64(b64) {
  const s = atob(b64.replace(/-/g, '+').replace(/_/g, '/').replace(/\s+/g, ''));
  const out = new Uint8Array(s.length);
  for (let i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
  return out;
}
export const toB64url = u8 => toB64(u8).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
export function concat(...parts) {
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let o = 0;
  for (const p of parts) { out.set(p, o); o += p.length; }
  return out;
}
// Constant-time comparison (no early exit on the first differing byte).
export function ctEqual(a, b) {
  if (a.length !== b.length) return false;
  let d = 0;
  for (let i = 0; i < a.length; i++) d |= a[i] ^ b[i];
  return d === 0;
}
export const randomBytes = n => crypto.getRandomValues(new Uint8Array(n));

export function fmtBytes(n) {
  if (!Number.isFinite(n)) return '—';
  const u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB'];
  let i = 0;
  while (Math.abs(n) >= 1000 && i < u.length - 1) { n /= 1000; i++; }
  return (i ? n.toFixed(n < 10 ? 2 : n < 100 ? 1 : 0) : Math.round(n)) + ' ' + u[i];
}
export function fmtNum(n, digits = 0) {
  if (!Number.isFinite(n)) return '—';
  return n.toLocaleString(undefined, { maximumFractionDigits: digits, minimumFractionDigits: 0 });
}
export function fmtCompact(n) {
  if (!Number.isFinite(n)) return '—';
  return new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(n);
}
export function fmtDuration(sec) {
  sec = Math.max(0, Math.round(sec));
  const h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
  return h ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`;
}

// ── Files ────────────────────────────────────────────────────────────────────
export function download(data, filename, mime = 'application/octet-stream') {
  const blob = data instanceof Blob ? data : new Blob([data], { type: mime });
  const url = URL.createObjectURL(blob);
  const a = el('a', { href: url, download: filename });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 4000);
}
export function readFileBytes(file) {
  return file.arrayBuffer().then(b => new Uint8Array(b));
}

// Wires a .lx-drop zone: click/keyboard to browse, drag & drop, change events.
export function dropZone(zone, onFiles) {
  const input = zone.querySelector('input[type=file]');
  const stop = e => { e.preventDefault(); e.stopPropagation(); };
  ['dragenter', 'dragover'].forEach(t => zone.addEventListener(t, e => { stop(e); zone.classList.add('is-over'); }));
  ['dragleave', 'dragend', 'drop'].forEach(t => zone.addEventListener(t, e => { stop(e); zone.classList.remove('is-over'); }));
  zone.addEventListener('drop', e => { const f = [...(e.dataTransfer?.files || [])]; if (f.length) onFiles(f); });
  zone.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input?.click(); } });
  input?.addEventListener('change', () => { if (input.files.length) onFiles([...input.files]); input.value = ''; });
}

// Accessible tab sets: [role=tablist] buttons with aria-controls.
export function tabs(list, onChange) {
  const btns = $$('[role="tab"]', list);
  const select = (btn, focus) => {
    btns.forEach(b => {
      const on = b === btn;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
      const p = document.getElementById(b.getAttribute('aria-controls'));
      if (p) p.hidden = !on;
    });
    if (focus) btn.focus();
    onChange?.(btn);
    if (btn.dataset.hash) history.replaceState(null, '', '#' + btn.dataset.hash);
  };
  btns.forEach((b, i) => {
    b.addEventListener('click', () => select(b));
    b.addEventListener('keydown', e => {
      const d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
      if (d) { e.preventDefault(); select(btns[(i + d + btns.length) % btns.length], true); }
    });
  });
  const fromHash = btns.find(b => b.dataset.hash && '#' + b.dataset.hash === location.hash);
  select(fromHash || btns.find(b => b.getAttribute('aria-selected') === 'true') || btns[0]);
}

// ── Community sync ───────────────────────────────────────────────────────────
export async function syncGet() {
  try {
    const r = await fetch(labsBase + 'api/progress', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return r.ok ? r.json() : { signedIn: false };
  } catch { return { signedIn: false }; }
}
export async function syncPut(progress) {
  const r = await fetch(labsBase + 'api/progress', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ progress }),
  });
  if (!r.ok) throw new Error('sync ' + r.status);
  return r.json();
}

// ── Page-level wiring shared by every Labs page ──────────────────────────────
document.addEventListener('click', async e => {
  const copy = e.target.closest('[data-copy]');
  if (copy) {
    const src = document.querySelector(copy.dataset.copy);
    const text = src ? (src.value ?? src.textContent) : '';
    try { await navigator.clipboard.writeText(text.trim()); toast('Copied to clipboard'); }
    catch { toast('Copy failed — select the text and copy it manually'); }
  }
  if (e.target.closest('[data-download-code]')) {
    const code = document.getElementById('lx-recovery')?.textContent.trim();
    if (code) download(`Hastra Labs recovery code\n\n${code}\n\nKeep this private. It restores your community profile on any device.\n`, 'hastra-labs-recovery-code.txt', 'text/plain');
  }
});
