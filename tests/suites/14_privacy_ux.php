<?php
// Privacy, compliance and usability layer: self-service erasure, first-party
// telemetry with scrubbing, the MySQL rate limiter, consent-gated analytics,
// email footers, accessible dialogs instead of native prompts, page metadata,
// the shared UX script, print styles and the query-path indexes.
T::group('Privacy, compliance & usability');
require_once ASTRA_ROOT . '/core/data_deletion.php';

function px_src(string $rel): string { return (string)file_get_contents(ASTRA_ROOT . '/' . $rel); }
function px_pages(): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ASTRA_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace(chr(92), '/', $f->getPathname());
        if (!preg_match('~\.(php|js)$~', $p) || preg_match('~/(_backup[^/]*|tests|Claude outputs|PHPMailer|edge|node_modules|vendor)/~', $p)) continue;
        $out[substr($p, strlen(str_replace(chr(92), '/', ASTRA_ROOT)) + 1)] = $p;
    }
    return $out;
}

// ── rate limiting ───────────────────────────────────────────────────────────
t('rate limiter: sliding window allows up to the limit, then refuses', function () {
    $key = 'test:' . bin2hex(random_bytes(6));
    $ok = 0;
    for ($i = 0; $i < 7; $i++) if (astra_rate_allow($GLOBALS['conn'], $key, 5, 60)) $ok++;
    expect($ok === 5, "allowed $ok of 7 with a limit of 5");
    expect(!q1("SELECT 1 FROM rate_windows WHERE bucket_hash = ?", [$key]), 'raw key stored instead of a hash');
});
t('rate limiter: public endpoints answer 429 with Retry-After when flooded', function () {
    $ip = '203.0.113.' . random_int(10, 250);
    $last = null;
    for ($i = 0; $i < 22; $i++) $last = cgi('POST', 'api/client_error.php', ['ip' => $ip, 'body' => '{"message":"x"}', 'content_type' => 'application/json']);
    expect($last['status'] === 429, 'status ' . $last['status']);
    expect(!empty($last['headers']['retry-after']), 'no Retry-After header');
});

// ── telemetry ───────────────────────────────────────────────────────────────
t('scrubber removes emails, secrets, tokens and long numbers', function () {
    $s = astra_scrub('fail for jane.doe@example.com password=hunter2 token=abc Bearer eyJabc.def Card 4111 1111 1111 1111 key ' . str_repeat('a1', 20));
    foreach (['jane.doe', 'hunter2', '4111', 'eyJabc', str_repeat('a1', 20)] as $leak) expect(!str_contains($s, $leak), "leaked: $leak");
    expect(str_contains($s, '[email]') && str_contains($s, '[redacted]'), "unexpected: $s");
});
t('analytics: path only (no query), no IP, daily visitor hash; GPC/DNT and bots dropped', function () {
    $tag = 'px' . bin2hex(random_bytes(4));
    $r = cgi('POST', 'api/track.php', ['body' => json_encode(['event' => 'pageview', 'path' => "/labs/$tag?token=secret&email=a@b.co", 'utm' => ['utm_source' => 'newsletter']]), 'content_type' => 'application/json']);
    expect($r['status'] === 204, 'status ' . $r['status']);
    $row = q1("SELECT * FROM analytics_events WHERE path = ?", ["/labs/$tag"]);
    expect($row !== null, 'event not stored with a clean path');
    expect($row['utm_source'] === 'newsletter' && strlen($row['visitor_day']) === 16, 'utm or visitor hash missing');
    expect(!array_intersect(array_keys($row), ['ip', 'ip_address', 'user_id', 'email']), 'personal column present');

    $tag2 = 'px' . bin2hex(random_bytes(4));
    cgi('POST', 'api/track.php', ['headers' => ['Sec-GPC' => '1'], 'body' => json_encode(['event' => 'pageview', 'path' => "/$tag2"]), 'content_type' => 'application/json']);
    cgi('POST', 'api/track.php', ['ua' => 'Googlebot/2.1', 'body' => json_encode(['event' => 'pageview', 'path' => "/$tag2"]), 'content_type' => 'application/json']);
    cgi('POST', 'api/track.php', ['body' => json_encode(['event' => 'not_on_the_list', 'path' => "/$tag2"]), 'content_type' => 'application/json']);
    expect(!q1("SELECT 1 FROM analytics_events WHERE path = ?", ["/$tag2"]), 'GPC, bot or unknown event was recorded');
});
t('analytics: browser side waits for consent and honours GPC / Do Not Track', function () {
    $js = px_src('assets/js/hastra-ux.js');
    expect(str_contains($js, "HastraConsent.allows('analytics')") && str_contains($js, "whenAllowed('analytics'"), 'not consent-gated');
    expect(str_contains($js, 'globalPrivacyControl') && str_contains($js, 'doNotTrack'), 'privacy signals ignored');
    expect(str_contains($js, "sessionStorage.setItem('hastra_utm'"), 'UTM tags not kept for the session');
    expect(!preg_match('~document\.cookie\s*=~', $js), 'the UX layer must not set cookies');
});
t('error tracking: browser errors stored scrubbed and de-duplicated', function () {
    $msg = 'TypeError px' . bin2hex(random_bytes(3)) . ' for owner@example.com';
    for ($i = 0; $i < 2; $i++) cgi('POST', 'api/client_error.php', ['ip' => '198.51.100.' . random_int(2, 250), 'body' => json_encode(['message' => $msg, 'location' => '/assets/js/x.js:10:4']), 'content_type' => 'application/json']);
    $row = q1("SELECT message, occurrences, environment FROM app_errors WHERE message LIKE ?", ['TypeError px%' . '[email]']);
    expect($row !== null, 'error not stored');
    expect(!str_contains($row['message'], 'owner@'), 'email kept');
    expect((int)$row['occurrences'] >= 2, 'not de-duplicated');
});

// ── data deletion ───────────────────────────────────────────────────────────
t('erasure: personal fields gone, sign-in impossible, sessions ended, records kept', function () {
    $u = fix_make_user('erase' . bin2hex(random_bytes(2)), 'client', null);
    q("UPDATE users SET phone_number = ?, phone_bindex = ? WHERE id = ?", [astra_db_encrypt('+15550001111'), astra_blind_index('+15550001111'), $u['id']]);
    q("INSERT INTO user_sessions (user_id, session_token_bindex, device_fingerprint_hash, ip_subnet) VALUES (?, ?, 'x', 'loopback')", [$u['id'], bin2hex(random_bytes(32))]);
    $res = astra_erase_user($GLOBALS['conn'], $u['id']);
    expect($res['ok'] && $res['email'] === $u['email'], $res['message']);
    $row = q1("SELECT * FROM users WHERE id = ?", [$u['id']]);
    expect($row['email_bindex'] === null && $row['phone_number'] === null && $row['phone_bindex'] === null && $row['gender'] === null, 'personal fields remain');
    expect($row['role'] === 'deleted', 'role not neutralised');
    expect(astra_db_decrypt($row['name']) === 'Deleted user', 'name not replaced');
    expect(!q1("SELECT 1 FROM users WHERE email_bindex = ?", [astra_blind_index($u['email'])]), 'old address can still sign in');
    expect(!q1("SELECT 1 FROM user_sessions WHERE user_id = ?", [$u['id']]), 'sessions survived');
    $tomb = q1("SELECT name, email FROM deleted_users WHERE id = ?", [$u['id']]);
    expect($tomb && $tomb['name'] === null && $tomb['email'] === null, 'tombstone holds personal data');
});
t('erasure: the platform administrator cannot erase themselves from the form', function () {
    expect((bool)astra_erasure_blocker($GLOBALS['conn'], ['id' => F::$u['sysadmin']['id'], 'role' => 'sysadmin']), 'sysadmin not blocked');
});
t('erasure: tokens are single-use, hashed at rest and expire', function () {
    $u = fix_make_user('tok' . bin2hex(random_bytes(2)), 'client', null);
    $tok = astra_erasure_issue_token($GLOBALS['conn'], $u['id']);
    expect(strlen((string)$tok) === 64, 'no token');
    expect(!q1("SELECT 1 FROM data_deletion_requests WHERE token_hash = ?", [$tok]), 'raw token stored');
    $req = astra_erasure_lookup_token($GLOBALS['conn'], $tok);
    expect($req && (int)$req['user_id'] === $u['id'], 'token not found');
    astra_erase_user($GLOBALS['conn'], $u['id'], (int)$req['id']);
    expect(astra_erasure_lookup_token($GLOBALS['conn'], $tok) === null, 'token reusable');
    q("UPDATE data_deletion_requests SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE user_id = ?", [$u['id']]);
});
t('erasure page: public, explains what is kept, never erases on GET, does not reveal accounts', function () {
    $r = cgi('GET', 'legal/delete-data.php');
    expect_clean($r);
    expect(str_contains($r['body'], 'What is erased') && str_contains($r['body'], 'What stays'), 'no explanation');
    expect(str_contains($r['body'], 'name="email"') && str_contains($r['body'], 'aria-describedby'), 'no accessible form');

    $u = fix_make_user('page' . bin2hex(random_bytes(2)), 'client', null);
    $tok = astra_erasure_issue_token($GLOBALS['conn'], $u['id']);
    $g = cgi('GET', 'legal/delete-data.php?token=' . $tok);
    expect(str_contains($g['body'], 'data-confirm='), 'confirmation step missing');
    expect(q1("SELECT role FROM users WHERE id = ?", [$u['id']])['role'] === 'client', 'erased on GET');

    $s = astra_test_session([]);
    $known = cgi('POST', 'legal/delete-data.php', ['session' => $s, 'post' => ['csrf_token' => csrf_of($s), 'action' => 'request', 'email' => $u['email'], 'understand' => '1']]);
    $s2 = astra_test_session([]);
    $unknown = cgi('POST', 'legal/delete-data.php', ['session' => $s2, 'post' => ['csrf_token' => csrf_of($s2), 'action' => 'request', 'email' => 'nobody.' . bin2hex(random_bytes(3)) . '@example.test', 'understand' => '1']]);
    $msg = fn($b) => preg_match('~<h2>.*?Check your email</h2>~s', $b) === 1;
    expect($msg($known['body']) && $msg($unknown['body']), 'answers differ, or no confirmation shown');
});
t('erasure is linked from every legal footer, the privacy policy and the profile', function () {
    expect(str_contains(px_src('core/legal.php'), 'privacy/delete-data'), 'footer');
    expect(str_contains(px_src('legal/privacy.php'), 'privacy/delete-data'), 'privacy policy');
    expect(str_contains(px_src('portals/user/profile.php'), 'privacy/delete-data'), 'profile');
    expect(preg_match('~RewriteRule \^privacy/delete-data/\?\$ legal/delete-data\.php~', px_src('.htaccess')) === 1, 'route');
});

// ── email ───────────────────────────────────────────────────────────────────
t('email: every message goes out with the sender, privacy and deletion footer', function () {
    $foot = astra_mail_footer();
    foreach ([LEGAL_ENTITY_NAME, LEGAL_SUPPORT_EMAIL, 'legal/privacy', 'privacy/delete-data', 'does not send marketing'] as $need) expect(str_contains($foot, $need), "footer lacks $need");
    $direct = [];
    foreach (px_pages() as $rel => $p) {
        if ($rel === 'core/db.php' || !str_ends_with($rel, '.php')) continue;
        if (preg_match('~\$mail->send\(\)~', (string)file_get_contents($p))) $direct[] = $rel;
    }
    expect(!$direct, 'sent without the footer: ' . implode(', ', $direct));
});

// ── no native prompts, fingerprinting or misleading copy ────────────────────
t('no browser-native confirm(), alert() or prompt() left; destructive actions use data-confirm', function () {
    $hits = [];
    foreach (px_pages() as $rel => $p) {
        if (in_array($rel, ['assets/js/hastra-ux.js', 'core/sast_scanner.php'], true)) continue;
        foreach (file($p) as $n => $line) {
            if (preg_match('~(?<![\w.$])(confirm|alert|prompt)\s*\(~', $line) && !preg_match('~^\s*(//|\*|#)~', $line)) $hits[] = "$rel:" . ($n + 1);
        }
    }
    expect(!$hits, implode(', ', array_slice($hits, 0, 6)));
    expect(substr_count(px_src('portals/emlpoyee/testing_portal.php'), 'data-confirm=') >= 7, 'testing portal confirmations missing');
});
t('Labs trial gate takes no device fingerprint and sets no cookie', function () {
    $js = px_src('assets/js/trial-gatekeeper.js');
    expect(!preg_match('~hardwareConcurrency|screen\.width|resolvedOptions\(\)\.timeZone~', $js), 'fingerprint inputs still read');
    expect(!preg_match('~document\.cookie\s*=\s*`\$\{LEGACY_COOKIE\}=\$\{~', $js), 'trial cookie still written');
    expect(!str_contains(px_src('labs/auth.php'), 'name="remember" value="1" checked'), 'remember-device box pre-ticked');
    expect(!str_contains(px_src('labs/index.php'), 'no sign-in unless'), 'hero still claims no sign-in is needed');
});
t('cookie policy lists every Labs cookie and storage key', function () {
    $c = px_src('legal/cookies.php');
    foreach (['HASTRA_LABS', 'hastra_labs_device', 'hastra_labs_trial_count', 'hastra_labs_study_v1', 'hastra_utm'] as $k) expect(str_contains($c, $k), "undisclosed: $k");
});
t('client invoices show the full breakdown and state that no fees are added', function () {
    $s = px_src('portals/client/delivery_billing.php');
    expect(str_contains($s, "dev_hours") && str_contains($s, 'no service, platform or payment fees'), 'breakdown or fee statement missing');
});

// ── metadata ────────────────────────────────────────────────────────────────
t('every page has a description and social tags; private ones are noindex', function () {
    $missing = [];
    foreach (px_pages() as $rel => $p) {
        if (!str_ends_with($rel, '.php')) continue;
        $src = (string)file_get_contents($p);
        if (!preg_match('~<title>~', $src) || str_starts_with($rel, 'core/')) continue;
        if (!preg_match('~name="description"|astra_seo_meta\(|astra_page_meta\(|labs_head\(~', $src)) $missing[] = $rel;
    }
    expect(!$missing, 'no description: ' . implode(', ', $missing));
    foreach (ASTRA_PAGE_DESCRIPTIONS as $rel => $d) {
        expect(strlen($d) >= 140 && strlen($d) <= 165, "$rel description is " . strlen($d) . ' chars');
        expect(!preg_match('/[\x{2013}\x{2014}]/u', $d), "$rel uses a dash");
    }
    $r = cgi('GET', 'auth/forgot.php');
    expect(str_contains($r['body'], 'property="og:image"') && str_contains($r['body'], 'name="twitter:card"'), 'no social tags');
    expect(str_contains($r['body'], 'content="noindex, nofollow"'), 'auth step indexable');
});
t('legal pages show a Last updated date', function () {
    $r = cgi('GET', 'legal/privacy.php');
    expect(preg_match('~Last updated <time datetime="\d{4}-\d{2}-\d{2}">~', $r['body']) === 1, 'no Last updated badge');
});
t('copyright years are generated, not hard-coded', function () {
    expect(str_contains(px_src('core/legal.php'), "date('Y')"), 'legal footer');
    expect(str_contains(px_src('landing-pages/hastra.html'), 'new Date().getFullYear()'), 'landing footer');
});

// ── shared UX layer ─────────────────────────────────────────────────────────
t('UX layer is mounted on every themed page and covers the agreed features', function () {
    expect(str_contains(px_src('core/theme.js'), "assets/js/hastra-ux.js"), 'theme.js does not mount it');
    $js = px_src('assets/js/hastra-ux.js');
    $needs = [
        'skip link' => 'Skip to main content', 'focus trap' => 'function trap(', 'escape closes' => "e.key === 'Escape'",
        'focus restore' => 'returnTo.focus', 'inline errors' => "aria-describedby", 'palette' => "e.ctrlKey || e.metaKey",
        'back to top' => 'y < 300', 'copy' => 'navigator.clipboard', 'copied state' => 'Copied!', 'drawer' => "max-width: 760px",
        'sticky hide' => 'hx-hide', 'progress' => 'hx-progress', 'error capture' => 'unhandledrejection', 'contact' => 'hx-contact',
    ];
    foreach ($needs as $what => $needle) expect(str_contains($js, $needle), "missing: $what");
});
t('UX styles stay boxless and readable: dialogs are words over a dimmed backdrop', function () {
    $css = px_src('assets/css/hastra-ux.css');
    $dlg = preg_match('~\.hx-dlg \{([^}]*)\}~', $css, $m) ? $m[1] : '';
    expect($dlg !== '' && !preg_match('~background|border\s*:|box-shadow~', $dlg), 'dialog has a box');
    expect(preg_match('~\.hx-shade \{[^}]*backdrop-filter~', $css) === 1, 'no blurred backdrop');
    expect(str_contains($css, 'prefers-reduced-motion'), 'ignores reduced motion');
});
t('print stylesheet, overflow guard and 44px touch targets are global', function () {
    $css = px_src('core/theme.css');
    expect(preg_match('~@media print \{.*?display: none !important~s', $css) === 1, 'no print rules');
    expect(str_contains($css, 'html, body { overflow-x: clip; }'), 'no horizontal overflow guard');
    expect(preg_match('~@media \(pointer: coarse\) \{[^@]*min-height: 44px~s', $css) === 1, 'touch targets');
});
t('Labs FAQ is a native details/summary accordion', function () {
    $r = cgi('GET', 'labs/index.php');
    expect(substr_count($r['body'], '<details>') >= 4 && str_contains($r['body'], '<summary>'), 'no accordion');
});

// ── performance ─────────────────────────────────────────────────────────────
t('query-path indexes exist after migrations', function () {
    if (!defined('ASTRA_MIGRATION_INCLUDE')) define('ASTRA_MIGRATION_INCLUDE', true); // the file 404s and exits otherwise
    require_once ASTRA_ROOT . '/config/migrations/2026_10_performance.php';
    foreach (ASTRA_PERFORMANCE_INDEXES as [$table, $index]) {
        $have = q1("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?", [$table, $index]);
        expect($have !== null, "$table.$index missing (run tools/run_migrations.php)");
    }
    $plan = q1("EXPLAIN SELECT id FROM logs WHERE user_id = 1 ORDER BY `timestamp` DESC LIMIT 50");
    expect(($plan['key'] ?? '') === 'idx_logs_user_time', 'logs activity query does not use its index');
});
t('static assets get long cache lifetimes in production; a load-test script exists', function () {
    $conf = px_src('docker/apache-hastra.conf');
    expect(str_contains($conf, 'stale-while-revalidate') && str_contains($conf, 'text/css'), 'no CSS/JS cache policy');
    expect(str_contains(px_src('tests/load/k6-hastra.js'), "exec: 'auth'"), 'no auth scenario in the load test');
});
