<?php
// Astra — run every migration from the CLI in one shot.
//
// Mirrors the sysadmin "Database migration" page (portals/sysadmin/migrate.php)
// but without needing a browser session. All migrations are idempotent and
// safe to re-run.
//
// Usage:
//   C:\xampp\php\php.exe tools\run_migrations.php [--reindex]
//
// --reindex also moves any users sitting outside their company's ID block
// (and every row referencing them). Without it, company reindexing only
// lists what *would* move.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

define('ASTRA_MIGRATION_INCLUDE', true);
require __DIR__ . '/../core/db.php'; // connects $conn and pulls in every helper (db_column_exists, etc.)
require __DIR__ . '/../config/migrations/2026_09_companies.php';
require __DIR__ . '/../config/migrations/2026_09_onboarding.php';
require __DIR__ . '/../config/migrations/2026_09_access.php';
require __DIR__ . '/../config/migrations/2026_09_integrity.php';
require __DIR__ . '/../config/migrations/2026_09_docs.php';
require __DIR__ . '/../config/migrations/2026_09_tours.php';
require __DIR__ . '/../config/migrations/2026_09_crypto.php';
require __DIR__ . '/../config/migrations/2026_09_attendance.php';
require __DIR__ . '/../config/migrations/2026_09_company_logo.php';
require __DIR__ . '/../config/migrations/2026_09_account_type.php';
require __DIR__ . '/../config/migrations/2026_09_user_pii.php';

$reindex = in_array('--reindex', $argv, true);

$out = function ($line) { echo $line, "\n"; };

try {
    astra_migrate_companies($conn, $reindex, $out);
    astra_migrate_onboarding($conn, $out);
    astra_migrate_access($conn, $out);
    astra_migrate_integrity($conn, $out);
    astra_migrate_docs($conn, $out);
    astra_migrate_tours($conn, $out);
    astra_migrate_crypto($conn, $out);
    astra_migrate_attendance($conn, $out);
    astra_migrate_company_logo($conn, $out);
    astra_migrate_account_type($conn, $out);
    astra_migrate_user_pii($conn, $out);
    echo "\nDone." . ($reindex ? "" : " (companies ran in list-only mode — pass --reindex to move users)") . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n!! " . $e->getMessage() . "\n");
    exit(1);
}
