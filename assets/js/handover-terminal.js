// Astra — live handover terminal client (portals/deliveries/terminal.php).
// The server does every check; this script only streams the results it
// returns, line by line, then shows the payload and the destruction receipt.
(function () {
  'use strict';
  const cfg = window.ASTRA_TERMINAL || {};
  const $ = (id) => document.getElementById(id);
  const form = $('htForm'), code = $('htCode'), go = $('htGo'), state = $('htState');
  const out = $('htConsole'), payloadBox = $('htPayload'), text = $('htText'), receipt = $('htReceipt');
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const sleep = (ms) => new Promise((r) => setTimeout(r, reduced ? 0 : ms));
  let payload = null;
  let t0 = 0;

  // An access code in the URL fragment (#t=...) pre-fills the field and is
  // removed from the address bar so it doesn't linger in history.
  const m = location.hash.match(/(?:^#|&)t=([a-fA-F0-9]{64})/);
  if (m) {
    code.value = m[1];
    history.replaceState(null, '', location.pathname + location.search);
  }

  function setState(label, cls) { state.textContent = label; state.className = 'ht-state' + (cls ? ' ' + cls : ''); }
  const stamp = () => '[' + ((performance.now() - t0) / 1000).toFixed(2) + 's]';

  // Typewriter one line; returns once it's fully printed.
  async function type(html, cls) {
    const line = document.createElement('div');
    line.className = 'ht-line' + (cls ? ' ' + cls : '');
    out.appendChild(line);
    const tmp = document.createElement('div');
    tmp.innerHTML = html;
    const plain = tmp.textContent;
    if (reduced) { line.innerHTML = html; return; }
    line.classList.add('ht-caret');
    for (let i = 1; i <= plain.length; i += 2) {
      line.textContent = plain.slice(0, i);
      await sleep(8);
    }
    line.classList.remove('ht-caret');
    line.innerHTML = html; // final render with colour spans
  }
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  async function post(body) {
    const fd = new FormData();
    fd.append('csrf_token', cfg.csrf);
    Object.entries(body).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch(cfg.endpoint, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!res.ok && res.status === 401) throw new Error('Your session ended. Sign in again.');
    return res.json();
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const token = code.value.trim();
    if (!/^[a-fA-F0-9]{64}$/.test(token)) { code.focus(); return; }
    go.disabled = true; code.disabled = true;
    out.hidden = false; out.innerHTML = '';
    setState('RUNNING', 'run');
    t0 = performance.now();

    let data;
    try {
      data = await post({ action: 'handshake', token });
    } catch (err) {
      await type(stamp() + ' <span class="bad">CONNECTION FAILED</span> ' + esc(err.message || ''));
      setState('FAILED', 'fail');
      go.disabled = false; code.disabled = false;
      return;
    }
    code.value = '';

    for (const s of data.steps || []) {
      await sleep(260);
      const verdict = s.detail && s.ok ? ' <span class="ok">' + esc(s.detail) + '</span>'
                    : s.ok ? '' : ' <span class="bad">FAILED</span>';
      await type('<span class="t">' + stamp() + '</span> ' + esc(s.text) + verdict);
      if (!s.ok && s.detail) await type(esc(s.detail), 'detail');
    }

    if (data.payload !== undefined) {
      payload = data.payload;
      $('htProject').textContent = data.project;
      text.textContent = payload;
      payloadBox.hidden = false;
      setState('DECRYPTED', 'ok');
      await sleep(350);
      await type('<span class="t">' + stamp() + '</span> <span class="warn">[!] Zeroing memory ledger and shredding storage payload...</span>');
      await sleep(200);
      const r = data.receipt || {};
      await type('<span class="t">' + stamp() + '</span> Storage payload overwritten with random bytes.' +
                 (r.ledger_block ? ' <span class="ok">Ledger block #' + esc(r.ledger_block) + '</span>' : ''));
      fillReceipt(r);
      window.addEventListener('pagehide', wipe);
    } else if (data.receipt) {
      setState('DESTROYED', 'fail');
      fillReceipt(data.receipt);
      receipt.hidden = false;
    } else {
      setState('LOCKED', 'fail');
      go.disabled = false; code.disabled = false;
    }
  });

  function fillReceipt(r) {
    $('rcDossier').textContent = '#' + (r.dossier_id ?? '-');
    $('rcProject').textContent = r.project || '-';
    $('rcAt').textContent = r.shredded_at || '-';
    $('rcBlock').textContent = r.ledger_block ? '#' + r.ledger_block + ' · ' + (r.ledger_hash || '').slice(0, 16) + '…' : 'recorded';
  }

  $('htCopy').addEventListener('click', async () => {
    if (payload === null) return;
    try { await navigator.clipboard.writeText(payload); $('htCopy').textContent = 'Copied'; }
    catch (_) { $('htCopy').textContent = 'Copy failed'; }
  });
  $('htDownload').addEventListener('click', () => {
    if (payload === null) return;
    const url = URL.createObjectURL(new Blob([payload], { type: 'text/plain' }));
    const a = Object.assign(document.createElement('a'), { href: url, download: 'astra-handover.txt' });
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  });

  // Visual shred: scramble the text in place, then drop it from the page.
  // (JavaScript can't guarantee memory is zeroed; this removes every
  // reference the page holds so the browser can discard it.)
  async function wipe() {
    if (payload === null) return;
    const glyphs = '▓▒░█#@%&*01';
    text.classList.add('shredding');
    const len = Math.min(payload.length, 4000);
    for (let pass = 0; pass < (reduced ? 0 : 6); pass++) {
      let s = '';
      for (let i = 0; i < len; i++) s += payload[i] === '\n' ? '\n' : glyphs[(Math.random() * glyphs.length) | 0];
      text.textContent = s;
      await sleep(70);
    }
    text.textContent = '';
    payload = null;
    payloadBox.hidden = true;
    receipt.hidden = false;
    setState('DESTROYED', 'fail');
  }
  $('htWipe').addEventListener('click', wipe);
})();
