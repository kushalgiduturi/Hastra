<?php
// Astra — documentation pipeline migration (P16, Sep 2026). Run after 2026_09_integrity.
// Adds: doc_drafts (versioned project documentation), projects.repo_url.

$__astra_doc_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_doc_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_docs($conn, callable $out) {
    $out("");
    $out("== documentation: schema");
    $changed = 0;
    if (!db_column_exists($conn, 'projects', 'repo_url')) {
        mysqli_query($conn, "ALTER TABLE projects ADD COLUMN repo_url VARCHAR(255) NULL AFTER deployment_notes");
        $out("   added projects.repo_url");
        $changed++;
    }
    $exists = mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doc_drafts'"));
    if (!$exists) {
        mysqli_query($conn, "CREATE TABLE doc_drafts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            version INT NOT NULL,
            body_html MEDIUMTEXT NOT NULL,
            source VARCHAR(12) NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_at DATETIME NULL,
            approved_by INT NULL,
            UNIQUE KEY uq_doc_version (project_id, version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out("   created doc_drafts");
        $changed++;
    }
    if (!$changed) $out("   already present");
    $n = (int)mysqli_fetch_row(mysqli_query($conn,
        "SELECT COUNT(*) FROM projects p WHERE p.status = 'deployment_pending'
         AND NOT EXISTS (SELECT 1 FROM doc_drafts d WHERE d.project_id = p.id AND d.approved_at IS NOT NULL)"))[0];
    if ($n) $out("   note: $n project(s) awaiting deployment need approved documentation before they can be approved");
}

if ($__astra_doc_cli) {
    require __DIR__ . '/../config.php';
    require __DIR__ . '/../../core/company.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    astra_migrate_docs($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
