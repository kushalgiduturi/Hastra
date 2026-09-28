<?php
// Hastra — column encryption migration (Sep 2026). Run after 2026_09_integrity.
//
// Widens users.phone_number and logs.geo to TEXT (requirements.description
// and requirements.expected_features are already TEXT) and encrypts any
// existing plaintext values with astra_db_encrypt() (see core/crypto.php,
// AES-256-GCM under ASTRA_DB_KEY). Safe to run more than once — columns are
// only altered if their type isn't already TEXT, and rows already carrying
// the "adb:v1:" prefix are skipped rather than re-encrypted.
//
// deliveries.credentials_note is a separate, already-encrypted column (see
// 2026_09_integrity.php / core/integrity.php's encrypt_secret()) and is not
// touched here.
//
// Run it either way:
//   • Sysadmin portal → "Database migration" page (portals/sysadmin/migrate.php)
//   • C:\xampp\php\php.exe C:\xampp\htdocs\Hastra\config\migrations\2026_09_crypto.php

$__astra_crypto_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_crypto_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_crypto_column_type($conn, $table, $column) {
    $stmt = mysqli_prepare($conn,
        "SELECT DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    mysqli_stmt_bind_param($stmt, "ss", $table, $column);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? strtolower($row['DATA_TYPE']) : null;
}

function astra_crypto_widen_to_text($conn, callable $out, $table, $column, $null_ok = true) {
    $type = astra_crypto_column_type($conn, $table, $column);
    if ($type === null) { $out("   !! $table.$column does not exist, skipped"); return; }
    if ($type === 'text' || $type === 'mediumtext' || $type === 'longtext') {
        $out("   $table.$column already $type");
        return;
    }
    $null_clause = $null_ok ? 'NULL' : 'NOT NULL';
    mysqli_query($conn, "ALTER TABLE `$table` MODIFY `$column` TEXT $null_clause");
    $out("   widened $table.$column from $type to TEXT");
}

// Encrypts every not-yet-encrypted, non-empty value in $table.$column with
// astra_db_encrypt(), verifying each one round-trips before moving on.
// Both envelope prefixes count as "already encrypted" — a row carrying the
// current hastra:v1: format must not be handed back to astra_db_encrypt() as if
// it were plaintext, or the round-trip self-check below compares a plaintext
// against its own ciphertext and fails.
//
// cli/migrate_encryption.php is the canonical runner now (chunked, resumable,
// and it also rewrites adb:v1: rows forward). This stays only so the sysadmin
// migration page keeps working on a database that predates it.
function astra_crypto_encrypt_column($conn, callable $out, $table, $column, $id_column = 'id') {
    $rows = mysqli_query($conn,
        "SELECT `$id_column` AS row_id, `$column` AS val FROM `$table`
         WHERE `$column` IS NOT NULL AND `$column` <> ''
           AND `$column` NOT LIKE '" . mysqli_real_escape_string($conn, ASTRA_ENC_PREFIX) . "%'
           AND `$column` NOT LIKE '" . mysqli_real_escape_string($conn, ASTRA_DB_ENC_PREFIX) . "%'");
    $upd = mysqli_prepare($conn, "UPDATE `$table` SET `$column` = ? WHERE `$id_column` = ?");
    $n = 0;
    while ($r = mysqli_fetch_assoc($rows)) {
        if (astra_is_encrypted($r['val'])) continue;
        $enc = astra_db_encrypt($r['val']);
        if (astra_db_decrypt($enc) !== $r['val']) {
            throw new RuntimeException("Encryption self-check failed for $table.$column #{$r['row_id']}");
        }
        mysqli_stmt_bind_param($upd, "si", $enc, $r['row_id']);
        mysqli_stmt_execute($upd);
        $n++;
    }
    $out("   $n existing $table.$column value(s) encrypted");
}

function astra_migrate_crypto($conn, callable $out) {
    $out("");
    $out("== column encryption: schema");
    astra_crypto_widen_to_text($conn, $out, 'users', 'phone_number');
    astra_crypto_widen_to_text($conn, $out, 'logs', 'geo');
    astra_crypto_widen_to_text($conn, $out, 'requirements', 'description', false);
    astra_crypto_widen_to_text($conn, $out, 'requirements', 'expected_features');

    $out("");
    $out("== column encryption: existing data");
    if (!function_exists('astra_db_encrypt')) {
        $out("   !! core/crypto.php isn't loaded, so existing rows cannot be encrypted.");
        return;
    }
    try {
        astra_db_key_material(); // create the key file up front so failures show clearly
    } catch (Throwable $e) {
        $out("   !! " . $e->getMessage());
        return;
    }
    astra_crypto_encrypt_column($conn, $out, 'users', 'phone_number');
    astra_crypto_encrypt_column($conn, $out, 'logs', 'geo');
    astra_crypto_encrypt_column($conn, $out, 'requirements', 'description');
    astra_crypto_encrypt_column($conn, $out, 'requirements', 'expected_features');

    $out("");
    $out("== column encryption: deliveries.credentials_note");
    $out("   already covered by the integrity migration (core/integrity.php, config/astra.key), left untouched here");
}

if ($__astra_crypto_cli) {
    require __DIR__ . '/../config.php';
    require __DIR__ . '/../../core/crypto.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    astra_migrate_crypto($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
