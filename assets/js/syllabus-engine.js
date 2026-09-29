/* Hastra Labs — Module 3: Student Syllabus Accelerator & Video Tutor.

   Pipeline
     documents ─▶ doc-extract.js ─▶ parseSyllabus() ─▶ course › unit › topic tree
     topic ─▶ /labs/api/youtube (view-ranked) ─▶ carousel with like / dislike-swap
     checkmarks + targets ─▶ forecast() ─▶ pace status, projection, motivation

   State lives in localStorage (this device) and, for a signed-in community
   session, is mirrored to /labs/api/progress. Merging is per field with
   timestamps, so two devices can study the same syllabus without clobbering
   each other's checkmarks. */
import { $, $$, el, svg, toast, busy, dropZone, download, labsBase, csrfToken, enc, toHex, fmtNum, fmtCompact, fmtDuration, syncGet, syncPut } from './labs-common.js';
import { extractText } from './doc-extract.js';

const STORE_KEY = 'hastra_labs_study_v1';
const DEFAULT_VIDEO_SEC = 45 * 60;
const DAY = 86400000;

// ════════════════════════════════════════════════════════════════════════════
// Parsing
// ════════════════════════════════════════════════════════════════════════════
const ROMAN = /^(?=[ivxlc])c{0,3}(?:xc|xl|l?x{0,3})(?:ix|iv|v?i{0,3})$/i;
const HEAD = /^\s*(?:[•\-*▪●◦]\s*)?(unit|module|chapter|part|week|block|lesson)\s*[-–—:.#]?\s*(\d{1,2}|[ivxlc]{1,7})(?![a-z0-9])\s*[-–—:.)\]]*\s*(.*)$/i;
const SECTION_STOP = /^(text\s*-?\s*books?|reference\s*books?|references?|suggested\s+(readings?|books)|further\s+reading|course\s+(outcomes?|objectives?)|learning\s+(outcomes?|objectives?)|(lab|laboratory)\s+(experiments?|exercises|component)|list\s+of\s+(experiments|practicals)|practicals?|e-?\s*resources|web\s*(resources|links)|online\s+resources|assessment|evaluation\s+scheme|mapping|co\s*[-–]?\s*po|prerequisites?|pre-requisites?|teaching\s+methodology|mode\s+of\s+evaluation)\b/i;
const NOISE = /^(page\s*\d+(\s*of\s*\d+)?|\d+|l\s*t\s*p\s*(j\s*)?c?(\s+\d+){0,5}|credits?\b.*|total\s+(hours|periods|lectures)\b.*|semester\b.*|(course|subject|paper)\s+code\b.*|max(imum)?\s+marks\b.*|(hours?|periods?|lectures?)\s*[:\-]?\s*\d+|\(?\d+\s*(hrs?|hours|periods|lectures|l)\)?\.?|syllabus|contents?|detailed\s+syllabus)$/i;
const BULLET = /^\s*(?:[•●▪◦‣∙*·]|-(?=\s)|\d+(?:\.\d+)*[.)]?\s|[a-z][.)]\s|\([a-z0-9ivx]+\)\s|[ivx]+[.)]\s)/i;

// ALL-CAPS headings become Title Case, but acronyms (and anything in
// parentheses, where syllabi usually spell them out) stay upper-case.
const ACRONYMS = new Set(('SIEM SOC UEBA API AWS GCP DNS TLS SSL IOC IOA MITRE ATT&CK NIST ISO IT IOT AI ML DL NLP SQL NOSQL OS CPU GPU TCP UDP IP ' +
  'HTTP HTTPS XSS CSRF SOAR EDR XDR NDR IDS IPS VPN IAM RBAC PKI OSI LAN WAN UI UX DBMS RDBMS OOP CI CD SDLC STIX TAXII MISP ECS OCSF ' +
  'K8S HTML CSS JS PHP REST JSON XML CNN RNN LSTM GAN AR VR DSA DAA TOC CN OOPS COA DS DBA').split(' '));
const titleCase = s => {
  if (s !== s.toUpperCase() || !/[A-Z]{3}/.test(s)) return s;
  let depth = 0;
  return s.split(/(\s+|[()\-/])/).map((w, i) => {
    if (w === '(') depth++;
    if (w === ')') depth = Math.max(0, depth - 1);
    if (!/[A-Z]/.test(w)) return w;
    if (depth > 0 || ACRONYMS.has(w.replace(/[^A-Z0-9&]/g, ''))) return w;
    const low = w.toLowerCase();
    if (i > 0 && /^(and|of|in|on|for|to|the|a|an|with|via|vs|at|by)$/.test(low)) return low;
    return low.charAt(0).toUpperCase() + low.slice(1);
  }).join('');
};
const stripHours = s => s.replace(/\s*[\[(]?\s*\d+\s*(hrs?|hours|periods|lectures|l|h)\s*[\])]?\s*\.?$/i, '').replace(/\s+\d{1,2}$/, '').trim();

function cleanTopic(raw) {
  let s = raw.replace(BULLET, '').replace(/^[\s\-–—:;,.]+|[\s\-–—:;,]+$/g, '');
  s = stripHours(s).replace(/\s{2,}/g, ' ').replace(/\s+([,.;:])/g, '$1').replace(/[.;:]+$/, '').trim();
  if (s.length < 3 || !/[a-z]/i.test(s) || NOISE.test(s)) return null;
  if (s.length > 140) s = s.slice(0, 137).replace(/\s+\S*$/, '') + '…';
  return s.charAt(0).toUpperCase() + s.slice(1);
}

// Splits on , ; • and spaced dashes — but never inside parentheses.
function splitTopics(text) {
  const out = [];
  let depth = 0, cur = '';
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (c === '(' || c === '[') depth++;
    if (c === ')' || c === ']') depth = Math.max(0, depth - 1);
    const dash = (c === '–' || c === '—' || (c === '-' && text[i - 1] === ' ' && text[i + 1] === ' '));
    if (depth === 0 && (c === ',' || c === ';' || c === '•' || c === '●' || c === '▪' || dash || (c === '.' && text[i + 1] === ' ' && /[A-Z]/.test(text[i + 2] || '')))) {
      out.push(cur); cur = '';
    } else cur += c;
  }
  out.push(cur);
  return out;
}

function unitBodyToTopics(lines) {
  const topics = [];
  const bulletish = lines.filter(l => BULLET.test(l)).length;
  if (lines.length >= 2 && bulletish >= lines.length * 0.6) {
    // a list: one topic per item, continuation lines glued to the previous item
    let cur = null;
    for (const l of lines) {
      if (BULLET.test(l) || cur === null) { if (cur) topics.push(cur); cur = l; }
      else cur += ' ' + l;
    }
    if (cur) topics.push(cur);
    return topics.flatMap(t => t.length > 90 ? splitTopics(t) : [t]);
  }
  // prose: rejoin lines the PDF wrapped mid-sentence, then split the run
  let text = '';
  for (const l of lines) {
    if (!text) text = l;
    else if (/[.;:]$/.test(text)) text += '\n' + l;
    else text += ' ' + l;
  }
  return text.split('\n').flatMap(splitTopics);
}

export function parseSyllabus(lines, fallbackTitle) {
  const modules = [];
  let mod = null, skipping = false, preamble = [];
  let courseTitle = null;
  for (const raw of lines) {
    const line = raw.trim();
    if (!line) continue;
    const t = line.match(/^(?:course\s+(?:title|name)|subject(?:\s+name)?|paper)\s*[:\-–]\s*(.{4,90})$/i);
    if (t && !courseTitle) { courseTitle = titleCase(t[1].trim()); continue; }
    const h = line.match(HEAD);
    const num = h && (/^\d+$/.test(h[2]) || ROMAN.test(h[2])) ? h[2] : null;
    if (num) {
      let rest = h[3].trim();
      let inline = '';
      const colon = rest.match(/^([^:–—]{3,80}?)\s*[:–—]\s+(.{8,})$/);
      if (colon && /[,;]/.test(colon[2])) { rest = colon[1]; inline = colon[2]; }
      const kind = h[1].charAt(0).toUpperCase() + h[1].slice(1).toLowerCase();
      const name = stripHours(rest.replace(/^[-–—:.\s]+/, ''));
      mod = { title: `${kind} ${/^\d+$/.test(num) ? num : num.toUpperCase()}${name ? ': ' + titleCase(name) : ''}`, body: inline ? [inline] : [] };
      modules.push(mod);
      skipping = false;
      continue;
    }
    if (SECTION_STOP.test(line)) { skipping = true; continue; }
    if (skipping || NOISE.test(line)) continue;
    if (mod) mod.body.push(line); else preamble.push(line);
  }

  const seen = new Set();
  let out = modules.map(m => {
    const topics = [];
    const local = new Set();
    for (const raw of unitBodyToTopics(m.body)) {
      const c = cleanTopic(raw);
      if (!c) continue;
      const k = c.toLowerCase();
      if (local.has(k)) continue;
      local.add(k); seen.add(k);
      topics.push(c);
    }
    return { title: m.title, topics };
  }).filter(m => m.topics.length);

  if (!out.length) {
    // no unit headings: treat each meaningful line as a topic, in parts of eight
    const topics = [...new Set(preamble.filter(l => !SECTION_STOP.test(l)).flatMap(l => l.length > 90 ? splitTopics(l) : [l]).map(cleanTopic).filter(Boolean))];
    for (let i = 0; i < topics.length; i += 8) out.push({ title: `Part ${i / 8 + 1}`, topics: topics.slice(i, i + 8) });
  }
  // No "Course Title:" line: a short heading-like first line is the title
  // ("COMPUTER NETWORKS"); otherwise fall back to the file name.
  const lead = preamble.find(l => !SECTION_STOP.test(l));
  const leadTitle = lead && lead.length >= 4 && lead.length <= 70 && !/[.;]$/.test(lead) && (lead === lead.toUpperCase() || lead.split(/\s+/).length <= 8) ? titleCase(lead) : null;
  return { title: courseTitle || leadTitle || fallbackTitle, modules: out };
}

// ════════════════════════════════════════════════════════════════════════════
// State
// ════════════════════════════════════════════════════════════════════════════
const hash = async s => toHex(new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(s))));
const topicKey = t => t.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
const today = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d; };
const isoDay = d => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

function blankState() { return { v: 1, syllabi: {}, active: null, disliked: {}, updatedAt: 0 }; }
let state = (() => { try { const s = JSON.parse(localStorage.getItem(STORE_KEY)); return s && s.v === 1 ? s : blankState(); } catch { return blankState(); } })();
let signedIn = false, syncTimer = 0, syncing = false;

function persist(bump = true) {
  if (bump) state.updatedAt = Date.now();
  try { localStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch { toast('Browser storage is full — progress is not being saved.', 5000); }
  if (signedIn) { clearTimeout(syncTimer); syncTimer = setTimeout(pushSync, 1200); }
  paintSync();
}
const active = () => state.syllabi[state.active] || null;

// Timestamped fields merge newest-wins; dislike lists are unions.
function mergeState(a, b) {
  const out = blankState();
  out.updatedAt = Math.max(a.updatedAt || 0, b.updatedAt || 0);
  out.active = (b.updatedAt || 0) > (a.updatedAt || 0) ? b.active : a.active;
  for (const src of [a, b]) for (const [k, ids] of Object.entries(src.disliked || {})) out.disliked[k] = [...new Set([...(out.disliked[k] || []), ...ids])];
  const ids = new Set([...Object.keys(a.syllabi || {}), ...Object.keys(b.syllabi || {})]);
  for (const id of ids) {
    const x = a.syllabi?.[id], y = b.syllabi?.[id];
    if (!x || !y) { out.syllabi[id] = structuredClone(x || y); continue; }
    const newer = (y.settingsAt || 0) > (x.settingsAt || 0) ? y : x;
    const s = structuredClone(newer);
    for (const f of ['done', 'liked']) {
      s[f] = {};
      for (const src of [x, y]) for (const [k, v] of Object.entries(src[f] || {})) if (!s[f][k] || (v.ts || 0) > (s[f][k].ts || 0)) s[f][k] = v;
    }
    s.deleted = !!(x.deleted || y.deleted);
    out.syllabi[id] = s;
  }
  for (const [id, s] of Object.entries(out.syllabi)) if (s.deleted) delete out.syllabi[id];
  if (!out.syllabi[out.active]) out.active = Object.keys(out.syllabi)[0] || null;
  return out;
}
async function pushSync() {
  if (!signedIn || syncing) return;
  syncing = true;
  try { await syncPut(state); paintSync('synced'); }
  catch { paintSync('error'); }
  finally { syncing = false; }
}
function paintSync(status) {
  const chip = $('#syl-sync');
  if (!chip) return;
  chip.textContent = !signedIn ? 'Saved on this device · start a free session to sync'
    : status === 'error' ? 'Sync failed — saved on this device, will retry'
    : status === 'synced' ? 'Synced to your community profile' : 'Saving…';
}

// ════════════════════════════════════════════════════════════════════════════
// Building a syllabus
// ════════════════════════════════════════════════════════════════════════════
async function buildSyllabus(docs, opts) {
  const courses = [];
  for (const d of docs) {
    const parsed = parseSyllabus(d.lines, d.name.replace(/\.[a-z0-9]+$/i, '').replace(/[_-]+/g, ' ').trim() || 'Syllabus');
    const modules = [];
    for (const m of parsed.modules) {
      const topics = [];
      for (const t of m.topics) topics.push({ id: (await hash(`${parsed.title}|${m.title}|${t}`)).slice(0, 12), text: t });
      modules.push({ title: m.title, topics });
    }
    if (modules.length) courses.push({ title: parsed.title, modules });
  }
  if (!courses.length) throw new Error('No units or topics were found. Check that the document has a text layer, or paste it into a .txt file.');
  const id = (await hash(JSON.stringify(courses.map(c => [c.title, c.modules.map(m => [m.title, m.topics.map(t => t.text)])])))).slice(0, 12);
  const existing = state.syllabi[id];
  const now = Date.now();
  state.syllabi[id] = {
    id, courses, title: courses.map(c => c.title).join(' + '),
    createdAt: existing?.createdAt || now, startDate: existing?.startDate || isoDay(today()),
    target: opts.target, dailyHours: opts.dailyHours, multiplier: opts.multiplier, settingsAt: now,
    done: existing?.done || {}, liked: existing?.liked || {}, durations: existing?.durations || {},
  };
  state.active = id;
  persist();
  return { id, restored: !!existing };
}

// ════════════════════════════════════════════════════════════════════════════
// Forecast
// ════════════════════════════════════════════════════════════════════════════
export function forecast(s, now = new Date()) {
  const topics = s.courses.flatMap(c => c.modules.flatMap(m => m.topics));
  const total = topics.length;
  const doneTs = topics.map(t => s.done[t.id]).filter(d => d?.v).map(d => d.ts);
  const done = doneTs.length, remaining = total - done;
  const known = topics.map(t => s.durations?.[t.id]).filter(x => x > 0);
  const avgSec = known.length ? known.reduce((a, b) => a + b, 0) / known.length : DEFAULT_VIDEO_SEC;
  const mult = Math.max(0.25, +s.multiplier || 1);
  const perTopicH = avgSec / 3600 * mult;
  const requiredH = total * perTopicH, remainingH = remaining * perTopicH;
  const daily = Math.max(0, +s.dailyHours || 0);
  const t0 = new Date(now); t0.setHours(0, 0, 0, 0);
  const target = new Date(s.target + 'T23:59:59');
  const start = new Date(s.startDate + 'T00:00:00');
  const daysLeft = Math.max(0, Math.ceil((target - now) / DAY));
  const capacityH = daysLeft * daily;
  const daysNeeded = daily > 0 ? Math.ceil(remainingH / daily) : Infinity;
  const projected = Number.isFinite(daysNeeded) ? new Date(t0.getTime() + Math.max(0, daysNeeded - (remaining ? 1 : 0)) * DAY) : null;

  let status, tone;
  if (!remaining) { status = 'Complete'; tone = 'ok'; }
  else if (target < now) { status = `Target passed · ${fmtNum(remainingH, 1)} h left`; tone = 'crit'; }
  else if (capacityH >= remainingH * 1.1 && daysLeft - daysNeeded >= 1) { status = `Ahead · finish ${daysLeft - daysNeeded} day${daysLeft - daysNeeded === 1 ? '' : 's'} early`; tone = 'ok'; }
  else if (capacityH >= remainingH) { status = 'On Track'; tone = 'ok'; }
  else { status = `Behind by ${fmtNum(remainingH - capacityH, 1)} hrs`; tone = remainingH - capacityH > daily * 3 ? 'crit' : 'warn'; }

  // velocity since the start date vs the pace the target demands
  const elapsedDays = Math.max(1, Math.ceil((now - start) / DAY));
  const plannedDays = Math.max(1, Math.ceil((target - start) / DAY));
  const actualRate = done / elapsedDays, plannedRate = total / plannedDays;
  const ratio = plannedRate ? actualRate / plannedRate : 0;
  const days = new Set(doneTs.map(ts => isoDay(new Date(ts))));
  let streak = 0;
  for (let d = new Date(t0); days.has(isoDay(d)); d = new Date(d.getTime() - DAY)) streak++;
  if (!streak && days.has(isoDay(new Date(t0.getTime() - DAY)))) for (let d = new Date(t0.getTime() - DAY); days.has(isoDay(d)); d = new Date(d.getTime() - DAY)) streak++;

  let motivation;
  const neededDaily = daysLeft ? remainingH / daysLeft : remainingH;
  if (!total) motivation = 'Add a syllabus to get a plan.';
  else if (!remaining) motivation = `🏆 Syllabus mastered — all ${total} topics done${target >= now ? `, ${daysLeft} day${daysLeft === 1 ? '' : 's'} before your target` : ''}. Export your mastery report.`;
  else if (target < now) motivation = `⏰ Your target date has passed. At ${fmtNum(daily, 1)} h/day the remaining ${remaining} topics take about ${daysNeeded} days — set a new target and keep going.`;
  else if (!done) motivation = `🎯 Start with one topic today. You need about ${fmtNum(neededDaily, 1)} h/day to finish by ${target.toLocaleDateString()}${daily >= neededDaily ? ' — your plan covers it.' : `, and your plan has ${fmtNum(daily, 1)} h.`}`;
  else if (ratio >= 1.05) {
    const atPace = Math.ceil(remaining / actualRate);
    const early = daysLeft - atPace;
    motivation = `🔥 You're ${Math.round((ratio - 1) * 100)}% faster than your milestone target!` + (early > 0 ? ` Keep this streak to finish ${early} day${early === 1 ? '' : 's'} early.` : ' Keep the streak going.');
  } else if (ratio >= 0.95) motivation = `✅ Right on your milestone pace — about ${fmtNum(actualRate, 1)} topics a day. Keep it steady.`;
  else {
    const extraMin = Math.max(5, Math.round((neededDaily - daily) * 60));
    motivation = `⚡ You're ${Math.round((1 - ratio) * 100)}% behind your milestone pace. ` + (neededDaily > daily ? `Add about ${extraMin} min a day to land on ${target.toLocaleDateString()}.` : `Your daily hours still cover it — just keep showing up.`);
  }
  return { total, done, remaining, avgSec, knownDurations: known.length, mult, perTopicH, requiredH, remainingH, daysLeft, capacityH, daysNeeded,
           projected, status, tone, pct: total ? done / total : 0, streak, ratio, motivation, start, target };
}

// ════════════════════════════════════════════════════════════════════════════
// Rendering
// ════════════════════════════════════════════════════════════════════════════
function renderLibrary() {
  const box = $('#syl-library');
  const list = Object.values(state.syllabi).sort((a, b) => b.createdAt - a.createdAt);
  box.hidden = !list.length;
  $('#syl-lib-list').replaceChildren(...list.map(s => el('button', { type: 'button', 'aria-pressed': String(s.id === state.active), text: s.title,
    onclick: () => { state.active = s.id; persist(); renderAll(); } })));
}

function renderBanner() {
  const s = active(), host = $('#syl-banner');
  if (!s) { host.replaceChildren(); host.hidden = true; return; }
  host.hidden = false;
  const f = forecast(s);
  const C = 2 * Math.PI * 50;
  const ring = el('div', { class: 'lx-ring', role: 'img', 'aria-label': `${Math.round(f.pct * 100)}% complete` },
    svg('svg', { width: 116, height: 116, viewBox: '0 0 116 116' },
      svg('circle', { class: 'track', cx: 58, cy: 58, r: 50, fill: 'none', 'stroke-width': 9 }),
      svg('circle', { class: 'fill', cx: 58, cy: 58, r: 50, fill: 'none', 'stroke-width': 9, 'stroke-dasharray': C, 'stroke-dashoffset': C * (1 - f.pct) })),
    el('div', { class: 'lx-ring-label' }, el('b', { text: Math.round(f.pct * 100) + '%' }), el('span', { text: `${f.done}/${f.total} topics` })));
  const fmtDate = d => d ? d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  host.replaceChildren(el('div', { class: 'lx-banner' },
    ring,
    el('div', {},
      el('span', { class: 'lx-eyebrow', style: { margin: 0 } }, el('span', { class: 'lx-dot' }), 'Completion engine'),
      el('h2', { class: 'lx-h2', style: { marginTop: '6px' }, text: s.title }),
      el('div', { class: 'lx-formula', style: { whiteSpace: 'normal' } },
        `${f.total} topics × ${fmtDuration(f.avgSec)} avg video${f.mult !== 1 ? ` × ${f.mult} practice` : ''} = ${fmtNum(f.requiredH, 1)} h required · ${fmtNum(f.remainingH, 1)} h remaining`,
        f.knownDurations ? ` (durations from ${f.knownDurations} ranked video${f.knownDurations === 1 ? '' : 's'})` : ' (45-min default until videos load)'),
      el('p', { class: 'lx-motivate', text: f.motivation }),
      el('div', { class: 'lx-timeline-strip' },
        el('span', {}, 'Start ', el('b', { text: fmtDate(f.start) })),
        el('span', {}, 'Target ', el('b', { text: fmtDate(f.target) })),
        el('span', {}, 'Projected ', el('b', { text: f.remaining ? fmtDate(f.projected) : 'done' })),
        el('span', {}, 'Days left ', el('b', { text: f.daysLeft })),
        el('span', {}, 'Capacity ', el('b', { text: fmtNum(f.capacityH, 1) + ' h' })),
        f.streak ? el('span', {}, '🔥 ', el('b', { text: `${f.streak}-day streak` })) : '')),
    el('div', { class: 'lx-stack', style: { textAlign: 'right' } },
      el('span', { class: 'lx-badge lx-badge--' + f.tone, text: f.status }),
      el('div', { class: 'lx-row lx-row--end lx-noprint' },
        el('span', { id: 'syl-scan-control' }),
        el('button', { class: 'lx-btn lx-btn--ghost lx-btn--sm', type: 'button', text: 'Report (.md)', onclick: () => exportReport('md') }),
        el('button', { class: 'lx-btn lx-btn--ghost lx-btn--sm', type: 'button', text: 'JSON', onclick: () => exportReport('json') }),
        el('button', { class: 'lx-btn lx-btn--ghost lx-btn--sm', type: 'button', text: 'Print / PDF', onclick: () => window.print() })))));
  $('#syl-target').value = s.target; $('#syl-hours').value = s.dailyHours; $('#syl-mult').value = s.multiplier;
  paintScanControl();
}

function renderTree() {
  const s = active(), host = $('#syl-tree');
  if (!s) {
    host.replaceChildren(el('div', { class: 'lx-pane lx-muted', text: 'Upload one or more syllabi, or try the demo, to build your study plan.' }));
    return;
  }
  host.replaceChildren(...s.courses.map(c => el('section', { class: 'lx-course' },
    el('div', { class: 'lx-course-head' }, el('h2', { text: c.title }),
      el('span', { class: 'lx-muted', text: `${c.modules.length} unit${c.modules.length === 1 ? '' : 's'} · ${c.modules.reduce((n, m) => n + m.topics.length, 0)} topics` })),
    ...c.modules.map((m, mi) => {
      const doneN = m.topics.filter(t => s.done[t.id]?.v).length;
      return el('details', { class: 'lx-module', open: mi === 0 || doneN < m.topics.length ? true : null },
        el('summary', {}, el('span', { class: 'lx-module-title', text: m.title }),
          el('span', { class: 'lx-module-meta', text: `${doneN}/${m.topics.length}` }),
          el('span', { class: 'lx-minibar', 'aria-hidden': 'true' }, el('i', { style: { width: (m.topics.length ? doneN / m.topics.length * 100 : 0) + '%' } }))),
        ...m.topics.map(t => topicRow(s, t)));
    }))));
  observeVideos();
}

function topicRow(s, t) {
  const d = s.done[t.id];
  const id = 'tp-' + t.id;
  const row = el('div', { class: 'lx-topic' + (d?.v ? ' is-done' : ''), 'data-topic-id': t.id });
  const box = el('input', { type: 'checkbox', id, checked: d?.v ? true : null });
  box.addEventListener('change', () => {
    s.done[t.id] = { v: box.checked, ts: Date.now() };
    row.classList.toggle('is-done', box.checked);
    when.textContent = box.checked ? 'Completed ' + new Date().toLocaleDateString() : '';
    persist();
    renderBanner();
    const det = row.closest('details');
    const all = $$('input[type=checkbox]', det), n = all.filter(i => i.checked).length;
    det.querySelector('.lx-module-meta').textContent = `${n}/${all.length}`;
    det.querySelector('.lx-minibar i').style.width = (n / all.length * 100) + '%';
    if (box.checked) toast(forecast(s).motivation, 4200);
  });
  const when = el('span', { class: 'lx-topic-when', text: d?.v ? 'Completed ' + new Date(d.ts).toLocaleDateString() : '' });
  const vbox = el('div', { class: 'lx-vbox', 'data-topic': t.text, 'data-topic-id': t.id, role: 'region', 'aria-label': `Video tutor for ${t.text}` },
    el('div', { class: 'lx-vstate lx-skeleton', text: 'Finding the most-viewed tutorial…' }));
  row.append(el('div', { class: 'lx-topic-main' }, box, el('label', { for: id }, t.text, when)), vbox);
  return row;
}

// ════════════════════════════════════════════════════════════════════════════
// Video tutor: view-ranked carousel with like / dislike-swap
// ════════════════════════════════════════════════════════════════════════════
const results = new Map();                  // topic text → api payload (this page)
const queue = [];
let inflight = 0;
const MAX_INFLIGHT = 2;
let scanStopped = false;
const stoppedBoxes = new Set();              // vboxes interrupted mid-scan, for Resume to re-enqueue

async function fetchRanked(topic) {
  if (results.has(topic)) return results.get(topic);
  const cacheKey = 'hlx-yt:' + topic;
  try { const c = JSON.parse(sessionStorage.getItem(cacheKey)); if (c) { results.set(topic, c); return c; } } catch { /* ignore */ }
  // The course title only steers the Wikipedia fallback description if the
  // exact topic has no video (disambiguates jargon like "IOC normalization");
  // the YouTube search itself always stays the bare topic name.
  const course = active()?.title || '';
  const url = labsBase + 'api/youtube?topic=' + encodeURIComponent(topic) + (course ? '&context=' + encodeURIComponent(course) : '');
  const r = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
  if (r.status === 429) throw new Error('Too many lookups right now — try again in a few minutes.');
  const data = await r.json();
  if (!r.ok) throw new Error(data.error || 'lookup failed');
  results.set(topic, data);
  try { sessionStorage.setItem(cacheKey, JSON.stringify(data)); } catch { /* full */ }
  return data;
}
// Community like/dislike consensus per topic — the video other students
// found most useful rises to the top for everyone, not just the device
// that liked it. Best-effort: never blocks or fails the ranked lookup.
const communityPicks = new Map();          // topicKey → { id, title, likes, dislikes } | null
async function fetchCommunityPick(topic) {
  const k = topicKey(topic);
  if (communityPicks.has(k)) return communityPicks.get(k);
  try {
    const r = await fetch(labsBase + 'api/vote?topic=' + encodeURIComponent(topic), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const data = await r.json();
    communityPicks.set(k, data.pick || null);
  } catch { communityPicks.set(k, null); }
  return communityPicks.get(k);
}
function postVote(topic, videoId, videoTitle, action) {
  fetch(labsBase + 'api/vote', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ topic, videoId, videoTitle, action }) }).catch(() => { /* best effort */ });
}
function pump() {
  if (scanStopped) return;
  while (inflight < MAX_INFLIGHT && queue.length) {
    const vbox = queue.shift();
    inflight++;
    Promise.all([fetchRanked(vbox.dataset.topic), fetchCommunityPick(vbox.dataset.topic)])
      .then(([data]) => { vbox.dataset.loaded = '1'; showVideo(vbox, data, 0); })
      .catch(err => { vbox.dataset.loaded = ''; vbox.replaceChildren(el('div', { class: 'lx-vstate', text: err.message })); })
      .finally(() => { inflight--; pump(); paintScanControl(); });
  }
  paintScanControl();
}
let io = null;
function observeVideos() {
  io?.disconnect();
  io = new IntersectionObserver(entries => {
    for (const e of entries) if (e.isIntersecting && !e.target.dataset.loaded) { e.target.dataset.loaded = 'pending'; io.unobserve(e.target); queue.push(e.target); }
    pump();
  }, { rootMargin: '300px 0px' });
  $$('.lx-vbox').forEach(v => io.observe(v));
}

// Stop/resume the video lookup scan: Stop empties the queue and freezes
// pump() (in-flight requests still land, since aborting mid-fetch isn't
// worth the complexity for a single small JSON request, but nothing new
// starts); the interrupted boxes are tracked so Resume can re-queue them.
function stopScan() {
  scanStopped = true;
  for (const vbox of queue.splice(0)) {
    stoppedBoxes.add(vbox);
    vbox.dataset.loaded = '';
    vbox.replaceChildren(el('div', { class: 'lx-vstate', text: 'Stopped.' }));
  }
  paintScanControl();
}
function resumeScan() {
  scanStopped = false;
  for (const vbox of [...stoppedBoxes]) {
    stoppedBoxes.delete(vbox);
    vbox.dataset.loaded = 'pending';
    vbox.replaceChildren(el('div', { class: 'lx-vstate lx-skeleton', text: 'Finding the most-viewed tutorial…' }));
    queue.push(vbox);
  }
  pump();
}
function paintScanControl() {
  const host = $('#syl-scan-control');
  if (!host) return;
  const busy = inflight > 0 || queue.length > 0;
  if (scanStopped) {
    host.replaceChildren(el('button', { class: 'lx-btn lx-btn--ghost lx-btn--sm', type: 'button', text: `Resume video lookups (${stoppedBoxes.size})`, onclick: resumeScan }));
  } else if (busy) {
    host.replaceChildren(el('button', { class: 'lx-btn lx-btn--ghost lx-btn--sm', type: 'button', text: 'Stop video lookups', onclick: stopScan }));
  } else {
    host.replaceChildren();
  }
}

const thirdPartyOK = () => !!window.HastraConsent?.allows?.('thirdparty');
const ICON = {
  play: () => svg('svg', { viewBox: '0 0 24 24', fill: 'currentColor', 'aria-hidden': 'true' }, svg('path', { d: 'M8 5v14l11-7z' })),
  up: () => svg('svg', { viewBox: '0 0 24 24', width: 13, height: 13, fill: 'currentColor', 'aria-hidden': 'true' }, svg('path', { d: 'M2 21h4V9H2v12zm20-11a2 2 0 0 0-2-2h-6.3l1-4.6v-.3c0-.4-.2-.8-.4-1.1L13.2 1 6.6 7.6C6.2 8 6 8.5 6 9v10a2 2 0 0 0 2 2h9c.8 0 1.5-.5 1.8-1.2l3-7.1c.1-.2.2-.5.2-.7v-2z' })),
  down: () => svg('svg', { viewBox: '0 0 24 24', width: 13, height: 13, fill: 'currentColor', 'aria-hidden': 'true' }, svg('path', { d: 'M22 3h-4v12h4V3zM2 14a2 2 0 0 0 2 2h6.3l-1 4.6v.3c0 .4.2.8.4 1.1l1.1 1 6.6-6.6c.4-.4.6-.9.6-1.4V5a2 2 0 0 0-2-2H7c-.8 0-1.5.5-1.8 1.2l-3 7.1c-.1.2-.2.5-.2.7v2z' })),
};

// The ranked list for a topic minus everything disliked. Order of
// precedence for what leads: this device's own like, then the community's
// like/dislike consensus for the topic, then the raw view-count ranking.
function candidates(topicText, topicId, data) {
  const s = active();
  const bad = new Set(state.disliked[topicKey(topicText)] || []);
  const vids = (data.videos || []).map((v, i) => ({ ...v, rank: i + 1 })).filter(v => !bad.has(v.id));
  const liked = s?.liked[topicId]?.id;
  const li = vids.findIndex(v => v.id === liked);
  if (li > 0) vids.unshift(vids.splice(li, 1)[0]);
  else if (!liked) {
    const pick = communityPicks.get(topicKey(topicText));
    if (pick && !bad.has(pick.id)) {
      const pi = vids.findIndex(v => v.id === pick.id);
      if (pi > 0) vids.unshift(vids.splice(pi, 1)[0]);
    }
  }
  return vids;
}

// A compact, play-in-page card for a related-topic video (poster + play +
// title/channel/views only — no rank badge, no like/dislike: it belongs to
// a different, broader topic, so it isn't part of this topic's ranking).
function miniPlayCard(v) {
  const card = el('div', { class: 'lx-vcard', 'data-video': v.id },
    el('div', { class: 'lx-vposter' }, thirdPartyOK() ? el('img', { src: `https://i.ytimg.com/vi/${v.id}/hqdefault.jpg`, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' }) : ''),
    el('button', { class: 'lx-vplay', type: 'button', 'aria-label': `Play “${v.title}” (loads the youtube-nocookie.com player)`, onclick: () => play(card, v) }, ICON.play()),
    el('div', { class: 'lx-vmeta' }, el('b', { text: v.title }), el('span', { text: `${v.channel} · ${fmtCompact(v.views)} views · ${fmtDuration(v.duration)}` })));
  return card;
}

function showVideo(vbox, data, pos, direction = 0) {
  const topic = vbox.dataset.topic, tid = vbox.dataset.topicId;
  const s = active();
  let card;
  if (data.mode === 'ranked') {
    const list = candidates(topic, tid, data);
    const v = list[pos];
    if (!v) {
      const isMiss = !data.videos?.length;
      vbox.classList.toggle('lx-vbox--tall', isMiss);
      const parts = [el('div', { class: 'lx-vmiss-title' },
        isMiss ? 'No embeddable tutorial found for this exact topic.' : 'You have skipped every ranked video for this topic.')];
      if (isMiss && data.description) {
        parts.push(el('div', { class: 'lx-vdesc' },
          el('b', { text: data.description.title }),
          el('p', { text: data.description.extract }),
          el('a', { href: data.description.url, target: '_blank', rel: 'noopener noreferrer', text: 'Read more on Wikipedia ↗' })));
      }
      const actions = el('div', { class: 'lx-vactions-static' },
        el('a', { href: data.searchUrl, target: '_blank', rel: 'noopener noreferrer', class: 'lx-vbtn', text: 'Search YouTube ↗' }));
      if (!isMiss) actions.append(el('button', { type: 'button', class: 'lx-vbtn', text: 'Reset skips', onclick: () => { delete state.disliked[topicKey(topic)]; persist(); showVideo(vbox, data, 0, 1); } }));
      parts.push(actions);
      if (isMiss && data.related?.videos?.length) {
        const rv = data.related.videos;
        parts.push(el('div', { class: 'lx-vrelated-head', text: `Closest related topic: “${data.related.query}”` }),
          el('div', { class: 'lx-vbox lx-vbox--mini' }, miniPlayCard(rv[0])));
        if (rv.length > 1) parts.push(el('div', { class: 'lx-vrelated-list' }, ...rv.slice(1, 4).map(r =>
          el('a', { class: 'lx-vrelated-item', href: `https://www.youtube.com/watch?v=${r.id}`, target: '_blank', rel: 'noopener noreferrer' },
            el('img', { src: `https://i.ytimg.com/vi/${r.id}/mqdefault.jpg`, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' }),
            el('span', {}, el('b', { text: r.title }), el('small', { text: `${r.channel} · ${fmtCompact(r.views)} views` }))))));
      }
      card = el('div', { class: 'lx-vcard lx-vcard--empty' }, ...parts);
    } else {
      vbox.classList.remove('lx-vbox--tall');
      if (s && v.duration) { s.durations[tid] = v.duration; }
      const liked = s?.liked[tid]?.id === v.id;
      const communityPick = communityPicks.get(topicKey(topic));
      const isCommunityPick = communityPick && communityPick.id === v.id;
      const poster = el('div', { class: 'lx-vposter' }, thirdPartyOK() ? el('img', { src: `https://i.ytimg.com/vi/${v.id}/hqdefault.jpg`, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' }) : '');
      card = el('div', { class: 'lx-vcard', 'data-video': v.id },
        poster,
        el('span', { class: 'lx-vrank', text: isCommunityPick ? `★ Community pick · #${v.rank} most viewed` : `#${v.rank} most viewed` }),
        el('button', { class: 'lx-vplay', type: 'button', 'aria-label': `Play “${v.title}” (loads the youtube-nocookie.com player)`, onclick: () => play(card, v) }, ICON.play()),
        el('div', { class: 'lx-vmeta' }, el('b', { text: v.title }), el('span', { text: `${v.channel} · ${fmtCompact(v.views)} views · ${fmtDuration(v.duration)}` })),
        el('div', { class: 'lx-vactions' },
          el('button', { class: 'lx-vbtn', type: 'button', 'aria-pressed': String(liked), onclick: e => like(e.currentTarget, tid, v, topic) }, ICON.up(), liked ? 'Liked' : 'Like'),
          el('button', { class: 'lx-vbtn', type: 'button', onclick: () => dislike(vbox, data, v, pos) }, ICON.down(), 'Next best'),
          el('a', { class: 'lx-vbtn', href: `https://www.youtube.com/watch?v=${v.id}`, target: '_blank', rel: 'noopener noreferrer', 'aria-label': 'Open on YouTube' }, '↗')));
    }
  } else {
    // no API key on the server, or quota resting: YouTube's own view-count
    // sort, one click away. Uses the server's own searchUrl (topic name
    // alone, correctly single-encoded) rather than building a separate query
    // client-side, so this always matches what the ranked API would have
    // searched for.
    vbox.classList.remove('lx-vbox--tall');
    card = el('div', { class: 'lx-vcard' },
      el('div', { class: 'lx-vposter' }),
      el('span', { class: 'lx-vrank', text: 'Sorted by views' }),
      el('div', { class: 'lx-vmeta', style: { bottom: '52px' } }, el('b', { text: data.query }), el('span', { text: {
        no_api_key: 'Ranked in-page videos need a YouTube API key on this server.',
        daily_quota: 'Live ranking is resting (daily quota). Opens YouTube sorted by views.',
        rate_limited: 'Too many lookups right now. Opens YouTube sorted by views.',
      }[data.reason] || 'Ranked lookup failed on this server. Opens YouTube sorted by views.' })),
      el('div', { class: 'lx-vactions' },
        el('a', { class: 'lx-vbtn', href: data.searchUrl, target: '_blank', rel: 'noopener noreferrer' }, 'Open most-viewed ↗')));
  }
  if (direction && vbox.firstElementChild) {
    card.classList.add('is-entering');
    vbox.replaceChildren(card);
    requestAnimationFrame(() => requestAnimationFrame(() => card.classList.remove('is-entering')));
  } else vbox.replaceChildren(card);
  if (s && data.mode === 'ranked') renderBannerSoon();
}
let bannerTimer = 0;
const renderBannerSoon = () => { clearTimeout(bannerTimer); bannerTimer = setTimeout(() => { persist(false); renderBanner(); }, 400); };

// Slide the current card out to the left, then run `next` (which slides in from the right).
function swap(vbox, next) {
  const cur = vbox.querySelector('.lx-vcard');
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!cur || reduce) return next();
  cur.classList.add('is-leaving');
  let done = false;
  const go = () => { if (done) return; done = true; next(); };
  cur.addEventListener('transitionend', go, { once: true });
  setTimeout(go, 400);
}
function dislike(vbox, data, v, pos) {
  const topic = vbox.dataset.topic;
  const k = topicKey(topic);
  state.disliked[k] = [...new Set([...(state.disliked[k] || []), v.id])];
  const s = active();
  if (s?.liked[vbox.dataset.topicId]?.id === v.id) s.liked[vbox.dataset.topicId] = { id: null, ts: Date.now() };
  persist();
  postVote(topic, v.id, v.title, 'dislike');
  swap(vbox, () => showVideo(vbox, data, pos, 1));   // same position now holds the next-best video
  toast('Skipped — it won’t be recommended for this topic again.');
}
function like(btn, tid, v, topic) {
  const s = active();
  if (!s) return;
  const on = s.liked[tid]?.id !== v.id;
  s.liked[tid] = { id: on ? v.id : null, title: v.title, ts: Date.now() };
  btn.setAttribute('aria-pressed', String(on));
  btn.lastChild.textContent = on ? 'Liked' : 'Like';
  persist();
  if (on) postVote(topic, v.id, v.title, 'like');
}
function play(card, v) {
  card.classList.add('is-playing');
  card.querySelector('.lx-vplay')?.remove();
  card.querySelector('.lx-vmeta')?.remove();
  card.prepend(el('iframe', { src: `https://www.youtube-nocookie.com/embed/${v.id}?autoplay=1&rel=0&modestbranding=1`, title: v.title,
    allow: 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture', allowfullscreen: true, referrerpolicy: 'strict-origin-when-cross-origin' }));
}

// ════════════════════════════════════════════════════════════════════════════
// Export
// ════════════════════════════════════════════════════════════════════════════
function exportReport(kind) {
  const s = active();
  if (!s) return;
  const f = forecast(s);
  if (kind === 'json') {
    download(JSON.stringify({ syllabus: s.title, generated: new Date().toISOString(), progress: { done: f.done, total: f.total, pct: Math.round(f.pct * 100) },
      pacing: { status: f.status, target: s.target, projected: f.projected && isoDay(f.projected), dailyHours: s.dailyHours, remainingHours: +f.remainingH.toFixed(1) },
      courses: s.courses.map(c => ({ title: c.title, units: c.modules.map(m => ({ title: m.title, topics: m.topics.map(t => ({ topic: t.text, done: !!s.done[t.id]?.v,
        completedAt: s.done[t.id]?.v ? new Date(s.done[t.id].ts).toISOString() : null, likedVideo: s.liked[t.id]?.id || null })) })) })) }, null, 2),
      `hastra-mastery-${s.id}.json`, 'application/json');
    return;
  }
  const L = [`# Mastery report — ${s.title}`, '', `Generated ${new Date().toLocaleString()} with Hastra Labs.`, '',
    `**Progress:** ${f.done}/${f.total} topics (${Math.round(f.pct * 100)}%)  `,
    `**Pacing:** ${f.status} · target ${s.target} · projected ${f.remaining ? (f.projected ? isoDay(f.projected) : '—') : 'done'}  `,
    `**Plan:** ${s.dailyHours} h/day · ${fmtNum(f.remainingH, 1)} h remaining${f.streak ? ` · ${f.streak}-day streak` : ''}`, '', `> ${f.motivation}`, ''];
  for (const c of s.courses) {
    L.push(`## ${c.title}`, '');
    for (const m of c.modules) {
      const n = m.topics.filter(t => s.done[t.id]?.v).length;
      L.push(`### ${m.title} — ${n}/${m.topics.length}`, '');
      for (const t of m.topics) {
        const d = s.done[t.id], lk = s.liked[t.id];
        L.push(`- [${d?.v ? 'x' : ' '}] ${t.text}${d?.v ? ` _(done ${isoDay(new Date(d.ts))})_` : ''}${lk?.id ? ` — [${lk.title || 'liked video'}](https://www.youtube.com/watch?v=${lk.id})` : ''}`);
      }
      L.push('');
    }
  }
  download(L.join('\n'), `hastra-mastery-${s.id}.md`, 'text/markdown');
}

// ════════════════════════════════════════════════════════════════════════════
// Page wiring
// ════════════════════════════════════════════════════════════════════════════
function renderAll() { renderLibrary(); renderBanner(); renderTree(); paintSync(); }

let pending = [];
const paintFiles = () => $('#syl-files-chips').replaceChildren(...pending.map((f, i) => el('span', { class: 'lx-filechip' }, f.name,
  el('button', { type: 'button', class: 'lx-vbtn', style: { padding: '2px 6px' }, 'aria-label': 'Remove ' + f.name, text: '×', onclick: () => { pending.splice(i, 1); paintFiles(); } }))));
dropZone($('#syl-drop'), files => {
  for (const f of files) if (!pending.some(p => p.name === f.name && p.size === f.size)) pending.push(f);
  paintFiles();
});

function targetOpts() {
  const target = $('#syl-target').value;
  const dailyHours = parseFloat($('#syl-hours').value);
  const multiplier = parseFloat($('#syl-mult').value) || 1;
  if (!target) throw new Error('Pick a target completion date.');
  if (!(dailyHours > 0 && dailyHours <= 16)) throw new Error('Daily study hours must be between 0.5 and 16.');
  return { target, dailyHours, multiplier };
}

$('#syl-run').addEventListener('click', e => busy(e.currentTarget, async () => {
  const msg = $('#syl-msg');
  const ac = new AbortController();
  const reading = text => el('div', { class: 'lx-alert lx-alert--info' },
    el('span', { text }), ' ',
    el('button', { type: 'button', class: 'lx-vbtn', text: 'Stop', onclick: () => ac.abort() }));
  try {
    const opts = targetOpts();
    if (!pending.length) throw new Error('Add at least one PDF, DOCX or TXT syllabus (or try the demo).');
    const docs = [], warnings = [];
    for (const f of pending) {
      msg.replaceChildren(reading(`Reading ${f.name}…`));
      const d = await extractText(f, { signal: ac.signal });
      warnings.push(...d.warnings);
      docs.push(d);
    }
    const r = await buildSyllabus(docs, opts);
    const s = state.syllabi[r.id];
    const n = s.courses.reduce((a, c) => a + c.modules.reduce((b, m) => b + m.topics.length, 0), 0);
    msg.replaceChildren(el('div', { class: 'lx-alert lx-alert--ok', text: `${r.restored ? 'Welcome back — progress restored. ' : ''}Extracted ${s.courses.length} course${s.courses.length === 1 ? '' : 's'}, ${s.courses.reduce((a, c) => a + c.modules.length, 0)} units and ${n} topics.` }),
      ...warnings.map(w => el('div', { class: 'lx-alert lx-alert--warn', text: w })));
    pending = []; paintFiles();
    renderAll();
    $('#syl-banner').scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch (err) {
    if (err.name === 'AbortError') msg.replaceChildren(el('div', { class: 'lx-alert lx-alert--warn', text: 'Stopped.' }));
    else msg.replaceChildren(el('div', { class: 'lx-alert lx-alert--err', role: 'alert', text: err.message }));
  }
}));

$('#syl-demo').addEventListener('click', e => busy(e.currentTarget, async () => {
  const opts = targetOpts();
  const r = await buildSyllabus([{ name: 'SOC-Engineering-Demo.txt', lines: DEMO.split('\n'), warnings: [] }], opts);
  $('#syl-msg').replaceChildren(el('div', { class: 'lx-alert lx-alert--ok', text: r.restored ? 'Demo syllabus re-opened with your progress.' : 'Demo syllabus parsed with the same engine used for uploads.' }));
  renderAll();
}));

$('#syl-apply').addEventListener('click', () => {
  const s = active();
  if (!s) return toast('Build a syllabus first');
  try { Object.assign(s, targetOpts(), { settingsAt: Date.now() }); persist(); renderBanner(); toast('Targets updated'); }
  catch (err) { toast(err.message, 4000); }
});
$('#syl-delete').addEventListener('click', () => {
  const s = active();
  if (!s || !confirm(`Remove “${s.title}” and its progress from this device${signedIn ? ' and your synced profile' : ''}?`)) return;
  delete state.syllabi[s.id];
  state.active = Object.keys(state.syllabi)[0] || null;
  persist();
  renderAll();
});

// defaults: target in 30 days, 2.5 h/day
{ const d = new Date(Date.now() + 30 * DAY); $('#syl-target').value = isoDay(d); $('#syl-target').min = isoDay(today()); }

// A syllabus written the way university documents usually are: roman unit
// numbers, hour counts, comma-separated topic runs wrapped across lines,
// and textbook / reference sections that must be ignored.
const DEMO = `Course Title: Security Operations & SIEM Engineering
Course Code: CS7412    L T P C 3 0 2 4
Course Objectives:
To understand threat intelligence, behaviour analytics and SIEM at scale.
UNIT I  EXTERNAL THREAT INTELLIGENCE & INGESTION   9 Hrs
STIX 2.1 domain objects and observables, TAXII 2.1 collections and polling, MISP events and galaxies,
IOC normalization and refanging, deduplication with key hashing, confidence scoring (0-100), mapping
indicators to MITRE ATT&CK techniques.
UNIT II  USER & ENTITY BEHAVIOUR ANALYTICS (UEBA)   9 Hrs
Baseline profiling and peer groups; standard deviation and z-score anomalies; impossible travel
detection; login time anomalies; composite risk scoring with correlation bonus; alert tiering and
SOAR escalation.
UNIT III  SIEM SCALABILITY - HIGH VOLUME INGESTION   9 Hrs
• Event filtering and sampling at the source
• Kafka as an ingestion buffer (partitions, replication, consumer groups)
• Time-partitioned indexing and rollover policies
• Hot, warm and cold storage tiering (NVMe, SSD, S3 / Glacier)
• Elasticsearch shard sizing and cluster state limits
UNIT IV  MULTI-CLOUD LOG PIPELINES   9 Hrs
Microsoft 365 Purview unified audit log, AWS CloudTrail management and data events, Kubernetes audit
policy and Fluent Bit, common schema normalization (ECS / OCSF), identity correlation across clouds,
building an incident timeline.
UNIT V  DETECTION ENGINEERING & RESPONSE   9 Hrs
Sigma rules and detection-as-code, correlation searches and suppression, incident response playbooks
(NIST SP 800-61), threat hunting with hypotheses, measuring MTTD and MTTR.
Text Books:
1. Security Operations Center: Building, Operating and Maintaining your SOC, Cisco Press.
2. Applied Network Security Monitoring, Syngress.
Reference Books:
1. The Practice of Network Security Monitoring, No Starch Press.
Total: 45 Periods`;

// ── boot ──
renderAll();
(async () => {
  const r = await syncGet();
  signedIn = !!r.signedIn;
  if (signedIn && r.progress && r.progress.v === 1) {
    state = mergeState(state, r.progress);
    persist(false);
    renderAll();
  } else if (signedIn) persist(false);
  paintSync(signedIn ? 'synced' : undefined);
})();
