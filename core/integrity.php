<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'integrity.php') { http_response_code(404); exit(); }
// core/integrity.php  (P15)
// • next_code(): race-free IDs like 26R0001, 26P0001, 26T0001, 26B0001, 26INV0001
// • Vulnerability classes + CWE IDs for security bugs
// • Encryption at rest for delivery credentials

// ── Record codes ──────────────────────────────────────────────────────────────
const CODE_TARGETS = [
    'R'   => ['requirements', 'requirement_id'],
    'P'   => ['projects',     'project_code'],
    'T'   => ['tasks',        'task_code'],
    'B'   => ['bugs',         'bug_code'],
    'INV' => ['invoices',     'invoice_code'],
];

function sequences_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $r = mysqli_query($conn, "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'id_sequences'");
        $ready = $r && mysqli_num_rows($r) > 0;
    }
    return $ready;
}

// Highest number already used for this prefix and year (e.g. 3 for 26T0003).
function highest_code_number($conn, $prefix, $yr) {
    [$table, $col] = CODE_TARGETS[$prefix];
    $like  = $yr . $prefix . '%';
    $start = strlen($yr . $prefix) + 1;
    $stmt  = mysqli_prepare($conn,
        "SELECT COALESCE(MAX(CAST(SUBSTRING(`$col`, $start) AS UNSIGNED)), 0) FROM `$table` WHERE `$col` LIKE ?");
    mysqli_stmt_bind_param($stmt, "s", $like);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $max);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);
    return (int)$max;
}

// Next code for a prefix. The counter row is locked by the upsert, so two
// requests can never get the same number, and deleted records are never reused.
function next_code($conn, $prefix) {
    if (!isset(CODE_TARGETS[$prefix])) throw new InvalidArgumentException("Unknown code prefix $prefix");
    $yr   = date('y');
    $seen = highest_code_number($conn, $prefix, $yr);

    if (sequences_ready($conn)) {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO id_sequences (prefix, yr, last_val) VALUES (?, ?, LAST_INSERT_ID(? + 1))
             ON DUPLICATE KEY UPDATE last_val = LAST_INSERT_ID(GREATEST(last_val, ?) + 1)");
        mysqli_stmt_bind_param($stmt, "ssii", $prefix, $yr, $seen, $seen);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $n = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT LAST_INSERT_ID()"))[0];
    } else {
        $n = $seen + 1;   // before the migration: still never reuses an existing number
    }
    return $yr . $prefix . str_pad((string)$n, 4, "0", STR_PAD_LEFT);
}

// ── Security bug classification ──────────────────────────────────────────────
// Value stored in bugs.vuln_class => [label, suggested CWE]
const VULN_CLASSES = [
    'SQL Injection'           => ['SQL Injection',                              'CWE-89'],
    'XSS'                     => ['Cross-Site Scripting (XSS)',                 'CWE-79'],
    'CSRF'                    => ['CSRF',                                       'CWE-352'],
    'Auth Bypass'             => ['Authentication Bypass',                      'CWE-287'],
    'Authorization'           => ['Authorization / IDOR / Privilege Escalation', 'CWE-639'],
    'Sensitive Data Exposure' => ['Sensitive Data Exposure',                    'CWE-200'],
    'Insecure Configuration'  => ['Insecure Configuration',                     'CWE-16'],
    'SSRF'                    => ['Server-Side Request Forgery (SSRF)',         'CWE-918'],
    'Insecure Deserialization'=> ['Insecure Deserialization',                   'CWE-502'],
    'Vulnerable Component'    => ['Vulnerable or Outdated Component',           'CWE-1104'],
    'Other'                   => ['Other',                                      ''],
];

// "cwe 89", "CWE-89", "89" → "CWE-89"; anything else → null
function normalize_cwe($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (!preg_match('/^(?:cwe[\s\-_]*)?(\d{1,4})$/i', $raw, $m)) return null;
    return 'CWE-' . (int)$m[1];
}

function cwe_link($cwe) {
    if (!preg_match('/^CWE-(\d{1,4})$/', (string)$cwe, $m)) return '';
    return 'https://cwe.mitre.org/data/definitions/' . $m[1] . '.html';
}

function bugs_have_cwe($conn) {
    static $has = null;
    if ($has === null) $has = db_column_exists($conn, 'bugs', 'cwe_id');
    return $has;
}

// ── Encryption at rest ────────────────────────────────────────────────────────
const SECRET_PREFIX = 'enc:v1:';

function secret_key_path() {
    return defined('ASTRA_KEY_FILE') ? ASTRA_KEY_FILE : __DIR__ . '/../config/astra.key';
}

// Loads the 32-byte key; creates it when $create is true and none exists.
function secret_key($create = false) {
    static $key = null;
    if ($key !== null) return $key;
    $path = secret_key_path();
    if (!is_file($path)) {
        if (!$create) return null;
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($path, base64_encode(random_bytes(32)) . "\n", LOCK_EX);
        @chmod($path, 0600);
    }
    $raw = base64_decode(trim((string)file_get_contents($path)), true);
    if ($raw === false || strlen($raw) !== 32) throw new RuntimeException("The encryption key file is damaged: $path");
    return $key = $raw;
}

function crypto_available() {
    return (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true))
        || function_exists('sodium_crypto_secretbox');
}

function is_encrypted_secret($value) {
    return strncmp((string)$value, SECRET_PREFIX, strlen(SECRET_PREFIX)) === 0;
}

// Empty stays empty. Returns "enc:v1:o:…" (OpenSSL AES-256-GCM) or "enc:v1:s:…" (libsodium).
function encrypt_secret($plain) {
    $plain = (string)$plain;
    if ($plain === '' || is_encrypted_secret($plain)) return $plain;
    $key = secret_key(true);
    if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
        $iv  = random_bytes(12);
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'astra-credentials');
        if ($ct === false) throw new RuntimeException("Encryption failed.");
        return SECRET_PREFIX . 'o:' . base64_encode($iv . $tag . $ct);
    }
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return SECRET_PREFIX . 's:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }
    throw new RuntimeException("Neither the OpenSSL nor the sodium PHP extension is enabled.");
}

// Plain text passes through unchanged (older, unencrypted rows).
function decrypt_secret($stored) {
    $stored = (string)$stored;
    if (!is_encrypted_secret($stored)) return $stored;
    $key  = secret_key(false);
    if (!$key) throw new RuntimeException("The encryption key file is missing: " . secret_key_path());
    $kind = substr($stored, strlen(SECRET_PREFIX), 1);
    $raw  = base64_decode(substr($stored, strlen(SECRET_PREFIX) + 2), true);
    if ($raw === false) throw new RuntimeException("Encrypted value is damaged.");
    if ($kind === 'o') {
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'astra-credentials');
    } elseif ($kind === 's' && function_exists('sodium_crypto_secretbox_open')) {
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    } else {
        $plain = false;
    }
    if ($plain === false) throw new RuntimeException("Couldn't decrypt the value: wrong key or damaged data.");
    return $plain;
}
