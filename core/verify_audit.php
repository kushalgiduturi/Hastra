<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/verify_audit.php
// Walks the audit ledger (core/audit_chain.php) in chain order and checks,
// for every row n:
//   1. chain_index is exactly n (no gaps: a missing index means a deleted row)
//   2. previous_hash equals row n-1's current_hash ('GENESIS' for row 1)
//   3. recomputing the HMAC over the row's stored columns gives current_hash
// Any row with no chain_index at all was inserted outside the chain.
//
// Pass $anchor = ['chain_index' => N, 'current_hash' => H] (a head you
// recorded earlier) to also detect rows truncated off the end, or a rewritten
// history. astra_verify_audit_ledger() does this automatically with the
// anchor file below.

require_once __DIR__ . '/audit_chain.php';

// Written after every successful verification. It lives on the web server's
// disk, outside the database, so someone who can edit the database but not
// these files can't cut entries off the end of the ledger unnoticed.
const ASTRA_AUDIT_ANCHOR_FILE = __DIR__ . '/../config/audit_chain.anchor';

function astra_audit_anchor_read(): ?array {
    if (!is_file(ASTRA_AUDIT_ANCHOR_FILE)) return null;
    $a = json_decode((string)file_get_contents(ASTRA_AUDIT_ANCHOR_FILE), true);
    return is_array($a) && isset($a['chain_index'], $a['current_hash']) ? $a : null;
}

// Verify against the stored anchor, then move the anchor forward on success.
function astra_verify_audit_ledger($conn): array {
    $anchor = astra_audit_anchor_read();
    $r = astra_verify_audit_chain($conn, $anchor);
    $r['anchor'] = $anchor;
    if ($r['ok'] && $r['blocks'] > 0) {
        file_put_contents(ASTRA_AUDIT_ANCHOR_FILE, json_encode([
            'chain_index'  => $r['blocks'],
            'current_hash' => $r['head_hash'],
            'verified_at'  => date('c'),
        ]), LOCK_EX);
    }
    return $r;
}

function astra_verify_audit_chain($conn, ?array $anchor = null): array {
    $out = ['ok' => false, 'blocks' => 0, 'broken_at' => null, 'log_id' => null, 'reason' => null];

    if (!astra_chain_ready($conn)) {
        $out['reason'] = 'The audit chain is not set up yet. Run the database migration.';
        return $out;
    }

    $unchained = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM logs WHERE chain_index IS NULL"))[0];

    // Unbuffered: the ledger only grows, so don't load it all into memory.
    $res = mysqli_query($conn,
        "SELECT id, chain_index, previous_hash, current_hash, user_id, username, action, ip_address,
                `timestamp`, geo, severity, incident_type, details
         FROM logs WHERE chain_index IS NOT NULL ORDER BY chain_index", MYSQLI_USE_RESULT);

    $expect_index = 1;
    $prev_hash    = ASTRA_CHAIN_GENESIS;
    $anchor_idx   = $anchor ? (int)$anchor['chain_index'] : 0;
    $fail = null;
    while ($row = mysqli_fetch_assoc($res)) {
        $idx = (int)$row['chain_index'];
        if ($fail === null) {
            if ($idx !== $expect_index) {
                $fail = [$expect_index, (int)$row['id'], $idx > $expect_index
                    ? "Block $expect_index is missing (the next block present is $idx). A log entry was deleted."
                    : "Block $idx appears out of order."];
            } elseif (!hash_equals($prev_hash, (string)$row['previous_hash'])) {
                $fail = [$idx, (int)$row['id'], "Block $idx does not link to block " . ($idx - 1) . ". Its previous_hash was altered, or the block before it was replaced."];
            } elseif (!hash_equals(astra_chain_hash($row), (string)$row['current_hash'])) {
                $fail = [$idx, (int)$row['id'], "Block $idx was modified after it was written. Its contents no longer match its hash."];
            }
        }
        if ($fail === null && $idx === $anchor_idx && $anchor['current_hash'] !== ''
            && !hash_equals((string)$anchor['current_hash'], (string)$row['current_hash'])) {
            $fail = [$idx, (int)$row['id'], "Block $idx no longer matches the hash recorded at the last verification. The ledger history was rewritten."];
        }
        $prev_hash = (string)$row['current_hash'];
        $expect_index = $idx + 1;
        $out['blocks']++;
    }
    mysqli_free_result($res);

    if ($fail === null && $anchor) {
        $a_idx = (int)$anchor['chain_index'];
        if ($out['blocks'] < $a_idx) {
            $fail = [$out['blocks'] + 1, null, "The ledger ends at block {$out['blocks']}, but the recorded anchor is block $a_idx. Entries were removed from the end."];
        }
    }
    if ($fail === null && $unchained > 0) {
        $first = (int)mysqli_fetch_row(mysqli_query($conn, "SELECT MIN(id) FROM logs WHERE chain_index IS NULL"))[0];
        $fail = [null, $first, "$unchained log entr" . ($unchained === 1 ? 'y was' : 'ies were') . " written outside the chain (first: log #$first)."];
    }

    if ($fail) {
        [$out['broken_at'], $out['log_id'], $out['reason']] = $fail;
        return $out;
    }
    $out['ok'] = true;
    $out['head_hash'] = $prev_hash;
    return $out;
}
