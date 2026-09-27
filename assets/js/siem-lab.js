/* Hastra Labs — Module 2: SIEM / Threat Intel & SOC Simulator (labs/siem.php). */
import { $, $$, el, svg, toast, tabs, download, enc, toHex, fmtBytes, fmtNum, fmtCompact } from './labs-common.js';
import * as U from './ueba-calculator.js';

tabs($('#sx-tabs'));

// ════════════════════════════════════════════════════════════════════════════
// A · STIX 2.1 / TAXII 2.1 / MISP threat-feed normalizer
// ════════════════════════════════════════════════════════════════════════════
const TECH_RE = /\bT\d{4}(?:\.\d{3})?\b/g;
const HASH_LEN = { md5: 32, sha1: 40, sha256: 64, sha512: 128 };

export function refang(v) {
  return String(v).trim()
    .replace(/^hxxp(s?):\/\//i, 'http$1://').replace(/^fxp:\/\//i, 'ftp://')
    .replace(/\[\.\]|\(\.\)|\{\.\}|\[dot\]|\(dot\)/gi, '.')
    .replace(/\[:\]/g, ':').replace(/\[@\]|\[at\]|\(at\)/gi, '@').replace(/\[\/\]/g, '/');
}

function canonIPv4(v) {
  const m = v.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(\/(\d{1,2}))?$/);
  if (!m) return null;
  const o = m.slice(1, 5).map(Number);
  if (o.some(x => x > 255) || (m[6] && +m[6] > 32)) return null;
  return o.join('.') + (m[6] !== undefined ? '/' + +m[6] : '');
}
function canonIPv6(v) {
  const s = v.replace(/^\[|\]$/g, '').toLowerCase();
  return /^[0-9a-f:]+(\/\d{1,3})?$/.test(s) && s.includes(':') && (s.match(/::/g) || []).length <= 1 ? s : null;
}
function canonDomain(v) {
  const s = v.toLowerCase().replace(/\.$/, '');
  return /^(?=.{3,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(xn--[a-z0-9-]{2,59}|[a-z]{2,63})$/.test(s) ? s : null;
}
function canonUrl(v) {
  try {
    const u = new URL(v);
    if (!/^(https?|ftp):$/.test(u.protocol)) return null;
    u.hostname = u.hostname.toLowerCase();
    if ((u.protocol === 'http:' && u.port === '80') || (u.protocol === 'https:' && u.port === '443')) u.port = '';
    return u.href;
  } catch { return null; }
}

// Canonicalizes one indicator; returns null when the value is not valid for its type.
export function canonical(type, raw) {
  const v = refang(raw);
  switch (type) {
    case 'ip': return canonIPv4(v) ? { type: 'ipv4', value: canonIPv4(v) } : canonIPv6(v) ? { type: 'ipv6', value: canonIPv6(v) } : null;
    case 'ipv4': return canonIPv4(v) ? { type, value: canonIPv4(v) } : null;
    case 'ipv6': return canonIPv6(v) ? { type, value: canonIPv6(v) } : null;
    case 'domain': return canonDomain(v) ? { type, value: canonDomain(v) } : null;
    case 'url': return canonUrl(v) ? { type, value: canonUrl(v) } : null;
    case 'email': return /^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(v) ? { type, value: v.toLowerCase() } : null;
    case 'md5': case 'sha1': case 'sha256': case 'sha512': {
      const h = v.toLowerCase();
      return new RegExp(`^[0-9a-f]{${HASH_LEN[type]}}$`).test(h) ? { type, value: h } : null;
    }
    case 'asn': { const m = v.match(/^(?:as)?(\d{1,10})$/i); return m ? { type, value: 'AS' + m[1] } : null; }
    case 'filename': case 'registry': case 'mutex': return v && v.length < 1024 ? { type, value: v } : null;
    default: return null;
  }
}

// Guess the type of a bare IOC (plain lists, MISP "other").
export function detectType(raw) {
  const v = refang(raw);
  if (canonIPv4(v)) return 'ipv4';
  if (canonIPv6(v)) return 'ipv6';
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(v)) return 'url';
  if (/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(v)) return 'email';
  if (/^[0-9a-f]+$/i.test(v)) return { 32: 'md5', 40: 'sha1', 64: 'sha256', 128: 'sha512' }[v.length] || null;
  if (/^AS\d+$/i.test(v)) return 'asn';
  if (canonDomain(v)) return 'domain';
  return null;
}

const STIX_PATH = {
  'ipv4-addr:value': 'ipv4', 'ipv6-addr:value': 'ipv6', 'domain-name:value': 'domain', 'url:value': 'url',
  'email-addr:value': 'email', 'file:name': 'filename', 'windows-registry-key:key': 'registry', 'mutex:name': 'mutex',
  'autonomous-system:number': 'asn',
};
function stixHashType(name) {
  const n = name.replace(/['"]/g, '').toUpperCase().replace(/[-_]/g, '');
  return { MD5: 'md5', SHA1: 'sha1', SHA256: 'sha256', SHA512: 'sha512' }[n] || null;
}
// Pulls every `object:path = 'value'` comparison out of a STIX pattern.
export function parseStixPattern(pattern) {
  const out = [];
  const re = /([a-z0-9-]+):([a-z0-9_]+(?:\.(?:'[^']+'|[a-z0-9_-]+))*)\s*=\s*'((?:[^'\\]|\\.)*)'/gi;
  let m;
  while ((m = re.exec(pattern))) {
    const obj = m[1].toLowerCase(), path = m[2], value = m[3].replace(/\\(['\\])/g, '$1');
    let type = STIX_PATH[`${obj}:${path.toLowerCase()}`];
    if (!type && obj === 'file' && /^hashes\./i.test(path)) type = stixHashType(path.slice(7));
    if (type) out.push({ type, value });
  }
  return out;
}

function techniques(...texts) {
  const s = new Set();
  for (const t of texts.flat()) if (typeof t === 'string') for (const m of t.match(TECH_RE) || []) s.add(m);
  return [...s];
}

// → { format, items: [{type, value, confidence, mitre[], source, firstSeen}], rejected: [{value, reason}] }
export function extractIndicators(text) {
  const trimmed = text.trim();
  if (!trimmed) throw new Error('Paste a feed first.');
  let data = null;
  if (/^[[{]/.test(trimmed)) { try { data = JSON.parse(trimmed); } catch (e) { throw new Error('That looks like JSON but does not parse: ' + e.message); } }
  const items = [], rejected = [];
  const push = (type, raw, extra) => {
    const c = canonical(type, raw);
    if (!c) { rejected.push({ value: String(raw).slice(0, 200), reason: `not a valid ${type}` }); return; }
    items.push({ ...c, confidence: Math.max(0, Math.min(100, Math.round(extra.confidence ?? 50))), mitre: extra.mitre || [], source: extra.source, firstSeen: extra.firstSeen || null });
  };

  // ── STIX 2.1 bundle / TAXII 2.1 envelope / OpenCTI export ──
  const objects = data && (data.type === 'bundle' || Array.isArray(data.objects)) ? data.objects
    : Array.isArray(data) && data.length && data[0]?.spec_version ? data : null;
  if (objects) {
    const byId = new Map(objects.map(o => [o.id, o]));
    const attack = new Map();
    for (const o of objects) if (o.type === 'attack-pattern') {
      const ref = (o.external_references || []).find(r => r.source_name === 'mitre-attack' && r.external_id);
      if (ref) attack.set(o.id, ref.external_id);
    }
    const indicates = new Map();
    for (const r of objects) if (r.type === 'relationship' && r.relationship_type === 'indicates' && attack.has(r.target_ref)) {
      indicates.set(r.source_ref, [...(indicates.get(r.source_ref) || []), attack.get(r.target_ref)]);
    }
    const sourceOf = o => byId.get(o.created_by_ref)?.name || (data.type === 'bundle' ? 'STIX 2.1 bundle' : 'TAXII 2.1 collection');
    for (const o of objects) {
      if (o.type === 'indicator' && (o.pattern_type || 'stix') === 'stix' && o.pattern) {
        const extra = {
          confidence: o.confidence ?? o.x_opencti_score ?? 50,
          mitre: [...new Set([...(indicates.get(o.id) || []), ...techniques(o.name, o.description, o.labels || [])])],
          source: sourceOf(o), firstSeen: o.valid_from || o.created,
        };
        const found = parseStixPattern(o.pattern);
        if (!found.length) rejected.push({ value: o.pattern.slice(0, 200), reason: 'no supported comparison in pattern' });
        found.forEach(f => push(f.type, f.value, extra));
      } else if (['ipv4-addr', 'ipv6-addr', 'domain-name', 'url', 'email-addr'].includes(o.type) && o.value) {
        push({ 'ipv4-addr': 'ipv4', 'ipv6-addr': 'ipv6', 'domain-name': 'domain', url: 'url', 'email-addr': 'email' }[o.type], o.value,
          { confidence: o.x_opencti_score ?? o.confidence ?? 40, mitre: techniques(o.description), source: sourceOf(o) + ' (observable)' });
      } else if (o.type === 'file' && o.hashes) {
        for (const [k, v] of Object.entries(o.hashes)) { const t = stixHashType(k); if (t) push(t, v, { confidence: o.x_opencti_score ?? 40, source: sourceOf(o) + ' (observable)' }); }
      }
    }
    return { format: data.type === 'bundle' ? 'STIX 2.1 bundle' : 'TAXII 2.1 envelope', items, rejected };
  }

  // ── MISP event(s) ──
  const events = data?.Event ? [data.Event] : Array.isArray(data?.response) ? data.response.map(r => r.Event).filter(Boolean)
    : Array.isArray(data) && data[0]?.Event ? data.map(r => r.Event) : null;
  if (events) {
    const CONF = { 'completely-confident': 100, 'usually-confident': 75, 'fairly-confident': 50, 'rarely-confident': 25, unconfident: 0 };
    const MISP_TYPES = { 'ip-src': 'ip', 'ip-dst': 'ip', domain: 'domain', hostname: 'domain', url: 'url', uri: 'url',
      md5: 'md5', sha1: 'sha1', sha256: 'sha256', sha512: 'sha512', 'email-src': 'email', 'email-dst': 'email', email: 'email',
      filename: 'filename', AS: 'asn', regkey: 'registry', mutex: 'mutex' };
    for (const ev of events) {
      const evTags = (ev.Tag || []).map(t => t.name);
      const galaxy = (ev.Galaxy || []).flatMap(g => (g.GalaxyCluster || []).map(c => c.value + ' ' + (c.meta?.external_id || '')));
      const source = 'MISP · ' + (ev.Orgc?.name || ev.info || 'event');
      const attrs = [...(ev.Attribute || []), ...(ev.Object || []).flatMap(o => o.Attribute || [])];
      for (const a of attrs) {
        const tags = [...evTags, ...(a.Tag || []).map(t => t.name)];
        let conf = a.to_ids ? 80 : 50;
        for (const t of tags) { const m = t.match(/confidence-level="([a-z-]+)"/); if (m && CONF[m[1]] !== undefined) conf = CONF[m[1]]; }
        const extra = { confidence: conf, mitre: techniques(tags, galaxy, a.comment), source,
          firstSeen: a.first_seen || (a.timestamp ? new Date(+a.timestamp * 1000).toISOString() : null) };
        const [t1, t2] = String(a.type).split('|');
        const [v1, v2] = String(a.value).split('|');
        if (t2) {
          const map = { 'ip-src': 'ip', 'ip-dst': 'ip', domain: 'domain', hostname: 'domain', filename: 'filename', port: null, ip: 'ip' };
          const a1 = map[t1] ?? MISP_TYPES[t1], a2 = t2 === 'port' ? null : (MISP_TYPES[t2] || map[t2]);
          if (a1) push(a1, v1, extra); else rejected.push({ value: a.value, reason: `unsupported MISP type ${a.type}` });
          if (a2 && v2 !== undefined) push(a2, v2, extra);
        } else if (MISP_TYPES[t1]) push(MISP_TYPES[t1], v1, extra);
        else rejected.push({ value: String(a.value).slice(0, 200), reason: `unsupported MISP type ${a.type}` });
      }
    }
    return { format: `MISP (${events.length} event${events.length === 1 ? '' : 's'})`, items, rejected };
  }
  if (data) throw new Error('Unrecognised JSON. Expected a STIX bundle, a TAXII envelope ({"objects": […]}) or a MISP event.');

  // ── Plain IOC list ──
  for (const line of trimmed.split(/\r?\n/)) {
    const v = line.replace(/#.*$/, '').trim();
    if (!v) continue;
    const t = detectType(v);
    if (t) push(t, v, { confidence: 50, mitre: techniques(line), source: 'IOC list' });
    else rejected.push({ value: v.slice(0, 200), reason: 'could not detect an indicator type' });
  }
  return { format: 'IOC list', items, rejected };
}

async function dedupKey(type, value) {
  return toHex(new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(type + '|' + value))));
}

// ── Enrichment: real range classes for IPs; everything else simulated ──
const IP_CLASSES = [
  ['10.0.0.0', 8, 'private (RFC 1918)'], ['172.16.0.0', 12, 'private (RFC 1918)'], ['192.168.0.0', 16, 'private (RFC 1918)'],
  ['127.0.0.0', 8, 'loopback'], ['169.254.0.0', 16, 'link-local'], ['100.64.0.0', 10, 'carrier-grade NAT'],
  ['192.0.2.0', 24, 'documentation (RFC 5737)'], ['198.51.100.0', 24, 'documentation (RFC 5737)'], ['203.0.113.0', 24, 'documentation (RFC 5737)'],
  ['224.0.0.0', 4, 'multicast'], ['240.0.0.0', 4, 'reserved'], ['0.0.0.0', 8, '"this" network'],
];
const ip2n = ip => ip.split('.').reduce((a, o) => a * 256 + +o, 0);
function ipClass(ip) {
  const n = ip2n(ip.split('/')[0]);
  for (const [base, bits, label] of IP_CLASSES) { const size = 2 ** (32 - bits); const b = ip2n(base); if (n >= b && n < b + size) return label; }
  return null;
}
const SIM_GEO = [['NL', 'AS60781', 'LeaseWeb (SIM)'], ['US', 'AS14061', 'DigitalOcean (SIM)'], ['DE', 'AS24940', 'Hetzner (SIM)'], ['SG', 'AS16509', 'Amazon (SIM)'],
                 ['RU', 'AS49505', 'Selectel (SIM)'], ['BR', 'AS28573', 'Claro (SIM)'], ['IN', 'AS9829', 'BSNL (SIM)'], ['FR', 'AS16276', 'OVH (SIM)']];
const SIM_REG = ['NameCheap (SIM)', 'GoDaddy (SIM)', 'Tucows (SIM)', 'Gandi (SIM)', 'Porkbun (SIM)', 'Alibaba Cloud (SIM)'];
const RISKY_TLDS = ['zip', 'mov', 'top', 'xyz', 'click', 'country', 'gq', 'tk', 'ml', 'cf', 'work', 'rest', 'support'];
function enrich(type, value, key) {
  const b = parseInt(key.slice(0, 8), 16);
  if (type === 'ipv4') {
    const cls = ipClass(value);
    if (cls) return { text: `${cls} · not internet-routable`, tone: 'mute' };
    const [cc, asn, org] = SIM_GEO[b % SIM_GEO.length];
    return { text: `GeoIP ${cc} · ${asn} ${org}`, tone: 'info' };
  }
  if (type === 'ipv6') {
    const cls = /^2001:0?db8:/.test(value) ? 'documentation (RFC 3849)' : /^fe[89ab]/.test(value) ? 'link-local' : /^f[cd]/.test(value) ? 'unique-local (RFC 4193)'
      : value === '::1' ? 'loopback' : /^ff/.test(value) ? 'multicast' : null;
    if (cls) return { text: `${cls} · not internet-routable`, tone: 'mute' };
    const [cc, asn, org] = SIM_GEO[b % SIM_GEO.length];
    return { text: `GeoIP ${cc} · ${asn} ${org}`, tone: 'info' };
  }
  if (type === 'domain' || type === 'url' || type === 'email') {
    const host = type === 'url' ? new URL(value).hostname : type === 'email' ? value.split('@')[1] : value;
    const tld = host.split('.').pop();
    if (['example', 'test', 'invalid', 'localhost'].includes(tld) || /(^|\.)example\.(com|net|org)$/.test(host)) return { text: 'reserved example domain (RFC 2606)', tone: 'mute' };
    const ageDays = b % 3650;
    const created = new Date(Date.now() - ageDays * 86400000).toISOString().slice(0, 10);
    const flags = [];
    if (ageDays < 30) flags.push('newly registered');
    if (RISKY_TLDS.includes(tld)) flags.push(`high-abuse .${tld}`);
    if (type === 'url' && value.startsWith('http:')) flags.push('no TLS');
    return { text: `WHOIS ${SIM_REG[b % SIM_REG.length]} · created ${created}${flags.length ? ' · ' + flags.join(', ') : ''}`, tone: flags.length ? 'warn' : 'info' };
  }
  if (HASH_LEN[type]) return { text: 'file reputation needs a sandbox / multi-AV lookup', tone: 'mute' };
  return { text: '—', tone: 'mute' };
}

const store = new Map();       // dedup_key → record
let lastStats = { raw: 0, rejected: 0, merged: 0 };

async function ingest(text) {
  const { format, items, rejected } = extractIndicators(text);
  let merged = 0;
  for (const it of items) {
    const key = await dedupKey(it.type, it.value);
    const cur = store.get(key);
    if (cur) {
      merged++;
      cur.confidence = Math.max(cur.confidence, it.confidence);
      it.mitre.forEach(t => cur.mitre.add(t));
      cur.sources.add(it.source);
      cur.seen++;
      if (it.firstSeen && (!cur.firstSeen || it.firstSeen < cur.firstSeen)) cur.firstSeen = it.firstSeen;
    } else {
      store.set(key, { key, indicator_value: it.value, indicator_type: it.type, confidence: it.confidence, mitre: new Set(it.mitre),
        sources: new Set([it.source]), firstSeen: it.firstSeen, seen: 1, enrichment: enrich(it.type, it.value, key) });
    }
  }
  lastStats = { raw: lastStats.raw + items.length, rejected: lastStats.rejected + rejected.length, merged: lastStats.merged + merged };
  return { format, added: items.length - merged, merged, rejected };
}

const exportRows = () => [...store.values()].map(r => ({ indicator_value: r.indicator_value, indicator_type: r.indicator_type, confidence: r.confidence,
  mitre_attack_id: [...r.mitre].join(',') || null, sources: [...r.sources], first_seen: r.firstSeen, occurrences: r.seen, dedup_key: r.key, enrichment: r.enrichment.text }));

function renderStore() {
  const q = $('#ti-filter').value.trim().toLowerCase();
  const rows = [...store.values()].sort((a, b) => b.confidence - a.confidence || a.indicator_type.localeCompare(b.indicator_type))
    .filter(r => !q || [r.indicator_value, r.indicator_type, [...r.mitre].join(' '), [...r.sources].join(' ')].join(' ').toLowerCase().includes(q));
  const stat = (n, l) => el('div', { class: 'lx-stat' }, el('b', { text: fmtNum(n) }), el('span', { text: l }));
  $('#ti-stats').replaceChildren(stat(lastStats.raw, 'extracted'), stat(store.size, 'unique'), stat(lastStats.merged, 'duplicates merged'), stat(lastStats.rejected, 'rejected'));
  const tone = c => c >= 80 ? 'crit' : c >= 60 ? 'high' : c >= 40 ? 'warn' : 'mute';
  $('#ti-rows').replaceChildren(...(rows.length ? rows.map(r => el('tr', {},
    el('td', { class: 'lx-mono', text: r.indicator_value }),
    el('td', {}, el('span', { class: 'lx-badge lx-badge--info', text: r.indicator_type })),
    el('td', {}, el('span', { class: 'lx-badge lx-badge--' + tone(r.confidence), text: r.confidence })),
    el('td', { class: 'lx-mono', text: [...r.mitre].join(', ') || '—' }),
    el('td', { text: `${[...r.sources].join(' + ')}${r.seen > 1 ? ` (×${r.seen})` : ''}` }),
    el('td', {}, el('span', { class: 'lx-badge lx-badge--' + r.enrichment.tone, style: { whiteSpace: 'normal', textTransform: 'none', letterSpacing: 0 }, text: r.enrichment.text })),
    el('td', { class: 'lx-mono', title: r.key, text: r.key.slice(0, 12) + '…' })))
    : [el('tr', {}, el('td', { colspan: 7, class: 'lx-muted', text: store.size ? 'No indicators match the filter.' : 'No indicators yet. Load a sample or paste a feed.' }))]));
}

$('#ti-add').addEventListener('click', async () => {
  const msg = $('#ti-msg');
  try {
    const r = await ingest($('#ti-in').value);
    const parts = [`${r.format}: ${r.added} new indicator${r.added === 1 ? '' : 's'}`, `${r.merged} merged into existing records`];
    if (r.rejected.length) parts.push(`${r.rejected.length} rejected`);
    msg.replaceChildren(el('div', { class: 'lx-alert lx-alert--ok', text: parts.join(' · ') + '.' }),
      ...(r.rejected.length ? [el('ul', { class: 'lx-rules' }, ...r.rejected.slice(0, 6).map(x => el('li', { text: `${x.value} — ${x.reason}` })))] : []));
    renderStore();
  } catch (e) { msg.replaceChildren(el('div', { class: 'lx-alert lx-alert--err', role: 'alert', text: e.message })); }
});
$('#ti-filter').addEventListener('input', renderStore);
$('#ti-clear').addEventListener('click', () => { store.clear(); lastStats = { raw: 0, rejected: 0, merged: 0 }; renderStore(); $('#ti-msg').replaceChildren(); });
$('#ti-json').addEventListener('click', () => store.size ? download(JSON.stringify(exportRows(), null, 2), 'hastra-normalized-iocs.json', 'application/json') : toast('Nothing to export yet'));
$('#ti-csv').addEventListener('click', () => {
  if (!store.size) return toast('Nothing to export yet');
  const cols = ['indicator_value', 'indicator_type', 'confidence', 'mitre_attack_id', 'sources', 'first_seen', 'occurrences', 'dedup_key', 'enrichment'];
  const esc = v => { const s = Array.isArray(v) ? v.join('; ') : v ?? ''; const t = String(s); return /^[=+\-@\t\r]/.test(t) ? `"'${t.replace(/"/g, '""')}"` : `"${t.replace(/"/g, '""')}"`; };
  download([cols.join(','), ...exportRows().map(r => cols.map(c => esc(r[c])).join(','))].join('\r\n'), 'hastra-normalized-iocs.csv', 'text/csv');
});

// Training samples: documentation IP ranges (RFC 5737), reserved .example
// domains (RFC 2606) and the EICAR test-file hashes, so nothing here names a
// real host or a real piece of malware.
const EICAR_MD5 = '44d88612fea8a8f36de82e1278abb02f';
const EICAR_SHA256 = '275a021bbfb6489e54d471899f7db9d1663fc695ec2fe2a2c4538aabf651fd0f';
const SAMPLES = {
  stix: {
    type: 'bundle', id: 'bundle--6f1d8f27-2f4e-4b8b-9a0c-6d8e5a1b0c01', objects: [
      { type: 'identity', spec_version: '2.1', id: 'identity--a1b2c3d4-0000-4000-8000-000000000001', name: 'Hastra Training CTI', identity_class: 'organization' },
      { type: 'attack-pattern', spec_version: '2.1', id: 'attack-pattern--0001', name: 'Spearphishing Attachment', external_references: [{ source_name: 'mitre-attack', external_id: 'T1566.001' }] },
      { type: 'attack-pattern', spec_version: '2.1', id: 'attack-pattern--0002', name: 'Web Protocols', external_references: [{ source_name: 'mitre-attack', external_id: 'T1071.001' }] },
      { type: 'indicator', spec_version: '2.1', id: 'indicator--0001', created_by_ref: 'identity--a1b2c3d4-0000-4000-8000-000000000001', name: 'C2 server',
        pattern: "[ipv4-addr:value = '203.0.113.66'] OR [ipv4-addr:value = '198.51.100.7']", pattern_type: 'stix', valid_from: '2026-09-20T00:00:00Z', confidence: 85 },
      { type: 'indicator', spec_version: '2.1', id: 'indicator--0002', created_by_ref: 'identity--a1b2c3d4-0000-4000-8000-000000000001', name: 'Credential phishing domain',
        pattern: "[domain-name:value = 'Login-Microsoft-Secure.example.']", pattern_type: 'stix', valid_from: '2026-09-21T00:00:00Z', confidence: 70 },
      { type: 'indicator', spec_version: '2.1', id: 'indicator--0003', created_by_ref: 'identity--a1b2c3d4-0000-4000-8000-000000000001', name: 'Dropper attachment (EICAR stand-in)',
        pattern: `[file:hashes.'SHA-256' = '${EICAR_SHA256}' AND file:name = 'invoice.pdf.exe']`, pattern_type: 'stix', valid_from: '2026-09-21T08:00:00Z', confidence: 60 },
      { type: 'relationship', spec_version: '2.1', id: 'relationship--0001', relationship_type: 'indicates', source_ref: 'indicator--0001', target_ref: 'attack-pattern--0002' },
      { type: 'relationship', spec_version: '2.1', id: 'relationship--0002', relationship_type: 'indicates', source_ref: 'indicator--0003', target_ref: 'attack-pattern--0001' },
    ],
  },
  misp: {
    Event: {
      info: 'Payroll-themed phishing wave', Orgc: { name: 'Training ISAC' },
      Tag: [{ name: 'tlp:green' }, { name: 'misp-galaxy:mitre-attack-pattern="Spearphishing Attachment - T1566.001"' }],
      Attribute: [
        { type: 'ip-dst', value: '203.0.113.66', to_ids: true, timestamp: '1758355200', Tag: [{ name: 'misp:confidence-level="completely-confident"' }] },
        { type: 'domain', value: 'login-microsoft-secure[.]example', to_ids: true, timestamp: '1758441600' },
        { type: 'url', value: 'hxxp://payroll-update[.]example/doc.php?id=77', to_ids: true, timestamp: '1758441600' },
        { type: 'filename|sha256', value: `invoice.pdf.exe|${EICAR_SHA256}`, to_ids: true, timestamp: '1758441600' },
        { type: 'md5', value: EICAR_MD5.toUpperCase(), to_ids: false, timestamp: '1758441600' },
        { type: 'email-src', value: 'billing@payroll-update.example', to_ids: false, timestamp: '1758441600' },
        { type: 'ip-src|port', value: '192.0.2.44|8443', to_ids: true, timestamp: '1758441600', comment: 'Beacon every 60s — T1071.001' },
      ],
    },
  },
  list: `# one IOC per line — types are detected automatically\n203.0.113.66\n198.51.100.7\nhxxps://cdn-sync[.]example/update.bin\n${EICAR_MD5}\n2001:db8::dead:beef\nnot-an-ioc\nAS64500`,
};
$$('[data-sample]').forEach(b => b.addEventListener('click', () => {
  const s = SAMPLES[b.dataset.sample];
  $('#ti-in').value = typeof s === 'string' ? s : JSON.stringify(s, null, 2);
  toast('Sample loaded — press “Normalize & add to store”');
}));
renderStore();

// ════════════════════════════════════════════════════════════════════════════
// B · UEBA composite risk score
// ════════════════════════════════════════════════════════════════════════════
const ub = { time: 10, geo: 0, z: 2, c2: 0, weights: { ...U.DEFAULT_WEIGHTS } };
function slider(key, label, hint, min, max, step, value) {
  const id = 'ub-' + key;
  const out = el('output', { class: 'lx-range-val', for: id, id: id + '-v' });
  const input = el('input', { class: 'lx-range', type: 'range', id, min, max, step, value, 'aria-describedby': id + '-h' });
  input.addEventListener('input', () => { ub[key] = parseFloat(input.value); renderUeba(); });
  return el('div', {}, el('div', { class: 'lx-range-head' }, el('label', { class: 'lx-label', for: id, text: label }), out), input, el('p', { class: 'lx-help', id: id + '-h', text: hint }));
}
$('#ub-sliders').append(
  slider('time', U.SIGNALS[0].label, U.SIGNALS[0].hint, 0, 100, 1, ub.time),
  slider('geo', U.SIGNALS[1].label, U.SIGNALS[1].hint, 0, 100, 1, ub.geo),
  slider('z', U.SIGNALS[2].label, U.SIGNALS[2].hint, 0, 10, 0.1, ub.z),
  slider('c2', U.SIGNALS[3].label, U.SIGNALS[3].hint, 0, 100, 1, ub.c2));
for (const s of U.SIGNALS) {
  const id = 'ubw-' + s.key;
  const input = el('input', { class: 'lx-input', id, type: 'number', min: 0, max: 1, step: 0.05, value: ub.weights[s.key] });
  input.addEventListener('input', () => { ub.weights[s.key] = parseFloat(input.value) || 0; renderUeba(); });
  $('#ub-weights').append(el('div', {}, el('label', { class: 'lx-label', for: id, text: s.key }), input));
}
for (const [k, p] of Object.entries(U.PRESETS)) {
  $('#ub-presets').append(el('button', { class: 'lx-btn lx-btn--ghost lx-btn--sm', type: 'button', text: p.label, onclick: () => {
    Object.assign(ub, { time: p.time, geo: p.geo, z: p.z, c2: p.c2 });
    $('#ub-time').value = p.time; $('#ub-geo').value = p.geo; $('#ub-z').value = p.z; $('#ub-c2').value = p.c2;
    renderUeba();
  } }));
}
function renderUeba() {
  const r = U.score({ time: ub.time, geo: ub.geo, volume: U.volumeFromZ(ub.z), c2: ub.c2 }, ub.weights);
  $('#ub-time-v').textContent = `${ub.time} / 100`;
  $('#ub-geo-v').textContent = `${ub.geo} / 100`;
  $('#ub-z-v').textContent = `z = ${ub.z.toFixed(1)} → ${Math.round(U.volumeFromZ(ub.z))}`;
  $('#ub-c2-v').textContent = `${ub.c2} / 100`;
  const color = { ok: 'var(--lx-ok)', warn: 'var(--lx-warn)', high: 'var(--lx-high)', crit: 'var(--lx-crit)' }[r.tier.tone];
  $('#ub-score').textContent = r.score;
  $('#ub-arc').style.strokeDashoffset = String(351.86 * (1 - r.score / 100));
  $('#ub-arc').style.stroke = color;
  $('#ub-tier').className = 'lx-badge lx-badge--' + r.tier.tone;
  $('#ub-tier').textContent = `${r.tier.label} · ${r.tier.key === 'normal' ? '0–30' : r.tier.key === 'watch' ? '31–60' : r.tier.key === 'high' ? '61–80' : '81–100'}`;
  $('#ub-action').textContent = r.tier.action;
  const names = Object.fromEntries(U.SIGNALS.map(s => [s.key, s.label]));
  $('#ub-bars').replaceChildren(...r.parts.map(p => el('div', { class: 'lx-bar' },
    el('span', { text: names[p.key] }), el('div', { class: 'lx-bar-track' }, el('i', { style: { width: p.points + '%' } })), el('b', { text: '+' + p.points.toFixed(1) }))),
    el('div', { class: 'lx-bar' }, el('span', { text: 'Correlation bonus' }), el('div', { class: 'lx-bar-track' }, el('i', { style: { width: r.bonus + '%', background: 'var(--lx-warn)' } })), el('b', { text: '+' + r.bonus })));
  $('#ub-rules').replaceChildren(...U.RULES.map(rule => el('li', { class: r.hits.includes(rule) ? 'is-hit' : '', text: `+${rule.bonus}  ${rule.label}` })));
  const terms = r.parts.map(p => `${p.weight.toFixed(3)}×${Math.round(p.signal)}`).join(' + ');
  $('#ub-formula').textContent = `Risk = min(100, ${terms} + ${r.bonus}${r.rawBonus > r.bonus ? ` (of ${r.rawBonus}, capped)` : ''})\n     = min(100, ${r.weighted.toFixed(1)} + ${r.bonus})\n     = ${r.score}`;
}
renderUeba();

// ════════════════════════════════════════════════════════════════════════════
// C · High-volume EPS scaling calculator
// ════════════════════════════════════════════════════════════════════════════
const GB = 1e9, TB = 1e12;
export function sizePipeline(p) {
  const eps = p.unit === 'eps' ? p.rate : p.rate / 86400;
  const epd = eps * 86400;
  const rawBpd = epd * p.size;
  const keptBpd = rawBpd * (1 - p.filter / 100);
  const hotPerDay = keptBpd * p.ratio;                      // primary copy, on disk
  const tiers = {
    hot:  { days: p.hotDays,  bytes: hotPerDay * (1 + p.replicas) * p.hotDays, price: p.pHot },
    warm: { days: p.warmDays, bytes: hotPerDay * (1 + p.replicas) * p.warmDays, price: p.pWarm },
    cold: { days: p.coldDays, bytes: keptBpd * p.coldRatio * p.coldDays, price: p.pCold },
  };
  for (const t of Object.values(tiers)) t.monthly = t.bytes / GB * t.price;
  const monthly = tiers.hot.monthly + tiers.warm.monthly + tiers.cold.monthly;
  const unfiltered = monthly / Math.max(1e-9, 1 - p.filter / 100);
  const dailyPrimaryGB = hotPerDay / GB;
  const SHARD_GB = 40;
  const shardsPerDay = Math.max(1, Math.ceil(dailyPrimaryGB / SHARD_GB));
  const hourly = shardsPerDay > 24;
  const partitionsPerDay = hourly ? 24 : 1;
  const shardsPerIndex = Math.max(1, Math.ceil(shardsPerDay / partitionsPerDay));
  const hotShards = shardsPerIndex * partitionsPerDay * (1 + p.replicas) * p.hotDays;
  const hotNodes = Math.max(1, Math.ceil(tiers.hot.bytes / (p.nodeTB * TB)));
  const ingestMBps = eps * p.size * (1 - p.filter / 100) / 1e6;
  const kafkaPartitions = Math.max(12, Math.ceil(ingestMBps * 2 / 10));  // 2× headroom, ~10 MB/s per partition
  const kafkaBrokers = Math.max(3, Math.ceil(ingestMBps * 3 / 150));     // RF 3, ~150 MB/s sustained writes per broker
  return { eps, epd, rawBpd, keptBpd, tiers, monthly, unfiltered, dailyPrimaryGB, shardsPerDay, hourly, partitionsPerDay, shardsPerIndex,
           hotShards, hotNodes, shardsPerNode: hotShards / hotNodes, ingestMBps, gbps: ingestMBps * 8 / 1000, kafkaPartitions, kafkaBrokers,
           kafkaBufferBytes: keptBpd * 3 };
}
const money = n => '$' + (n >= 1e6 ? fmtCompact(n) : fmtNum(n, n < 100 ? 2 : 0));
function renderEps() {
  const v = id => parseFloat($(id).value);
  const p = { rate: v('#eps-rate'), unit: $('#eps-unit').value, size: v('#eps-size'), filter: Math.min(99, Math.max(0, v('#eps-filter'))),
    hotDays: v('#eps-hotdays'), warmDays: v('#eps-warmdays'), coldDays: v('#eps-colddays'), replicas: v('#eps-replicas'),
    ratio: v('#eps-ratio'), coldRatio: v('#eps-coldratio'), pHot: v('#eps-p-hot'), pWarm: v('#eps-p-warm'), pCold: v('#eps-p-cold'), nodeTB: v('#eps-node') };
  if (Object.values(p).some(x => typeof x === 'number' && !Number.isFinite(x)) || p.rate <= 0 || p.size <= 0) return;
  const r = sizePipeline(p);
  const stat = (b, s) => el('div', { class: 'lx-stat' }, el('b', { text: b }), el('span', { text: s }));
  $('#eps-stats').replaceChildren(stat(fmtCompact(r.eps), 'events / second'), stat(fmtCompact(r.epd), 'events / day'),
    stat(fmtBytes(r.rawBpd), 'raw / day'), stat(fmtBytes(r.keptBpd), 'kept / day'), stat(money(r.monthly), 'storage / month'));
  const row = (name, t) => el('tr', {}, el('td', { text: name }), el('td', { text: `${t.days} d` }), el('td', { class: 'lx-mono', text: fmtBytes(t.bytes) }), el('td', { class: 'lx-mono', text: money(t.monthly) }));
  $('#eps-tiers').replaceChildren(row('Hot · NVMe', r.tiers.hot), row('Warm · SSD', r.tiers.warm), row('Cold · S3 / Glacier', r.tiers.cold),
    el('tr', {}, el('td', {}, el('b', { text: 'Total' })), el('td'), el('td', { class: 'lx-mono', text: fmtBytes(r.tiers.hot.bytes + r.tiers.warm.bytes + r.tiers.cold.bytes) }), el('td', { class: 'lx-mono' }, el('b', { text: money(r.monthly) }))));
  const kv = (box, pairs) => $(box).replaceChildren(...pairs.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v }), el('dd')]));
  kv('#eps-index', [
    ['Partition', r.hourly ? 'hourly indices · logs-<source>-YYYY.MM.DD.HH' : 'daily indices · logs-<source>-YYYY.MM.DD'],
    ['Primary data / day', fmtNum(r.dailyPrimaryGB, 1) + ' GB on disk'],
    ['Primary shards / index', `${fmtNum(r.shardsPerIndex)} (target ≤ 40 GB each), ${fmtNum(r.partitionsPerDay)} index${r.partitionsPerDay > 1 ? 'es' : ''} / day`],
    ['Rollover policy', 'max_primary_shard_size: 50gb · max_age: ' + (r.hourly ? '1h' : '1d')],
    ['Hot shards (incl. replicas)', fmtNum(r.hotShards)],
    ['Hot data nodes', `${fmtNum(r.hotNodes)} × ${p.nodeTB} TB usable · ≈ ${fmtNum(r.shardsPerNode, 0)} shards / node`],
  ]);
  kv('#eps-kafka', [
    ['Post-filter throughput', `${fmtNum(r.ingestMBps, 1)} MB/s · ${fmtNum(r.gbps, 2)} Gbit/s`],
    ['Kafka partitions', `${fmtNum(r.kafkaPartitions)} (2× headroom at ~10 MB/s each)`],
    ['Kafka brokers', `${fmtNum(r.kafkaBrokers)} (RF 3, ~150 MB/s writes per broker)`],
    ['24 h replay buffer', fmtBytes(r.kafkaBufferBytes) + ' across the cluster'],
  ]);
  const advice = [];
  if (p.filter > 0) advice.push(`Dropping ${p.filter}% at the source saves about ${money(r.unfiltered - r.monthly)} per month in storage, and the same share of network and indexing load.`);
  if (r.hourly) advice.push('More than 24 primary shards a day: hourly indices keep shards near 40 GB, so recovery and merges stay fast.');
  if (r.shardsPerNode > 1000) advice.push('More than ~1,000 shards per node strains cluster state. Lengthen rollover, lower replicas on warm, or add nodes.');
  if (r.tiers.hot.monthly > r.tiers.cold.monthly * 20) advice.push('Hot storage dominates cost. Every day moved from hot to warm cuts that day’s cost by ' + Math.round((1 - p.pWarm / p.pHot) * 100) + '%.');
  if (r.eps > 1e6) advice.push('At this rate, put a stream processor (Kafka Streams / Flink) in front of the indexers for parsing, enrichment and dedup, and scale indexers independently.');
  $('#eps-advice').replaceChildren(...advice.map(a => el('div', { class: 'lx-alert lx-alert--info', text: a })));
}
$$('#eps-form input, #eps-form select').forEach(i => i.addEventListener('input', renderEps));
$$('[data-eps]').forEach(b => b.addEventListener('click', () => { const [rate, unit] = b.dataset.eps.split(':'); $('#eps-rate').value = rate; $('#eps-unit').value = unit; renderEps(); }));
renderEps();

// ════════════════════════════════════════════════════════════════════════════
// D · Multi-cloud log pipeline mapper
// ════════════════════════════════════════════════════════════════════════════
const SUSPICIOUS = {
  m365: /^(New-InboxRule|Set-InboxRule|UpdateInboxRules|Add-MailboxPermission|Set-Mailbox|Consent to application|Add service principal|Add member to role|FileSyncDownloadedFull|MailItemsAccessed)$/i,
  aws: /^(CreateAccessKey|CreateUser|AttachUserPolicy|PutUserPolicy|PutBucketPolicy|PutBucketAcl|DeleteTrail|StopLogging|UpdateTrail|GetSecretValue|DisableKey|ScheduleKeyDeletion|CreateLoginProfile)$/,
  k8s: /^(get|list|watch) secrets|^create pods\/exec|^create (cluster)?rolebindings|^(create|patch) (daemonsets|clusterroles)|^delete events/,
};
const utc = s => { if (!s) return NaN; const t = /[zZ]|[+-]\d\d:?\d\d$/.test(s) ? s : s + 'Z'; return Date.parse(t); };
const stripPort = ip => { if (!ip) return null; const s = String(ip).trim(); const v6 = s.match(/^\[([^\]]+)\](?::\d+)?$/); if (v6) return v6[1]; return /^\d+\.\d+\.\d+\.\d+:\d+$/.test(s) ? s.split(':')[0] : s; };

function parseJsonList(text, arrayKeys) {
  const t = text.trim();
  if (!t) return [];
  try {
    const d = JSON.parse(t);
    if (Array.isArray(d)) return d;
    for (const k of arrayKeys) if (Array.isArray(d?.[k])) return d[k];
    return [d];
  } catch {
    return t.split(/\r?\n/).filter(Boolean).map(l => JSON.parse(l));  // NDJSON (Fluent Bit output)
  }
}
export function normalizeLogs(m365Text, awsText, k8sText, idMap) {
  const alias = new Map();
  for (const [canon, list] of Object.entries(idMap || {})) { alias.set(canon.toLowerCase(), canon.toLowerCase()); for (const a of list || []) alias.set(String(a).toLowerCase(), canon.toLowerCase()); }
  const who = raw => { if (!raw) return 'unknown'; const k = String(raw).toLowerCase(); return alias.get(k) || k; };
  const out = [];
  for (const r of parseJsonList(m365Text, ['value', 'records', 'Records'])) {
    const action = r.Operation || r.operation;
    out.push({ ts: utc(r.CreationTime || r.creationTime), source: 'm365', actor: who(r.UserId || r.userId), rawActor: r.UserId, action,
      target: r.ObjectId || r.Workload || '', ip: stripPort(r.ClientIP || r.ClientIPAddress || r.clientIp), suspicious: SUSPICIOUS.m365.test(action || '') });
  }
  for (const r of parseJsonList(awsText, ['Records'])) {
    const ui = r.userIdentity || {};
    const rawActor = ui.userName || ui.arn || ui.principalId;
    const arnActor = ui.arn ? who(ui.arn) : null;
    const actor = arnActor && arnActor !== ui.arn.toLowerCase() ? arnActor : who(rawActor);
    const rp = r.requestParameters || {};
    const mfaOff = r.eventName === 'ConsoleLogin' && (r.additionalEventData?.MFAUsed === 'No');
    out.push({ ts: utc(r.eventTime), source: 'aws', actor, rawActor, action: r.eventName + (mfaOff ? ' (no MFA)' : ''),
      target: rp.bucketName || rp.userName || rp.secretId || (r.eventSource || '').replace('.amazonaws.com', ''), ip: stripPort(r.sourceIPAddress),
      suspicious: SUSPICIOUS.aws.test(r.eventName || '') || mfaOff });
  }
  for (let r of parseJsonList(k8sText, ['items', 'records'])) {
    if (typeof r.log === 'string') { try { r = JSON.parse(r.log); } catch { continue; } } else if (r.log && typeof r.log === 'object') r = r.log;
    const o = r.objectRef || {};
    const action = `${r.verb} ${o.resource || ''}${o.subresource ? '/' + o.subresource : ''}`.trim();
    out.push({ ts: utc(r.requestReceivedTimestamp || r.stageTimestamp || r.timestamp), source: 'k8s', actor: who(r.user?.username), rawActor: r.user?.username,
      action, target: [o.namespace, o.name].filter(Boolean).join('/'), ip: stripPort(r.sourceIPs?.[0]), suspicious: SUSPICIOUS.k8s.test(action) });
  }
  return out.filter(e => Number.isFinite(e.ts)).sort((a, b) => a.ts - b.ts);
}

// Union-find over events that share an identity and/or IP within the window.
export function correlate(events, windowMin, by) {
  const parent = events.map((_, i) => i);
  const find = i => parent[i] === i ? i : (parent[i] = find(parent[i]));
  const links = [];
  const W = windowMin * 60000;
  const last = { actor: new Map(), ip: new Map() };
  events.forEach((e, i) => {
    for (const kind of by === 'both' ? ['actor', 'ip'] : [by]) {
      const key = e[kind];
      if (!key || key === 'unknown') continue;
      const j = last[kind].get(key);
      if (j !== undefined && e.ts - events[j].ts <= W) {
        if (events[j].source !== e.source || kind === 'actor') links.push({ from: j, to: i, kind });
        parent[find(i)] = find(j);
      }
      last[kind].set(key, i);
    }
  });
  const groups = new Map();
  events.forEach((_, i) => { const r = find(i); groups.set(r, [...(groups.get(r) || []), i]); });
  const chains = [...groups.values()].filter(g => g.length > 1 && new Set(g.map(i => events[i].source)).size > 1)
    .sort((a, b) => events[a[0]].ts - events[b[0]].ts);
  const chainOf = new Map();
  chains.forEach((g, ci) => g.forEach(i => chainOf.set(i, String.fromCharCode(65 + (ci % 26)))));
  return { links: links.filter(l => chainOf.has(l.from) && chainOf.get(l.from) === chainOf.get(l.to)), chains, chainOf };
}

const LANES = [['m365', 'Microsoft 365'], ['aws', 'AWS CloudTrail'], ['k8s', 'Kubernetes']];
// focus: null for every event, or a chain index to zoom the time axis onto it.
function drawTimeline(allEvents, corr, focus) {
  const Wd = Math.max(640, Math.round($('#pl-chart').clientWidth || 1100));
  const H = 300, L = Wd < 800 ? 104 : 150, R = Wd - 20, laneY = { m365: 70, aws: 150, k8s: 230 };
  let lo = allEvents[0].ts, hi = allEvents[allEvents.length - 1].ts;
  if (focus !== null && corr.chains[focus]) {
    const g = corr.chains[focus].map(i => allEvents[i].ts);
    lo = Math.min(...g); hi = Math.max(...g);
  }
  const vis = new Set(allEvents.map((e, i) => i).filter(i => allEvents[i].ts >= lo - (hi - lo) * 0.25 - 60000 && allEvents[i].ts <= hi + (hi - lo) * 0.25 + 60000));
  const events = allEvents;
  const t0 = lo, t1 = hi;
  const span = Math.max(60000, t1 - t0), pad = span * 0.04;
  const x = t => L + (t - (t0 - pad)) / (span + 2 * pad) * (R - L);
  const root = svg('svg', { class: 'lx-timeline', viewBox: `0 0 ${Wd} ${H}`, role: 'img', 'aria-label': `Timeline of ${events.length} events across three sources` });
  const step = [60e3, 300e3, 600e3, 900e3, 1800e3, 3600e3, 7200e3, 21600e3, 86400e3].find(s => span / s <= 8) || 86400e3;
  for (let t = Math.ceil((t0 - pad) / step) * step; t <= t1 + pad; t += step) {
    const d = new Date(t);
    root.append(svg('line', { x1: x(t), x2: x(t), y1: 30, y2: 262, stroke: 'var(--lx-hair)' }),
      svg('text', { class: 'tick', x: x(t), y: 284, 'text-anchor': 'middle', text: step >= 86400e3 ? d.toISOString().slice(5, 10) : d.toISOString().slice(11, 16) }));
  }
  for (const [k, label] of LANES) root.append(svg('line', { class: 'lane-line', x1: L, x2: R, y1: laneY[k], y2: laneY[k] }), svg('text', { class: 'lane-label', x: 8, y: laneY[k] + 4, text: label }));
  for (const l of corr.links) {
    if (!vis.has(l.from) || !vis.has(l.to)) continue;
    const a = events[l.from], b = events[l.to];
    const x1 = x(a.ts), y1 = laneY[a.source], x2 = x(b.ts), y2 = laneY[b.source];
    const mx = (x1 + x2) / 2, my = y1 === y2 ? y1 - 34 : (y1 + y2) / 2;
    root.append(svg('path', { class: 'link' + (l.kind === 'ip' ? ' ip' : ''), d: `M${x1},${y1} Q${mx},${my} ${x2},${y2}`, stroke: l.kind === 'ip' ? 'var(--lx-info)' : 'var(--lx-accent)' }));
  }
  events.forEach((e, i) => {
    if (!vis.has(i)) return;
    const c = svg('circle', { class: 'ev', cx: x(e.ts), cy: laneY[e.source], r: 7, tabindex: 0, role: 'button',
      fill: e.suspicious ? 'var(--lx-warn)' : 'var(--lx-plate-2)', stroke: corr.chainOf.has(i) ? 'var(--lx-accent)' : 'var(--lx-mute)', 'stroke-width': 2,
      'aria-label': `${new Date(e.ts).toISOString()} ${e.source} ${e.actor} ${e.action}` },
      svg('title', { text: `${new Date(e.ts).toISOString().slice(11, 19)} UTC · ${e.actor} · ${e.action}` }));
    const show = () => $('#pl-detail').replaceChildren(el('div', { class: 'lx-alert lx-alert--' + (e.suspicious ? 'warn' : 'info') },
      el('b', { text: `${new Date(e.ts).toISOString().replace('T', ' ').slice(0, 19)} UTC · ${LANES.find(l => l[0] === e.source)[1]}` }), el('br'),
      `${e.actor}${e.rawActor && e.rawActor.toLowerCase() !== e.actor ? ` (as ${e.rawActor})` : ''} → ${e.action}${e.target ? ' on ' + e.target : ''}${e.ip ? ' from ' + e.ip : ''}`,
      corr.chainOf.has(i) ? `  · chain ${corr.chainOf.get(i)}` : ''));
    c.addEventListener('click', show);
    c.addEventListener('keydown', ev => { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); show(); } });
    root.append(c);
  });
  return root;
}

function runPipeline() {
  const msg = $('#pl-msg');
  try {
    let ids = {};
    if ($('#pl-ids').value.trim()) { try { ids = JSON.parse($('#pl-ids').value); } catch { throw new Error('The identity map is not valid JSON.'); } }
    const events = normalizeLogs($('#pl-m365').value, $('#pl-aws').value, $('#pl-k8s').value, ids);
    if (!events.length) throw new Error('No events with a readable timestamp. Paste logs or load the incident sample.');
    const corr = correlate(events, Math.max(1, parseFloat($('#pl-window').value) || 30), $('#pl-by').value);
    const focusOn = f => {
      $('#pl-chart').replaceChildren(drawTimeline(events, corr, f));
      $$('#pl-focus button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.focus === String(f))));
    };
    $('#pl-focus').replaceChildren(
      el('button', { class: 'lx-btn lx-btn--ghost lx-btn--sm', type: 'button', 'data-focus': 'null', text: `All ${events.length} events`, onclick: () => focusOn(null) }),
      ...corr.chains.map((_, ci) => el('button', { class: 'lx-btn lx-btn--ghost lx-btn--sm', type: 'button', 'data-focus': String(ci), text: `Zoom to chain ${String.fromCharCode(65 + ci)}`, onclick: () => focusOn(ci) })));
    focusOn(corr.chains.length ? 0 : null);
    $('#pl-detail').replaceChildren();
    $('#pl-rows').replaceChildren(...events.map((e, i) => el('tr', {},
      el('td', { class: 'lx-mono', text: new Date(e.ts).toISOString().replace('T', ' ').slice(0, 19) }),
      el('td', { text: LANES.find(l => l[0] === e.source)[1] }),
      el('td', { class: 'lx-mono', text: e.actor }),
      el('td', {}, e.suspicious ? el('span', { class: 'lx-badge lx-badge--warn', style: { textTransform: 'none', letterSpacing: 0 }, text: e.action }) : e.action),
      el('td', { class: 'lx-mono', text: e.target || '—' }),
      el('td', { class: 'lx-mono', text: e.ip || '—' }),
      el('td', {}, corr.chainOf.has(i) ? el('span', { class: 'lx-badge lx-badge--crit', text: corr.chainOf.get(i) }) : '—'))));
    const sus = events.filter(e => e.suspicious).length;
    const lines = corr.chains.map((g, ci) => {
      const ev = g.map(i => events[i]);
      const actors = [...new Set(ev.map(e => e.actor))], ips = [...new Set(ev.map(e => e.ip).filter(Boolean))];
      const span = Math.round((ev[ev.length - 1].ts - ev[0].ts) / 60000);
      return `Chain ${String.fromCharCode(65 + ci)}: ${ev.length} events across ${new Set(ev.map(e => e.source)).size} clouds in ${span} min · ${actors.join(', ')} · ${ips.join(', ') || 'no IP'} · ${ev.filter(e => e.suspicious).length} suspicious`;
    });
    msg.replaceChildren(el('div', { class: 'lx-alert ' + (corr.chains.length ? 'lx-alert--warn' : 'lx-alert--ok') },
      el('b', { text: `${events.length} events normalized · ${sus} suspicious · ${corr.chains.length} cross-cloud chain${corr.chains.length === 1 ? '' : 's'}` }),
      ...lines.flatMap(l => [el('br'), l])));
  } catch (e) { msg.replaceChildren(el('div', { class: 'lx-alert lx-alert--err', role: 'alert', text: e.message })); }
}
$('#pl-run').addEventListener('click', runPipeline);

const PL_SAMPLE = {
  m365: [
    { CreationTime: '2026-09-27T09:02:11', Operation: 'UserLoggedIn', Workload: 'AzureActiveDirectory', UserId: 'alice@contoso.example', ClientIP: '203.0.113.50' },
    { CreationTime: '2026-09-27T09:05:40', Operation: 'New-InboxRule', Workload: 'Exchange', UserId: 'alice@contoso.example', ClientIP: '203.0.113.50:51432', ObjectId: 'Forward all to fwd@mailbox.example' },
    { CreationTime: '2026-09-27T09:21:03', Operation: 'FileDownloaded', Workload: 'SharePoint', UserId: 'alice@contoso.example', ClientIP: '203.0.113.50', ObjectId: 'Finance/Q3-payroll.xlsx' },
    { CreationTime: '2026-09-27T11:40:00', Operation: 'UserLoggedIn', Workload: 'AzureActiveDirectory', UserId: 'bob@contoso.example', ClientIP: '198.51.100.23' },
  ],
  aws: { Records: [
    { eventTime: '2026-09-27T09:04:55Z', eventSource: 'signin.amazonaws.com', eventName: 'ConsoleLogin', sourceIPAddress: '203.0.113.50',
      userIdentity: { type: 'IAMUser', userName: 'alice', arn: 'arn:aws:iam::111122223333:user/alice' }, additionalEventData: { MFAUsed: 'No' } },
    { eventTime: '2026-09-27T09:07:12Z', eventSource: 'iam.amazonaws.com', eventName: 'CreateAccessKey', sourceIPAddress: '203.0.113.50',
      userIdentity: { type: 'IAMUser', userName: 'alice', arn: 'arn:aws:iam::111122223333:user/alice' }, requestParameters: { userName: 'alice' } },
    { eventTime: '2026-09-27T09:15:30Z', eventSource: 's3.amazonaws.com', eventName: 'PutBucketPolicy', sourceIPAddress: '203.0.113.50',
      userIdentity: { type: 'IAMUser', userName: 'alice', arn: 'arn:aws:iam::111122223333:user/alice' }, requestParameters: { bucketName: 'payroll-exports' } },
    { eventTime: '2026-09-27T10:02:00Z', eventSource: 's3.amazonaws.com', eventName: 'GetObject', sourceIPAddress: '198.51.100.23',
      userIdentity: { type: 'IAMUser', userName: 'bob', arn: 'arn:aws:iam::111122223333:user/bob' }, requestParameters: { bucketName: 'team-docs' } },
  ] },
  k8s: [
    { date: 1790499005.1, log: JSON.stringify({ kind: 'Event', apiVersion: 'audit.k8s.io/v1', stage: 'ResponseComplete', requestReceivedTimestamp: '2026-09-27T09:10:05.214Z', verb: 'get',
      user: { username: 'alice@contoso.example' }, sourceIPs: ['203.0.113.50'], objectRef: { resource: 'secrets', namespace: 'payments', name: 'db-credentials' }, responseStatus: { code: 200 } }) },
    { date: 1790499164.6, log: JSON.stringify({ kind: 'Event', apiVersion: 'audit.k8s.io/v1', stage: 'ResponseStarted', requestReceivedTimestamp: '2026-09-27T09:12:44.050Z', verb: 'create',
      user: { username: 'alice@contoso.example' }, sourceIPs: ['203.0.113.50'], objectRef: { resource: 'pods', subresource: 'exec', namespace: 'payments', name: 'api-7d9f' }, responseStatus: { code: 101 } }) },
    { date: 1790501400.0, log: JSON.stringify({ kind: 'Event', apiVersion: 'audit.k8s.io/v1', stage: 'ResponseComplete', requestReceivedTimestamp: '2026-09-27T09:50:00.000Z', verb: 'list',
      user: { username: 'system:serviceaccount:ci:deployer' }, sourceIPs: ['10.0.4.7'], objectRef: { resource: 'pods', namespace: 'payments' }, responseStatus: { code: 200 } }) },
  ],
  ids: { 'alice@contoso.example': ['arn:aws:iam::111122223333:user/alice', 'alice'], 'bob@contoso.example': ['arn:aws:iam::111122223333:user/bob', 'bob'] },
};
$('#pl-sample').addEventListener('click', () => {
  $('#pl-m365').value = JSON.stringify(PL_SAMPLE.m365, null, 2);
  $('#pl-aws').value = JSON.stringify(PL_SAMPLE.aws, null, 2);
  $('#pl-k8s').value = PL_SAMPLE.k8s.map(r => JSON.stringify(r)).join('\n');
  $('#pl-ids').value = JSON.stringify(PL_SAMPLE.ids, null, 2);
  runPipeline();
});
