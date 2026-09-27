-- Astra v5: security scanner (SAST over uploaded source archives + OWASP ZAP
-- report ingestion).
--
-- Reference DDL. Apply with the PHP runner (idempotent):
--   Sysadmin portal > Migrate, or  C:\xampp\php\php.exe tools\run_migrations.php
-- Runner: config/migrations/2026_09_security_scanner.php
--
-- Additions beyond the original spec, all needed by the implementation:
--   security_scans.source_label   original archive / report filename (display)
--   security_scans.skipped_files  files skipped (binary, minified, vendor, too large)
--   security_scans.archive_path   server-side temp path of an uploaded archive
--                                 until it is scanned (never shown to users;
--                                 deleted after the scan)
--   security_scans.error / completed_at
--   security_findings.fingerprint    dedup key within a company
--   security_findings.instance_count clustered occurrences (ZAP endpoints)
--   security_findings.wasc_id / confidence (ZAP metadata)
-- For ZAP findings, affected_url holds a JSON array of the clustered
-- instances [{method, uri, param, evidence}], and remediation_steps holds a
-- JSON object {steps[], before, after, lang, references[]} for both sources.

CREATE TABLE security_scans (
  id             INT          NOT NULL AUTO_INCREMENT,
  company_id     INT          NOT NULL,
  user_id        INT          NOT NULL,
  scan_type      ENUM('sast_codebase','dast_zap_import') NOT NULL,
  status         ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
  source_label   VARCHAR(255) NULL,
  total_files    INT          NOT NULL DEFAULT 0,
  scanned_files  INT          NOT NULL DEFAULT 0,
  skipped_files  INT          NOT NULL DEFAULT 0,
  critical_count INT          NOT NULL DEFAULT 0,
  high_count     INT          NOT NULL DEFAULT 0,
  medium_count   INT          NOT NULL DEFAULT 0,
  low_count      INT          NOT NULL DEFAULT 0,
  info_count     INT          NOT NULL DEFAULT 0,
  report_summary TEXT         NULL,
  archive_path   VARCHAR(255) NULL,
  error          TEXT         NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at   DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_scans_company (company_id, created_at),
  CONSTRAINT fk_scans_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
  CONSTRAINT fk_scans_user    FOREIGN KEY (user_id)    REFERENCES users (id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE security_findings (
  id                INT          NOT NULL AUTO_INCREMENT,
  scan_id           INT          NOT NULL,
  company_id        INT          NOT NULL,
  source            ENUM('sast_engine','owasp_zap') NOT NULL,
  rule_id           VARCHAR(64)  NOT NULL,
  title             VARCHAR(255) NOT NULL,
  severity          ENUM('critical','high','medium','low','info') NOT NULL,
  cwe_id            VARCHAR(32)  NULL,
  wasc_id           VARCHAR(32)  NULL,
  confidence        VARCHAR(16)  NULL,
  file_path         VARCHAR(255) NULL,
  line_number       INT          NULL,
  affected_url      TEXT         NULL,
  vulnerable_param  VARCHAR(128) NULL,
  instance_count    INT          NOT NULL DEFAULT 1,
  description       TEXT         NOT NULL,
  remediation_steps TEXT         NOT NULL,
  code_snippet      TEXT         NULL,
  fingerprint       CHAR(64)     NOT NULL,
  status            ENUM('open','resolved','false_positive') NOT NULL DEFAULT 'open',
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_findings_company (company_id, severity, status),
  KEY idx_findings_scan (scan_id),
  KEY idx_findings_fp (company_id, fingerprint),
  CONSTRAINT fk_findings_scan FOREIGN KEY (scan_id) REFERENCES security_scans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
