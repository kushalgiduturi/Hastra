<?php
// Hastra — advanced governance migration (Sep 2026). Run after 2026_09_user_pii.
//
// Adds the four tables + supporting columns behind schema_v3
// (config/migrations/v3_advanced_governance.sql documents the shape this
// produces and, more importantly, why). Safe to run more than once — every
// step checks before it acts, same as every other migration here.
//
// Run it either way:
//   • Sysadmin portal → "Database migration" page (portals/sysadmin/migrate.php)
//   • C:\xampp\php\php.exe C:\xampp\htdocs\Hastra\config\migrations\2026_09_advanced_governance.php

$__astra_gov_cli = PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__;
if (!$__astra_gov_cli && !defined('ASTRA_MIGRATION_INCLUDE')) { http_response_code(404); exit(); }

function astra_migrate_advanced_governance($conn, callable $out) {
    $table_exists = fn($t) => (bool) mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'"));

    $out("");
    $out("== advanced governance: tables");

    if (!$table_exists('ephemeral_dossiers')) {
        mysqli_query($conn, "CREATE TABLE ephemeral_dossiers (
            id                INT           NOT NULL AUTO_INCREMENT,
            project_id        INT           NOT NULL,
            created_by        INT           NOT NULL,
            token_bindex      VARCHAR(64)   NOT NULL,
            encrypted_payload TEXT          NOT NULL,
            max_views         INT           NOT NULL DEFAULT 1,
            view_count        INT           NOT NULL DEFAULT 0,
            expires_at        DATETIME      NOT NULL,
            is_shredded       TINYINT(1)    NOT NULL DEFAULT 0,
            shredded_at       DATETIME      NULL,
            created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ephemeral_dossiers_token (token_bindex),
            KEY idx_ephemeral_dossiers_project (project_id),
            CONSTRAINT fk_dossier_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_dossier_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out("   created ephemeral_dossiers");
    }

    if (!$table_exists('milestone_signoffs')) {
        mysqli_query($conn, "CREATE TABLE milestone_signoffs (
            id                     INT          NOT NULL AUTO_INCREMENT,
            project_id             INT          NOT NULL,
            milestone_name         VARCHAR(128) NOT NULL,
            pm_user_id             INT          NOT NULL,
            pm_signed_at           DATETIME     NULL,
            pm_signature_hash      VARCHAR(64)  NULL,
            pm_ip                  VARCHAR(45)  NULL,
            client_user_id         INT          NULL,
            client_signed_at       DATETIME     NULL,
            client_signature_hash  VARCHAR(64)  NULL,
            client_ip              VARCHAR(45)  NULL,
            status                 ENUM('pending_client','completed','disputed') NOT NULL DEFAULT 'pending_client',
            created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_milestone_project_name (project_id, milestone_name),
            CONSTRAINT fk_signoff_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            CONSTRAINT fk_signoff_pm FOREIGN KEY (pm_user_id) REFERENCES users (id) ON DELETE RESTRICT,
            CONSTRAINT fk_signoff_client FOREIGN KEY (client_user_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out("   created milestone_signoffs");
    }

    if (!$table_exists('honeytokens')) {
        mysqli_query($conn, "CREATE TABLE honeytokens (
            id                INT         NOT NULL AUTO_INCREMENT,
            token_type        ENUM('canary_user','canary_doc','canary_key') NOT NULL,
            identifier_bindex VARCHAR(64) NOT NULL,
            description       VARCHAR(128) NULL,
            trigger_count     INT         NOT NULL DEFAULT 0,
            created_at        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_honeytokens_identifier (identifier_bindex)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out("   created honeytokens");
    }

    if (!$table_exists('requirement_versions')) {
        mysqli_query($conn, "CREATE TABLE requirement_versions (
            id                     INT       NOT NULL AUTO_INCREMENT,
            requirement_id         INT       NOT NULL,
            version_number         INT       NOT NULL,
            encrypted_title        TEXT      NOT NULL,
            encrypted_description  TEXT      NOT NULL,
            scope_points           INT       NOT NULL DEFAULT 0,
            created_by             INT       NOT NULL,
            pm_acknowledged_at     DATETIME  NULL,
            pm_acknowledged_by     INT       NULL,
            created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_requirement_version (requirement_id, version_number),
            CONSTRAINT fk_reqver_requirement FOREIGN KEY (requirement_id) REFERENCES requirements (id) ON DELETE CASCADE,
            CONSTRAINT fk_reqver_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out("   created requirement_versions");
    }

    $out("");
    $out("== advanced governance: supporting columns");
    if (!db_column_exists($conn, 'logs', 'severity')) {
        mysqli_query($conn, "ALTER TABLE logs ADD COLUMN severity VARCHAR(16) NOT NULL DEFAULT 'info'");
        $out("   added logs.severity");
    }
    if (!db_column_exists($conn, 'logs', 'incident_type')) {
        mysqli_query($conn, "ALTER TABLE logs ADD COLUMN incident_type VARCHAR(32) NULL");
        $out("   added logs.incident_type");
    }
    if (!db_column_exists($conn, 'ip_cache', 'is_blocked')) {
        mysqli_query($conn, "ALTER TABLE ip_cache ADD COLUMN is_blocked TINYINT(1) NOT NULL DEFAULT 0");
        $out("   added ip_cache.is_blocked");
    }
    if (!db_column_exists($conn, 'ip_cache', 'blocked_until')) {
        mysqli_query($conn, "ALTER TABLE ip_cache ADD COLUMN blocked_until DATETIME NULL");
        $out("   added ip_cache.blocked_until");
    }
    if (!db_column_exists($conn, 'ip_cache', 'block_reason')) {
        mysqli_query($conn, "ALTER TABLE ip_cache ADD COLUMN block_reason VARCHAR(64) NULL");
        $out("   added ip_cache.block_reason");
    }
    if (!db_column_exists($conn, 'requirements', 'current_version')) {
        mysqli_query($conn, "ALTER TABLE requirements ADD COLUMN current_version INT NOT NULL DEFAULT 1");
        $out("   added requirements.current_version");
    }
    if (!db_column_exists($conn, 'requirements', 'has_pending_revision')) {
        mysqli_query($conn, "ALTER TABLE requirements ADD COLUMN has_pending_revision TINYINT(1) NOT NULL DEFAULT 0");
        $out("   added requirements.has_pending_revision");
    }

    $out("");
    $out("== advanced governance: seed honeytokens");
    if (function_exists('astra_canary_seed_defaults')) {
        $n = astra_canary_seed_defaults($conn, $out);
        $out("   $n honeytoken(s) present");
    } else {
        $out("   !! core/canary.php not loaded, seeding skipped");
    }

    $out("");
    $out("== advanced governance: baseline requirement_versions");
    // Every requirement that predates this migration gets a version 1 snapshot
    // of its current state, so the diff engine always has something to diff
    // against the moment a client edits it for the first time.
    // requirement_versions.created_by is FK-RESTRICTed to users.id (the same
    // convention as tasks.created_by), but requirements.user_id itself has
    // never been FK-enforced — so a requirement submitted by a since-deleted
    // user is a pre-existing, legitimate state in this app, not something
    // this migration should crash on. Those rows are skipped with a warning
    // instead; they get a baseline the moment anyone (a PM acknowledging, an
    // admin) next touches them, same as a fresh submission would.
    $missing = mysqli_query($conn,
        "SELECT r.id, r.requirement_title, r.description, r.user_id,
                (SELECT 1 FROM users u WHERE u.id = r.user_id) AS submitter_exists
         FROM requirements r
         LEFT JOIN requirement_versions v ON v.requirement_id = r.id AND v.version_number = 1
         WHERE v.id IS NULL");
    $ins = mysqli_prepare($conn,
        "INSERT INTO requirement_versions (requirement_id, version_number, encrypted_title, encrypted_description, scope_points, created_by)
         VALUES (?, 1, ?, ?, 0, ?)");
    $n = 0; $skipped = 0;
    while ($r = mysqli_fetch_assoc($missing)) {
        if (!$r['submitter_exists']) { $skipped++; continue; }
        $title = function_exists('astra_db_encrypt') ? astra_db_encrypt($r['requirement_title']) : $r['requirement_title'];
        $desc  = function_exists('astra_db_decrypt') ? astra_db_encrypt(astra_db_decrypt($r['description'])) : $r['description'];
        mysqli_stmt_bind_param($ins, "issi", $r['id'], $title, $desc, $r['user_id']);
        mysqli_stmt_execute($ins);
        $n++;
    }
    $out("   $n baseline version(s) created");
    if ($skipped) $out("   !! $skipped requirement(s) skipped (submitted by a user that no longer exists)");
}

if ($__astra_gov_cli) {
    require __DIR__ . '/../config.php';
    require __DIR__ . '/../../core/crypto.php';
    require __DIR__ . '/../../core/company.php';   // db_column_exists()
    require __DIR__ . '/../../core/canary.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    mysqli_set_charset($conn, 'utf8mb4');
    astra_migrate_advanced_governance($conn, function ($line) { echo $line, "\n"; });
    echo "\nDone.\n";
}
