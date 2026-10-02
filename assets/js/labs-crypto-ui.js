/* Hastra Labs — Module 1 UI (labs/crypto.php). */
import { $, $$, el, toast, busy, tabs, dropZone, download, readFileBytes, enc, dec, toB64, fromB64, fmtBytes, fmtNum } from './labs-common.js';
import * as V from './pqc-crypto.js';
import { gateLabs } from './trial-gatekeeper.js';

// one free run for visitors who are not signed in (see trial-gatekeeper.js)
gateLabs({ clicks: {
  '#cx-run-enc': 'Encryption', '#cx-run-dec': 'Decryption', '#kx-run': 'Key pair generation',
  '#sx-run': 'Signing', '#vx-run': 'Signature verification', '#hx-run': 'Password hasher',
  '#hv-run': 'Hash verification', '#tx-run': 'Crypto self-test',
} });

tabs($('#cx-tabs'));
document.addEventListener('click', e => {
  const g = e.target.closest('[data-goto]');
  if (g) { e.preventDefault(); document.getElementById(g.dataset.goto)?.click(); }
});

const ARMOR_BEGIN = '-----BEGIN HASTRA LABS ENVELOPE-----';
const ARMOR_END = '-----END HASTRA LABS ENVELOPE-----';
const armor = bytes => ARMOR_BEGIN + '\n' + toB64(bytes).match(/.{1,64}/g).join('\n') + '\n' + ARMOR_END + '\n';
function unarmor(text) {
  const m = text.match(/-----BEGIN HASTRA LABS ENVELOPE-----([\s\S]*?)-----END HASTRA LABS ENVELOPE-----/);
  if (!m) throw new Error('Paste the whole armored envelope, including the BEGIN and END lines.');
  return fromB64(m[1]);
}
const chip = (id, text) => { const c = $(id); c.replaceChildren(text ? el('span', { class: 'lx-filechip', text }) : ''); };
const showError = (box, err) => box.replaceChildren(el('div', { class: 'lx-alert lx-alert--err', role: 'alert', text: err.message || String(err) }));
async function readJsonFile(file) { return (await file.text()).trim(); }

// ═══ Encrypt & Decrypt ═════════════════════════════════════════════════════
const state = { file: null, recipient: null, env: null, sk: null };
$$('input[name="cx-dir"]').forEach(r => r.addEventListener('change', () => {
  const encMode = $('input[name="cx-dir"]:checked').value === 'enc';
  $('#cx-enc').hidden = !encMode;
  $('#cx-dec').hidden = encMode;
}));

function syncAlg() {
  const alg = $('#cx-alg').value;
  $('#cx-alg-note').textContent = V.ALGORITHMS[alg].note;
  $('#cx-pass-wrap').hidden = !V.needsPassphrase(alg);
  $('#cx-rcpt-wrap').hidden = V.needsPassphrase(alg);
  if (state.recipient && state.recipient.alg !== alg) { state.recipient = null; chip('#cx-rcpt-chip', ''); }
}
$('#cx-alg').addEventListener('change', syncAlg);
syncAlg();

$('#cx-pass').addEventListener('input', e => paintMeter($('#cx-pass-meter'), $('#cx-pass-strength'), V.estimateStrength(e.target.value)));
function paintMeter(bar, label, s) {
  const colors = { 'very weak': 'var(--lx-crit)', weak: 'var(--lx-high)', fair: 'var(--lx-warn)', strong: 'var(--lx-ok)', 'very strong': 'var(--lx-ok)', excellent: 'var(--lx-info)' };
  bar.style.width = Math.max(4, s.score) + '%';
  bar.style.background = colors[s.rating] || 'var(--lx-hair)';
  if (label) label.textContent = s.rating === 'empty' ? 'Strength appears here.' : `≈ ${Math.round(s.bits)} bits · ${s.rating}${s.findings.length ? ' · ' + s.findings.slice(0, 2).join(', ') : ''}`;
}

dropZone($('#cx-drop'), files => { state.file = files[0]; chip('#cx-file-chip', `${files[0].name} · ${fmtBytes(files[0].size)}`); });
dropZone($('#cx-rcpt-drop'), async files => {
  try {
    const b = V.parseBundle(await readJsonFile(files[0]));
    const alg = $('#cx-alg').value;
    if (b.alg !== alg) throw new Error(`That is a ${b.alg} key; the selected engine is ${alg}.`);
    state.recipient = b;
    chip('#cx-rcpt-chip', `${b.alg} · key ${b.kid}`);
  } catch (e) { toast(e.message, 4000); }
});
dropZone($('#cx-env-drop'), async files => { state.env = { bytes: await readFileBytes(files[0]), name: files[0].name }; chip('#cx-env-chip', `${files[0].name} · ${fmtBytes(files[0].size)}`); });
dropZone($('#cx-sk-drop'), async files => {
  try { state.sk = V.parseBundle(await readJsonFile(files[0]), 'sec'); chip('#cx-sk-chip', `${state.sk.alg} · key ${state.sk.kid}${state.sk.secEnc ? ' · protected' : ''}`); }
  catch (e) { toast(e.message, 4000); }
});

function anatomy(header, total) {
  const rows = [['Algorithm', V.ALGORITHMS[header.alg].label]];
  if (header.kid) rows.push(['Recipient key id', header.kid]);
  if (header.kdf) rows.push(['Key derivation', `${header.kdf}, ${fmtNum(header.iter)} iterations, salt ${header.salt}`]);
  if (header.kem) rows.push(['KEM ciphertext', `${fmtNum(fromB64(header.kem).length)} bytes`]);
  if (header.epk) rows.push(['Ephemeral X25519 key', header.epk]);
  if (header.wrap) rows.push(['RSA-wrapped key', `${fmtNum(fromB64(header.wrap).length)} bytes`]);
  if (header.hkdf) rows.push(['HKDF salt', header.hkdf]);
  rows.push(['Nonce', header.iv]);
  if (header.name) rows.push(['Original name', header.name]);
  rows.push(['Plaintext size', fmtBytes(header.size)]);
  if (total) rows.push(['Envelope size', `${fmtBytes(total)} (${fmtBytes(total - header.size)} overhead)`]);
  $('#cx-anatomy').replaceChildren(...rows.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v }), el('dd')]));
}

$('#cx-run-enc').addEventListener('click', e => busy(e.currentTarget, async () => {
  const box = $('#cx-result');
  try {
    const alg = $('#cx-alg').value;
    const text = $('#cx-text').value;
    let data, name, type;
    if (state.file) { data = await readFileBytes(state.file); name = state.file.name; type = state.file.type || 'application/octet-stream'; }
    else if (text) { data = enc.encode(text); name = null; type = 'text/plain;charset=utf-8'; }
    else throw new Error('Type a message or drop a file first.');
    const opts = { alg, name, type };
    if (V.needsPassphrase(alg)) {
      opts.passphrase = $('#cx-pass').value;
      const s = V.estimateStrength(opts.passphrase);
      if (opts.passphrase && s.bits < 40 && !confirm(`This passphrase is ${s.rating} (≈${Math.round(s.bits)} bits). Encrypt anyway?`)) return;
    } else opts.recipient = state.recipient;
    const t0 = performance.now();
    const { envelope, header } = await V.encryptPayload(data, opts);
    const ms = Math.round(performance.now() - t0);
    anatomy(header, envelope.length);
    const fname = (name || 'message') + '.hlx';
    const kids = [
      el('div', { class: 'lx-alert lx-alert--ok', role: 'status', text: `Sealed with ${V.ALGORITHMS[alg].label} in ${ms} ms · ${fmtBytes(envelope.length)}.` }),
      el('div', { class: 'lx-row' },
        el('button', { class: 'lx-btn btn-beam', type: 'button', onclick: () => download(envelope, fname), text: `Download ${fname}` }))];
    if (!state.file && envelope.length < 256 * 1024) {
      const out = el('textarea', { class: 'lx-textarea', rows: 8, readonly: true, id: 'cx-armor-out', spellcheck: 'false' });
      out.value = armor(envelope);
      kids.push(out, el('button', { class: 'lx-btn lx-btn--ghost lx-btn--sm', type: 'button', 'data-copy': '#cx-armor-out', text: 'Copy armored text' }));
    }
    box.replaceChildren(...kids);
  } catch (err) { showError(box, err); }
}));

$('#cx-run-dec').addEventListener('click', e => busy(e.currentTarget, async () => {
  const box = $('#cx-result');
  try {
    let bytes, srcName;
    if (state.env) { bytes = state.env.bytes; srcName = state.env.name; }
    else if ($('#cx-armor').value.trim()) { bytes = unarmor($('#cx-armor').value); }
    else throw new Error('Drop a .hlx file or paste an armored envelope.');
    const { plain, header } = await V.decryptEnvelope(bytes, { passphrase: $('#cx-dpass').value, secretBundle: state.sk, keyPassphrase: $('#cx-skpass').value });
    anatomy(header, bytes.length);
    const name = header.name || (srcName ? srcName.replace(/\.hlx$/i, '') : 'decrypted.txt');
    const kids = [el('div', { class: 'lx-alert lx-alert--ok', role: 'status', text: `Authentic and decrypted · ${fmtBytes(plain.length)}. The header and ciphertext were not modified.` })];
    if ((header.type || '').startsWith('text/') || !header.name) {
      let text = null;
      try { text = new TextDecoder('utf-8', { fatal: true }).decode(plain); } catch { /* binary */ }
      if (text !== null) { const t = el('textarea', { class: 'lx-textarea', rows: 8, readonly: true }); t.value = text; kids.push(t); }
    }
    kids.push(el('button', { class: 'lx-btn btn-beam', type: 'button', text: `Download ${name}`, onclick: () => download(new Blob([plain], { type: header.type || 'application/octet-stream' }), name) }));
    box.replaceChildren(...kids);
  } catch (err) { showError(box, err); }
}));

// ═══ Key pairs ═════════════════════════════════════════════════════════════
const keyNote = () => { const a = $('#kx-alg').value; $('#kx-note').textContent = (V.ALGORITHMS[a] || V.SIGNATURES[a]).note; };
$('#kx-alg').addEventListener('change', keyNote); keyNote();
$('#kx-run').addEventListener('click', e => busy(e.currentTarget, async () => {
  const out = $('#kx-out');
  try {
    const alg = $('#kx-alg').value;
    const t0 = performance.now();
    const kp = await V.generateKeyPair(alg);
    const pass = $('#kx-pass').value;
    const sec = pass ? await V.protectSecretBundle(kp.secretBundle, pass) : kp.secretBundle;
    const ms = Math.round(performance.now() - t0);
    const base = `hastra-${alg}-${kp.publicBundle.kid}`;
    out.replaceChildren(
      el('div', { class: 'lx-alert lx-alert--ok', role: 'status', text: `Generated in ${ms} ms · public ${fmtNum(kp.sizes.pub)} B · secret ${fmtNum(kp.sizes.sec)} B${pass ? ' · secret sealed with your passphrase' : ''}.` }),
      el('dl', { class: 'lx-kv' }, el('dt', { text: 'Key id' }), el('dd', { text: kp.publicBundle.kid }), el('dd'),
        el('dt', { text: 'Algorithm' }), el('dd', { text: alg }), el('dd')),
      el('div', { class: 'lx-row lx-mt' },
        el('button', { class: 'lx-btn btn-beam', type: 'button', text: 'Download public key', onclick: () => download(JSON.stringify(kp.publicBundle, null, 2), base + '.hpub.json', 'application/json') }),
        el('button', { class: 'lx-btn lx-btn--ghost', type: 'button', text: 'Download secret key', onclick: () => download(JSON.stringify(sec, null, 2), base + '.hkey.json', 'application/json') })),
      pass ? '' : el('p', { class: 'lx-help', text: 'Tip: without a passphrase, anyone who gets the secret key file can decrypt your envelopes.' }));
    if (V.ALGORITHMS[alg]) { $('#cx-alg').value = alg; syncAlg(); state.recipient = kp.publicBundle; chip('#cx-rcpt-chip', `${alg} · key ${kp.publicBundle.kid} (just generated)`); }
  } catch (err) { showError(out, err); }
}));

// ═══ Sign & Verify ═════════════════════════════════════════════════════════
const sx = { file: null, sk: null, vfile: null, sig: null, pk: null };
dropZone($('#sx-drop'), f => { sx.file = f[0]; chip('#sx-file-chip', `${f[0].name} · ${fmtBytes(f[0].size)}`); });
dropZone($('#sx-sk-drop'), async f => { try { sx.sk = V.parseBundle(await readJsonFile(f[0]), 'sec'); if (!V.SIGNATURES[sx.sk.alg]) throw new Error('That is an encryption key. Load an ML-DSA key.'); chip('#sx-sk-chip', `${sx.sk.alg} · key ${sx.sk.kid}`); } catch (e) { sx.sk = null; toast(e.message, 4000); } });
dropZone($('#vx-drop'), f => { sx.vfile = f[0]; chip('#vx-file-chip', `${f[0].name} · ${fmtBytes(f[0].size)}`); });
dropZone($('#vx-sig-drop'), async f => { try { sx.sig = JSON.parse(await readJsonFile(f[0])); chip('#vx-sig-chip', `${sx.sig.alg || '?'} · key ${sx.sig.kid || '?'}`); } catch { toast('That signature file is not valid JSON.'); } });
dropZone($('#vx-pk-drop'), async f => { try { sx.pk = V.parseBundle(await readJsonFile(f[0])); chip('#vx-pk-chip', `${sx.pk.alg} · key ${sx.pk.kid}`); } catch (e) { toast(e.message, 4000); } });

const messageFrom = async (file, textarea) => file ? { bytes: await readFileBytes(file), name: file.name } : textarea.value ? { bytes: enc.encode(textarea.value), name: null } : null;
$('#sx-run').addEventListener('click', e => busy(e.currentTarget, async () => {
  const out = $('#sx-out');
  try {
    const m = await messageFrom(sx.file, $('#sx-text'));
    if (!m) throw new Error('Type a message or drop a file to sign.');
    if (!sx.sk) throw new Error('Load an ML-DSA secret key.');
    const doc = await V.signMessage(m.bytes, sx.sk, $('#sx-skpass').value, m.name);
    const fname = (m.name || 'message') + '.hsig.json';
    out.replaceChildren(el('div', { class: 'lx-alert lx-alert--ok', role: 'status', text: `Signed with ${doc.alg} · ${fmtNum(fromB64(doc.sig).length)}-byte signature · SHA-256 ${doc.sha256.slice(0, 16)}…` }),
      el('button', { class: 'lx-btn btn-beam', type: 'button', text: `Download ${fname}`, onclick: () => download(JSON.stringify(doc, null, 2), fname, 'application/json') }));
  } catch (err) { showError(out, err); }
}));
$('#vx-run').addEventListener('click', e => busy(e.currentTarget, async () => {
  const out = $('#sx-out');
  try {
    const m = await messageFrom(sx.vfile, $('#vx-text'));
    if (!m) throw new Error('Provide the exact message or file that was signed.');
    if (!sx.sig) throw new Error('Load the signature file.');
    if (!sx.pk) throw new Error("Load the signer's public key.");
    const r = await V.verifySignature(m.bytes, sx.sig, sx.pk);
    out.replaceChildren(el('div', { class: 'lx-alert ' + (r.ok ? 'lx-alert--ok' : 'lx-alert--err'), role: 'status',
      text: r.ok ? `Valid ${sx.sig.alg} signature from key ${sx.pk.kid}. The message is exactly what was signed.`
                 : `Invalid signature. ${r.digestMatches ? 'The message matches, so the signature or key is wrong.' : 'The message differs from the one that was signed.'}${r.keyMatches ? '' : ' The key id also differs from the signer recorded in the signature.'}` }));
  } catch (err) { showError(out, err); }
}));

// ═══ Password & Token Hasher ═══════════════════════════════════════════════
let lastHash = null;
const cls = () => $('input[name="hx-class"]:checked').value;
function syncClass() {
  const c = cls(), engines = V.HASHERS[c];
  $('#hx-engine').replaceChildren(...Object.entries(engines).map(([k, v]) => el('option', { value: k, text: `${v.label} · ${v.params}` })));
  $('#hx-gen-row').hidden = c !== 'app_secret';
  syncEngine();
  paintStrength();
}
function syncEngine() {
  const e = $('#hx-engine').value, meta = V.HASHERS[cls()][e];
  $('#hx-engine-note').textContent = meta.note;
  $('#hx-key-wrap').hidden = !(e === 'hmac-sha256' || e === 'blake3');
}
$$('input[name="hx-class"]').forEach(r => r.addEventListener('change', syncClass));
$('#hx-engine').addEventListener('change', syncEngine);
$('#hx-show').addEventListener('click', e => {
  const i = $('#hx-secret'), show = i.type === 'password';
  i.type = show ? 'text' : 'password';
  e.currentTarget.textContent = show ? 'Hide' : 'Show';
  e.currentTarget.setAttribute('aria-pressed', show ? 'true' : 'false');
});
$('#hx-gen').addEventListener('click', () => { $('#hx-secret').value = V.generateMachineSecret(); $('#hx-secret').type = 'text'; $('#hx-show').textContent = 'Hide'; paintStrength(); });
$('#hx-secret').addEventListener('input', paintStrength);

function paintStrength() {
  const s = V.estimateStrength($('#hx-secret').value);
  $('#hx-bits').textContent = s.rating === 'empty' ? '— bits' : `≈ ${Math.round(s.bits)} bits of entropy`;
  const tone = { empty: 'mute', 'very weak': 'crit', weak: 'high', fair: 'warn', strong: 'ok', 'very strong': 'ok', excellent: 'info' }[s.rating];
  $('#hx-rating').className = 'lx-badge lx-badge--' + tone;
  $('#hx-rating').textContent = s.rating;
  paintMeter($('#hx-meter'), null, s);
  const notes = [...s.findings];
  if (cls() === 'app_secret' && s.bits && s.bits < 128) notes.push('Machine tokens should carry at least 128 bits. Use “Generate a 256-bit token”.');
  if (cls() === 'user_password' && s.length && s.length < 12) notes.push('Aim for 12+ characters; a few random words beat symbols.');
  $('#hx-findings').replaceChildren(...notes.map(t => el('li', { class: 'is-hit', text: t })));
  const rows = s.crack ? [['Online, throttled login', s.crack.online], ['Offline · unsalted SHA-256', s.crack.fast], ['Offline · bcrypt cost 12', s.crack.bcrypt], ['Offline · Argon2id 64 MiB', s.crack.argon2]] : [];
  $('#hx-crack').replaceChildren(...rows.map(([k, v]) => el('tr', {}, el('td', { text: k }), el('td', { text: V.humanTime(v) }))));
}

$('#hx-run').addEventListener('click', e => busy(e.currentTarget, async () => {
  const out = $('#hx-out');
  try {
    const secret = $('#hx-secret').value;
    if (!secret) throw new Error('Type the secret to hash.');
    const engine = $('#hx-engine').value;
    const r = await V.hashCredential(secret, cls(), engine, $('#hx-key').value.trim() || null);
    lastHash = r;
    const row = (k, v, copyId) => [el('dt', { text: k }), el('dd', { id: copyId, text: v }), copyId ? el('button', { class: 'lx-btn lx-btn--ghost lx-btn--sm', type: 'button', 'data-copy': '#' + copyId, text: 'Copy' }) : el('dd')];
    out.replaceChildren(
      ...row('Engine', `${r.label} · ${r.params}`),
      ...row('Class', r.classification === 'user_password' ? 'Normal user password' : 'App secret / machine token'),
      ...(r.key ? row('Key (pepper)', r.key, 'hx-o-key') : row('Salt', r.salt, 'hx-o-salt')),
      ...row('Derived hash', r.hash, 'hx-o-hash'),
      ...row('Store this', r.stored, 'hx-o-stored'),
      ...row('Cost', `${fmtNum(r.ms)} ms in this browser`));
    if (r.truncated) out.append(el('dt', { text: 'Warning' }), el('dd', { text: 'bcrypt only uses the first 72 bytes of a password; the rest was ignored. Prefer Argon2id.' }), el('dd'));
    if (r.key) out.append(el('dt', { text: 'Important' }), el('dd', { text: 'Store the key separately (secrets manager / HSM). The database keeps only the “store this” string.' }), el('dd'));
    $('#hv-stored').value = r.stored;
    $('#hv-key').value = r.key || '';
  } catch (err) { out.replaceChildren(el('dt', { text: 'Error' }), el('dd', { text: err.message }), el('dd')); }
}));

$('#hv-run').addEventListener('click', e => busy(e.currentTarget, async () => {
  const out = $('#hv-out');
  try {
    const ok = await V.verifyCredential($('#hv-candidate').value, $('#hv-stored').value, $('#hv-key').value.trim() || null);
    out.replaceChildren(el('div', { class: 'lx-alert ' + (ok ? 'lx-alert--ok' : 'lx-alert--err'), text: ok ? 'Match: this secret produces the stored hash.' : 'No match.' }));
  } catch (err) { showError(out, err); }
}));
syncClass();

// ═══ Self-test ═════════════════════════════════════════════════════════════
$('#tx-run').addEventListener('click', e => busy(e.currentTarget, async () => {
  const body = $('#tx-out');
  body.replaceChildren();
  const results = await V.selfTest(r => body.append(el('tr', {},
    el('td', { text: r.name }),
    el('td', {}, el('span', { class: 'lx-badge ' + (r.ok ? 'lx-badge--ok' : 'lx-badge--crit'), text: r.ok ? 'pass' : 'fail' }), r.err ? ' ' + r.err : ''),
    el('td', { class: 'lx-mono', text: r.ms + ' ms' }))));
  const failed = results.filter(r => !r.ok).length;
  toast(failed ? `${failed} check(s) failed` : `All ${results.length} checks passed`, 4000);
}));
