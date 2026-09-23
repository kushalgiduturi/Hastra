<?php
// config/migrations/2026_09_enterprise_trust.php
// Runner for v4_enterprise_trust.sql (see that file for the schema and why
// it differs from the original spec). Idempotent: every step checks first.
// Run from Sysadmin > Migrate or tools/run_migrations.php.

$__astra_trust_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_trust_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_enterprise_trust($conn, callable $out) {
    $col = function (string $t, string $c) use ($conn): ?array {
        $r = mysqli_query($conn, "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c'");
        return $r ? (mysqli_fetch_assoc($r) ?: null) : null;
    };
    $has_index = fn(string $t, string $i) => (bool) mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND INDEX_NAME = '$i' LIMIT 1"));
    $has_fk = fn(string $t, string $n) => (bool) mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'
         AND CONSTRAINT_NAME = '$n' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"));
    $table_exists = fn(string $t) => (bool) mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'"));
    $run = function (string $sql, string $label) use ($conn, $out) {
        if (!mysqli_query($conn, $sql)) throw new RuntimeException("$label failed: " . mysqli_error($conn));
        $out("   $label");
    };

    $out("");
    $out("== enterprise trust: audit ledger");
    if (!$col('logs', 'chain_index'))   $run("ALTER TABLE logs ADD COLUMN chain_index BIGINT NULL AFTER id", "added logs.chain_index");
    if (!$col('logs', 'previous_hash')) $run("ALTER TABLE logs ADD COLUMN previous_hash VARCHAR(64) NULL DEFAULT 'GENESIS' AFTER chain_index", "added logs.previous_hash");
    if (!$col('logs', 'current_hash'))  $run("ALTER TABLE logs ADD COLUMN current_hash VARCHAR(64) NULL AFTER previous_hash", "added logs.current_hash");
    if (!$col('logs', 'details'))       $run("ALTER TABLE logs ADD COLUMN details TEXT NULL", "added logs.details");
    if (!$has_index('logs', 'uq_logs_chain_index')) $run("ALTER TABLE logs ADD UNIQUE KEY uq_logs_chain_index (chain_index)", "added unique index on logs.chain_index");

    $sealed = astra_chain_seal_legacy($conn);
    $out($sealed ? "   sealed $sealed existing log row(s) into the chain" : "   no unsealed log rows");
    if (($col('logs', 'current_hash')['IS_NULLABLE'] ?? '') === 'YES') {
        $run("ALTER TABLE logs MODIFY current_hash VARCHAR(64) NOT NULL", "logs.current_hash is now NOT NULL");
    }

    $out("== enterprise trust: milestone escrow");
    if (!$col('milestone_signoffs', 'escrow_amount')) $run("ALTER TABLE milestone_signoffs ADD COLUMN escrow_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00", "added milestone_signoffs.escrow_amount");
    if (!$col('milestone_signoffs', 'escrow_status')) $run("ALTER TABLE milestone_signoffs ADD COLUMN escrow_status ENUM('engineering_review','payment_pending','released','disputed') NOT NULL DEFAULT 'engineering_review'", "added milestone_signoffs.escrow_status");
    if (!$col('milestone_signoffs', 'invoice_id'))    $run("ALTER TABLE milestone_signoffs ADD COLUMN invoice_id INT NULL", "added milestone_signoffs.invoice_id");
    // Sign-offs that were already disputed before escrow existed.
    mysqli_query($conn, "UPDATE milestone_signoffs SET escrow_status = 'disputed' WHERE status = 'disputed' AND escrow_status <> 'disputed'");

    if (!str_contains($col('invoices', 'status')['COLUMN_TYPE'] ?? '', "'waived'")) {
        $run("ALTER TABLE invoices MODIFY status ENUM('generated','sent','paid','waived') NOT NULL DEFAULT 'generated'", "invoices.status now allows 'waived'");
    }
    foreach ([
        'milestone_id'   => "INT NULL",
        'company_id'     => "INT NULL",
        'client_id'      => "INT NULL",
        'currency'       => "VARCHAR(10) NOT NULL DEFAULT 'INR'",
        'paid_reference' => "VARCHAR(128) NULL",
    ] as $c => $def) {
        if (!$col('invoices', $c)) $run("ALTER TABLE invoices ADD COLUMN $c $def", "added invoices.$c");
    }
    if (!$has_index('invoices', 'uq_invoices_milestone')) $run("ALTER TABLE invoices ADD UNIQUE KEY uq_invoices_milestone (milestone_id)", "added unique index on invoices.milestone_id");
    if (!$has_fk('invoices', 'fk_invoice_milestone')) {
        $run("ALTER TABLE invoices ADD CONSTRAINT fk_invoice_milestone FOREIGN KEY (milestone_id) REFERENCES milestone_signoffs (id) ON DELETE SET NULL", "linked invoices.milestone_id to milestone_signoffs");
    }

    if (($col('ephemeral_dossiers', 'expires_at')['IS_NULLABLE'] ?? '') === 'NO') {
        $run("ALTER TABLE ephemeral_dossiers MODIFY expires_at DATETIME NULL", "ephemeral_dossiers.expires_at may now be NULL (dormant until release)");
    }
    if (!$col('ephemeral_dossiers', 'milestone_id')) $run("ALTER TABLE ephemeral_dossiers ADD COLUMN milestone_id INT NULL", "added ephemeral_dossiers.milestone_id");
    if (!$col('ephemeral_dossiers', 'ttl_minutes'))  $run("ALTER TABLE ephemeral_dossiers ADD COLUMN ttl_minutes INT NULL", "added ephemeral_dossiers.ttl_minutes");
    if (!$has_index('ephemeral_dossiers', 'idx_ephemeral_dossiers_milestone')) $run("ALTER TABLE ephemeral_dossiers ADD KEY idx_ephemeral_dossiers_milestone (milestone_id)", "added index on ephemeral_dossiers.milestone_id");
    if (!$has_fk('ephemeral_dossiers', 'fk_dossier_milestone')) {
        $run("ALTER TABLE ephemeral_dossiers ADD CONSTRAINT fk_dossier_milestone FOREIGN KEY (milestone_id) REFERENCES milestone_signoffs (id) ON DELETE SET NULL", "linked ephemeral_dossiers.milestone_id to milestone_signoffs");
    }

    $out("== enterprise trust: session anchors");
    if (!$table_exists('user_sessions')) {
        $run("CREATE TABLE user_sessions (
            id                      INT         NOT NULL AUTO_INCREMENT,
            user_id                 INT         NOT NULL,
            session_token_bindex    VARCHAR(64) NOT NULL,
            device_fingerprint_hash VARCHAR(64) NOT NULL,
            ip_subnet               VARCHAR(40) NOT NULL,
            created_at              TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_active             TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_revoked              TINYINT(1)  NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_sessions_token (session_token_bindex),
            KEY idx_user_sessions_user (user_id),
            CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", "created user_sessions");
    }

    $out("== enterprise trust: payment webhook key");
    $key_path = __DIR__ . '/../astra_payment_webhook.key';
    $existed  = is_file($key_path);
    astra_read_key_file($key_path, true);
    $out($existed ? "   config/astra_payment_webhook.key already present" : "   created config/astra_payment_webhook.key (share it with your payment provider only)");

    // Anything logged later in this same request must see the new schema.
    astra_chain_ready($conn, true);
    astra_escrow_ready($conn, true);
    astra_session_table_ready($conn, true);
}
