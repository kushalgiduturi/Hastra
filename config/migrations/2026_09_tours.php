<?php
// Astra — onboarding tour tracking migration (P18, Sep 2026).
//
// Adds user_page_tours(user_id, page_key, status, completed_at) so a user's
// per-page onboarding tour (completed or skipped) is remembered across
// sessions and devices. The browser also caches this in localStorage so a
// returning visit to the same page doesn't need to ask the server first —
// see assets/js/tour.js.
//
// Run it either way:
//   • Sysadmin portal → "Database migration" page (portals/sysadmin/migrate.php)
//   • C:\xampp\php\php.exe C:\xampp\htdocs\login\config\migrations\2026_09_tours.php

$__astra_tour_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_tour_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

// $out receives one line of text at a time.
function astra_migrate_tours($conn, callable $out) {
    $out("");
    $out("== onboarding tours: schema");

    $exists = mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_page_tours'"));
    if (!$exists) {
        mysqli_query($conn, "CREATE TABLE user_page_tours (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            page_key VARCHAR(64) NOT NULL,
            status ENUM('completed','skipped') NOT NULL DEFAULT 'completed',
            completed_at DATETIME NOT NULL,
            UNIQUE KEY uq_tours_user_page (user_id, page_key),
            INDEX idx_tours_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out("   created user_page_tours");
    } else {
        $out("   already present");
    }
}

if ($__astra_tour_cli) {
    require __DIR__ . '/../config.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    astra_migrate_tours($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
