<?php
// Query-path indexes and the tables behind rate limiting, telemetry and
// self-service data deletion (Oct 2026).
//
// Run it either way (idempotent, safe to repeat):
//   • Sysadmin portal → "Database migration" page (portals/sysadmin/migrate.php)
//   • php tools/run_migrations.php (also runs on every container start)
//
// Indexes, each matching a query the code runs:
//   logs          (user_id, timestamp)    profile activity: WHERE user_id = ? ORDER BY timestamp DESC
//                 (action, timestamp)     dashboards: WHERE action = 'login_failed' AND timestamp > NOW() - 24h
//                 (timestamp)             30-day security summaries
//   requirements  (user_id, status)       client requirement lists scoped to their company's users
//                 (status, created_at)    admin review queue, newest first
//   projects      (status)                portal lists filtered by stage
//   invoices      (client_id)             a client's invoices
//                 (company_id, status)    billing lists per organization
//                 (project_id, status)    "is this project paid" sub-queries
//   bugs          (project_id, status)    open bugs per project
//   tasks         (assigned_to, status)   "my tasks" lists

$__astra_perf_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_perf_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

const ASTRA_PERFORMANCE_INDEXES = [
    ['logs',         'idx_logs_user_time',          'user_id, `timestamp`'],
    ['logs',         'idx_logs_action_time',        'action, `timestamp`'],
    ['logs',         'idx_logs_time',               '`timestamp`'],
    ['requirements', 'idx_requirements_user_status','user_id, status'],
    ['requirements', 'idx_requirements_status_new', 'status, created_at'],
    ['projects',     'idx_projects_status',         'status'],
    ['invoices',     'idx_invoices_client',         'client_id'],
    ['invoices',     'idx_invoices_company_status', 'company_id, status'],
    ['invoices',     'idx_invoices_project_status', 'project_id, status'],
    ['bugs',         'idx_bugs_project_status',     'project_id, status'],
    ['tasks',        'idx_tasks_assignee_status',   'assigned_to, status'],
];

function astra_index_exists($conn, string $table, string $index): bool {
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $table, $index);
    mysqli_stmt_execute($stmt);
    return (bool)mysqli_fetch_row(mysqli_stmt_get_result($stmt));
}

function astra_migrate_performance($conn, callable $out) {
    $out("");
    $out("== performance: query-path indexes, rate limits, telemetry, data deletion");

    foreach (ASTRA_PERFORMANCE_INDEXES as [$table, $index, $cols]) {
        $plain = array_map(fn($c) => trim($c, " `"), explode(',', $cols));
        $missing = array_filter($plain, fn($c) => !db_column_exists($conn, $table, $c));
        if ($missing) { $out("  skip $table.$index: no column " . implode(', ', $missing)); continue; }
        if (astra_index_exists($conn, $table, $index)) { $out("  ok   $table.$index"); continue; }
        mysqli_query($conn, "ALTER TABLE `$table` ADD INDEX `$index` ($cols)");
        $out("  add  $table.$index ($cols)");
    }

    // The primary sysadmin is whoever holds PRIMARY_SYSADMIN_EMAIL (sign-up
    // proves ownership of that inbox with a one-time code). If that account had
    // to be re-created, for example after lost encryption keys made the old one
    // unreadable, it gets its role back here instead of staying a client.
    if (defined('PRIMARY_SYSADMIN_EMAIL') && PRIMARY_SYSADMIN_EMAIL !== '' && db_column_exists($conn, 'users', 'email_bindex')) {
        $bi = astra_blind_index(PRIMARY_SYSADMIN_EMAIL);
        $up = mysqli_prepare($conn, "UPDATE users SET role = 'sysadmin', client_role = NULL WHERE email_bindex = ? AND role NOT IN ('sysadmin', 'deleted')");
        mysqli_stmt_bind_param($up, "s", $bi);
        mysqli_stmt_execute($up);
        $out("  " . (mysqli_stmt_affected_rows($up) ? 'set  primary sysadmin role restored for ' . PRIMARY_SYSADMIN_EMAIL : 'ok   primary sysadmin role'));
    }

    require_once __DIR__ . '/../../core/data_deletion.php';
    $out("  " . (astra_rate_table_ready($conn) ? 'ok' : 'FAIL') . "   rate_windows");
    $out("  " . (astra_telemetry_ready($conn) ? 'ok' : 'FAIL') . "   analytics_events, app_errors");
    $out("  " . (astra_erasure_table_ready($conn) ? 'ok' : 'FAIL') . "   data_deletion_requests");
}

if ($__astra_perf_cli) {
    define('ASTRA_MIGRATION_INCLUDE', true);
    require __DIR__ . '/../../core/db.php';
    astra_migrate_performance($conn, function ($l) { echo $l, "\n"; });
}
