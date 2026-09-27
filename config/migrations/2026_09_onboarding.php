<?php
// Hastra — enterprise onboarding migration (P13, Sep 2026). Run after 2026_09_companies.
//
//   • Sysadmin portal → "Database migration" page
//   • C:\xampp\php\php.exe C:\xampp\htdocs\Hastra\config\migrations\2026_09_onboarding.php
//
// Adds only (never drops):
//   companies:             size_band, contract_ref, it_manager_id, onboarding_status
//   users:                 client_role  (it_manager | pm | teammate)
//   pending_registrations: company_size, contract_ref
//   roster_imports, roster_staging tables
// Backfills existing client companies: owner → IT Manager, other clients → PM.

$__astra_onb_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_onb_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_onboarding($conn, callable $out) {
    $step = function ($msg) use ($out) { $out(""); $out("== $msg"); };
    $info = function ($msg) use ($out) { $out("   $msg"); };
    $table_exists = fn($t) => (bool) mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'"));
    $add = function ($table, $col, $def) use ($conn, $info) {
        if (!db_column_exists($conn, $table, $col)) {
            mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$col` $def");
            $info("added $table.$col");
        }
    };

    $step("onboarding: schema");
    if (!db_column_exists($conn, 'users', 'company_id')) {
        throw new RuntimeException("Run the company migration first (users.company_id is missing).");
    }

    $add('companies', 'size_band',         "VARCHAR(20) NULL");
    $add('companies', 'contract_ref',      "VARCHAR(60) NULL");
    $add('companies', 'it_manager_id',     "INT NULL");
    $first_run = !db_column_exists($conn, 'companies', 'onboarding_status');
    $add('companies', 'onboarding_status', "VARCHAR(20) NOT NULL DEFAULT 'registered'");
    $add('users',     'client_role',       "VARCHAR(20) NULL AFTER company_id");
    if ($table_exists('pending_registrations')) {
        $add('pending_registrations', 'company_size', "VARCHAR(20) NULL");
        $add('pending_registrations', 'contract_ref', "VARCHAR(60) NULL");
    }
    if (!db_column_exists($conn, 'password_set_tokens', 'created_at')) {
        $add('password_set_tokens', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }

    if (!$table_exists('roster_imports')) {
        mysqli_query($conn, "CREATE TABLE roster_imports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            uploaded_by INT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            parser VARCHAR(10) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'parsed',
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            confirmed_at DATETIME NULL,
            INDEX idx_roster_imports_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $info("created roster_imports");
    }
    if (!$table_exists('roster_staging')) {
        mysqli_query($conn, "CREATE TABLE roster_staging (
            id INT AUTO_INCREMENT PRIMARY KEY,
            import_id INT NOT NULL,
            source_row INT NULL,
            full_name VARCHAR(100) NOT NULL DEFAULT '',
            email VARCHAR(100) NOT NULL DEFAULT '',
            phone_number VARCHAR(20) NOT NULL DEFAULT '',
            client_role VARCHAR(20) NOT NULL DEFAULT 'teammate',
            role_raw VARCHAR(60) NULL,
            confidence DECIMAL(3,2) NULL,
            include_row TINYINT(1) NOT NULL DEFAULT 1,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            user_id INT NULL,
            INDEX idx_roster_staging_import (import_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $info("created roster_staging");
    }

    $step("onboarding: existing companies");
    mysqli_query($conn, "UPDATE companies SET onboarding_status = 'active' WHERE is_internal = 1");
    mysqli_query($conn, "UPDATE companies c JOIN users u ON u.id = c.user_id AND u.role = 'client'
                         SET c.it_manager_id = c.user_id
                         WHERE c.is_internal = 0 AND c.it_manager_id IS NULL");
    $info(mysqli_affected_rows($conn) . " company owner(s) recorded as IT Manager");
    // Repair: an IT Manager link that points at a user who no longer exists (for
    // example after an ID move by an older version of this migration) falls back
    // to the company owner if that owner is a client, otherwise it is cleared.
    mysqli_query($conn, "UPDATE companies c
                         LEFT JOIN users m ON m.id = c.it_manager_id
                         LEFT JOIN users o ON o.id = c.user_id AND o.role = 'client'
                         SET c.it_manager_id = o.id
                         WHERE c.it_manager_id IS NOT NULL AND m.id IS NULL");
    if (mysqli_affected_rows($conn) > 0) $info(mysqli_affected_rows($conn) . " stale IT Manager link(s) repaired");
    mysqli_query($conn, "UPDATE users u JOIN companies c ON c.it_manager_id = u.id
                         SET u.client_role = 'it_manager' WHERE u.role = 'client' AND u.client_role IS NULL");
    mysqli_query($conn, "UPDATE users SET client_role = 'pm' WHERE role = 'client' AND client_role IS NULL");
    $info(mysqli_affected_rows($conn) . " other client(s) set to Project Manager");
    // Repair: "schema_migrated" log entries written under a sysadmin ID that has since moved.
    // Uses the email_bindex column when present (users.email is encrypted once
    // 2026_09_user_pii has run); falls back to plain email otherwise, since
    // that migration can run before or after this one.
    if (defined('PRIMARY_SYSADMIN_EMAIL')) {
        $has_bindex = db_column_exists($conn, 'users', 'email_bindex');
        if ($has_bindex && function_exists('astra_blind_index')) {
            $fix = mysqli_prepare($conn, "UPDATE logs l
                LEFT JOIN users u ON u.id = l.user_id
                JOIN users p ON p.email_bindex = ?
                SET l.user_id = p.id
                WHERE l.action = 'schema_migrated' AND u.id IS NULL");
            $email = astra_blind_index(PRIMARY_SYSADMIN_EMAIL);
        } else {
            $fix = mysqli_prepare($conn, "UPDATE logs l
                LEFT JOIN users u ON u.id = l.user_id
                JOIN users p ON p.email = ?
                SET l.user_id = p.id
                WHERE l.action = 'schema_migrated' AND u.id IS NULL");
            $email = PRIMARY_SYSADMIN_EMAIL;
        }
        mysqli_stmt_bind_param($fix, "s", $email);
        mysqli_stmt_execute($fix);
        if (mysqli_stmt_affected_rows($fix) > 0) $info(mysqli_stmt_affected_rows($fix) . " migration log entr(y/ies) repaired");
    }
    if ($first_run) {
        // Companies that existed before onboarding are already in use.
        mysqli_query($conn, "UPDATE companies SET onboarding_status = 'active'");
        $info(mysqli_affected_rows($conn) . " existing compan(y/ies) marked active");
    }
    $info("done");
}

if ($__astra_onb_cli) {
    require __DIR__ . '/../config.php';
    require __DIR__ . '/../../core/company.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    mysqli_set_charset($conn, 'utf8mb4');
    astra_migrate_onboarding($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
