<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'crypto.php') { http_response_code(404); exit(); }
// Astra — application-level column encryption (AES-256-GCM).
//
// astra_encrypt()/astra_decrypt() are the low-level primitives. Call sites
// that touch the specific columns this covers (users.phone_number,
// requirements.description/expected_features, logs.geo) should use the
// astra_db_encrypt()/astra_db_decrypt() wrappers below instead of calling
// astra_encrypt()/astra_decrypt() directly — they add a version prefix so
// re-running a write or a migration never double-encrypts a value, and they
// pass legacy plaintext / empty values through unchanged instead of throwing,
// so a partially migrated table never breaks a page render.
//
// This is deliberately separate from core/integrity.php's encrypt_secret()/
// decrypt_secret(), which already covers deliveries.credentials_note with
// its own key file and AAD-bound format — that system is left as-is.

define('ASTRA_DB_KEY_FILE', __DIR__ . '/../config/astra_db.key');

// Loads (and lazily creates) the 32-byte master key. Stored base64-encoded
// in a file under config/, which config/.htaccess denies to all web
// requests — i.e. it never leaves the filesystem, let alone the webroot.
function astra_db_key_material() {
    static $key = null;
    if ($key !== null) return $key;
    $path = ASTRA_DB_KEY_FILE;
    if (!is_file($path)) {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($path, base64_encode(random_bytes(32)) . "\n", LOCK_EX);
        @chmod($path, 0600);
    }
    $raw = base64_decode(trim((string)file_get_contents($path)), true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException("The Astra DB encryption key file is damaged: $path");
    }
    return $key = $raw;
}
if (!defined('ASTRA_DB_KEY')) {
    define('ASTRA_DB_KEY', astra_db_key_material());
}

// ── Low-level primitives ─────────────────────────────────────────────────────

// Encrypts $plaintext with AES-256-GCM under a caller-supplied 32-byte $key.
// Returns base64(iv[12] . tag[16] . ciphertext). Empty input stays empty.
function astra_encrypt(string $plaintext, string $key): string {
    if ($plaintext === '') return '';
    if (strlen($key) !== 32) throw new InvalidArgumentException('astra_encrypt() requires a 32-byte key.');

    $iv  = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false) throw new RuntimeException('astra_encrypt(): encryption failed.');

    return base64_encode($iv . $tag . $ciphertext);
}

// Reverses astra_encrypt(). Returns null (rather than throwing) on a bad key,
// truncated payload, or failed authentication tag check, so callers can fail
// closed without a try/catch at every call site.
function astra_decrypt(string $payload, string $key): ?string {
    if ($payload === '') return '';
    if (strlen($key) !== 32) return null;

    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 28) return null;

    $iv         = substr($raw, 0, 12);
    $tag        = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);

    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plaintext === false ? null : $plaintext;
}

// ── Column-level wrappers bound to the master ASTRA_DB_KEY ──────────────────
const ASTRA_DB_ENC_PREFIX = 'adb:v1:';

function astra_db_is_encrypted($value): bool {
    return strncmp((string)$value, ASTRA_DB_ENC_PREFIX, strlen(ASTRA_DB_ENC_PREFIX)) === 0;
}

// Safe to call unconditionally on every write: empty and already-encrypted
// values pass straight through, so it can't double-encrypt.
function astra_db_encrypt(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '' || astra_db_is_encrypted($plaintext)) return $plaintext;
    return ASTRA_DB_ENC_PREFIX . astra_encrypt($plaintext, ASTRA_DB_KEY);
}

// Safe to call unconditionally on every read: values without the prefix
// (legacy plaintext rows not yet migrated) pass through unchanged instead of
// erroring, and a corrupt/undecryptable value falls back to the raw stored
// value rather than blanking it out.
function astra_db_decrypt(?string $stored): ?string {
    if ($stored === null || $stored === '' || !astra_db_is_encrypted($stored)) return $stored;
    $payload = substr($stored, strlen(ASTRA_DB_ENC_PREFIX));
    $plain   = astra_decrypt($payload, ASTRA_DB_KEY);
    return $plain === null ? $stored : $plain;
}

// ── Blind indexing — a second, distinct key, deliberately separate from
//    ASTRA_DB_KEY so a leak of one never compromises the other. Deterministic
//    (same input -> same output), so it can never itself be used to decrypt —
//    only to test "does this value match a known one" via an indexed column,
//    which is exactly what an exact-match lookup (login email, a phone
//    number, a domain) needs and nothing more. ────────────────────────────
define('ASTRA_INDEX_KEY_FILE', __DIR__ . '/../config/astra_index.key');

function astra_index_key_material() {
    static $key = null;
    if ($key !== null) return $key;
    $path = ASTRA_INDEX_KEY_FILE;
    if (!is_file($path)) {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($path, base64_encode(random_bytes(32)) . "\n", LOCK_EX);
        @chmod($path, 0600);
    }
    $raw = base64_decode(trim((string)file_get_contents($path)), true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException("The Astra blind-index key file is damaged: $path");
    }
    return $key = $raw;
}
if (!defined('ASTRA_INDEX_KEY')) {
    define('ASTRA_INDEX_KEY', astra_index_key_material());
}

// Deterministic keyed hash for an exact-match lookup column (e.g.
// users.email_bindex). Lowercased + trimmed first so "Foo@Bar.com " and
// "foo@bar.com" hit the same row — matching how email lookups already
// behaved before encryption. Returns 64 lowercase hex chars.
function astra_blind_index(?string $value): ?string {
    if ($value === null || $value === '') return $value;
    return hash_hmac('sha256', strtolower(trim($value)), ASTRA_INDEX_KEY);
}

// ── users row decryption ─────────────────────────────────────────────────
// Call on every fetched users row (or a row from a query that JOINs users
// and selects its name/email/gender) before the data reaches a template,
// JSON response, or $_SESSION. Mutates in place; no-ops on keys the query
// didn't select, so it's safe to call unconditionally regardless of which
// columns a given SELECT actually asked for.
function astra_decrypt_user_row(?array &$row): void {
    if (!$row) return;
    foreach (['name', 'email', 'gender'] as $col) {
        if (array_key_exists($col, $row)) $row[$col] = astra_db_decrypt($row[$col]);
    }
}

// Same, for an array of rows (mysqli_fetch_all(..., MYSQLI_ASSOC) style).
function astra_decrypt_user_rows(array $rows): array {
    foreach ($rows as &$row) astra_decrypt_user_row($row);
    unset($row);
    return $rows;
}
