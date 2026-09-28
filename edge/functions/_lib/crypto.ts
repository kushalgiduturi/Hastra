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

/** Argon2id password hashing (matches PHP's PASSWORD_ARGON2ID defaults closely). */
export async function hashPassword(password: string): Promise<string> {
  const { argon2id } = await import("hash-wasm");
  const salt = crypto.getRandomValues(new Uint8Array(16));
  return argon2id({
    password,
    salt,
    parallelism: 1,
    iterations: 3,
    memorySize: 19456, // ~19 MiB, matches PHP's PASSWORD_ARGON2ID default
    hashLength: 32,
    outputType: "encoded",
  });
}

export async function verifyPassword(password: string, encodedHash: string): Promise<boolean> {
  const { argon2Verify } = await import("hash-wasm");
  return argon2Verify({ password, hash: encodedHash });
}

/** A short numeric OTP, e.g. for email 2FA. */
export function generateOtp(digits = 6): string {
  const max = 10 ** digits;
  const n = crypto.getRandomValues(new Uint32Array(1))[0] % max;
  return String(n).padStart(digits, "0");
}
