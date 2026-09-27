/* Hastra Labs — HastraCryptoVault
   Classical, post-quantum and hybrid encryption, ML-DSA signatures, and a
   dual-classification credential hasher. Runs entirely in the browser.

   Primitives
     WebCrypto ............ AES-256-GCM, RSA-OAEP-4096 (SHA-256), HKDF-SHA256,
                            PBKDF2 (SHA-256 / SHA-512), HMAC-SHA256, SHA-256
     @noble/ciphers ....... ChaCha20-Poly1305            (audited, pinned)
     @noble/curves ........ X25519
     @noble/post-quantum .. ML-KEM-768/1024 (FIPS 203), ML-DSA-65/87 (FIPS 204),
                            X-Wing = ML-KEM-768 + X25519 hybrid KEM
     @noble/hashes ........ BLAKE3
     hash-wasm ............ Argon2id, bcrypt
   Libraries are resolved through the page's import map (labs/_boot.php pins
   the versions) and loaded on first use.

   Envelope (.hlx) ─ one self-describing binary file
     "HLX1" | u32 BE header length | header JSON | AEAD ciphertext
   The magic, length and header bytes are the AEAD's associated data, so
   editing any header field (algorithm, KEM ciphertext, file name…) makes
   decryption fail instead of silently changing meaning.

   Content encryption is always an AEAD under a fresh 256-bit key:
     passphrase modes  key = PBKDF2-SHA256(passphrase, 16-byte salt, 600 000)
     RSA-OAEP          random CEK wrapped with the recipient's RSA-4096 key
     X25519            ephemeral-static ECDH, then HKDF-SHA256
     ML-KEM / X-Wing   KEM encapsulation, then HKDF-SHA256
   HKDF binds the algorithm and recipient key id into the derived key. */

import { enc, dec, toHex, toB64, fromB64, concat, ctEqual, randomBytes } from './labs-common.js';

const MAGIC = enc.encode('HLX1');
const PBKDF2_ITER = 600000;          // OWASP 2023 guidance for PBKDF2-HMAC-SHA256
export const MAX_FILE_BYTES = 256 * 1024 * 1024;

const libs = {};
const lib = name => (libs[name] ||= import(name).catch(err => { delete libs[name]; throw new Error(`Could not load the ${name} library (${err.message}). Check your connection.`); }));

// ── Algorithm catalogue ─────────────────────────────────────────────────────
export const ALGORITHMS = {
  'aes-256-gcm':       { family: 'symmetric', label: 'AES-256-GCM', note: 'Passphrase → PBKDF2-SHA256 (600k) → AES-256-GCM. The NIST standard AEAD.' },
  'chacha20-poly1305': { family: 'symmetric', label: 'ChaCha20-Poly1305', note: 'Passphrase → PBKDF2-SHA256 (600k) → ChaCha20-Poly1305 (RFC 8439). Fast without AES hardware.' },
  'rsa-oaep-4096':     { family: 'asymmetric', label: 'RSA-4096-OAEP', note: 'A random AES-256 key wrapped with RSA-OAEP (SHA-256). Classical; broken by a large quantum computer.' },
  'x25519':            { family: 'asymmetric', label: 'X25519 (ECIES)', note: 'Ephemeral-static X25519 ECDH → HKDF-SHA256 → AES-256-GCM. Classical; broken by a large quantum computer.' },
  'ml-kem-768':        { family: 'pqc', label: 'ML-KEM-768 (FIPS 203)', note: 'Module-lattice KEM, NIST security category 3. Quantum-resistant.' },
  'ml-kem-1024':       { family: 'pqc', label: 'ML-KEM-1024 (FIPS 203)', note: 'Module-lattice KEM, NIST category 5 (comparable to AES-256). Quantum-resistant.' },
  'xwing':             { family: 'hybrid', label: 'Hybrid X-Wing (ML-KEM-768 + X25519)', note: 'IETF X-Wing hybrid KEM: secure if either ML-KEM or X25519 holds. Recommended for long-lived secrets.' },
};
export const SIGNATURES = {
  'ml-dsa-65': { label: 'ML-DSA-65 (FIPS 204)', note: 'Module-lattice signatures, NIST category 3.' },
  'ml-dsa-87': { label: 'ML-DSA-87 (FIPS 204)', note: 'Module-lattice signatures, NIST category 5.' },
};
export const needsPassphrase = alg => ALGORITHMS[alg]?.family === 'symmetric';

// ── Small primitives ────────────────────────────────────────────────────────
const sha256 = async bytes => new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
export async function sha256Hex(bytes) { return toHex(await sha256(bytes)); }

async function pbkdf2Bits(passphrase, salt, iterations, hash = 'SHA-256', bits = 256) {
  const base = await crypto.subtle.importKey('raw', typeof passphrase === 'string' ? enc.encode(passphrase) : passphrase, 'PBKDF2', false, ['deriveBits']);
  return new Uint8Array(await crypto.subtle.deriveBits({ name: 'PBKDF2', salt, iterations, hash }, base, bits));
}
async function hkdfBytes(ikm, salt, info, bytes = 32) {
  const base = await crypto.subtle.importKey('raw', ikm, 'HKDF', false, ['deriveBits']);
  return new Uint8Array(await crypto.subtle.deriveBits({ name: 'HKDF', hash: 'SHA-256', salt, info: enc.encode(info) }, base, bytes * 8));
}
const aesKey = (raw, usage) => crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, usage);
async function aesGcm(mode, keyBytes, iv, data, aad) {
  const key = await aesKey(keyBytes, [mode]);
  const fn = mode === 'encrypt' ? crypto.subtle.encrypt : crypto.subtle.decrypt;
  return new Uint8Array(await fn.call(crypto.subtle, { name: 'AES-GCM', iv, additionalData: aad, tagLength: 128 }, key, data));
}

// Key id: first 8 bytes of SHA-256(alg ‖ 0x00 ‖ public key), in hex.
export async function keyId(alg, pub) { return toHex((await sha256(concat(enc.encode(alg), new Uint8Array([0]), pub))).slice(0, 8)); }

// ── Envelope framing ────────────────────────────────────────────────────────
function frame(header) {
  const h = enc.encode(JSON.stringify(header));
  const len = new Uint8Array(4);
  new DataView(len.buffer).setUint32(0, h.length, false);
  return concat(MAGIC, len, h);           // this whole prefix is the AAD
}
export function parseEnvelope(bytes) {
  if (bytes.length < 8 || !ctEqual(bytes.subarray(0, 4), MAGIC)) throw new Error('Not a Hastra Labs envelope (.hlx). The file header is missing.');
  const hlen = new DataView(bytes.buffer, bytes.byteOffset + 4, 4).getUint32(0, false);
  if (hlen > 64 * 1024 || 8 + hlen > bytes.length) throw new Error('The envelope header is damaged.');
  const aad = bytes.subarray(0, 8 + hlen);
  let header;
  try { header = JSON.parse(dec.decode(bytes.subarray(8, 8 + hlen))); } catch { throw new Error('The envelope header is not valid JSON.'); }
  if (header.v !== 1 || !ALGORITHMS[header.alg]) throw new Error('Unsupported envelope version or algorithm.');
  return { header, aad, body: bytes.subarray(8 + hlen) };
}

// ── Key bundles ─────────────────────────────────────────────────────────────
// { kty: 'hastra-labs-pub' | 'hastra-labs-sec', v: 1, alg, kid, pub, sec | secEnc, created }
export async function generateKeyPair(alg) {
  let pub, sec;
  if (alg === 'rsa-oaep-4096') {
    const kp = await crypto.subtle.generateKey({ name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['encrypt', 'decrypt']);
    pub = new Uint8Array(await crypto.subtle.exportKey('spki', kp.publicKey));
    sec = new Uint8Array(await crypto.subtle.exportKey('pkcs8', kp.privateKey));
  } else if (alg === 'x25519') {
    const { x25519 } = await lib('curves');
    sec = x25519.utils.randomSecretKey();
    pub = x25519.getPublicKey(sec);
  } else if (alg === 'ml-kem-768' || alg === 'ml-kem-1024') {
    const m = await lib('ml-kem');
    const kp = (alg === 'ml-kem-768' ? m.ml_kem768 : m.ml_kem1024).keygen();
    pub = kp.publicKey; sec = kp.secretKey;
  } else if (alg === 'xwing') {
    const { ml_kem768_x25519 } = await lib('hybrid');
    const kp = ml_kem768_x25519.keygen();
    pub = kp.publicKey; sec = kp.secretKey;
  } else if (alg === 'ml-dsa-65' || alg === 'ml-dsa-87') {
    const m = await lib('ml-dsa');
    const kp = (alg === 'ml-dsa-65' ? m.ml_dsa65 : m.ml_dsa87).keygen();
    pub = kp.publicKey; sec = kp.secretKey;
  } else throw new Error('No key pair for ' + alg);
  const kid = await keyId(alg, pub);
  const created = new Date().toISOString();
  return {
    publicBundle: { kty: 'hastra-labs-pub', v: 1, alg, kid, pub: toB64(pub), created },
    secretBundle: { kty: 'hastra-labs-sec', v: 1, alg, kid, pub: toB64(pub), sec: toB64(sec), created },
    sizes: { pub: pub.length, sec: sec.length },
  };
}

// Optionally seal the secret key under a passphrase (AES-256-GCM, AAD = kid).
export async function protectSecretBundle(bundle, passphrase) {
  const salt = randomBytes(16), iv = randomBytes(12);
  const key = await pbkdf2Bits(passphrase, salt, PBKDF2_ITER);
  const ct = await aesGcm('encrypt', key, iv, fromB64(bundle.sec), enc.encode('hastra-labs/key/' + bundle.kid));
  const { sec, ...rest } = bundle;
  return { ...rest, secEnc: { kdf: 'PBKDF2-SHA256', iter: PBKDF2_ITER, salt: toB64(salt), iv: toB64(iv), ct: toB64(ct) } };
}
async function openSecretBundle(bundle, passphrase) {
  if (bundle.sec) return fromB64(bundle.sec);
  if (!bundle.secEnc) throw new Error('This key file has no secret key in it.');
  if (!passphrase) throw new Error('This secret key is passphrase-protected. Enter its passphrase.');
  const s = bundle.secEnc;
  const key = await pbkdf2Bits(passphrase, fromB64(s.salt), s.iter || PBKDF2_ITER);
  try { return await aesGcm('decrypt', key, fromB64(s.iv), fromB64(s.ct), enc.encode('hastra-labs/key/' + bundle.kid)); }
  catch { throw new Error('Wrong passphrase for this secret key.'); }
}
export function parseBundle(text, want) {
  let b;
  try { b = JSON.parse(text); } catch { throw new Error('That key file is not valid JSON.'); }
  if (!b || b.v !== 1 || !(b.kty === 'hastra-labs-pub' || b.kty === 'hastra-labs-sec')) throw new Error('Not a Hastra Labs key file.');
  if (want === 'sec' && b.kty !== 'hastra-labs-sec') throw new Error('That is a public key. Decrypting and signing need the secret key file.');
  return b;
}

// ── Encrypt ─────────────────────────────────────────────────────────────────
// opts: { alg, passphrase?, recipient? (public bundle), name, type }
export async function encryptPayload(data, opts) {
  const { alg } = opts;
  if (!ALGORITHMS[alg]) throw new Error('Pick an algorithm.');
  if (data.length > MAX_FILE_BYTES) throw new Error('Files up to 256 MB are supported in the browser.');
  const header = { v: 1, alg, name: opts.name || null, type: opts.type || null, size: data.length, created: new Date().toISOString() };
  let cek;

  if (needsPassphrase(alg)) {
    if (!opts.passphrase) throw new Error('Enter a passphrase.');
    const salt = randomBytes(16);
    Object.assign(header, { kdf: 'PBKDF2-SHA256', iter: PBKDF2_ITER, salt: toB64(salt) });
    cek = await pbkdf2Bits(opts.passphrase, salt, PBKDF2_ITER);
  } else {
    const r = opts.recipient;
    if (!r || r.alg !== alg) throw new Error(`Load a ${ALGORITHMS[alg].label} public key for the recipient.`);
    const rpk = fromB64(r.pub);
    header.kid = r.kid;
    if (alg === 'rsa-oaep-4096') {
      cek = randomBytes(32);
      const key = await crypto.subtle.importKey('spki', rpk, { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['encrypt']);
      header.wrap = toB64(new Uint8Array(await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, key, cek)));
    } else {
      let ss;
      if (alg === 'x25519') {
        const { x25519 } = await lib('curves');
        const esk = x25519.utils.randomSecretKey();
        const epk = x25519.getPublicKey(esk);
        ss = x25519.getSharedSecret(esk, rpk);
        header.epk = toB64(epk);
      } else {
        const kem = alg === 'xwing' ? (await lib('hybrid')).ml_kem768_x25519
          : (await lib('ml-kem'))[alg === 'ml-kem-768' ? 'ml_kem768' : 'ml_kem1024'];
        const { cipherText, sharedSecret } = kem.encapsulate(rpk);
        ss = sharedSecret;
        header.kem = toB64(cipherText);
      }
      const hsalt = randomBytes(32);
      header.hkdf = toB64(hsalt);
      cek = await hkdfBytes(ss, hsalt, `hastra-labs/v1/${alg}/${r.kid}`);
      ss.fill?.(0);
    }
  }

  const iv = randomBytes(12);
  header.iv = toB64(iv);
  const aad = frame(header);
  let body;
  if (alg === 'chacha20-poly1305') {
    const { chacha20poly1305 } = await lib('chacha');
    body = chacha20poly1305(cek, iv, aad).encrypt(data);
  } else {
    body = await aesGcm('encrypt', cek, iv, data, aad);
  }
  cek.fill(0);
  return { envelope: concat(aad, body), header };
}

// ── Decrypt ─────────────────────────────────────────────────────────────────
// opts: { passphrase?, secretBundle?, keyPassphrase? }
export async function decryptEnvelope(bytes, opts = {}) {
  const { header, aad, body } = parseEnvelope(bytes);
  const alg = header.alg;
  let cek;
  if (needsPassphrase(alg)) {
    if (!opts.passphrase) throw new Error('This envelope is passphrase-encrypted. Enter the passphrase.');
    cek = await pbkdf2Bits(opts.passphrase, fromB64(header.salt), header.iter || PBKDF2_ITER);
  } else {
    const b = opts.secretBundle;
    if (!b) throw new Error(`Load the ${ALGORITHMS[alg].label} secret key (key id ${header.kid}).`);
    if (b.alg !== alg) throw new Error(`This envelope needs a ${ALGORITHMS[alg].label} key; the loaded key is ${b.alg}.`);
    if (b.kid !== header.kid) throw new Error(`Wrong key: the envelope was sealed for key ${header.kid}, the loaded key is ${b.kid}.`);
    const sk = await openSecretBundle(b, opts.keyPassphrase);
    try {
      if (alg === 'rsa-oaep-4096') {
        const key = await crypto.subtle.importKey('pkcs8', sk, { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['decrypt']);
        try { cek = new Uint8Array(await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, key, fromB64(header.wrap))); }
        catch { throw new Error('The wrapped key could not be opened with this RSA key.'); }
      } else {
        let ss;
        if (alg === 'x25519') {
          const { x25519 } = await lib('curves');
          ss = x25519.getSharedSecret(sk, fromB64(header.epk));
        } else {
          const kem = alg === 'xwing' ? (await lib('hybrid')).ml_kem768_x25519
            : (await lib('ml-kem'))[alg === 'ml-kem-768' ? 'ml_kem768' : 'ml_kem1024'];
          ss = kem.decapsulate(fromB64(header.kem), sk);   // implicit rejection: a bad ct yields a random secret
        }
        cek = await hkdfBytes(ss, fromB64(header.hkdf), `hastra-labs/v1/${alg}/${header.kid}`);
      }
    } finally { sk.fill(0); }
  }
  try {
    let plain;
    if (alg === 'chacha20-poly1305') {
      const { chacha20poly1305 } = await lib('chacha');
      plain = chacha20poly1305(cek, fromB64(header.iv), aad).decrypt(body);
    } else {
      plain = await aesGcm('decrypt', cek, fromB64(header.iv), body, aad);
    }
    return { plain, header };
  } catch {
    throw new Error(needsPassphrase(alg)
      ? 'Decryption failed: wrong passphrase, or the file was modified.'
      : 'Decryption failed: the file was modified, or this is not the key it was sealed for.');
  } finally { cek?.fill(0); }
}

// ── ML-DSA signatures ───────────────────────────────────────────────────────
export async function signMessage(message, secretBundle, keyPassphrase, name) {
  const alg = secretBundle.alg;
  if (!SIGNATURES[alg]) throw new Error('Load an ML-DSA secret key to sign.');
  const m = await lib('ml-dsa');
  const dsa = alg === 'ml-dsa-65' ? m.ml_dsa65 : m.ml_dsa87;
  const sk = await openSecretBundle(secretBundle, keyPassphrase);
  try {
    const sig = dsa.sign(message, sk);
    return { kty: 'hastra-labs-sig', v: 1, alg, kid: secretBundle.kid, name: name || null, size: message.length,
             sha256: await sha256Hex(message), sig: toB64(sig), created: new Date().toISOString() };
  } finally { sk.fill(0); }
}
export async function verifySignature(message, sigDoc, publicBundle) {
  if (sigDoc?.kty !== 'hastra-labs-sig') throw new Error('Not a Hastra Labs signature file.');
  if (publicBundle.alg !== sigDoc.alg) throw new Error(`The signature is ${sigDoc.alg}; the loaded key is ${publicBundle.alg}.`);
  const m = await lib('ml-dsa');
  const dsa = sigDoc.alg === 'ml-dsa-65' ? m.ml_dsa65 : m.ml_dsa87;
  const digest = await sha256Hex(message);
  const ok = dsa.verify(fromB64(sigDoc.sig), message, fromB64(publicBundle.pub));
  return { ok, keyMatches: publicBundle.kid === sigDoc.kid, digestMatches: digest === sigDoc.sha256, digest };
}

// ── Credential hashing ──────────────────────────────────────────────────────
export const HASHERS = {
  user_password: {
    argon2id: { label: 'Argon2id', params: 'm=65536 KiB, t=4, p=2, 32-byte tag', note: 'Memory-hard (RFC 9106). The first choice for human passwords.' },
    bcrypt:   { label: 'bcrypt',   params: 'cost=12', note: 'Battle-tested and CPU-hard. Only the first 72 bytes of a password count.' },
  },
  app_secret: {
    'hmac-sha256':   { label: 'HMAC-SHA256', params: '256-bit server-side key (pepper)', note: 'Fast keyed hash for high-entropy API keys. Keep the key in a secrets manager.' },
    'pbkdf2-sha512': { label: 'PBKDF2-SHA512', params: '100,000 iterations, 16-byte salt, 64-byte output', note: 'FIPS-approved KDF for machine credentials in regulated stacks.' },
    blake3:          { label: 'BLAKE3 (keyed)', params: '256-bit key, 32-byte output', note: 'Very fast keyed hash for token lookups at high request rates.' },
  },
};

const b64nopad = u8 => toB64(u8).replace(/=+$/, '');
const b64any = s => fromB64(s + '='.repeat((4 - s.length % 4) % 4));

export async function hashCredential(secret, classification, engine, keyHex) {
  const t0 = performance.now();
  const pw = enc.encode(secret);
  let out;
  if (engine === 'argon2id') {
    const hw = await lib('hashwasm');
    const salt = randomBytes(16);
    const encoded = await hw.argon2id({ password: pw, salt, parallelism: 2, iterations: 4, memorySize: 65536, hashLength: 32, outputType: 'encoded' });
    const parts = encoded.split('$');
    out = { salt: toHex(b64any(parts[4])), hash: toHex(b64any(parts[5])), stored: encoded };
  } else if (engine === 'bcrypt') {
    const hw = await lib('hashwasm');
    const salt = randomBytes(16);
    const encoded = await hw.bcrypt({ password: pw.length > 72 ? pw.slice(0, 72) : pw, salt, costFactor: 12, outputType: 'encoded' });
    out = { salt: encoded.slice(7, 29) + '  (bcrypt radix-64)', hash: encoded.slice(29), stored: encoded, truncated: pw.length > 72 };
  } else if (engine === 'hmac-sha256') {
    const key = keyHex ? hexKey(keyHex) : randomBytes(32);
    const k = await crypto.subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    const mac = new Uint8Array(await crypto.subtle.sign('HMAC', k, pw));
    const kid = toHex((await sha256(key)).slice(0, 4));
    out = { key: toHex(key), hash: toHex(mac), stored: `hmac-sha256$kid=${kid}$${toHex(mac)}` };
  } else if (engine === 'pbkdf2-sha512') {
    const salt = randomBytes(16);
    const dk = await pbkdf2Bits(pw, salt, 100000, 'SHA-512', 512);
    out = { salt: toHex(salt), hash: toHex(dk), stored: `$pbkdf2-sha512$i=100000$${b64nopad(salt)}$${b64nopad(dk)}` };
  } else if (engine === 'blake3') {
    const { blake3 } = await lib('blake3');
    const key = keyHex ? hexKey(keyHex) : randomBytes(32);
    const h = blake3(pw, { key });
    const kid = toHex((await sha256(key)).slice(0, 4));
    out = { key: toHex(key), hash: toHex(h), stored: `blake3-keyed$kid=${kid}$${toHex(h)}` };
  } else throw new Error('Unknown hashing engine.');
  const meta = HASHERS[classification]?.[engine] || HASHERS.user_password[engine] || HASHERS.app_secret[engine];
  return { engine, classification, label: meta.label, params: meta.params, ms: Math.round(performance.now() - t0), ...out };
}

function hexKey(h) {
  const s = h.trim().replace(/^0x/, '');
  if (!/^[0-9a-fA-F]{64}$/.test(s)) throw new Error('The key must be 32 bytes written as 64 hex characters.');
  const u = new Uint8Array(32);
  for (let i = 0; i < 32; i++) u[i] = parseInt(s.substr(i * 2, 2), 16);
  return u;
}

// Verifies a candidate against a stored hash string (any engine above).
export async function verifyCredential(candidate, stored, keyHex) {
  stored = stored.trim();
  const pw = enc.encode(candidate);
  if (stored.startsWith('$argon2')) return (await lib('hashwasm')).argon2Verify({ password: pw, hash: stored });
  if (/^\$2[aby]\$/.test(stored)) return (await lib('hashwasm')).bcryptVerify({ password: pw.length > 72 ? pw.slice(0, 72) : pw, hash: stored });
  let m = stored.match(/^\$pbkdf2-sha512\$i=(\d+)\$([A-Za-z0-9+/]+)\$([A-Za-z0-9+/]+)$/);
  if (m) {
    const want = b64any(m[3]);
    const got = await pbkdf2Bits(pw, b64any(m[2]), parseInt(m[1], 10), 'SHA-512', want.length * 8);
    return ctEqual(got, want);
  }
  m = stored.match(/^(hmac-sha256|blake3-keyed)\$kid=([0-9a-f]{8})\$([0-9a-f]{64})$/);
  if (m) {
    if (!keyHex) throw new Error('Keyed hashes need the 64-hex-character key they were made with.');
    const key = hexKey(keyHex);
    if (toHex((await sha256(key)).slice(0, 4)) !== m[2]) throw new Error('That key does not match the key id in the stored hash.');
    let got;
    if (m[1] === 'hmac-sha256') {
      const k = await crypto.subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
      got = new Uint8Array(await crypto.subtle.sign('HMAC', k, pw));
    } else {
      got = (await lib('blake3')).blake3(pw, { key });
    }
    return ctEqual(got, Uint8Array.from(m[3].match(/../g), h => parseInt(h, 16)));
  }
  throw new Error('Unrecognised stored hash format.');
}

export function generateMachineSecret(bytes = 32) {
  return 'hst_' + toB64(randomBytes(bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

// ── Strength estimation ─────────────────────────────────────────────────────
// Pattern-aware, in the spirit of zxcvbn but much smaller: the secret is split
// into the cheapest explanation (common password, dictionary word, keyboard or
// alphabet run, repeat, year/date, or random characters) and each piece is
// charged the bits an attacker would need to guess it.
const COMMON = ('123456 password 123456789 12345678 12345 qwerty 1234567 111111 1234567890 123123 abc123 1234 password1 iloveyou 1q2w3e4r ' +
  '000000 qwerty123 zaq12wsx dragon sunshine princess letmein 654321 monkey 27653 1qaz2wsx 123321 qwertyuiop superman asdfghjkl ' +
  'trustno1 welcome admin login master hello freedom whatever qazwsx football baseball shadow michael jennifer charlie ' +
  'jordan hunter2 passw0rd p@ssw0rd p@ssword changeme secret root toor test guest default india pakistan cricket').split(' ');
const WORDS = ('love life time world house money power music water family friend summer winter spring autumn happy lucky magic ' +
  'secret dream angel tiger lion eagle dragon falcon shadow ghost storm thunder cyber hacker admin system server secure access ' +
  'network cloud data login pass word user guest hello welcome sunshine flower rose apple orange banana mango cherry lemon ' +
  'yellow green black white silver golden diamond star moon planet ocean river mountain forest coffee pizza chocolate cookie ' +
  'monkey donkey horse puppy kitty pepper ginger sugar honey baby sweet heart cool super master king queen prince princess ' +
  'soccer football cricket hockey tennis ninja pirate robot rocket space galaxy matrix phoenix legend hunter killer warrior ' +
  'student college school teacher engineer doctor office company hastra astra temple kyoto tokyo india london paris').split(' ');
const KEYROWS = ['qwertyuiop', 'asdfghjkl', 'zxcvbnm', '1234567890', 'abcdefghijklmnopqrstuvwxyz'];
const LEET = { '0': 'o', '1': 'i', '3': 'e', '4': 'a', '5': 's', '7': 't', '@': 'a', '$': 's', '!': 'i' };
const unleet = s => s.toLowerCase().replace(/[013457@$!]/g, c => LEET[c]);

// A letters-only run that reads like a word (vowels spread through it, no
// long consonant clusters) is priced as one pick from a large dictionary,
// even when it is not in the small list above: that is how an attacker's
// wordlists and mangling rules find "Tr0ub4dor".
function wordLike(w) {
  if (w.length < 5 || w.length > 16 || !/^[a-z]+$/.test(w)) return false;
  const v = (w.match(/[aeiouy]/g) || []).length / w.length;
  return v >= 0.25 && v <= 0.7 && !/[^aeiouy]{4}/.test(w) && !/[aeiouy]{3}/.test(w);
}

// Would a human plausibly have typed this piece as a (mangled) word?
function humanWordPiece(piece) {
  const letters = (piece.match(/[a-z]/gi) || []).length;
  const leet = (piece.match(/[013457@$!]/g) || []).length;
  return letters / piece.length >= 0.7 && leet <= 2 && /[aeiouy]/i.test(piece)
    && /^(?:[A-Z]?[a-z013457@$!]+|[A-Z013457@$!]+)$/.test(piece);
}

// Recognise encoded random tokens by their alphabet rather than by the
// character classes they happen to contain.
function encodedAlphabet(s) {
  if (s.length < 16) return 0;
  if (/^[0-9a-f]+$/i.test(s)) return 16;
  if (/^[A-Z2-7]+=*$/.test(s)) return 32;
  if (/^[A-Za-z0-9_-]+$/.test(s) && /[A-Z]/.test(s) && /[a-z]/.test(s) && /\d/.test(s)) return 64;
  if (/^[A-Za-z0-9+/]+=*$/.test(s) && /[A-Z]/.test(s) && /[a-z]/.test(s) && /\d/.test(s)) return 64;
  return 0;
}

function poolSize(s) {
  const encoded = encodedAlphabet(s);
  if (encoded) return encoded;
  let p = 0;
  if (/[a-z]/.test(s)) p += 26;
  if (/[A-Z]/.test(s)) p += 26;
  if (/[0-9]/.test(s)) p += 10;
  if (/[^a-zA-Z0-9\s]/.test(s) && /[\x21-\x7e]/.test(s.replace(/[a-zA-Z0-9]/g, ''))) p += 33;
  if (/\s/.test(s)) p += 1;
  if (/[^\x00-\x7f]/.test(s)) p += 100;
  return Math.max(p, 1);
}

export function estimateStrength(secret) {
  let s = secret || '';
  if (!s) return { bits: 0, naive: 0, findings: [], rating: 'empty', score: 0 };
  // A fixed type prefix ("hst_", "sk_live_", "ghp_") adds no entropy.
  const prefix = s.match(/^(?:[a-z]{2,6}_){1,2}(?=[A-Za-z0-9_-]{16,}$)/);
  if (prefix) s = s.slice(prefix[0].length);
  const pool = poolSize(s);
  const encoded = encodedAlphabet(s) > 0;
  const naive = s.length * Math.log2(pool);
  const findings = [];
  const lower = unleet(s);

  const common = COMMON.indexOf(lower);
  if (common >= 0) {
    const bits = Math.log2(common + 2) + (s !== lower ? 1 : 0);
    return finish(s, bits, naive, [`“${s}” is one of the most common passwords (rank ${common + 1}).`]);
  }

  // dynamic programming over positions: cheapest cover of the whole string
  const n = s.length;
  const best = new Array(n + 1).fill(Infinity);
  const how = new Array(n + 1).fill(null);
  best[0] = 0;
  const perChar = Math.log2(pool);
  for (let i = 0; i < n; i++) {
    if (best[i] === Infinity) continue;
    const relax = (j, bits, why) => { if (best[i] + bits < best[j]) { best[j] = best[i] + bits; how[j] = { i, why }; } };
    relax(i + 1, perChar, null);
    for (let j = i + 3; j <= n; j++) {
      const piece = s.slice(i, j), pl = lower.slice(i, j);
      if (/^(.)\1+$/.test(piece)) relax(j, perChar + Math.log2(j - i), `repeated “${piece[0]}” ×${j - i}`);
      if (KEYROWS.some(r => r.includes(pl) || r.split('').reverse().join('').includes(pl))) relax(j, Math.log2(26 * 2) + Math.log2(j - i), `sequence “${piece}”`);
      if (j - i === 4 && /^(19|20)\d\d$/.test(piece)) relax(j, Math.log2(130), `year “${piece}”`);
      if (/^\d{6,8}$/.test(piece) && /^(0[1-9]|[12]\d|3[01])(0[1-9]|1[0-2])((19|20)?\d\d)$|^((19|20)\d\d)(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])$/.test(piece)) relax(j, Math.log2(365 * 100), `date “${piece}”`);
      if (j - i >= 4 && !encoded && humanWordPiece(piece)) {
        const w = WORDS.indexOf(pl);
        const leet = [...piece].filter((ch, k) => /[013457@$!]/.test(ch) && /[a-z]/.test(pl[k])).length;
        const caps = piece !== piece.toLowerCase() ? 1 : 0;
        if (w >= 0) relax(j, Math.log2(20000) + caps + leet, `dictionary word “${piece}”`);
        else if (wordLike(pl)) relax(j, Math.log2(50000) + caps + leet, `word-like “${piece}”${leet ? ' (leetspeak doesn’t help much)' : ''}`);
        const c = COMMON.indexOf(pl);
        if (c >= 0) relax(j, Math.log2(c + 2) + 2, `common password “${piece}”`);
      }
    }
  }
  for (let j = n; j > 0; j = how[j]?.i ?? 0) { if (how[j]?.why) findings.unshift(how[j].why); if (!how[j]) break; }
  return finish(s, Math.min(best[n], naive), naive, findings);
}

function finish(s, bits, naive, findings) {
  bits = Math.max(0, bits);
  const rating = bits < 28 ? 'very weak' : bits < 40 ? 'weak' : bits < 60 ? 'fair' : bits < 80 ? 'strong' : bits < 128 ? 'very strong' : 'excellent';
  const score = Math.min(100, Math.round(bits / 128 * 100));
  const guesses = Math.pow(2, Math.max(0, bits - 1)); // average case
  const crack = {
    online:  guesses / 10,        // throttled login form
    fast:    guesses / 1e11,      // unsalted SHA-256 on a GPU rig
    bcrypt:  guesses / 2e4,       // bcrypt cost 12, same rig
    argon2:  guesses / 5e2,       // Argon2id m=64 MiB t=4, same rig
  };
  return { bits, naive, findings, rating, score, crack, length: [...(s || '')].length };
}

export function humanTime(sec) {
  if (!Number.isFinite(sec) || sec > 1e18) return 'longer than the age of the universe';
  if (sec < 1) return 'instantly';
  const u = [[31557600e2, 'century'], [31557600, 'year'], [2629800, 'month'], [86400, 'day'], [3600, 'hour'], [60, 'minute'], [1, 'second']];
  for (const [n, name] of u) if (sec >= n) {
    const v = Math.round(sec / n);
    if (name === 'century' && v > 1e6) return 'millions of centuries';
    return `${v.toLocaleString()} ${name === 'century' ? (v === 1 ? 'century' : 'centuries') : name + (v === 1 ? '' : 's')}`;
  }
  return 'instantly';
}

// ── Self-test: known-answer vectors plus a round trip through every algorithm ──
export async function selfTest(log) {
  const results = [];
  const check = async (name, fn) => {
    const t0 = performance.now();
    try { const ok = await fn(); results.push({ name, ok: !!ok, ms: Math.round(performance.now() - t0) }); }
    catch (e) { results.push({ name, ok: false, err: e.message, ms: Math.round(performance.now() - t0) }); }
    log?.(results[results.length - 1]);
  };
  const msg = enc.encode('Hastra Labs self-test ✓ ' + Date.now());

  await check('KAT · HMAC-SHA256 (RFC 4231 case 2)', async () => {
    const k = await crypto.subtle.importKey('raw', enc.encode('Jefe'), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    return toHex(new Uint8Array(await crypto.subtle.sign('HMAC', k, enc.encode('what do ya want for nothing?')))) ===
      '5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843';
  });
  await check('KAT · PBKDF2-HMAC-SHA512 (c=1)', async () => toHex(await pbkdf2Bits('password', enc.encode('salt'), 1, 'SHA-512', 512)) ===
      '867f70cf1ade02cff3752599a3a53dc4af34c7a669815ae5d513554e1c8cf252c02d470a285a0501bad999bfe943c08f050235d7d68b1da55e63f73b60a57fce');
  await check('KAT · BLAKE3("abc")', async () => toHex((await lib('blake3')).blake3(enc.encode('abc'))) ===
      '6437b3ac38465133ffb63b75273a8db548c558465d79db03fd359c6cd5bd9d85');
  for (const alg of Object.keys(ALGORITHMS)) {
    await check(`Round trip · ${ALGORITHMS[alg].label}`, async () => {
      let opts = { alg, name: 'selftest.txt' }, dopts = {};
      if (needsPassphrase(alg)) { opts.passphrase = dopts.passphrase = 'correct horse battery staple'; }
      else { const kp = await generateKeyPair(alg); opts.recipient = kp.publicBundle; dopts.secretBundle = kp.secretBundle; }
      const { envelope } = await encryptPayload(msg, opts);
      const { plain } = await decryptEnvelope(envelope, dopts);
      const tampered = envelope.slice(); tampered[tampered.length - 5] ^= 1;
      let rejected = false;
      try { await decryptEnvelope(tampered, dopts); } catch { rejected = true; }
      return ctEqual(plain, msg) && rejected;
    });
  }
  for (const alg of Object.keys(SIGNATURES)) {
    await check(`Sign / verify · ${SIGNATURES[alg].label}`, async () => {
      const kp = await generateKeyPair(alg);
      const sig = await signMessage(msg, kp.secretBundle);
      const good = await verifySignature(msg, sig, kp.publicBundle);
      const bad = await verifySignature(concat(msg, new Uint8Array([1])), sig, kp.publicBundle);
      return good.ok && !bad.ok;
    });
  }
  await check('Hash + verify · Argon2id', async () => { const h = await hashCredential('pässword', 'user_password', 'argon2id'); return await verifyCredential('pässword', h.stored) && !(await verifyCredential('password', h.stored)); });
  await check('Hash + verify · bcrypt', async () => { const h = await hashCredential('pässword', 'user_password', 'bcrypt'); return await verifyCredential('pässword', h.stored) && !(await verifyCredential('password', h.stored)); });
  for (const e of ['hmac-sha256', 'pbkdf2-sha512', 'blake3']) {
    await check(`Hash + verify · ${HASHERS.app_secret[e].label}`, async () => {
      const h = await hashCredential('hst_token', 'app_secret', e);
      return await verifyCredential('hst_token', h.stored, h.key) && !(await verifyCredential('hst_tokem', h.stored, h.key));
    });
  }
  return results;
}
