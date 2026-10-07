<?php
// Governance & workflow subsystems.

// ── Profile completion barrier (core/auth_check.php + api/complete_profile.php)
T::group('Profile barrier');
t('user without gender sees the blocking modal on their portal', function () {
    $r = cgi('GET', 'portals/emlpoyee/employee_portal.php', ['session' => as_user('nogender')]);
    expect_eq($r['status'], 200, 'status'); expect_clean($r);
    expect(str_contains($r['body'], 'profileBarrierOverlay'), 'no barrier overlay rendered');
    return 'modal trap (the page renders under an unclosable dialog), not a redirect';
});
t('completing gender flips profile_updated to 1 and removes the modal', function () {
    $s = as_user('nogender');
    $r = cgi('POST', 'api/complete_profile.php', ['session' => $s, 'accept' => 'application/json',
        'post' => ['csrf_token' => csrf_of($s), 'gender' => 'prefer_not_to_say']]);
    expect_clean($r);
    expect(json_of($r)['ok'] ?? false, 'API refused: ' . ($r['body']));
    $u = q1("SELECT gender, profile_updated FROM users WHERE id = ?", [F::$u['nogender']['id']]);
    expect_eq((int)$u['profile_updated'], 1, 'profile_updated');
    expect_eq(astra_db_decrypt($u['gender']), 'prefer_not_to_say', 'stored gender (encrypted)');
    $again = cgi('GET', 'portals/emlpoyee/employee_portal.php', ['session' => $s]);
    expect(!str_contains($again['body'], 'profileBarrierOverlay'), 'modal still shown');
    q("UPDATE users SET gender = NULL, profile_updated = 0 WHERE id = ?", [F::$u['nogender']['id']]);
});
t('invalid gender value is refused', function () {
    $s = as_user('nogender');
    $r = cgi('POST', 'api/complete_profile.php', ['session' => $s, 'post' => ['csrf_token' => csrf_of($s), 'gender' => 'x']]);
    expect(!(json_of($r)['ok'] ?? true), 'accepted a bad value');
});

t('barrier is boxless and the streaming banner is gone from every stylesheet', function () {
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/profile-barrier.css');
    expect(!preg_match('~\.profile-barrier-modal\s*\{[^}]*(border:\s*1px|box-shadow|background:\s*var\(--navy-card\))~s', $css), 'the barrier modal is a box again');
    expect(!preg_match('~\.pb-opt\s*\{[^}]*(border:\s*1\.5px|background:\s*var\(--input-bg\))~s', $css) && str_contains($css, 'border-bottom: 2px solid transparent'), 'the choices are boxes again');
    foreach (glob(ASTRA_ROOT . '/assets/css/*.css') as $c) expect(!str_contains(file_get_contents($c), 'NOW STREAMING'), basename($c) . ' still draws the NOW STREAMING banner');
    foreach (glob(ASTRA_ROOT . '/{portals/*,core,auth}/*.php', GLOB_BRACE) as $p) expect(!str_contains(file_get_contents($p), 'NOW STREAMING'), basename($p) . ' still prints the NOW STREAMING banner');
});
t('role: independent people choose a role label (a profile label, never an access role)', function () {
    $conn = $GLOBALS['conn'];
    $co = create_company($conn, 'Indy ' . F::$suffix . ' (Individual)', null, ['account_type' => 'client_individual']);
    $u = fix_make_user('indy', 'client', (int)$co['id'], ['gender' => null]);
    try {
        $s = as_user('indy');
        $page = cgi('GET', 'portals/client/client_portal.php', ['session' => $s]);
        expect_clean($page, 'portal');
        expect(str_contains($page['body'], 'data-focus="auditor"') && str_contains($page['body'], 'data-needs-focus="1"') && !str_contains($page['body'], 'Role locked by your organization'), 'an independent user was not offered the role choice');
        $post = fn(array $extra) => json_of(cgi('POST', 'api/complete_profile.php', ['session' => $s, 'accept' => 'application/json', 'post' => ['csrf_token' => csrf_of($s), 'gender' => 'male'] + $extra]));
        expect(!($post([])['ok'] ?? true), 'accepted no role');
        expect(!($post(['focus' => 'sysadmin'])['ok'] ?? true), 'accepted a role that is not on the list');
        expect(!($post(['focus' => 'admin'])['ok'] ?? true), 'accepted an access role as a profile label');
        expect($post(['focus' => 'security_analyst'])['ok'] ?? false, 'refused a valid role');
        $row = q1("SELECT role, profile_focus, profile_updated FROM users WHERE id = ?", [$u['id']]);
        expect_eq($row['profile_focus'], 'security_analyst', 'stored role label');
        expect_eq($row['role'], 'client', 'the access role must not change');
        expect_eq((int)$row['profile_updated'], 1, 'profile_updated');
    } finally {
        q("DELETE FROM users WHERE id = ?", [$u['id']]);
        q("DELETE FROM companies WHERE id = ?", [(int)$co['id']]);
    }
});
t('role: organization members see the lock and cannot post a role', function () {
    $s = as_user('nogender');   // a team member of an organization
    $page = cgi('GET', 'portals/emlpoyee/employee_portal.php', ['session' => $s]);
    expect_clean($page, 'portal');
    expect(str_contains($page['body'], "Role locked by your organization's policy") && str_contains($page['body'], 'data-needs-focus="0"') && !str_contains($page['body'], 'data-focus='), 'the lock notice is missing or a picker is shown');
    $r = cgi('POST', 'api/complete_profile.php', ['session' => $s, 'accept' => 'application/json',
        'post' => ['csrf_token' => csrf_of($s), 'gender' => 'male', 'focus' => 'auditor']]);
    expect($r['status'] === 403 && !(json_of($r)['ok'] ?? true), 'an organization member could post a role');
    $u = q1("SELECT gender, profile_updated FROM users WHERE id = ?", [F::$u['nogender']['id']]);
    expect($u['gender'] === null && (int)$u['profile_updated'] === 0, 'a refused request still changed the profile');
    // posting only the gender still works for them
    $ok = json_of(cgi('POST', 'api/complete_profile.php', ['session' => $s, 'accept' => 'application/json', 'post' => ['csrf_token' => csrf_of($s), 'gender' => 'female']]));
    expect($ok['ok'] ?? false, 'an organization member could not finish setup');
    q("UPDATE users SET gender = NULL, profile_updated = 0 WHERE id = ?", [F::$u['nogender']['id']]);
});

// ── Ephemeral dossiers (core/ephemeral_dossier.php) ─────────────────────────
T::group('Ephemeral dossiers');
function gov_released_milestone(): int {
    static $id = null;
    if ($id) return $id;
    $name = 'Audit milestone ' . F::$suffix;
    astra_signoff_initiate($GLOBALS['conn'], F::$project, $name, F::$u['admin']['id'], F::$u['client']['id'], '127.0.0.1', $e, 0);
    $ms = astra_signoff_client_sign($GLOBALS['conn'], F::$project, $name, F::$u['client']['id'], '127.0.0.1', $e);
    if (!$ms || $ms['escrow_status'] !== 'released') throw new TestFailure('could not prepare a released milestone: ' . $e);
    return $id = (int)$ms['id'];
}
t('single-view dossier: first open decrypts, second open is refused and shredded', function () {
    $token = astra_dossier_create($GLOBALS['conn'], F::$project, F::$u['admin']['id'], 'SECRET-' . F::$suffix, 1, 60, $e, gov_released_milestone());
    expect($token, $e ?? 'create failed');
    $row = q1("SELECT * FROM ephemeral_dossiers WHERE token_bindex = ?", [astra_blind_index($token)]);
    expect(str_starts_with($row['encrypted_payload'], 'hastra:v1:'), 'payload not encrypted at rest');
    $s = as_user('client');
    $a = cgi('POST', 'portals/deliveries/terminal.php', ['session' => $s, 'accept' => 'application/json',
        'post' => ['csrf_token' => csrf_of($s), 'action' => 'handshake', 'token' => $token]]);
    expect_clean($a, 'terminal');
    $j = json_of($a);
    expect(($j['payload'] ?? null) === 'SECRET-' . F::$suffix, 'payload not delivered: ' . json_encode($j['steps'] ?? $j));
    $row = q1("SELECT * FROM ephemeral_dossiers WHERE id = ?", [$row['id']]);
    expect((int)$row['is_shredded'] === 1 && preg_match('/^[a-f0-9]{128}$/', $row['encrypted_payload']), 'not shredded with random bytes');
    $b = json_of(cgi('POST', 'portals/deliveries/terminal.php', ['session' => $s, 'accept' => 'application/json',
        'post' => ['csrf_token' => csrf_of($s), 'action' => 'handshake', 'token' => $token]]));
    expect(!isset($b['payload']) && isset($b['receipt']), 'second open was not refused with a receipt');
});
t('legacy dossier page shows the expired screen for a shredded token', function () {
    $token = astra_dossier_create($GLOBALS['conn'], F::$project, F::$u['admin']['id'], 'OLD-' . F::$suffix, 1, 60, $e, gov_released_milestone());
    astra_dossier_consume($GLOBALS['conn'], $token, $e);
    $r = cgi('GET', 'portals/deliveries/dossier.php?token=' . $token, ['session' => as_user('client')]);
    expect_clean($r);
    expect(str_contains($r['body'], 'shredded'), 'no expired/shredded screen');
});

// ── Dual-key milestone sign-off (core/milestone_signoff.php) ────────────────
T::group('Milestone sign-off');
t('PM initiation records pending_client + a verifiable PM signature', function () {
    $name = 'Final Delivery';
    q("DELETE FROM milestone_signoffs WHERE project_id = ? AND milestone_name = ?", [F::$project, $name]);
    $s = as_user('admin');
    $r = cgi('POST', 'portals/projects/signoff.php', ['session' => $s, 'post' => ['csrf_token' => csrf_of($s),
        'action' => 'initiate', 'project_id' => F::$project, 'milestone_name' => $name, 'escrow_amount' => '1500']]);
    expect_clean($r);
    $ms = q1("SELECT * FROM milestone_signoffs WHERE project_id = ? AND milestone_name = ?", [F::$project, $name]);
    expect($ms && $ms['status'] === 'pending_client', 'status: ' . ($ms['status'] ?? 'none'));
    expect(astra_signoff_verify($GLOBALS['conn'], $ms)['pm_valid'] === true, 'PM signature does not verify');
    expect_eq((float)$ms['escrow_amount'], 1500.0, 'escrow amount');
});
t('delivery and invoicing are blocked until the client countersigns', function () {
    expect(!astra_signoff_is_complete($GLOBALS['conn'], F::$project), 'reads complete with one signature');
    q("UPDATE projects SET status = 'completed' WHERE id = ?", [F::$project]);
    $s = as_user('admin');
    $r = cgi('POST', 'portals/admin/billing.php', ['session' => $s, 'post' => ['csrf_token' => csrf_of($s),
        'action' => 'generate_invoice', 'project_id' => F::$project, 'dev_hours' => 10, 'hourly_rate' => 100, 'infra_cost' => 0]]);
    expect_clean($r);
    expect(str_contains($r['body'], 'milestone sign-off') || str_contains($r['body'], 'already exists'), 'invoice generation was not gated');
    return 'spec says completion "seals task modifications"; the app gates delivery + invoicing instead';
});
t('client countersignature completes the milestone and issues its invoice', function () {
    $ms = astra_signoff_client_sign($GLOBALS['conn'], F::$project, 'Final Delivery', F::$u['client']['id'], '127.0.0.1', $e);
    expect($ms && $ms['status'] === 'completed', $e ?? 'not completed');
    expect(astra_signoff_is_complete($GLOBALS['conn'], F::$project), 'still incomplete');
    $inv = q1("SELECT * FROM invoices WHERE milestone_id = ?", [$ms['id']]);
    expect($inv && (float)$inv['total_amount'] === 1500.0, 'no matching escrow invoice');
    expect_eq($ms['escrow_status'], 'payment_pending', 'escrow status');
});
t('the wrong client cannot countersign', function () {
    $name = 'Wrong signer ' . F::$suffix;
    astra_signoff_initiate($GLOBALS['conn'], F::$project, $name, F::$u['admin']['id'], F::$u['client']['id'], '127.0.0.1', $e, 0);
    expect(astra_signoff_client_sign($GLOBALS['conn'], F::$project, $name, F::$u['admin']['id'], '127.0.0.1', $e) === null, 'accepted another user');
});

// ── Honeytokens (core/canary.php) ───────────────────────────────────────────
T::group('Honeytokens');
t('login with a canary email aborts, logs HONEYTOKEN_BREACH and blocks the IP', function () {
    $ip = '203.0.113.66';
    q("REPLACE INTO ip_cache (ip, country, city, is_vpn, isp, updated_at) VALUES (?, 'IN', 'Pune', 0, 'Test ISP', NOW())", [$ip]);
    $before = (int)qv("SELECT COUNT(*) FROM logs WHERE incident_type = 'HONEYTOKEN_BREACH'");
    $s = astra_test_session([]);
    $r = cgi('POST', 'auth/login.php', ['session' => $s, 'ip' => $ip, 'post' => ['csrf_token' => csrf_of($s),
        'portal' => 'enterprise', 'email' => 'root-legacy@cycops.com', 'password' => 'anything', 'g-recaptcha-response' => 'test-pass']]);
    expect_clean($r);
    expect(!str_contains($r['body'], 'Invalid email or password'), 'request continued to the normal failure path');
    expect((int)qv("SELECT COUNT(*) FROM logs WHERE incident_type = 'HONEYTOKEN_BREACH'") === $before + 1, 'breach not logged');
    $c = q1("SELECT is_blocked, blocked_until FROM ip_cache WHERE ip = ?", [$ip]);
    expect((int)$c['is_blocked'] === 1 && $c['blocked_until'] !== null, 'IP not blocked');
    $again = cgi('GET', 'auth/login.php', ['ip' => $ip]);
    expect(in_array($again['status'], [403, 429], true) || str_contains($again['body'], 'blocked'), "blocked IP still served normally (HTTP {$again['status']})");
    return "aborted with HTTP {$r['status']}";
});

// ── Requirement versions & diff (core/requirement_versions.php) ─────────────
T::group('Requirement versioning');
t('revising a requirement archives the previous version', function () {
    $req = q1("SELECT r.id, r.description FROM requirements r JOIN projects p ON p.requirement_id = r.id WHERE p.id = ?", [F::$project]);
    $before = (int)qv("SELECT COUNT(*) FROM requirement_versions WHERE requirement_id = ?", [$req['id']]);
    $old = astra_db_decrypt($req['description']);
    $s = as_user('client');
    $new = $old . "\nAdded line: audit export " . F::$suffix;
    $r = cgi('POST', 'portals/client/my_requirements.php', ['session' => $s, 'post' => ['csrf_token' => csrf_of($s),
        'action' => 'edit', 'req_id' => $req['id'], 'requirement_title' => 'Audit revision ' . F::$suffix,
        'description' => $new, 'expected_features' => '']]);
    expect_clean($r);
    expect((int)qv("SELECT COUNT(*) FROM requirement_versions WHERE requirement_id = ?", [$req['id']]) === $before + 1, 'no version archived');
    expect_eq(astra_db_decrypt(qv("SELECT description FROM requirements WHERE id = ?", [$req['id']])), $new, 'live description');
    $d = cgi('GET', 'portals/client/requirements_diff.php?req_id=' . $req['id'], ['session' => $s]);
    expect_clean($d, 'diff page');
    expect(str_contains($d['body'], 'audit export ' . F::$suffix), 'added line not shown in the diff');
});
t('diff engine marks additions and deletions line by line', function () {
    $d = astra_reqver_diff_lines("a\nb\nc", "a\nc\nd");
    $types = array_map(fn($x) => $x['type'] . ':' . $x['text'], $d);
    expect_eq($types, ['same:a', 'removed:b', 'same:c', 'added:d'], 'diff');
});

// ── Leave -> attendance ─────────────────────────────────────────────────────
T::group('Leave & attendance');
t('employee leave request is stored as pending', function () {
    $s = as_user('employee');
    $start = date('Y-m-d', strtotime('+10 days')); $end = date('Y-m-d', strtotime('+12 days'));
    $r = cgi('POST', 'portals/emlpoyee/employee_portal.php', ['session' => $s, 'post' => ['csrf_token' => csrf_of($s),
        'action' => 'request_leave', 'leave_type' => 'unpaid', 'start_date' => $start, 'end_date' => $end, 'reason' => 'audit']]);
    expect_clean($r);
    $lr = q1("SELECT * FROM leave_requests WHERE user_id = ? AND start_date = ?", [F::$u['employee']['id'], $start]);
    expect($lr && $lr['status'] === 'pending', 'no pending leave row');
    expect_eq((float)$lr['total_days'], 3.0, 'total_days');
    $GLOBALS['__leave_id'] = (int)$lr['id'];
});
t('approval marks each day on_leave and keeps manual overrides', function () {
    $id = $GLOBALS['__leave_id'] ?? 0;
    expect($id > 0, 'previous test did not create a request');
    $lr = q1("SELECT * FROM leave_requests WHERE id = ?", [$id]);
    $mid = date('Y-m-d', strtotime($lr['start_date'] . ' +1 day'));
    q("INSERT INTO attendance (user_id, company_id, work_date, status, source, is_overridden) VALUES (?, ?, ?, 'present', 'manual_manager', 1)",
      [F::$u['employee']['id'], F::$internal_company, $mid]);
    $s = as_user('admin');
    $r = cgi('POST', 'portals/admin/leave_management.php', ['session' => $s, 'post' => ['csrf_token' => csrf_of($s),
        'action' => 'review_leave', 'leave_id' => $id, 'new_status' => 'approved']]);
    expect_clean($r);
    expect_eq(qv("SELECT status FROM leave_requests WHERE id = ?", [$id]), 'approved', 'leave status');
    $rows = mysqli_fetch_all(q("SELECT work_date, status FROM attendance WHERE user_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date",
        [F::$u['employee']['id'], $lr['start_date'], $lr['end_date']]), MYSQLI_ASSOC);
    expect_eq(array_column($rows, 'status'), ['on_leave', 'present', 'on_leave'], 'attendance for the span');
});

// ── Attendance webhook (api/attendance_sync.php) ────────────────────────────
T::group('Webhooks');
t('attendance: no secret -> 401', function () {
    $r = cgi('POST', 'api/attendance_sync.php', ['body' => '[]', 'content_type' => 'application/json']);
    expect_eq($r['status'], 401, 'status'); expect_clean($r);
});
t('attendance: wrong secret -> 403', function () {
    $r = cgi('POST', 'api/attendance_sync.php', ['body' => '[]', 'content_type' => 'application/json', 'headers' => ['X-Hastra-Webhook-Secret' => 'nope']]);
    expect_eq($r['status'], 403, 'status');
});
t('attendance: valid secret inserts, and a resend updates without duplicates', function () {
    $secret = bin2hex(random_bytes(16));
    q("UPDATE companies SET attendance_webhook_secret = ? WHERE id = ?", [$secret, F::$internal_company]);
    $day = date('Y-m-d', strtotime('-3 days'));
    $body = json_encode([['email' => F::$u['employee']['email'], 'work_date' => $day, 'check_in' => '09:12', 'status' => 'present']]);
    $opt = ['body' => $body, 'content_type' => 'application/json', 'headers' => ['X-Hastra-Webhook-Secret' => $secret]];
    $a = cgi('POST', 'api/attendance_sync.php', $opt);
    $b = cgi('POST', 'api/attendance_sync.php', $opt);
    expect_clean($a); expect_clean($b);
    expect_eq($a['status'], 200, 'first status');
    expect_eq((int)qv("SELECT COUNT(*) FROM attendance WHERE user_id = ? AND work_date = ?", [F::$u['employee']['id'], $day]), 1, 'row count');
});
t('payment webhook: signed request settles an escrow invoice and releases it', function () {
    $inv = q1("SELECT i.* FROM invoices i JOIN milestone_signoffs ms ON ms.id = i.milestone_id WHERE ms.milestone_name = 'Final Delivery' AND ms.project_id = ?", [F::$project]);
    expect($inv, 'no escrow invoice from the sign-off tests');
    $key = astra_read_key_file(ASTRA_ROOT . '/config/astra_payment_webhook.key', false);
    $body = json_encode(['invoice_number' => $inv['invoice_code'], 'amount' => $inv['total_amount'], 'reference' => 'pay_' . F::$suffix, 'status' => 'paid']);
    $ts = (string)time();
    $sig = 'sha256=' . hash_hmac('sha256', "$ts.$body", $key);
    $bad = cgi('POST', 'api/payment_webhook.php', ['body' => $body, 'content_type' => 'application/json',
        'headers' => ['X-Hastra-Timestamp' => $ts, 'X-Hastra-Signature' => 'sha256=' . str_repeat('0', 64)]]);
    expect_eq($bad['status'], 401, 'bad signature status');
    $r = cgi('POST', 'api/payment_webhook.php', ['body' => $body, 'content_type' => 'application/json',
        'headers' => ['X-Hastra-Timestamp' => $ts, 'X-Hastra-Signature' => $sig]]);
    expect_clean($r);
    expect_eq($r['status'], 200, 'status');
    expect_eq(qv("SELECT status FROM invoices WHERE id = ?", [$inv['id']]), 'paid', 'invoice');
    expect_eq(qv("SELECT escrow_status FROM milestone_signoffs WHERE id = ?", [$inv['milestone_id']]), 'released', 'milestone');
});
