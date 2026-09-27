// Hastra — Scan Center client (portals/security/scan_center.php).
// Findings contain attacker-influenced text (file names from uploaded
// archives, ZAP evidence, URLs), so every dynamic value goes into the page
// with textContent / el(), never innerHTML.
(function () {
  'use strict';
  const cfg = window.SCAN_CENTER || {};
  const $ = (id) => document.getElementById(id);
  const SEV = ['critical', 'high', 'medium', 'low', 'info'];
  const SEV_LABEL = { critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low', info: 'Info' };
  const STATUS_LABEL = { open: 'Open', resolved: 'Resolved', false_positive: 'False positive' };
  const state = { findings: [], scans: [], sevOn: new Set(SEV), sort: 'severity', dir: 1, current: null };

  function el(tag, attrs, ...kids) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs || {})) {
      if (k === 'class') n.className = v; else if (k === 'text') n.textContent = v; else n.setAttribute(k, v);
    }
    for (const k of kids) if (k != null) n.append(k instanceof Node ? k : document.createTextNode(String(k)));
    return n;
  }
  function toast(msg, isErr) {
    const t = $('toast'); t.textContent = msg; t.className = 'sc-toast' + (isErr ? ' err' : ''); t.hidden = false;
    clearTimeout(toast._t); toast._t = setTimeout(() => { t.hidden = true; }, isErr ? 7000 : 3500);
  }
  async function api(path, opts) {
    const res = await fetch(cfg.api + path, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, opts || {}));
    let j = null; try { j = await res.json(); } catch (_) { /* non-JSON */ }
    if (!res.ok || !j || !j.ok) throw new Error((j && j.error) || ('Request failed (HTTP ' + res.status + ')'));
    return j;
  }

  // ── Data ──────────────────────────────────────────────────────────────
  async function load(scanId) {
    const q = scanId ? '?scan_id=' + encodeURIComponent(scanId) : '';
    const j = await api('findings.php' + q);
    state.findings = j.findings; state.scans = j.scans;
    renderScans(scanId); renderSevbar(); renderRows();
  }
  function renderScans(selected) {
    const s = $('fScan'); const keep = selected != null ? String(selected) : s.value;
    s.replaceChildren(el('option', { value: '', text: 'All scans' }));
    for (const sc of state.scans) {
      const kind = sc.scan_type === 'sast_codebase' ? 'Code' : 'ZAP';
      const total = (+sc.critical_count) + (+sc.high_count) + (+sc.medium_count) + (+sc.low_count) + (+sc.info_count);
      const label = `#${sc.id} ${kind} · ${sc.source_label || 'untitled'} · ${sc.status === 'completed' ? total + ' open' : sc.status}`;
      s.append(el('option', { value: sc.id, text: label }));
    }
    s.value = keep;
  }
  function visible() {
    const src = $('fSource').value, st = $('fStatus').value, txt = $('fText').value.trim().toLowerCase();
    let list = state.findings.filter((f) => state.sevOn.has(f.severity) && (!src || f.source === src) && (!st || f.status === st));
    if (txt) list = list.filter((f) => [f.title, f.file_path, f.rule_id, f.cwe_id, ...(f.instances || []).map((i) => i.uri)].join(' ').toLowerCase().includes(txt));
    const key = state.sort, d = state.dir;
    list.sort((a, b) => {
      if (key === 'severity') return (SEV.indexOf(a.severity) - SEV.indexOf(b.severity)) * d || b.id - a.id;
      return String(a[key] || '').localeCompare(String(b[key] || '')) * d;
    });
    return list;
  }
  function renderSevbar() {
    const bar = $('sevbar'); bar.replaceChildren();
    const st = $('fStatus').value;
    for (const s of SEV) {
      const n = state.findings.filter((f) => f.severity === s && (!st || f.status === st)).length;
      const b = el('button', { type: 'button', 'aria-pressed': state.sevOn.has(s) ? 'true' : 'false' }, el('span', { class: 'sc-sev ' + s, text: SEV_LABEL[s] }), el('b', { text: n }));
      b.addEventListener('click', () => { state.sevOn.has(s) ? state.sevOn.delete(s) : state.sevOn.add(s); if (!state.sevOn.size) state.sevOn = new Set(SEV); renderSevbar(); renderRows(); });
      bar.append(b);
    }
  }
  function location(f) {
    if (f.file_path) return f.file_path + (f.line_number ? ':' + f.line_number : '');
    const inst = f.instances || [];
    if (!inst.length) return '';
    return inst[0].uri + (inst.length > 1 ? `  (+${inst.length - 1} more)` : '');
  }
  function renderRows() {
    const tb = $('rows'); tb.replaceChildren();
    const list = visible();
    $('empty').hidden = list.length > 0;
    for (const f of list) {
      const tr = el('tr', { tabindex: '0' },
        el('td', {}, el('span', { class: 'sc-sev ' + f.severity, text: SEV_LABEL[f.severity] })),
        el('td', {}, el('span', { class: 't-title', text: f.title }), el('span', { class: 't-sub', text: [f.cwe_id, f.rule_id].filter(Boolean).join(' · ') })),
        el('td', { class: 't-loc', text: location(f) }),
        el('td', { text: f.source === 'owasp_zap' ? 'OWASP ZAP' : 'Source code' }),
        el('td', {}, el('span', { class: 'sc-status ' + f.status, text: STATUS_LABEL[f.status] })));
      tr.addEventListener('click', () => openDrawer(f));
      tr.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDrawer(f); } });
      tb.append(tr);
    }
  }

  // ── Drawer ────────────────────────────────────────────────────────────
  let lastFocus = null;
  function openDrawer(f) {
    state.current = f; lastFocus = document.activeElement;
    $('dSev').className = 'sc-sev ' + f.severity; $('dSev').textContent = SEV_LABEL[f.severity];
    $('dTitle').textContent = f.title;
    $('dMeta').textContent = [f.rule_id, f.cwe_id, f.wasc_id, f.confidence ? 'confidence ' + f.confidence : null, 'scan #' + f.scan_id].filter(Boolean).join('  ·  ');
    $('dSummary').textContent = f.description;

    const surf = $('dSurface'); surf.replaceChildren();
    if (f.file_path) {
      surf.append(el('div', { class: 'sc-surface-file', text: f.file_path + (f.line_number ? '  line ' + f.line_number : '') }));
      if (f.code_snippet) surf.append(el('pre', { text: f.code_snippet }));
    } else {
      const inst = f.instances || [];
      surf.append(el('p', { text: `${f.instance_count} occurrence${f.instance_count === 1 ? '' : 's'} across ${inst.length} endpoint${inst.length === 1 ? '' : 's'}` + (f.vulnerable_param ? `; parameters: ${f.vulnerable_param}` : '') + '.' }));
      const ul = el('ul', { class: 'sc-endpoints' });
      for (const i of inst.slice(0, 200)) {
        ul.append(el('li', {}, el('span', { class: 'm', text: i.method }), ' ', i.uri, i.param ? el('span', { class: 'p', text: '  [' + i.param + ']' }) : null,
          i.evidence ? el('span', { class: 'e', text: 'evidence: ' + i.evidence }) : null));
      }
      surf.append(ul);
    }

    const r = f.remediation || {};
    const steps = $('dSteps'); steps.replaceChildren(...(r.steps || []).map((s) => el('li', { text: s })));
    $('dDiff').hidden = !(r.before || r.after);
    $('dBefore').textContent = r.before || ''; $('dAfter').textContent = r.after || '';
    const refs = $('dRefs'); refs.replaceChildren();
    const links = (r.references || []).filter((u) => /^https?:\/\//.test(u));
    if (links.length) {
      refs.className = 'sc-refs';
      refs.append('References: ');
      links.forEach((u, k) => { if (k) refs.append(' · '); refs.append(el('a', { href: u, target: '_blank', rel: 'noopener noreferrer', text: u })); });
    }
    document.querySelectorAll('.sc-drawer-foot [data-status]').forEach((b) => { b.hidden = b.dataset.status === f.status; });
    $('drawer').hidden = false; $('dClose').focus();
  }
  function closeDrawer() { $('drawer').hidden = true; state.current = null; if (lastFocus) lastFocus.focus(); }
  $('dClose').addEventListener('click', closeDrawer);
  $('drawer').addEventListener('click', (e) => { if (e.target === $('drawer')) closeDrawer(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('drawer').hidden) closeDrawer(); });
  document.querySelectorAll('.sc-drawer-foot [data-status]').forEach((b) => b.addEventListener('click', async () => {
    const f = state.current; if (!f) return;
    const fd = new FormData(); fd.append('csrf_token', cfg.csrf); fd.append('finding_id', f.id); fd.append('status', b.dataset.status);
    try {
      await api('findings.php', { method: 'POST', body: fd });
      f.status = b.dataset.status; closeDrawer(); renderSevbar(); renderRows();
      toast('Finding marked ' + STATUS_LABEL[f.status].toLowerCase() + '.');
    } catch (err) { toast(err.message, true); }
  }));

  // ── Live stage ────────────────────────────────────────────────────────
  const CIRC = 2 * Math.PI * 52;
  function stage(mode, title) {
    const s = $('stage'); s.hidden = false; s.className = 'sc-stage ' + mode;
    $('stageState').textContent = { running: 'SCANNING', done: 'COMPLETE', failed: 'FAILED', queued: 'QUEUED' }[mode] || mode.toUpperCase();
    if (title) $('stageTitle').textContent = title;
  }
  function ring(pct) { $('ringFill').style.strokeDashoffset = String(CIRC * (1 - Math.max(0, Math.min(100, pct)) / 100)); $('ringPct').textContent = Math.round(pct); }
  function tick(text, cls) {
    const t = $('ticker'); t.append(el('li', { class: cls || '', text }));
    while (t.children.length > 8) t.firstElementChild.remove();
  }
  function metrics(p) { $('mScanned').textContent = p.scanned ?? 0; $('mSkipped').textContent = p.skipped ?? 0; $('mIssues').textContent = p.issues_found ?? 0; $('mTotal').textContent = p.total ?? 0; }

  function runStream(scanId, label) {
    stage('running', 'Scanning ' + label); ring(0); $('ticker').replaceChildren(); tick('> Hastra SAST engine started', 'ok');
    const es = new EventSource(cfg.api + 'stream_scan.php?scan_id=' + encodeURIComponent(scanId));
    let lastIssues = 0, finished = false;
    es.addEventListener('progress', (e) => {
      const p = JSON.parse(e.data);
      ring(p.percent); metrics(p);
      if (p.skip_reason) tick('  skip ' + p.current_file + '  (' + p.skip_reason + ')', 'skip');
      else tick((p.issues_found > lastIssues ? '! ' : '  ') + p.current_file + (p.issues_found > lastIssues ? '  +' + (p.issues_found - lastIssues) + ' issue(s)' : ''), p.issues_found > lastIssues ? 'hit' : '');
      lastIssues = p.issues_found;
    });
    es.addEventListener('complete', async (e) => {
      const d = JSON.parse(e.data); finished = true;
      ring(100); stage('done', 'Scan complete: ' + label);
      const c = d.counts || {};
      tick(`> Done. ${d.stats ? d.stats.scanned : 0} files scanned, ${d.stats ? d.stats.skipped : 0} skipped. Critical ${c.critical || 0}, high ${c.high || 0}, medium ${c.medium || 0}, low ${c.low || 0}.`, 'ok');
      if (d.stats) metrics({ scanned: d.stats.scanned, skipped: d.stats.skipped, total: d.stats.total, issues_found: Object.values(c).reduce((a, b) => a + b, 0) });
      await load(scanId); $('fScan').value = String(scanId);
    });
    es.addEventListener('error', (e) => {
      if (e.data) { finished = true; stage('failed', 'Scan failed'); tick('> ' + JSON.parse(e.data).message, 'bad'); toast(JSON.parse(e.data).message, true); }
    });
    es.addEventListener('done', () => { es.close(); setBusy(false); });
    es.onerror = () => { if (!finished && es.readyState === EventSource.CLOSED) { stage('failed', 'Connection lost'); setBusy(false); } };
  }

  // ── Uploads ───────────────────────────────────────────────────────────
  function setBusy(b) { ['dropCode', 'dropZap'].forEach((id) => $(id).classList.toggle('busy', b)); }
  async function uploadCode(file) {
    if (!/\.zip$/i.test(file.name)) return toast('Upload a .zip archive of your project.', true);
    if (file.size > 20 * 1024 * 1024) return toast('The archive is larger than 20 MB.', true);
    setBusy(true); stage('queued', 'Uploading ' + file.name); ring(0); $('ticker').replaceChildren(); tick('> Uploading ' + file.name + ' (' + Math.round(file.size / 1024) + ' KB)');
    const fd = new FormData(); fd.append('csrf_token', cfg.csrf); fd.append('archive', file);
    try { const j = await api('upload_code.php', { method: 'POST', body: fd }); tick('> ' + j.entries + ' entries queued', 'ok'); runStream(j.scan_id, j.label); }
    catch (err) { stage('failed', 'Upload failed'); tick('> ' + err.message, 'bad'); toast(err.message, true); setBusy(false); }
  }
  async function uploadZap(file) {
    if (!/\.(json|xml)$/i.test(file.name)) return toast('Upload a ZAP report exported as JSON or XML.', true);
    setBusy(true); stage('running', 'Importing ' + file.name); ring(35); $('ticker').replaceChildren(); tick('> Parsing ZAP report ' + file.name);
    const fd = new FormData(); fd.append('csrf_token', cfg.csrf); fd.append('report', file);
    try {
      const j = await api('upload_zap.php', { method: 'POST', body: fd });
      ring(100); stage('done', 'Imported ' + j.label);
      metrics({ scanned: j.findings, skipped: 0, total: j.raw_alerts, issues_found: Object.values(j.counts).reduce((a, b) => a + b, 0) });
      tick(`> ${j.raw_alerts} ZAP alerts clustered into ${j.findings} findings`, 'ok');
      await load(j.scan_id); $('fScan').value = String(j.scan_id);
    } catch (err) { stage('failed', 'Import failed'); tick('> ' + err.message, 'bad'); toast(err.message, true); }
    setBusy(false);
  }
  function wireDrop(zoneId, inputId, handler) {
    const z = $(zoneId), inp = $(inputId);
    inp.addEventListener('change', () => { if (inp.files[0]) handler(inp.files[0]); inp.value = ''; });
    z.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); } });
    ['dragenter', 'dragover'].forEach((ev) => z.addEventListener(ev, (e) => { e.preventDefault(); z.classList.add('over'); }));
    ['dragleave', 'drop'].forEach((ev) => z.addEventListener(ev, () => z.classList.remove('over')));
    z.addEventListener('drop', (e) => { e.preventDefault(); const f = e.dataTransfer.files[0]; if (f) handler(f); });
  }
  wireDrop('dropCode', 'fileCode', uploadCode);
  wireDrop('dropZap', 'fileZap', uploadZap);

  // ── Filters / sort ────────────────────────────────────────────────────
  $('fScan').addEventListener('change', () => load($('fScan').value || null).catch((e) => toast(e.message, true)));
  ['fSource', 'fStatus'].forEach((id) => $(id).addEventListener('change', () => { renderSevbar(); renderRows(); }));
  $('fText').addEventListener('input', renderRows);
  document.querySelectorAll('.sc-table th [data-sort]').forEach((b) => b.addEventListener('click', () => {
    const k = b.dataset.sort; state.dir = state.sort === k ? -state.dir : 1; state.sort = k; renderRows();
  }));

  load(null).catch((e) => toast(e.message, true));
})();
