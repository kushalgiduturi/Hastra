<?php
// Authentication: auth/login.php -> auth/otp.php, lockout, enumeration.
T::group('Authentication');

function login_post(string $email, string $password, string $portal = 'enterprise', ?string $session = null, array $extra = []): array {
    $s = $session ?? astra_test_session([]);
    $r = cgi('POST', 'auth/login.php', ['session' => $s, 'post' => $extra + [
        'csrf_token' => csrf_of($s), 'portal' => $portal, 'email' => $email,
        'password' => $password, 'g-recaptcha-response' => 'test-pass']]);
    $r['session'] = $s;
    return $r;
}
// Full sign-in: password -> emailed OTP -> session. Returns the final response.
function full_login(string $key, string $portal = 'enterprise'): array {
    mail_clear();
    $r = login_post(F::$u[$key]['email'], FIX_PASSWORD, $portal);
    expect_clean($r, 'login');
    expect(str_contains((string)$r['location'], 'otp'), "no OTP redirect for $key (status {$r['status']}): " . substr(trim(visible_text($r['body'])), 0, 160));
    $code = mail_code_to(F::$u[$key]['email']);
    expect($code !== null, 'no OTP email captured');
    $o = cgi('POST', 'auth/otp.php', ['session' => $r['session'], 'post' => ['csrf_token' => csrf_of($r['session']), 'otp' => $code]]);
    expect_clean($o, 'otp');
    $o['session'] = $r['session'];
    return $o;
}

t('login page renders cleanly (both portals)', function () {
    $r = cgi('GET', 'auth/login.php');
    expect_eq($r['status'], 200, 'status'); expect_clean($r);
    expect(str_contains($r['body'], 'Enterprise Workspace') && str_contains($r['body'], 'Client Gateway'), 'portal toggle missing');
});
t('enterprise sign-in: password + OTP -> admin portal, session populated', function () {
    $o = full_login('admin');
    expect(str_contains((string)$o['location'], 'portals/admin/admin_portal'), "routed to '{$o['location']}' (HTTP {$o['status']}): " . substr(trim(preg_replace('/\s+/', ' ', visible_text($o['body']))), 0, 200));
    $sess = astra_test_session_data($o['session']);
    // otp.php regenerates the session id; follow it via the stored data instead.
    $row = q1("SELECT user_id FROM user_sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1", [F::$u['admin']['id']]);
    expect($row !== null, 'no user_sessions anchor row written');
    return 'user_sessions anchor recorded; role routed to admin portal';
});
t('session holds decrypted name + role (not ciphertext)', function () {
    mail_clear();
    $r = login_post(F::$u['employee']['email'], FIX_PASSWORD);
    $code = mail_code_to(F::$u['employee']['email']);
    $o = cgi('POST', 'auth/otp.php', ['session' => $r['session'], 'post' => ['csrf_token' => csrf_of($r['session']), 'otp' => $code]]);
    // The id was regenerated; find the new session file by its contents.
    $found = null;
    foreach (glob(rtrim(ini_get('session.save_path'), '/\\') . '/sess_*') as $f) {
        if (filemtime($f) < time() - 30) continue;
        $d = astra_test_session_data(substr(basename($f), 5));
        if (($d['user_id'] ?? null) == F::$u['employee']['id']) { $found = $d; $GLOBALS['__astra_test_sessions'][] = substr(basename($f), 5); break; }
    }
    expect($found !== null, 'signed-in session not found');
    expect_eq($found['user_name'], F::$u['employee']['name'], 'user_name');
    expect_eq($found['user_role'], 'employee', 'user_role');
    expect(!str_starts_with((string)$found['user_name'], 'astra:'), 'name stored encrypted in session');
    expect(!empty($found['auth_fp']), 'no fingerprint anchor in session');
    return "keys: user_id, user_name, user_role (spec's \$_SESSION['user'] doesn't exist in this app)";
});
t('client sign-in routes to the Client Gateway', function () {
    $o = full_login('client', 'client');
    expect(str_contains((string)$o['location'], 'portals/client/client_portal'), 'routed to ' . $o['location']);
});
t('wrong OTP is rejected and the session stays signed out', function () {
    mail_clear();
    $r = login_post(F::$u['admin']['email'], FIX_PASSWORD);
    $o = cgi('POST', 'auth/otp.php', ['session' => $r['session'], 'post' => ['csrf_token' => csrf_of($r['session']), 'otp' => '000000']]);
    expect_clean($o, 'otp');
    expect(str_contains($o['body'], 'Invalid OTP'), 'no error message');
    expect(!isset(astra_test_session_data($r['session'])['user_id']), 'signed in with a wrong code');
});
t('wrong password and unknown account get the identical message (no enumeration)', function () {
    q("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?", [F::$u['employee']['id']]);
    $a = login_post(F::$u['employee']['email'], 'wrong-password-1');
    $b = login_post('nobody.' . F::$suffix . '@example.test', 'wrong-password-1');
    expect_clean($a); expect_clean($b);
    $msg = fn($r) => preg_match('~<div class="alert"[^>]*>.*?</svg>\s*(.*?)\s*</div>~s', $r['body'], $m) ? trim(html_entity_decode($m[1])) : '';
    expect($msg($a) !== '', 'no error shown');
    expect_eq($msg($a), $msg($b), 'existing vs unknown account message');
    q("UPDATE users SET login_attempts = 0 WHERE id = ?", [F::$u['employee']['id']]);
});
t('5 failed passwords lock the account for 15 minutes; correct password then refused', function () {
    q("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?", [F::$u['nogender']['id']]);
    q("DELETE FROM ip_attempts WHERE ip = '127.0.0.1'");
    for ($i = 0; $i < 5; $i++) login_post(F::$u['nogender']['email'], 'nope-' . $i);
    $u = q1("SELECT login_attempts, locked_until FROM users WHERE id = ?", [F::$u['nogender']['id']]);
    expect((int)$u['login_attempts'] >= 5 && $u['locked_until'] !== null, 'not locked: ' . json_encode($u));
    mail_clear();
    $r = login_post(F::$u['nogender']['email'], FIX_PASSWORD);
    expect_clean($r);
    expect(str_contains($r['body'], 'locked'), 'correct password accepted while locked');
    expect(mail_code_to(F::$u['nogender']['email']) === null, 'OTP sent while locked');
    q("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?", [F::$u['nogender']['id']]);
    q("DELETE FROM ip_attempts WHERE ip = '127.0.0.1'");
});
t('OTP step: more than 5 guesses ends the attempt', function () {
    mail_clear();
    $r = login_post(F::$u['admin']['email'], FIX_PASSWORD);
    $last = null;
    for ($i = 0; $i < 6; $i++) $last = cgi('POST', 'auth/otp.php', ['session' => $r['session'], 'post' => ['csrf_token' => csrf_of($r['session']), 'otp' => '11111' . $i]]);
    expect(str_contains((string)$last['location'], 'login'), 'sixth guess was still accepted for checking');
});
t('CAPTCHA failure is handled without PHP warnings', function () {
    $s = astra_test_session([]);
    $r = login_post(F::$u['admin']['email'], FIX_PASSWORD, 'enterprise', $s, ['g-recaptcha-response' => 'bad']);
    expect_clean($r);
    expect(str_contains($r['body'], 'CAPTCHA'), 'no CAPTCHA message');
});
t('missing CSRF token is rejected', function () {
    $s = astra_test_session([]);
    $r = cgi('POST', 'auth/login.php', ['session' => $s, 'post' => ['email' => 'x@example.test', 'password' => 'x']]);
    expect(str_contains($r['body'], 'Invalid request'), 'accepted without CSRF');
});
t('session_breach notice shown on the login page', function () {
    $r = cgi('GET', 'auth/login.php?error=session_breach');
    expect_clean($r);
    expect(str_contains($r['body'], 'different device or network'), 'notice missing');
});
t('logout revokes the session anchor', function () {
    $o = full_login('employee');
    $row = (int)qv("SELECT id FROM user_sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1", [F::$u['employee']['id']]);
    // Find the regenerated session and log out with it.
    $sid = null;
    foreach (glob(rtrim(ini_get('session.save_path'), '/\\') . '/sess_*') as $f) {
        $d = astra_test_session_data(substr(basename($f), 5));
        if (($d['auth_session_row'] ?? null) === $row) { $sid = substr(basename($f), 5); $GLOBALS['__astra_test_sessions'][] = $sid; break; }
    }
    expect($sid !== null, 'signed-in session not found');
    $l = cgi('GET', 'auth/logout.php', ['session' => $sid]);
    expect_clean($l, 'logout');
    expect_eq((int)qv("SELECT is_revoked FROM user_sessions WHERE id = ?", [$row]), 1, 'is_revoked');
});
