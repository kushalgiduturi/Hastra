<?php
// Astra — company model migration (Sep 2026)
//
// Run it either way:
//   • Sysadmin portal → "Database migration" page (portals/sysadmin/migrate.php)
//   • C:\xampp\php\php.exe C:\xampp\htdocs\login\config\migrations\2026_09_companies.php [--reindex]
//
// Safe to run more than once. Without reindex it only lists which existing
// users sit outside their company's ID block; with reindex it moves them
// (and every row that points at them). Take a backup first.
//
// What it does
//   1. companies: id primary key, user_id nullable, + email_domain, id_block_start, is_internal
//   2. users:     + company_id
//   3. Creates the internal company (INTERNAL_COMPANY_NAME, @cycops.com, IDs 1000–2999)
//   4. Merges client companies that resolve to the same domain
//   5. Gives every client company a domain and a 1000-wide ID block (3000, 4000, …)
//   6. Links users to companies (staff → internal, clients → their company)
//   7. Drops the old scheduled_deletions table (feature removed)
//   8. reindex: moves users into their company's ID block

$__astra_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

if (!function_exists('mig_one')) {
    function mig_one($conn, $sql) { $r = mysqli_query($conn, $sql); return $r ? mysqli_fetch_assoc($r) : null; }
    function mig_all($conn, $sql) { $r = mysqli_query($conn, $sql); return $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : []; }
    function mig_has_index($conn, $table, $index) {
        return (bool) mig_one($conn, "SELECT 1 AS x FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND INDEX_NAME = '$index' LIMIT 1");
    }
}

// $out receives one line of text at a time.
function astra_migrate_companies($conn, bool $reindex, callable $out) {
    $step    = function ($msg) use ($out) { $out(""); $out("== $msg"); };
    $info    = function ($msg) use ($out) { $out("   $msg"); };
    $STAFF   = ['sysadmin', 'admin', 'employee', 'pending_employee'];

    // ── 1. companies table ────────────────────────────────────────────────────────
    $step("companies table");
    if (!mig_one($conn, "SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'")) {
        mysqli_query($conn, "CREATE TABLE companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            company_name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $info("created companies");
    }

    if (!db_column_exists($conn, 'companies', 'id')) {
        $pk = mig_has_index($conn, 'companies', 'PRIMARY');
        $pk_on_user = (bool) mig_one($conn, "SELECT 1 AS x FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND INDEX_NAME = 'PRIMARY' AND COLUMN_NAME = 'user_id' LIMIT 1");
        mysqli_query($conn, "ALTER TABLE companies " .
                            ($pk_on_user ? "ADD INDEX idx_companies_user (user_id), " : "") .
                            ($pk ? "DROP PRIMARY KEY, " : "") .
                            "ADD COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        $info("added companies.id");
    }

    // A foreign key on user_id needs its own index once user_id is no longer the primary key.
    if (db_column_exists($conn, 'companies', 'user_id') && !mig_one($conn, "SELECT 1 AS x FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'user_id' AND SEQ_IN_INDEX = 1 LIMIT 1")) {
        mysqli_query($conn, "ALTER TABLE companies ADD INDEX idx_companies_user (user_id)");
        $info("indexed companies.user_id");
    }

    $uid_col = mig_one($conn, "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'user_id'");
    if ($uid_col && $uid_col['IS_NULLABLE'] === 'NO') {
        mysqli_query($conn, "ALTER TABLE companies MODIFY user_id {$uid_col['COLUMN_TYPE']} NULL");
        $info("companies.user_id is now nullable");
    }

    foreach ([
        'email_domain'   => "VARCHAR(100) NULL AFTER company_name",
        'id_block_start' => "INT NULL AFTER email_domain",
        'is_internal'    => "TINYINT(1) NOT NULL DEFAULT 0 AFTER id_block_start",
    ] as $col => $def) {
        if (!db_column_exists($conn, 'companies', $col)) {
            mysqli_query($conn, "ALTER TABLE companies ADD COLUMN $col $def");
            $info("added companies.$col");
        }
    }

    // ── 2. users.company_id ───────────────────────────────────────────────────────
    $step("users.company_id");
    if (!db_column_exists($conn, 'users', 'company_id')) {
        mysqli_query($conn, "ALTER TABLE users ADD COLUMN company_id INT NULL AFTER role, ADD INDEX idx_users_company (company_id)");
        $info("added users.company_id");
    } else {
        $info("already present");
    }

    // ── 3. Internal company ───────────────────────────────────────────────────────
    $step("internal company");
    $internal = mig_one($conn, "SELECT * FROM companies WHERE is_internal = 1 ORDER BY id LIMIT 1");
    if (!$internal) {
        $name   = INTERNAL_COMPANY_NAME;
        $domain = normalize_domain(EMPLOYEE_EMAIL_DOMAIN);
        $start  = INTERNAL_ADMIN_RANGE[0];
        $stmt = mysqli_prepare($conn,
            "INSERT INTO companies (user_id, company_name, email_domain, id_block_start, is_internal) VALUES (NULL, ?, ?, ?, 1)");
        mysqli_stmt_bind_param($stmt, "ssi", $name, $domain, $start);
        mysqli_stmt_execute($stmt);
        $internal = mig_one($conn, "SELECT * FROM companies WHERE id = " . mysqli_insert_id($conn));
        $info("created {$internal['company_name']} (@{$internal['email_domain']}, IDs 1000–2999)");
    } else {
        $info("{$internal['company_name']} (@{$internal['email_domain']}) already exists");
    }
    $internal_id = (int)$internal['id'];

    // ── 4. Merge client companies with the same domain ───────────────────────────
    $step("merge duplicate client companies");
    $clients = mig_all($conn, "SELECT * FROM companies WHERE is_internal = 0 ORDER BY id");
    $groups  = [];
    foreach ($clients as $c) {
        $key = $c['email_domain'] ? normalize_domain($c['email_domain']) : company_domain_from_name($c['company_name']);
        $groups[$key][] = $c;
    }
    $owner_to_company = [];   // client user_id → canonical company id
    $merged = 0;
    foreach ($groups as $key => $rows) {
        $keep = $rows[0];
        foreach ($rows as $r) {
            if ($r['user_id']) $owner_to_company[(int)$r['user_id']] = (int)$keep['id'];
            if ($r['id'] === $keep['id']) continue;
            mysqli_query($conn, "UPDATE users SET company_id = {$keep['id']} WHERE company_id = {$r['id']}");
            mysqli_query($conn, "DELETE FROM companies WHERE id = {$r['id']}");
            $info("merged \"{$r['company_name']}\" (#{$r['id']}) into \"{$keep['company_name']}\" (#{$keep['id']})");
            $merged++;
        }
    }
    if (!$merged) $info("nothing to merge");

    // ── 6 (first). Link users to companies ───────────────────────────────────────
    // Done before block assignment so blocks can skip over clients who will move.
    $step("link users to companies");
    $staff_in = "'" . implode("','", $STAFF) . "'";
    mysqli_query($conn, "UPDATE users SET company_id = $internal_id WHERE role IN ($staff_in) AND company_id IS NULL");
    $info(mysqli_affected_rows($conn) . " staff linked to " . INTERNAL_COMPANY_NAME);

    $linked = 0;
    foreach ($owner_to_company as $uid => $cid) {
        $s = mysqli_prepare($conn, "UPDATE users SET company_id = ? WHERE id = ? AND role NOT IN ($staff_in) AND company_id IS NULL");
        mysqli_stmt_bind_param($s, "ii", $cid, $uid);
        mysqli_stmt_execute($s);
        $linked += mysqli_stmt_affected_rows($s);
    }
    $info("$linked client(s) linked to their company");

    // ── 5. Domains and ID blocks for client companies ────────────────────────────
    $step("client company domains and ID blocks");
    $client_user_ids = array_map('intval', array_column(
        mig_all($conn, "SELECT id FROM users WHERE role NOT IN ($staff_in)"), 'id'));
    // Clients are moved into blocks by --reindex, so their current IDs never block a range.
    $ignore = $client_user_ids;

    foreach (mig_all($conn, "SELECT * FROM companies WHERE is_internal = 0 ORDER BY id") as $c) {
        $sets = [];
        if (!$c['email_domain']) {
            $d = unique_company_domain($conn, $c['company_name'], (int)$c['id']);
            $s = mysqli_prepare($conn, "UPDATE companies SET email_domain = ? WHERE id = ?");
            mysqli_stmt_bind_param($s, "si", $d, $c['id']);
            mysqli_stmt_execute($s);
            $sets[] = "@$d";
        }
        if ($c['id_block_start'] === null) {
            $b = next_company_block($conn, $ignore);
            if ($b === null) { $info("!! no free ID block left for {$c['company_name']}"); continue; }
            mysqli_query($conn, "UPDATE companies SET id_block_start = $b WHERE id = {$c['id']}");
            $sets[] = "IDs {$b}–" . ($b + ID_BLOCK_SIZE - 1);
        }
        if ($sets) $info("{$c['company_name']}: " . implode(', ', $sets));
    }

    foreach (['email_domain' => 'uq_companies_domain', 'id_block_start' => 'uq_companies_block'] as $col => $idx) {
        if (!mig_has_index($conn, 'companies', $idx)) {
            mysqli_query($conn, "ALTER TABLE companies ADD UNIQUE KEY $idx ($col)");
            $info("added unique key on companies.$col");
        }
    }

    // ── 7. Remove scheduled deletions ────────────────────────────────────────────
    $step("scheduled deletions");
    if (mig_one($conn, "SELECT 1 AS x FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scheduled_deletions'")) {
        mysqli_query($conn, "DROP TABLE scheduled_deletions");
        $info("dropped scheduled_deletions (deleted accounts remain archived in deleted_users)");
    } else {
        $info("already removed");
    }

    // ── 8. ID blocks for existing users ──────────────────────────────────────────
    $step($reindex ? "moving users into their ID blocks" : "users outside their ID block (dry run — run with reindex to move them)");
    $companies = [];
    // Read companies directly: company_schema_ready() may have cached "not ready" earlier in this request.
    foreach (mig_all($conn, "SELECT * FROM companies") as $c) $companies[(int)$c['id']] = $c;

    $users    = mig_all($conn, "SELECT id, name, email, role, company_id FROM users ORDER BY id");
    $pending  = 0; $moved = 0; $failed = 0;
    $reserved = [];
    foreach ($users as $u) {
        if (in_array($u['role'], ['newuser', 'user'], true) && !$u['company_id']) continue;
        $company = $u['company_id'] ? ($companies[(int)$u['company_id']] ?? null) : null;
        $range   = user_id_range_for($company, $u['role']);
        if (id_in_range($u['id'], $range)) continue;

        $pending++;
        $label = $company ? $company['company_name'] : 'no company';
        $new   = first_free_id($conn, $range, $reserved);
        if ($new === null) { $info("!! {$u['email']}: range {$range[0]}–{$range[1]} is full"); $failed++; continue; }
        $reserved[] = $new;

        if (!$reindex) { $info("#{$u['id']} → #$new  {$u['email']} ({$u['role']}, $label)"); continue; }

        if (move_user_id($conn, $u['id'], $new)) {
            $info("#{$u['id']} → #$new  {$u['email']}");
            $moved++;
        } else {
            $info("!! could not move #{$u['id']} {$u['email']}");
            $failed++;
        }
    }
    if (!$pending) $info("every user is already inside their block");
    elseif (!$reindex) $info("$pending user(s) would move. Anyone moved is signed out and must log in again.");
    else $info("$moved moved, $failed failed. Moved users must log in again.");

    $out("");
    $out("Done.");
}

if ($__astra_cli) {
    require __DIR__ . '/../config.php';
    require __DIR__ . '/../../core/company.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    mysqli_set_charset($conn, 'utf8mb4');
    astra_migrate_companies($conn, in_array('--reindex', $argv, true), function ($line) { echo $line, "\n"; });
}
