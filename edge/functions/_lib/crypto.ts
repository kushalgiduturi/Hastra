// Port of core/crypto.php: AES-256-GCM column envelope + HMAC blind indexes.
// Envelope format is byte-compatible with the PHP `hastra:v1:` prefix so a
// future data migration can carry ciphertext across without re-encrypting.
//   "hastra:v1:" + base64([1B key_version][12B IV][16B tag][ciphertext])

const ENVELOPE_PREFIX = "hastra:v1:";
const ACTIVE_KEY_VERSION = 1;

function b64ToBytes(b64: string): Uint8Array {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

function bytesToB64(bytes: Uint8Array): string {
  let bin = "";
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin);
}

async function importAesKey(rawKey: Uint8Array): Promise<CryptoKey> {
  return crypto.subtle.importKey("raw", rawKey as BufferSource, "AES-GCM", false, ["encrypt", "decrypt"]);
}

async function importHmacKey(rawKey: Uint8Array): Promise<CryptoKey> {
  return crypto.subtle.importKey("raw", rawKey as BufferSource, { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
}

/** Encrypt a plaintext column value. `encKeyB64` is a base64, 32-byte AES-256 key. */
export async function dbEncrypt(plaintext: string, encKeyB64: string): Promise<string> {
  if (plaintext === "" || plaintext === null || plaintext === undefined) return plaintext ?? "";
  const key = await importAesKey(b64ToBytes(encKeyB64));
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ciphertextAndTag = new Uint8Array(
    await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, new TextEncoder().encode(plaintext)),
  );
  const envelope = new Uint8Array(1 + 12 + ciphertextAndTag.length);
  envelope[0] = ACTIVE_KEY_VERSION;
  envelope.set(iv, 1);
  envelope.set(ciphertextAndTag, 13);
  return ENVELOPE_PREFIX + bytesToB64(envelope);
}

/** Decrypt a column value produced by dbEncrypt (or the legacy PHP envelope). */
export async function dbDecrypt(value: string, encKeyB64: string): Promise<string> {
  if (!value || !value.startsWith(ENVELOPE_PREFIX)) return value ?? "";
  const envelope = b64ToBytes(value.slice(ENVELOPE_PREFIX.length));
  const keyVersion = envelope[0];
  if (keyVersion !== ACTIVE_KEY_VERSION) {
    throw new Error(`dbDecrypt: unsupported key version ${keyVersion} (only v${ACTIVE_KEY_VERSION} is wired up in the pilot)`);
  }
  const iv = envelope.slice(1, 13);
  const ciphertextAndTag = envelope.slice(13);
  const key = await importAesKey(b64ToBytes(encKeyB64));
  const plaintext = await crypto.subtle.decrypt({ name: "AES-GCM", iv }, key, ciphertextAndTag);
  return new TextDecoder().decode(plaintext);
}

const ALLOWED_BINDEX_FIELDS = new Set(["email_bindex", "phone_bindex", "domain_bindex", "token_bindex", "google_id_bindex"]);

/**
 * Deterministic HMAC-SHA256 blind index for equality lookups on an encrypted
 * column. Restricted to an allow-list (matches core/crypto.php's
 * astra_blind_index_for()) because low-cardinality columns leak via
 * frequency analysis under a deterministic index.
 */
export async function blindIndex(field: string, value: string, indexKeyB64: string): Promise<string> {
  if (!ALLOWED_BINDEX_FIELDS.has(field)) {
    throw new Error(`blindIndex: "${field}" is not an allow-listed blind-index field`);
  }
  const normalized = value.trim().toLowerCase();
  const key = await importHmacKey(b64ToBytes(indexKeyB64));
  const sig = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(normalized));
  return bytesToB64(new Uint8Array(sig));
}

// Password hashing: PBKDF2-HMAC-SHA256 via native Web Crypto, NOT Argon2id.
//
// The PHP app uses PASSWORD_ARGON2ID. The pilot originally ported that with
// hash-wasm, but Cloudflare Workers disallow WebAssembly.compile() on bytes
// at runtime (a security restriction — only WASM modules statically bundled
// at deploy time are allowed), and hash-wasm only ships Argon2 as a
// base64-embedded blob it compiles on the fly, with no standalone .wasm file
// to import instead. Extracting and re-wiring that binary against
// hash-wasm's internal calling convention is fragile and version-locked —
// out of scope for the pilot. PBKDF2-SHA256 at a high iteration count is
// OWASP's accepted fallback when Argon2id isn't available and needs no WASM.
const PBKDF2_ITERATIONS = 600_000;
const PBKDF2_SCHEME = "pbkdf2-sha256";

async function pbkdf2Bits(password: string, salt: Uint8Array, iterations: number): Promise<Uint8Array> {
  const keyMaterial = await crypto.subtle.importKey("raw", new TextEncoder().encode(password), "PBKDF2", false, ["deriveBits"]);
  const bits = await crypto.subtle.deriveBits(
    { name: "PBKDF2", salt: salt as BufferSource, iterations, hash: "SHA-256" },
    keyMaterial,
    256,
  );
  return new Uint8Array(bits);
}

function timingSafeEqual(a: Uint8Array, b: Uint8Array): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a[i] ^ b[i];
  return diff === 0;
}

export async function hashPassword(password: string): Promise<string> {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const hash = await pbkdf2Bits(password, salt, PBKDF2_ITERATIONS);
  return `${PBKDF2_SCHEME}$${PBKDF2_ITERATIONS}$${bytesToB64(salt)}$${bytesToB64(hash)}`;
}

export async function verifyPassword(password: string, encodedHash: string): Promise<boolean> {
  const parts = encodedHash.split("$");
  if (parts.length !== 4 || parts[0] !== PBKDF2_SCHEME) return false;
  const [, iterationsStr, saltB64, hashB64] = parts;
  const iterations = Number(iterationsStr);
  if (!Number.isFinite(iterations) || iterations <= 0) return false;
  const salt = b64ToBytes(saltB64);
  const computed = await pbkdf2Bits(password, salt, iterations);
  return timingSafeEqual(computed, b64ToBytes(hashB64));
}

/** A short numeric OTP, e.g. for email 2FA. */
export function generateOtp(digits = 6): string {
  const max = 10 ** digits;
  const n = crypto.getRandomValues(new Uint32Array(1))[0] % max;
  return String(n).padStart(digits, "0");
}
