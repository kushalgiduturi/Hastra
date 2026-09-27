<?php
// Hastra — company logo migration (Sep 2026)
//
// Run it either way:
//   • Sysadmin portal → "Database migration" page (portals/sysadmin/migrate.php)
//   • C:\xampp\php\php.exe C:\xampp\htdocs\Hastra\config\migrations\2026_09_company_logo.php
//
// Adds companies.logo_url (either an external logo API URL or a path under
// uploads/company_logos/ for a manually uploaded file — see
// auth/verify_register.php) and pending_registrations.logo_data, which
// holds the registrant's pick during the pending/OTP window (an http(s)
// URL, or a data: URI for an upload, resolved into logo_url once the
// company is actually created).

$__astra_logo_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_logo_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_company_logo($conn, callable $out) {
    $out("");
    $out("== company logo: schema");

    if (!db_column_exists($conn, 'companies', 'logo_url')) {
        mysqli_query($conn, "ALTER TABLE companies ADD COLUMN logo_url VARCHAR(255) NULL");
        $out("   added companies.logo_url");
    } else {
        $out("   already present");
    }

    if (db_column_exists($conn, 'pending_registrations', 'id') && !db_column_exists($conn, 'pending_registrations', 'logo_data')) {
        mysqli_query($conn, "ALTER TABLE pending_registrations ADD COLUMN logo_data MEDIUMTEXT NULL");
        $out("   added pending_registrations.logo_data");
    }
}

if ($__astra_logo_cli) {
    require __DIR__ . '/../config.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    astra_migrate_company_logo($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
