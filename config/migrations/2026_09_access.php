<?php
// Astra — client access rules migration (P14, Sep 2026). Run after 2026_09_onboarding.
// Adds only: deliveries.security_viewed_at / security_viewed_by, security_disclosures table.

$__astra_acc_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_acc_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_access($conn, callable $out) {
    $out("");
    $out("== access rules: schema");
    $added = 0;
    foreach (['security_viewed_at' => "DATETIME NULL", 'security_viewed_by' => "INT NULL"] as $col => $def) {
        if (!db_column_exists($conn, 'deliveries', $col)) {
            mysqli_query($conn, "ALTER TABLE deliveries ADD COLUMN `$col` $def");
            $out("   added deliveries.$col");
            $added++;
        }
    }
    $exists = mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_disclosures'"));
    if (!$exists) {
        mysqli_query($conn, "CREATE TABLE security_disclosures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            delivery_id INT NOT NULL,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_security_token (token_hash),
            INDEX idx_security_delivery (delivery_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out("   created security_disclosures");
        $added++;
    }
    mysqli_query($conn, "DELETE FROM security_disclosures WHERE expires_at < NOW()");
    if (!$added) $out("   already present");
}

if ($__astra_acc_cli) {
    require __DIR__ . '/../config.php';
    require __DIR__ . '/../../core/company.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    astra_migrate_access($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
