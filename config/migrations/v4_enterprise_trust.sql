-- Astra v4: enterprise trust (tamper-evident ledger, milestone escrow,
-- session anchors, ephemeral handover).
--
-- Reference DDL. Apply it with the PHP runner, which is idempotent and also
-- seals existing log rows into the hash chain (that step needs the HMAC key,
-- so it can't be done in SQL):
--   Sysadmin portal > Migrate, or
--   C:\xampp\php\php.exe tools\run_migrations.php
-- Runner: config/migrations/2026_09_enterprise_trust.php
--
-- Differences from the original spec, forced by the existing schema:
--   * logs.id is already AUTO_INCREMENT and MySQL allows one per table, so
--     chain_index is a plain unique BIGINT assigned by core/audit_chain.php
--     under a named lock.
--   * invoices already exists (invoice_code, status generated/sent/paid), so
--     it is extended rather than recreated: invoice_code is the invoice
--     number, 'generated'/'sent' mean unpaid, and 'waived' is added.
--   * There is no milestones table; milestone_signoffs is the milestone.
--   * Currency defaults to INR to match the rest of Astra's billing.

-- 1. Tamper-evident audit ledger ------------------------------------------
ALTER TABLE logs
  ADD COLUMN chain_index   BIGINT       NULL AFTER id,
  ADD COLUMN previous_hash VARCHAR(64)  NULL DEFAULT 'GENESIS' AFTER chain_index,
  ADD COLUMN current_hash  VARCHAR(64)  NULL AFTER previous_hash,
  ADD COLUMN details       TEXT         NULL,           -- encrypted event detail
  ADD UNIQUE KEY uq_logs_chain_index (chain_index);
-- (runner seals existing rows here, then:)
ALTER TABLE logs MODIFY current_hash VARCHAR(64) NOT NULL;

-- 2. Milestone escrow -------------------------------------------------------
ALTER TABLE milestone_signoffs
  ADD COLUMN escrow_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN escrow_status ENUM('engineering_review','payment_pending','released','disputed')
             NOT NULL DEFAULT 'engineering_review',
  ADD COLUMN invoice_id    INT NULL;

ALTER TABLE invoices
  MODIFY status ENUM('generated','sent','paid','waived') NOT NULL DEFAULT 'generated',
  ADD COLUMN milestone_id   INT          NULL,
  ADD COLUMN company_id     INT          NULL,
  ADD COLUMN client_id      INT          NULL,
  ADD COLUMN currency       VARCHAR(10)  NOT NULL DEFAULT 'INR',
  ADD COLUMN paid_reference VARCHAR(128) NULL,
  ADD UNIQUE KEY uq_invoices_milestone (milestone_id),
  ADD CONSTRAINT fk_invoice_milestone FOREIGN KEY (milestone_id) REFERENCES milestone_signoffs (id) ON DELETE SET NULL;

-- Dossiers linked to a milestone stay dormant (expires_at NULL) until release.
ALTER TABLE ephemeral_dossiers
  MODIFY expires_at DATETIME NULL,
  ADD COLUMN milestone_id INT NULL,
  ADD COLUMN ttl_minutes  INT NULL,
  ADD KEY idx_ephemeral_dossiers_milestone (milestone_id),
  ADD CONSTRAINT fk_dossier_milestone FOREIGN KEY (milestone_id) REFERENCES milestone_signoffs (id) ON DELETE SET NULL;

-- 3. Session fingerprint anchors -------------------------------------------
CREATE TABLE user_sessions (
  id                      INT         NOT NULL AUTO_INCREMENT,
  user_id                 INT         NOT NULL,
  session_token_bindex    VARCHAR(64) NOT NULL,
  device_fingerprint_hash VARCHAR(64) NOT NULL,
  ip_subnet               VARCHAR(40) NOT NULL,   -- /24 IPv4 or /64 IPv6 (39 chars)
  created_at              TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_active             TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  is_revoked              TINYINT(1)  NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_sessions_token (session_token_bindex),
  KEY idx_user_sessions_user (user_id),
  CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
