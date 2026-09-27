<?php
// Hastra — user PII encryption migration (Sep 2026). Run after 2026_09_crypto.
//
// Encrypts users.name, users.email, and users.gender (AES-256-GCM under
// ASTRA_DB_KEY, same as users.phone_number already is — see
// config/migrations/2026_09_crypto.php for the widen/encrypt helpers this
// reuses) and adds users.email_bindex (HMAC-SHA256 under the separate
// ASTRA_INDEX_KEY — see core/crypto.php's astra_blind_index()), a
// deterministic, non-reversible fingerprint of the lowercased/trimmed email
// that auth/login.php and every duplicate-email check now query against
// instead of the (now-encrypted, non-searchable) email column itself.
//
// users.role, .status-like, and timestamp columns are deliberately NOT
// encrypted — they're not sensitive PII, and the app filters/sorts/counts
// on them directly in SQL throughout (WHERE role = ..., ORDER BY, GROUP BY);
// encrypting them would require rewriting that filtering to decrypt-and-scan
// in PHP app-wide for no real security benefit.
//
// Order matters and this is safe to run more than once: email_bindex is
// backfilled from each row's *current* email value (decrypting first if a
// previous partial run already encrypted it) before any new encryption
// happens, so the blind index is always computed from real plaintext, never
// from ciphertext.
//
// Run it either way:
//   • Sysadmin portal → "Database migration" page (portals/sysadmin/migrate.php)
//   • C:\xampp\php\php.exe C:\xampp\htdocs\Hastra\config\migrations\2026_09_user_pii.php

$__astra_pii_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_pii_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_user_pii($conn, callable $out) {
    $out("");
    $out("== user PII encryption: schema");
    astra_crypto_widen_to_text($conn, $out, 'users', 'name', false);
    astra_crypto_widen_to_text($conn, $out, 'users', 'email', false);
    astra_crypto_widen_to_text($conn, $out, 'users', 'gender');

    if (!db_column_exists($conn, 'users', 'email_bindex')) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN email_bindex VARCHAR(64) NULL");
        $out("   added users.email_bindex");
    } else {
        $out("   users.email_bindex already present");
    }

    if (!function_exists('astra_blind_index') || !function_exists('astra_db_decrypt')) {
        $out("   !! core/crypto.php isn't loaded, so nothing can be backfilled or encrypted.");
        return;
    }
    try {
        astra_db_key_material();
        astra_index_key_material(); // create both key files up front so failures show clearly
    } catch (Throwable $e) {
        $out("   !! " . $e->getMessage());
        return;
    }

    $out("");
    $out("== user PII encryption: backfilling email_bindex");
    $rows = mysqli_query($conn, "SELECT id, email FROM users WHERE email_bindex IS NULL AND email IS NOT NULL AND email <> ''");
    $upd  = mysqli_prepare($conn, "UPDATE users SET email_bindex = ? WHERE id = ?");
    $n = 0;
    while ($r = mysqli_fetch_assoc($rows)) {
        $plain = astra_db_decrypt($r['email']); // no-ops if not yet encrypted
        $bindex = astra_blind_index($plain);
        mysqli_stmt_bind_param($upd, "si", $bindex, $r['id']);
        mysqli_stmt_execute($upd);
        $n++;
    }
    $out("   $n row(s) backfilled");

    $has_unique = (bool) mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_email_bindex' LIMIT 1"));
    if (!$has_unique) {
        $dupe = mysqli_fetch_row(mysqli_query($conn,
            "SELECT email_bindex FROM users WHERE email_bindex IS NOT NULL GROUP BY email_bindex HAVING COUNT(*) > 1 LIMIT 1"));
        if ($dupe) {
            $out("   !! duplicate email(s) found. Skipping the UNIQUE index on email_bindex until resolved manually.");
        } else {
            mysqli_query($conn, "ALTER TABLE users ADD UNIQUE INDEX uq_users_email_bindex (email_bindex)");
            $out("   added UNIQUE index on users.email_bindex");
        }
    } else {
        $out("   UNIQUE index on users.email_bindex already present");
    }

    $out("");
    $out("== user PII encryption: existing data");
    astra_crypto_encrypt_column($conn, $out, 'users', 'name');
    astra_crypto_encrypt_column($conn, $out, 'users', 'email');
    astra_crypto_encrypt_column($conn, $out, 'users', 'gender');
}

if ($__astra_pii_cli) {
    require __DIR__ . '/../config.php';
    require __DIR__ . '/../../core/crypto.php';
    require __DIR__ . '/2026_09_crypto.php';
    require __DIR__ . '/../../core/company.php'; // db_column_exists()
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    astra_migrate_user_pii($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
