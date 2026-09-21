-- Astra — schema v2: encrypted columns, blind indexes, relational integrity.
--
-- Reference document, not a build script. The live schema is produced by the
-- migrations under config/migrations/ plus cli/migrate_encryption.php; this
-- file records the shape they converge on and, more importantly, the rules
-- that decide which column gets which treatment.
--
-- Verified against the live database after cli/migrate_encryption.php:
--   23 foreign keys intact · 6 ON DELETE CASCADE rules preserved · 0 orphaned rows
--
-- ===========================================================================
-- RULE 1 — Identity and relationships are never encrypted.
-- ===========================================================================
-- Primary keys and foreign keys stay native INT. They carry no personal data
-- (an integer row id tells an attacker nothing), and encrypting them would
-- destroy the thing the database is for: a JOIN cannot match two ciphertexts
-- that were produced with different random IVs, and ON DELETE CASCADE cannot
-- follow a reference it cannot compare. Every id/user_id/company_id/
-- project_id/requirement_id below is therefore plaintext INT and indexed.
--
-- ===========================================================================
-- RULE 2 — Encrypt payloads; index only high-entropy identifiers.
-- ===========================================================================
-- Encryption is AES-256-GCM with a fresh 12-byte IV per value, so the same
-- plaintext never yields the same ciphertext twice.
--
-- A blind index (HMAC-SHA256 under a separate key) is deterministic, which is
-- what makes an indexed exact-match lookup possible — and also what makes it
-- dangerous. Deterministic output leaks equality, so on a column with few
-- distinct values an attacker simply counts: the hash appearing 60% of the
-- time is "male", the one on 3% of rows is "sysadmin". The column is then
-- effectively public despite being encrypted.
--
-- Blind indexes are therefore restricted to four high-entropy identifiers,
-- where equality is the thing being searched for anyway:
--
--     email_bindex · phone_bindex · domain_bindex · token_bindex
--
-- This list is enforced in code, not just documented here — see
-- ASTRA_BINDEX_FIELDS and astra_blind_index_for() in core/crypto.php, which
-- throws if any other column is passed.
--
-- Low-cardinality columns (gender, role, status, severity, leave_type) get
-- encryption only, never an index. gender is encrypted; role and status stay
-- plaintext because the application filters, groups and counts on them
-- directly in SQL and they are not personal data.
--
-- ===========================================================================
-- RULE 3 — Encrypted columns are TEXT, and their constraints move to the index.
-- ===========================================================================
-- Ciphertext is longer than plaintext, so every encrypted column is TEXT.
-- A UNIQUE key cannot live on such a column: MySQL cannot index TEXT without
-- a prefix length, and random IVs would make the constraint meaningless even
-- if it could. Uniqueness moves to the blind index, which is fixed-width and
-- deterministic. companies.uq_companies_domain was dropped for exactly this
-- reason and replaced by uq_companies_domain_bindex.


-- ── users ──────────────────────────────────────────────────────────────────
CREATE TABLE users (
    id            INT          NOT NULL AUTO_INCREMENT,   -- identity: plaintext
    company_id    INT          NULL,                       -- FK: plaintext
    name          TEXT         NOT NULL,                   -- encrypted
    email         TEXT         NOT NULL,                   -- encrypted
    email_bindex  VARCHAR(64)  NULL,                       -- HMAC, UNIQUE: login lookup
    phone_number  TEXT         NULL,                       -- encrypted
    phone_bindex  VARCHAR(64)  NULL,                       -- HMAC, non-unique (shared desk phones)
    gender        TEXT         NULL,                       -- encrypted, deliberately NO index
    role          VARCHAR(30)  NOT NULL,                   -- plaintext: filtered/grouped in SQL
    password      VARCHAR(255) NOT NULL,                   -- Argon2id hash, not encryption
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email_bindex (email_bindex),
    KEY idx_users_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── companies ──────────────────────────────────────────────────────────────
CREATE TABLE companies (
    id             INT          NOT NULL AUTO_INCREMENT,   -- identity: plaintext
    user_id        INT          NULL,                       -- FK: plaintext
    company_name   VARCHAR(150) NOT NULL,                   -- plaintext: sorted/matched in SQL
    email_domain   TEXT         NULL,                       -- encrypted
    domain_bindex  VARCHAR(64)  NULL,                       -- HMAC, UNIQUE: replaces uq_companies_domain
    id_block_start INT          NULL,
    is_internal    TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_companies_domain_bindex (domain_bindex),
    UNIQUE KEY uq_companies_block (id_block_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── password_set_tokens ────────────────────────────────────────────────────
-- The activation token only ever travels by email. Storing it encrypted, and
-- resolving the inbound ?token= through token_bindex, means a dump of this
-- table is not a set of working account-activation links.
CREATE TABLE password_set_tokens (
    id           INT         NOT NULL AUTO_INCREMENT,
    user_id      INT         NOT NULL,                      -- FK: plaintext
    token        TEXT        NOT NULL,                      -- encrypted
    token_bindex VARCHAR(64) NULL,                          -- HMAC, UNIQUE: activation lookup
    is_used      TINYINT(1)  NOT NULL DEFAULT 0,
    created_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_set_tokens_bindex (token_bindex),
    KEY idx_pst_user (user_id),
    CONSTRAINT fk_pst_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Other encrypted payload columns (no lookup, so no index) ───────────────
--   requirements.description        TEXT  encrypted
--   requirements.expected_features  TEXT  encrypted
--   logs.geo                        TEXT  encrypted
--   deliveries.credentials_note     TEXT  encrypted under core/integrity.php's
--                                         separate AAD-bound scheme ("enc:v1:")

-- ── Relational integrity, unchanged by encryption ──────────────────────────
-- All joins run on plaintext integers, so cascades behave exactly as before:
--
--   tasks.project_id            -> projects.id   ON DELETE CASCADE
--   bugs.project_id             -> projects.id   ON DELETE CASCADE
--   bug_files.bug_id            -> bugs.id       ON DELETE CASCADE
--   task_files.task_id          -> tasks.id      ON DELETE CASCADE
--   project_members.project_id  -> projects.id   ON DELETE CASCADE
--   project_comments.project_id -> projects.id   ON DELETE CASCADE
--   bugs.task_id                -> tasks.id      ON DELETE SET NULL
--   (user references use RESTRICT so a person cannot be deleted out from
--    under their own audit trail)

-- ── migration_progress ─────────────────────────────────────────────────────
-- Resume ledger for cli/migrate_encryption.php. Written inside the same
-- transaction as the rows each chunk converts, so the recorded position can
-- never describe work that was rolled back.
CREATE TABLE migration_progress (
    id           INT         NOT NULL AUTO_INCREMENT,
    spec_key     VARCHAR(191) NOT NULL,                     -- "table.column"
    last_id      BIGINT      NOT NULL DEFAULT 0,            -- keyset cursor
    rows_done    BIGINT      NOT NULL DEFAULT 0,
    completed_at DATETIME    NULL,
    updated_at   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_migration_progress_spec (spec_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
