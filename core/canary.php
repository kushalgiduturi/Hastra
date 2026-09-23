<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'canary.php') { http_response_code(404); exit(); }
// Astra — honeytoken intrusion traps and the lockdown they trigger.
//
// A honeytoken is a decoy identifier (an unlinked "admin" email, a fake API
// key, a planted document token) that no legitimate user or integration has
// any reason to ever submit. Its blind index sits in honeytokens.identifier_bindex
// — the same HMAC-SHA256-under-ASTRA_INDEX_KEY scheme as every other blind
// index in this app (core/crypto.php) — so the check is a single indexed
// lookup, not a table scan, and the decoy value itself is never stored in
// clear.
//
// astra_canary_check() is the single call site every entry point (login,
// requirement submission, dossier retrieval, ...) should route a
// user-supplied identifier through before treating it as real. A match
// terminates the request immediately from inside the check — callers never
// see a "true" they forgot to act on.
//
// astra_canary_guard() is the other half: once an IP is blocked, every
// subsequent request from it is rejected before authentication or business
// logic runs at all. core/db.php calls it unconditionally on every page.

require_once __DIR__ . '/network.php';

const CANARY_BLOCK_HOURS = 72;

function astra_canary_schema_ready($conn) {
    static $ready = null;
    if ($ready === null) {
        $ready = (bool) mysqli_fetch_row(mysqli_query($conn,
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'honeytokens'"))
            && db_column_exists($conn, 'ip_cache', 'is_blocked')
            && db_column_exists($conn, 'logs', 'incident_type');
    }
    return $ready;
}

// Decoy values seeded once so there is something to trip over out of the box.
// None of these correspond to a real users row, a real project, or a real
// secret — that is the point: any request that presents one is, by
// definition, not a legitimate user who forgot their own credentials.
function astra_canary_seed_defaults($conn, callable $out = null): int {
    // Deliberately does NOT go through astra_canary_schema_ready() — that
    // check is static-cached for the hot guard path (called on every
    // request), and this runs from inside the same migration call that just
    // created the honeytokens table a moment earlier. The cached "not ready"
    // from before the table existed would otherwise make seeding a no-op for
    // the rest of that process.
    $honeytokens_exists = (bool) mysqli_fetch_row(mysqli_query($conn,
        "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'honeytokens'"));
    if (!$honeytokens_exists) return 0;
    $say = $out ?? function ($x) {};

    $defaults = [
        ['canary_user', 'root-legacy@cycops.com',                 'Decoy sysadmin mailbox, never assigned to a real account'],
        ['canary_user', 'db-admin@cycops-internal.local',          'Decoy internal-looking admin address'],
        ['canary_key',  'astra_live_sk_4f8e2c9b7a1d3f6082b4c9e1a7', 'Decoy API key, formatted to look production-issued'],
        ['canary_doc',  'DOSSIER-DECOY-0000-PLANTED',               'Decoy dossier token embedded in seeded documentation'],
    ];

    $ins = mysqli_prepare($conn,
        "INSERT IGNORE INTO honeytokens (token_type, identifier_bindex, description) VALUES (?, ?, ?)");
    $n = 0;
    foreach ($defaults as [$type, $value, $desc]) {
        $bindex = astra_blind_index($value);
        mysqli_stmt_bind_param($ins, "sss", $type, $bindex, $desc);
        mysqli_stmt_execute($ins);
        if (mysqli_stmt_affected_rows($ins) > 0) { $n++; $say("   seeded $type honeytoken: $desc"); }
    }
    return (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM honeytokens"))[0];
}

// True the moment $value's blind index matches a seeded honeytoken. Checks
// nothing else about the request — callers pass whatever identifier they were
// about to trust (a login email, a submitted API key, a dossier token) BEFORE
// using it for anything.
function astra_canary_is_tripped($conn, string $token_type, ?string $value): ?array {
    if (!astra_canary_schema_ready($conn) || $value === null || $value === '') return null;
    $bindex = astra_blind_index($value);
    $stmt = mysqli_prepare($conn,
        "SELECT id, token_type, description, trigger_count FROM honeytokens WHERE token_type = ? AND identifier_bindex = ?");
    mysqli_stmt_bind_param($stmt, "ss", $token_type, $bindex);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
}

// Writes the emergency-severity log entry, hard-blocks the IP for 72 hours,
// and bumps the honeytoken's own counter. Does NOT terminate the request —
// that is astra_canary_check()'s job, so this half stays testable in
// isolation without a script-ending side effect.
function astra_canary_record_breach($conn, array $honeytoken, string $ip, ?int $user_id = null): void {
    mysqli_query($conn, "UPDATE honeytokens SET trigger_count = trigger_count + 1 WHERE id = " . (int)$honeytoken['id']);

    $stmt = mysqli_prepare($conn,
        "INSERT INTO logs (user_id, username, action, ip_address, severity, incident_type)
         VALUES (?, ?, 'honeytoken_breach', ?, 'critical', 'HONEYTOKEN_BREACH')");
    $desc = $honeytoken['description'] ?? $honeytoken['token_type'];
    mysqli_stmt_bind_param($stmt, "iss", $user_id, $desc, $ip);
    mysqli_stmt_execute($stmt);

    $reason = 'honeytoken:' . $honeytoken['token_type'];
    $block  = mysqli_prepare($conn,
        "INSERT INTO ip_cache (ip, is_vpn, is_blocked, blocked_until, block_reason, updated_at)
         VALUES (?, 0, 1, NOW() + INTERVAL " . CANARY_BLOCK_HOURS . " HOUR, ?, NOW())
         ON DUPLICATE KEY UPDATE is_blocked = 1,
                                  blocked_until = NOW() + INTERVAL " . CANARY_BLOCK_HOURS . " HOUR,
                                  block_reason = VALUES(block_reason),
                                  updated_at = NOW()");
    mysqli_stmt_bind_param($block, "ss", $ip, $reason);
    mysqli_stmt_execute($block);
}

// The generic response shown to a tripped request. Deliberately says nothing
// about why — no "honeytoken detected", no stack trace, no distinguishing
// detail an attacker could use to map the defense.
function astra_canary_terminate(): void {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Connection terminated.";
    exit();
}

// Call with every user-supplied identifier before it is trusted (a login
// email, an API key header, a dossier token). Terminates the request in
// place on a match — there is no return value to check because there is
// nothing left to do afterward.
function astra_canary_check($conn, string $token_type, ?string $value, ?int $user_id = null): void {
    $hit = astra_canary_is_tripped($conn, $token_type, $value);
    if (!$hit) return;
    $ip = astra_get_client_ip();
    astra_canary_record_breach($conn, $hit, $ip, $user_id);
    astra_canary_terminate();
}

// Called unconditionally, early, on every request (core/db.php). Rejects
// anything from an IP still inside its block window before auth, CSRF, or any
// business logic runs — a honeytoken trip has to actually lock the door, not
// just get logged and let the next request through.
function astra_canary_guard($conn): void {
    if (!astra_canary_schema_ready($conn)) return;
    $ip = astra_get_client_ip();
    $stmt = mysqli_prepare($conn,
        "SELECT is_blocked FROM ip_cache WHERE ip = ? AND is_blocked = 1 AND blocked_until > NOW()");
    mysqli_stmt_bind_param($stmt, "s", $ip);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_row(mysqli_stmt_get_result($stmt))) {
        astra_canary_terminate();
    }
}
