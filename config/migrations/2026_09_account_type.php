<?php
// Astra — multi-track registration migration (Sep 2026)
//
// Run it either way:
//   • Sysadmin portal → "Database migration" page (portals/sysadmin/migrate.php)
//   • C:\xampp\php\php.exe C:\xampp\htdocs\login\config\migrations\2026_09_account_type.php
//
// Adds companies.account_type, distinguishing the four self-serve
// registration tracks in auth/register.php:
//   full_org         — Enterprise Workspace, full organization (has its own
//                       staff, leave policy, attendance, biometric webhook)
//   solo_enterprise  — Enterprise Workspace, solo developer/studio (SDLC
//                       pipeline only — see astra_is_solo_company())
//   client_org       — Client Gateway, company client
//   client_individual — Client Gateway, individual/freelancer client
// Existing rows (all pre-dating this column) default to 'full_org' for the
// internal company and 'client_org' for every other company — see the
// backfill below — so nothing already onboarded silently loses features.

$__astra_acct_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_acct_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_account_type($conn, callable $out) {
    $out("");
    $out("== account type: schema");

    if (!db_column_exists($conn, 'companies', 'account_type')) {
        mysqli_query($conn, "ALTER TABLE companies ADD COLUMN account_type
            ENUM('full_org','solo_enterprise','client_org','client_individual') NOT NULL DEFAULT 'full_org'");
        $out("   added companies.account_type");

        mysqli_query($conn, "UPDATE companies SET account_type = 'client_org' WHERE is_internal = 0");
        $out("   backfilled existing non-internal companies as client_org");
    } else {
        $out("   already present");
    }

    if (db_column_exists($conn, 'pending_registrations', 'id')) {
        if (!db_column_exists($conn, 'pending_registrations', 'flow')) {
            mysqli_query($conn, "ALTER TABLE pending_registrations ADD COLUMN flow VARCHAR(24) NULL");
            $out("   added pending_registrations.flow");
        }
        if (!db_column_exists($conn, 'pending_registrations', 'country')) {
            mysqli_query($conn, "ALTER TABLE pending_registrations ADD COLUMN country VARCHAR(60) NULL");
            $out("   added pending_registrations.country");
        }
    }
}

if ($__astra_acct_cli) {
    require __DIR__ . '/../config.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    astra_migrate_account_type($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
