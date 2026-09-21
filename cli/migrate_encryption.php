<?php
// Astra — encryption migration runner (CLI only).
//
//   php cli/migrate_encryption.php [--dry-run] [--chunk=500] [--status] [--reset]
//
// Converts every encrypted column forward to the current envelope
// ("astra:v1:", see core/crypto.php) and backfills the four blind indexes.
// It handles all three input states a column can be in — untouched plaintext,
// the superseded "adb:v1:" envelope, and already-current values — so it is
// safe to run against a fresh database, a half-migrated one, or one that is
// already fully converted.
//
// Why it is built the way it is:
//
//   • Chunked keyset cursor, not LIMIT/OFFSET. Rows are walked with
//     "WHERE id > :last ORDER BY id LIMIT :chunk". OFFSET pagination shifts
//     underneath you the moment the rows you already processed stop matching
//     the "needs work" filter, which silently skips records; a cursor on the
//     primary key cannot. It also keeps memory flat regardless of table size,
//     which is the actual reason the old in-migration loop had to go — it
//     buffered the entire result set before writing a single row.
//
//   • One transaction per chunk, and the progress ledger is written inside it.
//     If a chunk throws, the rows and the ledger roll back together, so the
//     recorded position can never run ahead of the data it claims to describe.
//     A re-run resumes from the last committed chunk.
//
//   • Every value is decrypt-verified before it is written. A value that does
//     not survive a round trip aborts the run rather than committing a row
//     nothing can read back.
//
// Schema changes (widening columns, adding index columns) all happen up front,
// before the first transaction — MySQL implicitly commits on DDL, so mixing
// the two phases would silently break the rollback guarantee above.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../core/crypto.php';
require __DIR__ . '/../core/company.php';   // normalize_domain(), db_column_exists()

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
mysqli_set_charset($conn, 'utf8mb4');

$dry_run = in_array('--dry-run', $argv, true);
$reset   = in_array('--reset', $argv, true);
$status  = in_array('--status', $argv, true);
$chunk   = 500;
foreach ($argv as $a) {
    if (preg_match('/^--chunk=(\d+)$/', $a, $m)) $chunk = max(1, min(10000, (int)$m[1]));
}

function say(string $line = ''): void { echo $line, "\n"; }

// ── What gets encrypted, and what may carry a blind index ───────────────────
// A blind index is deterministic, so it leaks equality. On a column with few
// distinct values that is enough to recover the plaintext by frequency alone —
// which is why users.gender is encrypted here but deliberately has no index,
// and why role/status/severity/leave_type are absent entirely. core/crypto.php
// enforces the same list in astra_blind_index_for().
$SPECS = [
    ['table' => 'users',               'column' => 'name'],
    ['table' => 'users',               'column' => 'email',             'bindex' => 'email_bindex'],
    ['table' => 'users',               'column' => 'gender'],
    ['table' => 'users',               'column' => 'phone_number',      'bindex' => 'phone_bindex'],
    ['table' => 'companies',           'column' => 'email_domain',      'bindex' => 'domain_bindex', 'normalize' => 'normalize_domain'],
    ['table' => 'password_set_tokens', 'column' => 'token',             'bindex' => 'token_bindex'],
    ['table' => 'logs',                'column' => 'geo'],
    ['table' => 'requirements',        'column' => 'description'],
    ['table' => 'requirements',        'column' => 'expected_features'],
];

// ── Schema helpers ──────────────────────────────────────────────────────────
function column_data_type($conn, string $table, string $column): ?string {
    $stmt = mysqli_prepare($conn, "SELECT DATA_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    mysqli_stmt_bind_param($stmt, "ss", $table, $column);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? strtolower($row['DATA_TYPE']) : null;
}

function table_exists($conn, string $table): bool {
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    mysqli_stmt_bind_param($stmt, "s", $table);
    mysqli_stmt_execute($stmt);
    return (bool) mysqli_fetch_row(mysqli_stmt_get_result($stmt));
}

function index_exists($conn, string $table, string $index): bool {
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ss", $table, $index);
    mysqli_stmt_execute($stmt);
    return (bool) mysqli_fetch_row(mysqli_stmt_get_result($stmt));
}

// Ciphertext is far longer than the plaintext it replaces, so every encrypted
// column has to be TEXT before a single row is written to it.
function widen_to_text($conn, string $table, string $column, bool $dry): void {
    $type = column_data_type($conn, $table, $column);
    if ($type === null) { say("   !! $table.$column missing — skipped"); return; }
    if (in_array($type, ['text', 'mediumtext', 'longtext'], true)) return;
    if ($dry) { say("   [dry-run] would widen $table.$column ($type -> TEXT)"); return; }

    // NOT NULL columns keep that constraint; a NULL slipping into users.name
    // would break far more than it fixes.
    $nullable = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$column'"))['IS_NULLABLE'] ?? 'YES';
    mysqli_query($conn, "ALTER TABLE `$table` MODIFY `$column` TEXT " . ($nullable === 'NO' ? 'NOT NULL' : 'NULL'));
    say("   widened $table.$column ($type -> TEXT)");
}

function add_bindex_column($conn, string $table, string $column, bool $dry): void {
    if (db_column_exists($conn, $table, $column)) return;
    if ($dry) { say("   [dry-run] would add $table.$column VARCHAR(64)"); return; }
    mysqli_query($conn, "ALTER TABLE `$table` ADD COLUMN `$column` VARCHAR(64) NULL");
    say("   added $table.$column");
}

// ── Phase 1: schema ─────────────────────────────────────────────────────────
// All DDL runs here, before any transaction is opened.
function prepare_schema($conn, array $specs, bool $dry): void {
    say();
    say("== schema");

    // A UNIQUE key on a column about to hold random-IV ciphertext enforces
    // nothing (two rows with the same domain encrypt differently) and MySQL
    // cannot index TEXT without a prefix length anyway. The guarantee moves
    // to the blind index, which is added once the backfill is complete.
    if (index_exists($conn, 'companies', 'uq_companies_domain')) {
        if ($dry) {
            say("   [dry-run] would drop UNIQUE uq_companies_domain (moves to domain_bindex)");
        } else {
            mysqli_query($conn, "ALTER TABLE companies DROP INDEX uq_companies_domain");
            say("   dropped UNIQUE companies.uq_companies_domain — uniqueness moves to domain_bindex");
        }
    }

    foreach ($specs as $s) {
        if (!table_exists($conn, $s['table'])) { say("   !! table {$s['table']} missing — skipped"); continue; }
        widen_to_text($conn, $s['table'], $s['column'], $dry);
        if (isset($s['bindex'])) add_bindex_column($conn, $s['table'], $s['bindex'], $dry);
    }

    if (!table_exists($conn, 'migration_progress')) {
        if ($dry) { say("   [dry-run] would create migration_progress"); return; }
        mysqli_query($conn, "CREATE TABLE migration_progress (
            id INT AUTO_INCREMENT PRIMARY KEY,
            spec_key   VARCHAR(191) NOT NULL,
            last_id    BIGINT  NOT NULL DEFAULT 0,
            rows_done  BIGINT  NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_migration_progress_spec (spec_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        say("   created migration_progress ledger");
    }
}

// ── Ledger ──────────────────────────────────────────────────────────────────
function ledger_get($conn, string $key): array {
    // Absent during a dry run on a database that has never been migrated.
    if (!table_exists($conn, 'migration_progress')) return ['last_id' => 0, 'rows_done' => 0, 'completed_at' => null];
    $stmt = mysqli_prepare($conn, "SELECT last_id, rows_done, completed_at FROM migration_progress WHERE spec_key = ?");
    mysqli_stmt_bind_param($stmt, "s", $key);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ?: ['last_id' => 0, 'rows_done' => 0, 'completed_at' => null];
}

function ledger_save($conn, string $key, int $last_id, int $rows_done, bool $done): void {
    $stmt = mysqli_prepare($conn,
        "INSERT INTO migration_progress (spec_key, last_id, rows_done, completed_at)
         VALUES (?, ?, ?, " . ($done ? "NOW()" : "NULL") . ")
         ON DUPLICATE KEY UPDATE last_id = VALUES(last_id), rows_done = VALUES(rows_done),
                                 completed_at = " . ($done ? "NOW()" : "NULL"));
    mysqli_stmt_bind_param($stmt, "sii", $key, $last_id, $rows_done);
    mysqli_stmt_execute($stmt);
}

// ── Phase 2: data ───────────────────────────────────────────────────────────
function migrate_column($conn, array $spec, int $chunk, bool $dry): array {
    $table  = $spec['table'];
    $column = $spec['column'];
    $bindex = $spec['bindex'] ?? null;
    $key    = "$table.$column";
    $norm   = $spec['normalize'] ?? null;

    if (!table_exists($conn, $table) || !db_column_exists($conn, $table, $column)) {
        return ['converted' => 0, 'skipped' => 0, 'missing' => true];
    }

    $ledger  = ledger_get($conn, $key);
    $last_id = $dry ? 0 : (int)$ledger['last_id'];
    $done    = (int)$ledger['rows_done'];
    $converted = 0;

    // "Needs work" = not yet on the current envelope, or missing its blind
    // index. Filtering in SQL keeps a re-run over an already-converted table
    // cheap, while the cursor guarantees forward progress either way.
    $needs = "(`$column` IS NOT NULL AND `$column` <> '' AND `$column` NOT LIKE 'astra:v1:%')";
    if ($bindex && db_column_exists($conn, $table, $bindex)) {
        $needs = "($needs OR (`$bindex` IS NULL AND `$column` IS NOT NULL AND `$column` <> ''))";
    } else {
        $bindex = null;
    }

    $select = mysqli_prepare($conn,
        "SELECT id, `$column` AS val FROM `$table` WHERE id > ? AND $needs ORDER BY id LIMIT $chunk");
    $update = $bindex
        ? mysqli_prepare($conn, "UPDATE `$table` SET `$column` = ?, `$bindex` = ? WHERE id = ?")
        : mysqli_prepare($conn, "UPDATE `$table` SET `$column` = ? WHERE id = ?");

    while (true) {
        mysqli_stmt_bind_param($select, "i", $last_id);
        mysqli_stmt_execute($select);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);
        if (!$rows) break;

        if ($dry) {
            $converted += count($rows);
            $last_id = (int)end($rows)['id'];
            continue;
        }

        mysqli_begin_transaction($conn);
        try {
            foreach ($rows as $r) {
                // Always recover plaintext first: the stored value may be raw,
                // legacy-enveloped, or current, and the blind index must be
                // computed from the real value in every one of those cases.
                $plain = astra_decrypt($r['val']);
                if ($plain === null) {
                    throw new RuntimeException("$key #{$r['id']}: stored value failed authentication — refusing to rewrite it.");
                }

                $enc = astra_encrypt($plain);
                if (astra_decrypt($enc) !== $plain) {
                    throw new RuntimeException("$key #{$r['id']}: encryption self-check failed.");
                }

                if ($bindex) {
                    $bval = astra_blind_index_for($bindex, $norm ? $norm($plain) : $plain);
                    mysqli_stmt_bind_param($update, "ssi", $enc, $bval, $r['id']);
                } else {
                    mysqli_stmt_bind_param($update, "si", $enc, $r['id']);
                }
                mysqli_stmt_execute($update);
                $converted++;
                $done++;
            }

            $last_id = (int)end($rows)['id'];
            // Written inside the transaction on purpose: ledger and rows
            // commit together or not at all.
            ledger_save($conn, $key, $last_id, $done, false);
            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            throw new RuntimeException("chunk starting after id $last_id failed — rolled back. " . $e->getMessage(), 0, $e);
        }
    }

    // Cursor reset on completion, so a later run rescans for rows added since.
    // The SQL filter makes that rescan skip everything already converted.
    if (!$dry) ledger_save($conn, $key, 0, $done, true);
    return ['converted' => $converted, 'skipped' => 0, 'missing' => false];
}

// ── Phase 3: unique indexes on the blind indexes ────────────────────────────
// Added only after the backfill, because a UNIQUE key cannot be created while
// the column is still half NULL — and if real duplicates exist, that is a data
// problem for a human, not something to paper over.
function finalize_indexes($conn, array $specs, bool $dry): void {
    say();
    say("== unique indexes");
    $wanted = [
        'users.email_bindex'               => 'uq_users_email_bindex',
        'companies.domain_bindex'          => 'uq_companies_domain_bindex',
        'password_set_tokens.token_bindex' => 'uq_password_set_tokens_bindex',
    ];
    foreach ($wanted as $path => $index) {
        [$table, $column] = explode('.', $path);
        if (!table_exists($conn, $table) || !db_column_exists($conn, $table, $column)) continue;
        if (index_exists($conn, $table, $index)) { say("   $index already present"); continue; }
        if ($dry) { say("   [dry-run] would add UNIQUE $index"); continue; }

        $dupe = mysqli_fetch_row(mysqli_query($conn,
            "SELECT `$column` FROM `$table` WHERE `$column` IS NOT NULL GROUP BY `$column` HAVING COUNT(*) > 1 LIMIT 1"));
        if ($dupe) { say("   !! $table.$column has duplicates — UNIQUE $index skipped, resolve by hand"); continue; }

        mysqli_query($conn, "ALTER TABLE `$table` ADD UNIQUE INDEX `$index` (`$column`)");
        say("   added UNIQUE $index");
    }
    // phone_bindex is intentionally non-unique: two people legitimately share
    // a desk phone, and nothing looks a user up by phone today.
}

// ── Entry point ─────────────────────────────────────────────────────────────
if ($status) {
    say("Encryption migration status");
    say(str_repeat('-', 58));
    if (!table_exists($conn, 'migration_progress')) { say("ledger not created yet — nothing has run."); exit(0); }
    $res = mysqli_query($conn, "SELECT spec_key, last_id, rows_done, completed_at FROM migration_progress ORDER BY spec_key");
    foreach (mysqli_fetch_all($res, MYSQLI_ASSOC) as $r) {
        say(sprintf("%-38s %7d row(s)  %s", $r['spec_key'], $r['rows_done'],
            $r['completed_at'] ? "complete {$r['completed_at']}" : "IN PROGRESS at id {$r['last_id']}"));
    }
    exit(0);
}

if ($reset) {
    if (!table_exists($conn, 'migration_progress')) { say("No ledger to reset."); exit(0); }
    mysqli_query($conn, "DELETE FROM migration_progress");
    say("Ledger cleared. The next run rescans every column (already-converted rows are skipped).");
    exit(0);
}

say("Astra encryption migration" . ($dry_run ? "  [DRY RUN — no writes]" : ""));
say("chunk size: $chunk rows per transaction");

try {
    prepare_schema($conn, $SPECS, $dry_run);

    say();
    say("== data");
    $total = 0;
    foreach ($SPECS as $spec) {
        $r = migrate_column($conn, $spec, $chunk, $dry_run);
        $label = "{$spec['table']}.{$spec['column']}";
        if ($r['missing'])              say(sprintf("   %-38s skipped (not present)", $label));
        elseif ($r['converted'] === 0)  say(sprintf("   %-38s already current", $label));
        else                            say(sprintf("   %-38s %d row(s) %s", $label, $r['converted'], $dry_run ? "would convert" : "converted"));
        $total += $r['converted'];
    }

    finalize_indexes($conn, $SPECS, $dry_run);

    say();
    say($dry_run
        ? "Dry run complete — $total row(s) would be converted. Re-run without --dry-run to apply."
        : "Done — $total row(s) converted.");
} catch (Throwable $e) {
    say();
    fwrite(STDERR, "!! " . $e->getMessage() . "\n");
    fwrite(STDERR, "   Nothing past the last committed chunk was written. Fix the cause and re-run —\n");
    fwrite(STDERR, "   the ledger resumes from where it stopped (php cli/migrate_encryption.php --status).\n");
    exit(1);
}
