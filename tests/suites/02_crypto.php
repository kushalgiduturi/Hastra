<?php
// Cryptographic subsystem: core/crypto.php
T::group('Crypto');

foreach ([
    'ASCII'   => 'Hello, Astra 123',
    'Unicode' => "Priya Nair · ₹45,000 · मुंबई · 🚀",
    'JSON'    => json_encode(['k' => 'v', 'n' => [1, 2, 3], 'q' => "quote\"s"]),
    'Long'    => str_repeat('x', 10000),
] as $label => $plain) {
    t("encrypt/decrypt round-trip: $label", function () use ($plain) {
        $ct = astra_encrypt($plain);
        expect(is_string($ct) && $ct !== $plain, 'ciphertext equals plaintext');
        expect_eq(astra_decrypt($ct), $plain, 'decrypted value');
    });
}

t('empty string passes through unencrypted', function () {
    expect_eq(astra_encrypt(''), '', 'astra_encrypt("")');
    expect_eq(astra_decrypt(''), '', 'astra_decrypt("")');
    expect_eq(astra_db_encrypt(null), null, 'astra_db_encrypt(null)');
    expect_eq(astra_db_decrypt(null), null, 'astra_db_decrypt(null)');
});

t('envelope is astra:v1: + version byte + 12-byte IV + 16-byte tag + ciphertext', function () {
    $plain = 'envelope-check';
    $ct = astra_encrypt($plain);
    expect(str_starts_with($ct, 'astra:v1:'), 'missing astra:v1: prefix: ' . substr($ct, 0, 12));
    $raw = base64_decode(substr($ct, 9), true);
    expect($raw !== false, 'payload is not valid base64');
    expect_eq(strlen($raw), 1 + 12 + 16 + strlen($plain), 'decoded length (GCM adds no padding)');
    expect_eq(ord($raw[0]), ASTRA_ACTIVE_KEY_VERSION, 'key version byte');
});

t('fresh random IV per encryption (same plaintext, different ciphertext)', function () {
    expect(astra_encrypt('same') !== astra_encrypt('same'), 'two encryptions produced identical output');
});

t('tampered ciphertext is rejected (returns null, never garbage)', function () {
    $ct  = astra_encrypt('tamper-me-please');
    $raw = base64_decode(substr($ct, 9), true);
    foreach ([29, 5, 20] as $pos) {                 // ciphertext body, IV, tag
        $bad = $raw; $bad[$pos] = chr(ord($bad[$pos]) ^ 0x01);
        $r = astra_decrypt('astra:v1:' . base64_encode($bad));
        expect($r === null, "flipping byte $pos returned " . var_export($r, true));
    }
});

t('truncated / malformed envelopes are rejected', function () {
    expect(astra_decrypt('astra:v1:' . base64_encode('short')) === null, 'short payload accepted');
    expect(astra_decrypt('astra:v1:@@not-base64@@') === null, 'bad base64 accepted');
});

t('blind index: deterministic HMAC-SHA256, case and whitespace normalised', function () {
    $a = astra_blind_index('Priya.Nair@Example.com');
    expect((bool)preg_match('/^[a-f0-9]{64}$/', $a), 'not 64 hex chars');
    expect_eq(astra_blind_index('  priya.nair@example.com '), $a, 'normalised variant');
    expect_eq($a, hash_hmac('sha256', 'priya.nair@example.com', ASTRA_INDEX_KEY), 'HMAC-SHA256 with the index key');
    expect(astra_blind_index('someone.else@example.com') !== $a, 'different inputs collided');
});

t('blind index refuses low-cardinality columns', function () {
    try { astra_blind_index_for('gender', 'male'); }
    catch (InvalidArgumentException $e) { return 'refused as expected'; }
    throw new TestFailure('built a blind index for gender');
});

// ── Audit ledger (core/audit_chain.php, core/verify_audit.php) ─────────────
T::group('Audit ledger');
require_once __DIR__ . '/../../core/verify_audit.php';

t('cloned ledger verifies intact', function () {
    $r = astra_verify_audit_chain($GLOBALS['conn']);
    expect($r['ok'], $r['reason'] ?? 'not ok');
    return "{$r['blocks']} blocks";
});
t('append extends the chain by one linked block', function () {
    $before = astra_verify_audit_chain($GLOBALS['conn'])['blocks'];
    $b = astra_log_chained('AUDIT_SUITE', 'append check', null, $GLOBALS['conn']);
    expect($b && $b['chain_index'] === $before + 1, 'unexpected chain index');
    $r = astra_verify_audit_chain($GLOBALS['conn']);
    expect($r['ok'] && $r['blocks'] === $before + 1, $r['reason'] ?? 'count mismatch');
});
t('editing a block is detected at that block', function () {
    $idx = (int)qv("SELECT chain_index FROM logs WHERE chain_index IS NOT NULL ORDER BY chain_index LIMIT 1 OFFSET 10");
    $orig = qv("SELECT action FROM logs WHERE chain_index = ?", [$idx]);
    q("UPDATE logs SET action = 'tampered' WHERE chain_index = ?", [$idx]);
    $r = astra_verify_audit_chain($GLOBALS['conn']);
    q("UPDATE logs SET action = ? WHERE chain_index = ?", [$orig, $idx]);
    expect(!$r['ok'] && $r['broken_at'] === $idx, "expected break at $idx, got " . var_export($r['broken_at'], true));
});
t('deleting a block is detected', function () {
    $row = q1("SELECT * FROM logs WHERE chain_index IS NOT NULL ORDER BY chain_index LIMIT 1 OFFSET 20");
    q("DELETE FROM logs WHERE id = ?", [$row['id']]);
    $r = astra_verify_audit_chain($GLOBALS['conn']);
    $cols = array_keys($row);
    q("INSERT INTO logs (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")", array_values($row));
    expect(!$r['ok'] && $r['broken_at'] === (int)$row['chain_index'], 'deletion not detected');
    expect(astra_verify_audit_chain($GLOBALS['conn'])['ok'], 'restore failed');
});
t('rows written outside the chain are detected', function () {
    q("INSERT INTO logs (action, current_hash) VALUES ('sneaky', 'x')");
    $id = (int)mysqli_insert_id($GLOBALS['conn']);
    $r = astra_verify_audit_chain($GLOBALS['conn']);
    q("DELETE FROM logs WHERE id = ?", [$id]);
    expect(!$r['ok'] && $r['log_id'] === $id, 'unchained row not reported');
});
t('tail truncation is detected against a recorded anchor', function () {
    $head = q1("SELECT chain_index, current_hash FROM logs ORDER BY chain_index DESC LIMIT 1");
    $row = q1("SELECT * FROM logs WHERE chain_index = ?", [$head['chain_index']]);
    q("DELETE FROM logs WHERE id = ?", [$row['id']]);
    $r = astra_verify_audit_chain($GLOBALS['conn'], $head);
    $cols = array_keys($row);
    q("INSERT INTO logs (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")", array_values($row));
    expect(!$r['ok'], 'truncation not detected');
});

// ── Session guard (core/session_guard.php) ──────────────────────────────────
T::group('Session guard');
t('subnet normalisation: IPv4 /24, IPv6 /64, loopback, mapped IPv4', function () {
    expect_eq(astra_ip_subnet('203.0.113.77'), '203.0.113', 'IPv4');
    expect_eq(astra_ip_subnet('2001:db8::1'), astra_ip_subnet('2001:0db8:0:0::ffff'), 'IPv6 same /64');
    expect_eq(astra_ip_subnet('127.0.0.1'), astra_ip_subnet('::1'), 'loopback forms');
    expect_eq(astra_ip_subnet('::ffff:203.0.113.5'), '203.0.113', 'IPv4-mapped IPv6');
});
t('guard allows the anchored device and blocks a different browser', function () {
    $s = as_user('admin');
    $ok = cgi('GET', 'portals/admin/admin_portal.php', ['session' => $s]);
    expect_eq($ok['status'], 200, 'anchored request status');
    $bad = cgi('GET', 'portals/admin/admin_portal.php', ['session' => $s, 'ua' => 'curl/8.4']);
    expect_eq($bad['status'], 302, 'hijacked request status');
    expect(str_contains((string)$bad['location'], 'error=session_breach'), 'no session_breach redirect: ' . $bad['location']);
    expect((bool)qv("SELECT 1 FROM logs WHERE action = 'SESSION_HIJACK_ATTEMPT' AND user_id = ? LIMIT 1", [F::$u['admin']['id']]), 'hijack not logged');
    $after = cgi('GET', 'portals/admin/admin_portal.php', ['session' => $s]);
    expect_eq($after['status'], 302, 'session must be dead afterwards');
});
t('guard blocks a request from a different network', function () {
    $s = as_user('admin');
    $r = cgi('GET', 'portals/admin/admin_portal.php', ['session' => $s, 'ip' => '198.51.100.9']);
    expect(str_contains((string)$r['location'], 'session_breach'), 'not blocked: ' . $r['status']);
});
t('pre-guard sessions (no anchor) must sign in again', function () {
    $s = astra_test_session(['user_id' => F::$u['admin']['id'], 'user_role' => 'admin', 'auth_fp' => null]);
    $r = cgi('GET', 'portals/admin/admin_portal.php', ['session' => $s]);
    expect(str_contains((string)$r['location'], 'error=reauth'), 'expected reauth redirect, got ' . $r['location']);
});
t('API callers get JSON 401, not an HTML redirect', function () {
    $s = as_user('admin');
    $r = cgi('POST', 'api/complete_profile.php', ['session' => $s, 'ua' => 'curl/8.4', 'accept' => 'application/json', 'post' => ['gender' => 'male']]);
    expect_eq($r['status'], 401, 'status');
    expect_eq(json_of($r)['error'] ?? null, 'session_breach', 'error code');
});

// ── Anti-VPN gate (core/geo_security.php, core/network.php) ────────────────
T::group('Anti-VPN & IP reputation');
t('localhost IPv4 / IPv6 bypass reputation checks (local development)', function () {
    foreach (['127.0.0.1', '::1'] as $ip) {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $r = astra_inspect_ip($ip, $GLOBALS['conn']);
        expect($r['is_vpn'] === false, "$ip flagged");
    }
    unset($_SERVER['REMOTE_ADDR']);
});
t('cached VPN / datacenter verdict blocks login before any credential check', function () {
    $ip = '203.0.113.50';
    q("REPLACE INTO ip_cache (ip, country, city, is_vpn, isp, updated_at) VALUES (?, 'NL', 'Amsterdam', 1, 'Test Hosting BV', NOW())", [$ip]);
    mail_clear();
    $s = astra_test_session([]);
    $r = cgi('POST', 'auth/login.php', ['session' => $s, 'ip' => $ip, 'post' => [
        'csrf_token' => csrf_of($s), 'portal' => 'enterprise', 'email' => F::$u['admin']['email'],
        'password' => FIX_PASSWORD, 'g-recaptcha-response' => 'test-pass']]);
    expect_clean($r, 'login');
    expect(str_contains($r['body'], 'VPN or Proxy connection detected'), 'no VPN message');
    expect(mail_code_to(F::$u['admin']['email']) === null, 'an OTP was still sent');
    expect((bool)qv("SELECT 1 FROM logs WHERE action = 'login_blocked_vpn' AND ip_address = ? LIMIT 1", [$ip]), 'block not logged');
    return 'blocked with an in-page message (status 200), by design';
});
t('trusted domestic IP proceeds to the credential check', function () {
    $ip = '203.0.113.51';
    q("REPLACE INTO ip_cache (ip, country, city, is_vpn, isp, updated_at) VALUES (?, 'IN', 'Hyderabad', 0, 'Test Broadband', NOW())", [$ip]);
    mail_clear();
    $s = astra_test_session([]);
    $r = cgi('POST', 'auth/login.php', ['session' => $s, 'ip' => $ip, 'post' => [
        'csrf_token' => csrf_of($s), 'portal' => 'enterprise', 'email' => F::$u['admin']['email'],
        'password' => FIX_PASSWORD, 'g-recaptcha-response' => 'test-pass']]);
    expect_clean($r, 'login');
    expect(str_contains((string)$r['location'], 'otp'), 'did not reach OTP step: status ' . $r['status']);
    expect(mail_code_to(F::$u['admin']['email']) !== null, 'no OTP email captured');
});
