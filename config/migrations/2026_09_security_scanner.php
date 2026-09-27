<?php
// config/migrations/2026_09_security_scanner.php
// Runner for v5_security_scanner.sql. Idempotent.
$__astra_scan_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_scan_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_security_scanner($conn, callable $out) {
    $table_exists = fn(string $t) => (bool) mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'"));
    $out("");
    $out("== security scanner: tables");
    $sql = file_get_contents(__DIR__ . '/v5_security_scanner.sql');
    $sql = preg_replace('~^\s*--.*$~m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if (!preg_match('~CREATE TABLE (\w+)~', $stmt, $m)) continue;
        if ($table_exists($m[1])) { $out("   {$m[1]} already exists"); continue; }
        if (!mysqli_query($conn, $stmt)) throw new RuntimeException("create {$m[1]} failed: " . mysqli_error($conn));
        $out("   created {$m[1]}");
    }
    if (function_exists('astra_security_ready')) astra_security_ready($conn, true);
}
