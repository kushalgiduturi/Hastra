<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/session_guard.php
// Session fingerprint anchor + mid-session hijack killswitch.
//
// At sign-in (auth/otp.php, the only place a session becomes authenticated)
// astra_session_anchor() binds the session to an HMAC of:
//   User-Agent + Accept-Language + the client's network (IPv4 /24, IPv6 /64)
// and records it in user_sessions.
//
// On every request, secure_session_start() (core/db.php) calls
// astra_session_guard(). If the fingerprint no longer matches, or the
// session row was revoked, the session is destroyed on the spot, the event
// goes to the tamper-evident ledger, and the user must sign in again with
// password + email OTP.
//
// REMOTE_ADDR is used deliberately, not X-Forwarded-For: forwarded headers
// are client-controlled, so a hijacker could set them to match.
//
// Trade-off to know about: a user whose network changes mid-session (a
// laptop moving from office Wi-Fi to a phone hotspot) is signed out too.
// That is the intended behaviour for a hijack killswitch.

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/audit_chain.php';

const ASTRA_SESSION_TOUCH_SEC = 60; // how often last_active is refreshed

function astra_session_table_ready($conn, bool $refresh = false): bool {
    static $ready = null;
    if ($ready === null || $refresh) {
        $ready = (bool) mysqli_fetch_row(mysqli_query($conn,
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_sessions'"));
    }
    return $ready;
}

// IPv4 -> first three octets ("203.0.113"); IPv6 -> first four hextets,
// expanded, so "2001:db8::1" and "2001:0db8:0:0::2" agree.
// Loopback is one network however it's written: a browser can reach
// "localhost" over 127.0.0.1 or ::1 and switch between them mid-session.
// IPv4-mapped IPv6 (::ffff:203.0.113.5) is treated as the IPv4 address.
function astra_ip_subnet(string $ip): string {
    if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip = substr($ip, 7);
    }
    if ($ip === '::1' || str_starts_with($ip, '127.')) return 'loopback';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return substr($ip, 0, strrpos($ip, '.'));
    }
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        $hex = bin2hex(substr($bin, 0, 8));
        return implode(':', str_split($hex, 4));
    }
    return '';
}

function astra_session_fingerprint(): array {
    $subnet = astra_ip_subnet($_SERVER['REMOTE_ADDR'] ?? '');
    $hash = hash_hmac('sha256',
        ($_SERVER['HTTP_USER_AGENT'] ?? '') . "\n" . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . "\n" . $subnet,
        ASTRA_INDEX_KEY);
    return ['hash' => $hash, 'subnet' => $subnet];
}

// Call right after a session becomes authenticated (and after
// session_regenerate_id, so the recorded token is the live one).
function astra_session_anchor($conn, int $user_id): void {
    $fp = astra_session_fingerprint();
    $_SESSION['auth_fp'] = $fp['hash'];
    $_SESSION['auth_fp_touched'] = time();
    unset($_SESSION['auth_session_row']);

    if (!astra_session_table_ready($conn)) return;
    $bindex = astra_blind_index(session_id());
    $stmt = mysqli_prepare($conn,
        "INSERT INTO user_sessions (user_id, session_token_bindex, device_fingerprint_hash, ip_subnet)
         VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "isss", $user_id, $bindex, $fp['hash'], $fp['subnet']);
    if (mysqli_stmt_execute($stmt)) {
        // The row id, not the session id, identifies this session from here
        // on: session_regenerate_id() elsewhere would change the latter.
        $_SESSION['auth_session_row'] = (int)mysqli_insert_id($conn);
    }
}

function astra_session_revoke($conn): void {
    $row = (int)($_SESSION['auth_session_row'] ?? 0);
    if ($row && astra_session_table_ready($conn)) {
        mysqli_query($conn, "UPDATE user_sessions SET is_revoked = 1 WHERE id = $row");
    }
}

// Ends the session and sends the browser to sign in again. JSON/XHR callers
// get a 401 body instead of an HTML redirect they can't follow.
function astra_session_kill(string $reason_code): void {
    session_unset();
    session_destroy();
    $wants_json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
               || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
               || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
    if ($wants_json) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $reason_code, 'message' => 'Your session ended. Sign in again.']);
    } else {
        header('Location: ' . get_base_url() . 'auth/login?error=' . urlencode($reason_code));
    }
    exit();
}

function astra_session_guard($conn): void {
    if (!isset($_SESSION['user_id'])) return;
    $user_id = (int)$_SESSION['user_id'];

    // Sessions that predate the guard carry no anchor. Adopting one here
    // would let a session stolen before deployment anchor itself to the
    // thief, so they sign in again instead (a one-time event).
    if (empty($_SESSION['auth_fp'])) {
        astra_session_kill('reauth');
    }

    $fp = astra_session_fingerprint();
    if (!hash_equals($_SESSION['auth_fp'], $fp['hash'])) {
        astra_session_revoke($conn);
        astra_log_chained('SESSION_HIJACK_ATTEMPT',
            'Mismatched fingerprint on active session (network ' . ($fp['subnet'] ?: 'unknown') . ', UA ' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120) . ')',
            $user_id, $conn, 'critical', 'SESSION_HIJACK');
        astra_session_kill('session_breach');
    }

    $row = (int)($_SESSION['auth_session_row'] ?? 0);
    if ($row && astra_session_table_ready($conn)) {
        $r = mysqli_fetch_row(mysqli_query($conn, "SELECT is_revoked FROM user_sessions WHERE id = $row"));
        if (!$r || (int)$r[0] === 1) {
            astra_log_chained('SESSION_REVOKED_REUSE', 'Request on a revoked session', $user_id, $conn, 'warning', 'SESSION_REVOKED');
            astra_session_kill('session_revoked');
        }
        if (time() - (int)($_SESSION['auth_fp_touched'] ?? 0) >= ASTRA_SESSION_TOUCH_SEC) {
            mysqli_query($conn, "UPDATE user_sessions SET last_active = NOW() WHERE id = $row");
            $_SESSION['auth_fp_touched'] = time();
        }
    }
}
