<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'audit_chain.php') { http_response_code(404); exit(); }
// core/audit_chain.php
// Tamper-evident audit ledger. Every row written to `logs` carries:
//   chain_index    1, 2, 3, ... with no gaps
//   previous_hash  the current_hash of row chain_index - 1 ('GENESIS' for row 1)
//   current_hash   HMAC-SHA256 (ASTRA_INDEX_KEY) over this row's canonical form
//
// Editing any sealed column of any row, deleting a row, or re-ordering rows
// breaks the chain at that point, and astra_verify_audit_chain()
// (core/verify_audit.php) reports exactly where.
//
// Canonical form: a JSON array of every stored column in a fixed order, with
// all values as strings (or null). Plain concatenation would be ambiguous:
// user_id "1" + details "23" and user_id "12" + details "3" would hash the
// same, letting someone with DB access shift characters between fields.
//
// Concurrency: appends are serialized with a MySQL named lock rather than
// SELECT ... FOR UPDATE on the latest row. An empty table has no row to lock,
// and a named lock also works whether or not the caller is inside a
// transaction. No caller logs from inside a transaction today; keep it that
// way, because a rolled-back log row would leave a gap in the chain.
//
// Limitation: the chain proves the rows it contains are intact and
// contiguous. Truncating rows off the END is only detectable against an
// externally recorded head (a later chain_index / hash you wrote down or
// exported), which astra_verify_audit_chain() accepts as an optional anchor.

require_once __DIR__ . '/crypto.php';

const ASTRA_CHAIN_GENESIS  = 'GENESIS';
const ASTRA_CHAIN_LOCK     = 'astra_audit_chain';
const ASTRA_CHAIN_LOCK_SEC = 10;

// The sealed columns, in hash order. Changing this list invalidates every
// existing hash, so it is effectively part of the ledger format.
const ASTRA_CHAIN_FIELDS = ['user_id', 'username', 'action', 'ip_address', 'timestamp',
                            'geo', 'severity', 'incident_type', 'details'];

function astra_chain_ready($conn, bool $refresh = false): bool {
    static $ready = null;
    if ($ready === null || $refresh) {
        $r = mysqli_query($conn,
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'logs'
               AND COLUMN_NAME IN ('chain_index', 'previous_hash', 'current_hash', 'details')");
        $ready = $r && (int)mysqli_fetch_row($r)[0] === 4;
    }
    return $ready;
}

// HMAC over the canonical form of one row. $row needs chain_index,
// previous_hash and every ASTRA_CHAIN_FIELDS key (missing keys count as null).
function astra_chain_hash(array $row): string {
    $canon = [(string)$row['chain_index'], (string)$row['previous_hash']];
    foreach (ASTRA_CHAIN_FIELDS as $f) {
        $v = $row[$f] ?? null;
        $canon[] = $v === null ? null : (string)$v;
    }
    return hash_hmac('sha256', json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ASTRA_INDEX_KEY);
}

// Appends one row to the ledger. $fields may hold any ASTRA_CHAIN_FIELDS key
// except timestamp, which is taken from the database clock inside the lock so
// chain order and time order always agree. Returns
// ['chain_index' => int, 'current_hash' => string], or null on failure.
function astra_chain_append($conn, array $fields): ?array {
    if (!astra_chain_ready($conn)) {
        // Before the v4 migration: plain insert, exactly as before.
        $stmt = mysqli_prepare($conn,
            "INSERT INTO logs (user_id, username, action, ip_address, geo, severity, incident_type)
             VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) { error_log('[astra-chain] prepare failed: ' . mysqli_error($conn)); return null; }
        $sev = $fields['severity'] ?? 'info';
        mysqli_stmt_bind_param($stmt, "issssss", $fields['user_id'], $fields['username'], $fields['action'],
            $fields['ip_address'], $fields['geo'], $sev, $fields['incident_type']);
        mysqli_stmt_execute($stmt);
        return null;
    }

    $lock = mysqli_fetch_row(mysqli_query($conn, "SELECT GET_LOCK('" . ASTRA_CHAIN_LOCK . "', " . ASTRA_CHAIN_LOCK_SEC . ")"));
    if (!$lock || (int)$lock[0] !== 1) {
        // Never write an unchained row. Keep the event in the PHP error log
        // so it isn't lost silently.
        error_log('[astra-chain] lock timeout, event not written: ' . json_encode(array_intersect_key($fields, array_flip(['user_id', 'action', 'ip_address']))));
        return null;
    }
    try {
        $head = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT chain_index, current_hash FROM logs WHERE chain_index IS NOT NULL ORDER BY chain_index DESC LIMIT 1"));
        $now  = mysqli_fetch_row(mysqli_query($conn, "SELECT NOW()"))[0];

        $row = [
            'chain_index'   => $head ? (int)$head['chain_index'] + 1 : 1,
            'previous_hash' => $head ? $head['current_hash'] : ASTRA_CHAIN_GENESIS,
            'timestamp'     => $now,
            'severity'      => $fields['severity'] ?? 'info',
        ];
        foreach (ASTRA_CHAIN_FIELDS as $f) {
            if (!array_key_exists($f, $row)) $row[$f] = $fields[$f] ?? null;
        }
        $row['current_hash'] = astra_chain_hash($row);

        $stmt = mysqli_prepare($conn,
            "INSERT INTO logs (chain_index, previous_hash, current_hash, user_id, username, action, ip_address,
                               `timestamp`, geo, severity, incident_type, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) { error_log('[astra-chain] prepare failed: ' . mysqli_error($conn)); return null; }
        mysqli_stmt_bind_param($stmt, "ississssssss",
            $row['chain_index'], $row['previous_hash'], $row['current_hash'], $row['user_id'], $row['username'],
            $row['action'], $row['ip_address'], $row['timestamp'], $row['geo'], $row['severity'],
            $row['incident_type'], $row['details']);
        if (!mysqli_stmt_execute($stmt)) { error_log('[astra-chain] insert failed: ' . mysqli_stmt_error($stmt)); return null; }

        return ['chain_index' => $row['chain_index'], 'current_hash' => $row['current_hash']];
    } finally {
        mysqli_query($conn, "SELECT RELEASE_LOCK('" . ASTRA_CHAIN_LOCK . "')");
    }
}

// Security-event logger. $details is encrypted at rest; the hash covers the
// stored ciphertext, so verification never needs to decrypt anything.
// $severity / $incident_type feed the security dashboard.
function astra_log_chained(string $action, string $details = '', ?int $user_id = null,
                           $conn = null, string $severity = 'info', ?string $incident_type = null): ?array {
    $conn = $conn ?? ($GLOBALS['conn'] ?? null);
    if (!$conn) return null;
    return astra_chain_append($conn, [
        'user_id'       => $user_id,
        'username'      => $_SESSION['user_name'] ?? null,
        'action'        => substr($action, 0, 50),
        'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
        'severity'      => $severity,
        'incident_type' => $incident_type,
        'details'       => $details === '' ? null : astra_db_encrypt($details),
    ]);
}

// One-time seal of rows written before the chain existed, in id order.
// Called by the v4 migration only. Rows sealed this way are protected from
// later edits, but the chain can't prove they weren't altered before sealing.
function astra_chain_seal_legacy($conn): int {
    $lock = mysqli_fetch_row(mysqli_query($conn, "SELECT GET_LOCK('" . ASTRA_CHAIN_LOCK . "', 30)"));
    if (!$lock || (int)$lock[0] !== 1) throw new RuntimeException('Could not acquire the audit chain lock.');
    try {
        $head = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT chain_index, current_hash FROM logs WHERE chain_index IS NOT NULL ORDER BY chain_index DESC LIMIT 1"));
        $idx  = $head ? (int)$head['chain_index'] : 0;
        $prev = $head ? $head['current_hash'] : ASTRA_CHAIN_GENESIS;

        $rows = mysqli_query($conn,
            "SELECT id, user_id, username, action, ip_address, `timestamp`, geo, severity, incident_type, details
             FROM logs WHERE chain_index IS NULL ORDER BY id");
        $upd = mysqli_prepare($conn, "UPDATE logs SET chain_index = ?, previous_hash = ?, current_hash = ? WHERE id = ?");
        $n = 0;
        while ($r = mysqli_fetch_assoc($rows)) {
            $r['chain_index']   = ++$idx;
            $r['previous_hash'] = $prev;
            $hash = astra_chain_hash($r);
            mysqli_stmt_bind_param($upd, "issi", $idx, $prev, $hash, $r['id']);
            mysqli_stmt_execute($upd);
            $prev = $hash;
            $n++;
        }
        return $n;
    } finally {
        mysqli_query($conn, "SELECT RELEASE_LOCK('" . ASTRA_CHAIN_LOCK . "')");
    }
}
