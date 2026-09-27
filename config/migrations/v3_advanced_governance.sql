-- Astra — schema v3: advanced governance modules (Sep 2026).
--
-- Reference document, not a build script — the live schema is produced by
-- config/migrations/2026_09_advanced_governance.php (idempotent, wired into
-- portals/sysadmin/migrate.php and tools/run_migrations.php, same as every
-- other migration in this codebase). This file records the shape it produces.
--
-- Four tables, one per governance module:
--   ephemeral_dossiers    — self-destructing credential/report handover links
--   milestone_signoffs    — dual-key (PM + client) cryptographic approval chain
--   honeytokens           — canary identifiers that trip an intrusion lockdown
--   requirement_versions  — snapshot history behind the scope-drift diff view
--
-- Encryption note: encrypted_payload, encrypted_title and encrypted_description
-- use the same AES-256-GCM envelope as every other encrypted column in this
-- app (astra_db_encrypt()/astra_db_decrypt(), core/crypto.php) — TEXT, not a
-- new format. token_bindex / identifier_bindex are HMAC-SHA256 blind indexes
-- under ASTRA_INDEX_KEY (astra_blind_index()), exactly like email_bindex —
-- high-entropy random tokens, so a deterministic index does not leak anything
-- by frequency analysis the way it would on a low-cardinality column.

CREATE TABLE ephemeral_dossiers (
    id                INT           NOT NULL AUTO_INCREMENT,
    project_id        INT           NOT NULL,
    created_by        INT           NOT NULL,
    token_bindex      VARCHAR(64)   NOT NULL,   -- HMAC of the raw token; the token itself is never stored
    encrypted_payload TEXT          NOT NULL,   -- AES-256-GCM; overwritten with random noise on shred
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE milestone_signoffs (
    id                     INT          NOT NULL AUTO_INCREMENT,
    project_id             INT          NOT NULL,
    milestone_name         VARCHAR(128) NOT NULL,
    pm_user_id             INT          NOT NULL,
    pm_signed_at           DATETIME     NULL,
    pm_signature_hash      VARCHAR(64)  NULL,   -- HMAC-SHA256(project_id|milestone_name|user_id|timestamp|ip, ASTRA_INDEX_KEY)
    pm_ip                  VARCHAR(45)  NULL,   -- stored so the hash above can be re-derived and verified later
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE honeytokens (
    id                INT         NOT NULL AUTO_INCREMENT,
    token_type        ENUM('canary_user','canary_doc','canary_key') NOT NULL,
    identifier_bindex VARCHAR(64) NOT NULL,   -- HMAC of the real decoy value (email / doc token / API key)
    description       VARCHAR(128) NULL,
    trigger_count     INT         NOT NULL DEFAULT 0,
    created_at        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_honeytokens_identifier (identifier_bindex)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE requirement_versions (
    id                     INT       NOT NULL AUTO_INCREMENT,
    requirement_id         INT       NOT NULL,
    version_number         INT       NOT NULL,
    encrypted_title        TEXT      NOT NULL,
    encrypted_description  TEXT      NOT NULL,
    scope_points           INT       NOT NULL DEFAULT 0,   -- signed delta vs. the previous version
    created_by             INT       NOT NULL,
    pm_acknowledged_at     DATETIME  NULL,                  -- additive: gates Kanban incorporation (requirement 5)
    pm_acknowledged_by     INT       NULL,
    created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_requirement_version (requirement_id, version_number),
    CONSTRAINT fk_reqver_requirement FOREIGN KEY (requirement_id) REFERENCES requirements (id) ON DELETE CASCADE,
    CONSTRAINT fk_reqver_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Supporting columns on existing tables ───────────────────────────────────
-- logs: honeytoken breaches need a severity/incident_type distinct from the
-- ordinary action feed, so the sysadmin dashboard can surface them separately.
ALTER TABLE logs
    ADD COLUMN severity      VARCHAR(16) NOT NULL DEFAULT 'info',
    ADD COLUMN incident_type VARCHAR(32) NULL;

-- ip_cache: is_vpn already exists (soft "warn/block at login" signal); a
-- honeytoken trip needs a harder, time-boxed block enforced on every request,
-- not just login.
ALTER TABLE ip_cache
    ADD COLUMN is_blocked   TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN blocked_until DATETIME  NULL,
    ADD COLUMN block_reason VARCHAR(64) NULL;

-- requirements: which version is currently "live" on the Kanban board, and
-- whether a newer, unacknowledged revision exists.
ALTER TABLE requirements
    ADD COLUMN current_version INT NOT NULL DEFAULT 1,
    ADD COLUMN has_pending_revision TINYINT(1) NOT NULL DEFAULT 0;
