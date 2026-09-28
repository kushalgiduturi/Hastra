<?php
// Hastra — data integrity migration (P15, Sep 2026). Run after 2026_09_access.
// Adds: id_sequences (race-free record codes), bugs.cwe_id, the encryption key
// file (config/astra.key) — and encrypts existing delivery credentials.

$__astra_int_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_int_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_integrity($conn, callable $out) {
    $out("");
    $out("== integrity: record codes");
    $exists = mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'id_sequences'"));
    if (!$exists) {
        mysqli_query($conn, "CREATE TABLE id_sequences (
            prefix VARCHAR(8) NOT NULL,
            yr CHAR(2) NOT NULL,
            last_val INT NOT NULL,
            PRIMARY KEY (prefix, yr)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out("   created id_sequences");
    }
    $yr = date('y');
    foreach (array_keys(CODE_TARGETS) as $prefix) {
        $max  = highest_code_number($conn, $prefix, $yr);
        $stmt = mysqli_prepare($conn,
            "INSERT INTO id_sequences (prefix, yr, last_val) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE last_val = GREATEST(last_val, VALUES(last_val))");
        mysqli_stmt_bind_param($stmt, "ssi", $prefix, $yr, $max);
        mysqli_stmt_execute($stmt);
        $cur = mysqli_fetch_row(mysqli_query($conn, "SELECT last_val FROM id_sequences WHERE prefix = '$prefix' AND yr = '$yr'"))[0];
        $out(sprintf("   %-3s next is %s%s%04d", $prefix, $yr, $prefix, $cur + 1));
    }

    $out("");
    $out("== integrity: security bug CWE IDs");
    if (!db_column_exists($conn, 'bugs', 'cwe_id')) {
        mysqli_query($conn, "ALTER TABLE bugs ADD COLUMN cwe_id VARCHAR(12) NULL AFTER vuln_class");
        $out("   added bugs.cwe_id");
    }
    $filled = 0;
    $fill = mysqli_prepare($conn, "UPDATE bugs SET cwe_id = ? WHERE bug_type = 'security' AND vuln_class = ? AND cwe_id IS NULL");
    foreach (VULN_CLASSES as $value => [, $cwe]) {
        if ($cwe === '') continue;
        mysqli_stmt_bind_param($fill, "ss", $cwe, $value);
        mysqli_stmt_execute($fill);
        $filled += mysqli_stmt_affected_rows($fill);
    }
    $out("   $filled existing security bug(s) given a CWE ID from their class");

    $out("");
    $out("== integrity: encrypted credentials");
    if (!crypto_available()) {
        $out("   !! Neither OpenSSL nor sodium is enabled in PHP, so credentials stay unencrypted.");
        $out("      Enable extension=openssl in C:\\xampp\\php\\php.ini, restart Apache, and run this again.");
        return;
    }
    $had_key = is_file(secret_key_path());
    secret_key(true);
    $out($had_key ? "   key file already present" : "   created key file config/astra.key. Back it up; encrypted credentials can't be read without it");
    $rows = mysqli_query($conn, "SELECT id, credentials_note FROM deliveries WHERE credentials_note IS NOT NULL AND credentials_note <> '' AND credentials_note NOT LIKE 'enc:v1:%'");
    $upd  = mysqli_prepare($conn, "UPDATE deliveries SET credentials_note = ? WHERE id = ?");
    $n = 0;
    while ($r = mysqli_fetch_assoc($rows)) {
        $enc = encrypt_secret($r['credentials_note']);
        if (decrypt_secret($enc) !== $r['credentials_note']) throw new RuntimeException("Encryption self-check failed for delivery #{$r['id']}");
        mysqli_stmt_bind_param($upd, "si", $enc, $r['id']);
        mysqli_stmt_execute($upd);
        $n++;
    }
    $out("   $n credentials note(s) encrypted");
}

if ($__astra_int_cli) {
    require __DIR__ . '/../config.php';
    require __DIR__ . '/../../core/company.php';
    require __DIR__ . '/../../core/integrity.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    astra_migrate_integrity($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
