<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'crypto.php') { http_response_code(404); exit(); }
// Astra — application-level column encryption (AES-256-GCM, versioned envelope).
//
// Payload format
//   astra:v1:{base64( [1-byte key_version][12-byte IV][16-byte tag][ciphertext] )}
//
// The key_version byte lives *inside* the authenticated envelope, so a value
// always carries the identity of the key that produced it. That is what makes
// key rotation possible without a flag day: re-key one column at a time while
// old and new ciphertexts sit side by side in the same column, each decryptable
// by the key it names.
//
// Three input shapes are accepted on read, and they are distinguishable without
// any out-of-band state:
//   • "astra:v1:…"  current envelope, key chosen by the embedded version byte
//   • "adb:v1:…"    pre-envelope format (raw iv|tag|ct under key v1); still
//                   readable so a half-migrated table renders correctly.
//                   cli/migrate_encryption.php rewrites these forward.
//   • anything else plaintext that hasn't been migrated yet — returned as-is
//
// Blind indexes are deliberately NOT part of this envelope. They use a separate
// key (ASTRA_INDEX_KEY) and are restricted to high-entropy identifiers — see
// ASTRA_BINDEX_FIELDS below for why.
//
// deliveries.credentials_note is covered by core/integrity.php's
// encrypt_secret()/decrypt_secret() (its own key file and AAD-bound format) and
// is intentionally left alone here.

// ── Key ring ────────────────────────────────────────────────────────────────
// Version 1 is config/astra_db.key — the key every existing ciphertext was
// written under. Later versions live alongside it as astra_db.v2.key,
// astra_db.v3.key, … so a rotation is "drop in the next file, bump
// ASTRA_ACTIVE_KEY_VERSION", with old values still readable throughout.
define('ASTRA_DB_KEY_FILE', __DIR__ . '/../config/astra_db.key');
define('ASTRA_KEY_VERSION_MAX', 8);

function astra_key_file_for(int $version): string {
    return $version === 1
        ? ASTRA_DB_KEY_FILE
        : dirname(ASTRA_DB_KEY_FILE) . '/astra_db.v' . $version . '.key';
}

// Reads a 32-byte key file, creating it on first use for version 1 only.
// Rotation keys are never auto-created: a key that appears by accident would
// silently start encrypting data nothing else can read.
function astra_read_key_file(string $path, bool $create = false): ?string {
    if (!is_file($path)) {
        if (!$create) return null;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($path, base64_encode(random_bytes(32)) . "\n", LOCK_EX);
        @chmod($path, 0600);
    }
    $raw = base64_decode(trim((string)file_get_contents($path)), true);
    if ($raw === false || strlen($raw) !== 32) {
        throw new RuntimeException("An Astra key file is damaged (expected 32 base64-encoded bytes): $path");
    }
    return $raw;
}

// version => 32-byte key, for every key file actually present on disk.
function astra_key_ring(): array {
    static $ring = null;
    if ($ring !== null) return $ring;
    $ring = [1 => astra_read_key_file(astra_key_file_for(1), true)];
    for ($v = 2; $v <= ASTRA_KEY_VERSION_MAX; $v++) {
        $key = astra_read_key_file(astra_key_file_for($v));
        if ($key !== null) $ring[$v] = $key;
    }
    return $ring;
}

// Back-compat name used by the migrations; version 1 is the master key.
function astra_db_key_material() {
    return astra_key_ring()[1];
}
if (!defined('ASTRA_DB_KEY')) {
    define('ASTRA_DB_KEY', astra_db_key_material());
}

// The version new ciphertext is written under. Override in config/config.php
// to promote a rotation key once it has been deployed everywhere.
if (!defined('ASTRA_ACTIVE_KEY_VERSION')) {
    define('ASTRA_ACTIVE_KEY_VERSION', 1);
}

// ── Envelope primitives ─────────────────────────────────────────────────────
const ASTRA_ENC_PREFIX    = 'astra:v1:';  // current envelope
const ASTRA_DB_ENC_PREFIX = 'adb:v1:';    // pre-envelope, read-only

function astra_is_encrypted($value): bool {
    $s = (string)$value;
    return strncmp($s, ASTRA_ENC_PREFIX, strlen(ASTRA_ENC_PREFIX)) === 0
        || strncmp($s, ASTRA_DB_ENC_PREFIX, strlen(ASTRA_DB_ENC_PREFIX)) === 0;
}

// True only for the superseded format, i.e. "this row still needs rewriting".
function astra_is_legacy_envelope($value): bool {
    return strncmp((string)$value, ASTRA_DB_ENC_PREFIX, strlen(ASTRA_DB_ENC_PREFIX)) === 0;
}

// Encrypts under the named key version. A fresh 12-byte IV per call means the
// same plaintext never produces the same ciphertext twice — which is precisely
// what defeats frequency analysis on low-cardinality columns like gender or
// status, and also why such columns can never carry a blind index.
function astra_encrypt(string $plaintext, int $key_version = ASTRA_ACTIVE_KEY_VERSION): string {
    if ($plaintext === '') return '';

    $ring = astra_key_ring();
    if (!isset($ring[$key_version])) {
        throw new RuntimeException("astra_encrypt(): no key loaded for version $key_version.");
    }

    $iv  = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $ring[$key_version], OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false) throw new RuntimeException('astra_encrypt(): encryption failed.');

    return ASTRA_ENC_PREFIX . base64_encode(pack('C', $key_version) . $iv . $tag . $ciphertext);
}

// Reverses astra_encrypt(). Plaintext (unmigrated) values pass through
// untouched; a failed authentication tag returns null rather than throwing, so
// callers fail closed without a try/catch at every site.
function astra_decrypt(?string $payload): ?string {
    if ($payload === null || $payload === '') return $payload;

    if (strncmp($payload, ASTRA_ENC_PREFIX, strlen(ASTRA_ENC_PREFIX)) === 0) {
        $raw = base64_decode(substr($payload, strlen(ASTRA_ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) return astra_tamper_alert('truncated envelope');

        $version    = unpack('C', substr($raw, 0, 1))[1];
        $iv         = substr($raw, 1, 12);
        $tag        = substr($raw, 13, 16);
        $ciphertext = substr($raw, 29);

        $ring = astra_key_ring();
        if (!isset($ring[$version])) return astra_tamper_alert("no key loaded for version $version");

        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $ring[$version], OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? astra_tamper_alert('authentication tag mismatch') : $plain;
    }

    if (astra_is_legacy_envelope($payload)) {
        $raw = base64_decode(substr($payload, strlen(ASTRA_DB_ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) return astra_tamper_alert('truncated legacy envelope');

        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', astra_key_ring()[1],
                                 OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? astra_tamper_alert('legacy authentication tag mismatch') : $plain;
    }

    return $payload; // not encrypted yet
}

// A tag mismatch means the stored bytes are not what this key wrote: either
// genuine tampering or the wrong key file deployed. Both need a human, and
// neither should quietly reach a template — so it is logged and nulled.
function astra_tamper_alert(string $reason): ?string {
    error_log("[astra-crypto] decrypt failed: $reason");
    return null;
}

// ── Column wrappers ─────────────────────────────────────────────────────────
// Safe to call unconditionally on every write: empty and already-encrypted
// values pass straight through, so a re-run can never double-encrypt.
function astra_db_encrypt(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '' || astra_is_encrypted($plaintext)) return $plaintext;
    return astra_encrypt($plaintext);
}

// Safe to call unconditionally on every read, in any migration state.
function astra_db_decrypt(?string $stored): ?string {
    return astra_decrypt($stored);
}

function astra_db_is_encrypted($value): bool {
    return astra_is_encrypted($value);
}

// ── Blind indexing ──────────────────────────────────────────────────────────
// A second, distinct key, deliberately separate from the data key ring so a
// leak of one never compromises the other. Deterministic by design — same
// input, same output — which is what makes an indexed exact-match lookup
// possible without storing anything reversible.
//
// That determinism is also the whole danger: a blind index leaks equality.
// On a column with few distinct values (gender, role, status, severity,
// leave_type) the ciphertext histogram alone reveals which hash means "female"
// or "admin", so a blind index there is equivalent to publishing the column.
// Only high-entropy identifiers, where equality is the thing being searched
// for anyway, may carry one:
define('ASTRA_BINDEX_FIELDS', ['email_bindex', 'phone_bindex', 'domain_bindex', 'token_bindex']);

define('ASTRA_INDEX_KEY_FILE', __DIR__ . '/../config/astra_index.key');

function astra_index_key_material() {
    static $key = null;
    if ($key !== null) return $key;
    return $key = astra_read_key_file(ASTRA_INDEX_KEY_FILE, true);
}
if (!defined('ASTRA_INDEX_KEY')) {
    define('ASTRA_INDEX_KEY', astra_index_key_material());
}

// Lowercased + trimmed first so "Foo@Bar.com " and "foo@bar.com" hit the same
// row — matching how these lookups behaved before encryption. 64 hex chars.
function astra_blind_index(?string $value): ?string {
    if ($value === null || $value === '') return $value;
    return hash_hmac('sha256', strtolower(trim($value)), ASTRA_INDEX_KEY);
}

// Same, but refuses to build an index for a column outside the allow-list.
// Used by cli/migrate_encryption.php so the restriction is enforced where new
// index columns actually get created, not just documented in a comment.
function astra_blind_index_for(string $column, ?string $value): ?string {
    if (!in_array($column, ASTRA_BINDEX_FIELDS, true)) {
        throw new InvalidArgumentException(
            "Refusing to build a blind index for '$column': only " . implode(', ', ASTRA_BINDEX_FIELDS) .
            " are high-entropy enough. A deterministic index on a low-cardinality column leaks its value by frequency analysis."
        );
    }
    return astra_blind_index($value);
}

// ── Row helpers ─────────────────────────────────────────────────────────────
// Call on every fetched row (or a row from a query that JOINs the table and
// selects these columns) before the data reaches a template, JSON response or
// $_SESSION. Mutates in place; no-ops on keys the query didn't select, so it's
// safe to call regardless of which columns a given SELECT asked for.
function astra_decrypt_user_row(?array &$row): void {
    if (!$row) return;
    foreach (['name', 'email', 'gender', 'phone_number'] as $col) {
        if (array_key_exists($col, $row)) $row[$col] = astra_db_decrypt($row[$col]);
    }
}

function astra_decrypt_user_rows(array $rows): array {
    foreach ($rows as &$row) astra_decrypt_user_row($row);
    unset($row);
    return $rows;
}

// companies.email_domain is encrypted; company_email_domain() and every
// template that prints "@domain" reads it straight off the row, so the four
// company lookups in core/company.php run this before returning.
function astra_decrypt_company_row(?array &$row): void {
    if (!$row) return;
    if (array_key_exists('email_domain', $row)) {
        $row['email_domain'] = astra_db_decrypt($row['email_domain']);
    }
}

function astra_decrypt_company_rows(array $rows): array {
    foreach ($rows as &$row) astra_decrypt_company_row($row);
    unset($row);
    return $rows;
}
