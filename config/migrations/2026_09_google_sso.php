<?php
// Astra — Google sign-in and recorded consent (Sep 2026)
//
// Run it either way:
//   • Sysadmin portal → "Database migration" page (portals/sysadmin/migrate.php)
//   • C:\xampp\php\php.exe C:\xampp\htdocs\login\config\migrations\2026_09_google_sso.php
//
// users.google_id_bindex  HMAC blind index of Google's stable account id
//                         ("sub"), same scheme as email_bindex, so the raw id
//                         is never stored.
// users.terms_accepted_at / terms_version, and the same on
// pending_registrations: when each account affirmatively accepted the Terms
// and Privacy Policy, and which revision (LEGAL_TERMS_VERSION) it was.

$__astra_gsso_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_gsso_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_google_sso($conn, callable $out) {
    $out("");
    $out("== google sign-in + consent: schema");

    if (!db_column_exists($conn, 'users', 'google_id_bindex')) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN google_id_bindex VARCHAR(64) NULL,
                             ADD UNIQUE KEY uniq_google_id_bindex (google_id_bindex)");
        $out("   added users.google_id_bindex");
    }
    foreach (['users', 'pending_registrations'] as $t) {
        if (!db_column_exists($conn, $t, 'id')) continue;
        if (!db_column_exists($conn, $t, 'terms_accepted_at')) {
            mysqli_query($conn, "ALTER TABLE $t ADD COLUMN terms_accepted_at DATETIME NULL");
            $out("   added $t.terms_accepted_at");
        }
        if (!db_column_exists($conn, $t, 'terms_version')) {
            mysqli_query($conn, "ALTER TABLE $t ADD COLUMN terms_version VARCHAR(16) NULL");
            $out("   added $t.terms_version");
        }
    }
    $out("   done");
}

if ($__astra_gsso_cli) {
    require __DIR__ . '/../config.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!function_exists('db_column_exists')) {
        function db_column_exists($conn, $table, $col) {
            $s = mysqli_prepare($conn, "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            mysqli_stmt_bind_param($s, "ss", $table, $col);
            mysqli_stmt_execute($s);
            return (bool) mysqli_fetch_row(mysqli_stmt_get_result($s));
        }
    }
    astra_migrate_google_sso($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
