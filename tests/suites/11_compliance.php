<?php
// Legal documents, consent, consumer-protection copy, accessibility and
// Google sign-in.
T::group('Compliance & SSO');

function cmp_contrast(string $a, string $b): float {
    $lum = function (string $h) {
        $h = ltrim($h, '#');
        $c = array_map(fn($i) => hexdec(substr($h, $i, 2)) / 255, [0, 2, 4]);
        $c = array_map(fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    [$x, $y] = [$lum($a), $lum($b)];
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}
// The value of a custom property inside a given selector block of a stylesheet.
function cmp_token(string $css, string $selector, string $prop): ?string {
    $at = strpos($css, $selector . ' {');
    if ($at === false) return null;
    $block = substr($css, $at, strpos($css, '}', $at) - $at);
    return preg_match('~' . preg_quote($prop, '~') . ':\s*(#[0-9a-fA-F]{6})~', $block, $m) ? strtolower($m[1]) : null;
}
function cmp_with_google(callable $fn) {
    putenv('ASTRA_GOOGLE_CLIENT_ID=test-client.apps.googleusercontent.com');
    putenv('ASTRA_GOOGLE_CLIENT_SECRET=test-secret');
    try { return $fn(); } finally { putenv('ASTRA_GOOGLE_CLIENT_ID'); putenv('ASTRA_GOOGLE_CLIENT_SECRET'); }
}

t('policy documents render with the facts they must state', function () {
    $need = [
        'privacy' => ['AES-256-GCM', 'blind index', 'HMAC-SHA256', 'Ephemeral dossiers', 'deletion', 'CCPA', 'ip-api.com', 'biometric identifier'],
        'terms'   => ['Acceptable use', 'Multi-tenant', 'SSDLC', 'Dual-key', '99.5%', 'honeytoken', 'IP address', 'Intellectual property'],
        'cookies' => ['Strictly essential', 'Performance and telemetry', 'Security and fraud prevention', 'HttpOnly', 'SameSite=Strict', 'Secure', 'PHPSESSID'],
        'refunds' => ['Billing cycles', 'Solo developer', 'escrow', '30 days', 'Ephemeral dossiers that have been decrypted'],
    ];
    foreach ($need as $doc => $phrases) {
        $r = cgi('GET', "legal/$doc.php");
        expect_clean($r, $doc);
        expect_eq($r['status'], 200, "$doc status");
        foreach ($phrases as $p) expect(str_contains($r['body'], $p), "$doc lacks '$p'");
        expect(str_contains($r['body'], LEGAL_SUPPORT_EMAIL) && str_contains($r['body'], htmlspecialchars(LEGAL_ENTITY_NAME)), "$doc lacks business details");
        expect(str_contains($r['body'], 'id="astra-consent"'), "$doc lacks the consent banner");
    }
    return '4 documents';
});

t('public views carry the legal footer and the consent banner', function () {
    foreach (['auth/login.php', 'auth/register.php', 'auth/forgot.php', 'index.php'] as $page) {
        $r = cgi('GET', $page);
        expect_clean($r, $page);
        expect(str_contains($r['body'], 'id="astra-consent"') && str_contains($r['body'], 'cookie-consent.js'), "$page: no consent banner");
        if ($page === 'index.php') continue; // the landing footer lives in the framed document
        foreach (array_keys(ASTRA_LEGAL_DOCS) as $d) expect(str_contains($r['body'], "legal/$d"), "$page: no link to $d");
        expect(str_contains($r['body'], LEGAL_SUPPORT_EMAIL) && str_contains($r['body'], htmlspecialchars(LEGAL_ENTITY_NAME)), "$page: no business details");
        if (LEGAL_COMPANY_ID === '') expect(!str_contains($r['body'], 'Company ID'), "$page: shows an empty company ID");
        expect(!str_contains($r['body'], 'set LEGAL_'), "$page: still shows a config placeholder");
    }
    $doc = file_get_contents(ASTRA_ROOT . '/landing-pages/astra.html');
    foreach (['../legal/privacy', '../legal/terms', '../legal/cookies', '../legal/refunds', LEGAL_SUPPORT_EMAIL] as $n)
        expect(str_contains($doc, $n), "landing footer lacks $n");
});

t('consent banner: essential locked on, others off until chosen, no dismiss without a choice', function () {
    $b = file_get_contents(ASTRA_ROOT . '/legal/_cookie_banner.php');
    expect(preg_match('~<input type="checkbox" checked disabled~', $b) === 1, 'essential toggle is not locked on');
    expect(preg_match_all('~data-consent-cat="(analytics|thirdparty)"~', $b) === 2, 'analytics / third-party toggles missing');
    expect(!preg_match('~data-consent-cat="[^"]+"[^>]*\bchecked\b~', $b), 'a non-essential toggle is pre-checked');
    expect(!str_contains(strtolower($b), 'aria-label="close'), 'banner can be closed without choosing');
    $js = file_get_contents(ASTRA_ROOT . '/assets/js/cookie-consent.js');
    expect(str_contains($js, "'astra_consent_state'") && str_contains($js, 'Click to load external asset'), 'consent engine lost its key or placeholder');
    expect(str_contains(file_get_contents(ASTRA_ROOT . '/auth/register.php'), "AstraConsent.allows('thirdparty')"), 'logo lookups are not consent-gated');
});

t('affirmative consent: never pre-checked, and enforced server-side', function () {
    foreach (['auth/register.php', 'portals/client/submit_requirement.php'] as $f) {
        $src = file_get_contents(ASTRA_ROOT . "/$f");
        expect(preg_match('~<input type="checkbox" name="accept_terms"[^>]*>~', $src, $m) === 1, "$f: no consent checkbox");
        expect(!preg_match('~\bchecked\b~', $m[0]), "$f: consent checkbox is pre-checked");
        expect(str_contains($src, 'I accept the') && str_contains($src, 'Terms and Conditions') && str_contains($src, 'Privacy Policy'), "$f: wording");
    }
    $email = 'noconsent.' . F::$suffix . '@example.test';
    $s = astra_test_session([]);
    $r = cgi('POST', 'auth/register.php', ['session' => $s, 'post' => [
        'csrf_token' => csrf_of($s), 'flow' => 'client_individual', 'name' => 'No Consent', 'email' => $email,
        'phone_number' => '+919876500077', 'password' => FIX_PASSWORD, 'confirm' => FIX_PASSWORD, 'country' => 'India']]);
    expect_clean($r);
    expect(!str_contains((string)$r['location'], 'verify_register'), 'registration accepted without consent');
    expect(!q1("SELECT 1 FROM pending_registrations WHERE email_bindex = ?", [astra_blind_index($email)]), 'pending row created without consent');

    $title = 'No consent ' . F::$suffix;
    $s = as_user('client');
    $r = cgi('POST', 'portals/client/submit_requirement.php', ['session' => $s, 'post' => [
        'csrf_token' => csrf_of($s), 'project_title' => $title, 'requirement_title' => $title, 'description' => 'x',
        'expected_features' => '', 'budget_min' => '', 'budget_max' => '', 'deadline' => '']]);
    expect_clean($r);
    expect(!q1("SELECT 1 FROM requirements WHERE project_title = ?", [$title]), 'requirement stored without consent');
});

t('recorded consent: version and time travel from sign-up to the account', function () {
    $email = 'consent.' . F::$suffix . '@example.test';
    $s = astra_test_session([]);
    mail_clear();
    cgi('POST', 'auth/register.php', ['session' => $s, 'post' => [
        'csrf_token' => csrf_of($s), 'accept_terms' => '1', 'flow' => 'client_individual', 'name' => 'Consent Person', 'email' => $email,
        'phone_number' => '+919876500078', 'password' => FIX_PASSWORD, 'confirm' => FIX_PASSWORD, 'country' => 'India']]);
    $p = q1("SELECT terms_accepted_at, terms_version FROM pending_registrations WHERE email_bindex = ?", [astra_blind_index($email)]);
    expect($p && $p['terms_accepted_at'] && $p['terms_version'] === LEGAL_TERMS_VERSION, 'consent not recorded on the pending row');
    $code = mail_code_to($email);
    cgi('POST', 'auth/verify_register.php', ['session' => $s, 'post' => ['csrf_token' => csrf_of($s), 'action' => 'verify_otp', 'otp' => $code]]);
    $u = q1("SELECT terms_accepted_at, terms_version FROM users WHERE email_bindex = ?", [astra_blind_index($email)]);
    expect($u && $u['terms_accepted_at'] && $u['terms_version'] === LEGAL_TERMS_VERSION, 'consent not carried onto the account');
});

t('landing copy: no fake social proof or absolute claims', function () {
    $doc = file_get_contents(ASTRA_ROOT . '/landing-pages/astra.html');
    foreach (['unhackable', 'bug free', 'bug-free', 'instant delivery', 'zero-knowledge', '5220.22', 'immutable audit', 'edge nodes',
              'plaintext exposure', 'testimonial', '★', 'trusted by', 'node [frankfurt'] as $bad)
        expect(!str_contains(strtolower($doc), $bad), "landing still says '$bad'");
    foreach (['AES-256', 'Column Encryption', 'HMAC', 'Chained Audit Integrity', 'Tamper-Evident Audit Ledger'] as $good)
        expect(str_contains($doc, $good), "landing lost verifiable claim '$good'");
    $login = file_get_contents(ASTRA_ROOT . '/auth/login.php');
    expect(!str_contains($login, 'All systems operational'), 'login shows an unbacked status claim');
});

t('accessibility: focus ring, labelled controls, tabs, alerts', function () {
    expect(preg_match('~\*:focus-visible\s*\{\s*outline:\s*2px solid~', file_get_contents(ASTRA_ROOT . '/core/theme.css')) === 1, 'no global focus ring');
    $login = cgi('GET', 'auth/login.php')['body'];
    expect(str_contains($login, 'aria-controls="panel-enterprise"') && str_contains($login, 'role="tabpanel"'), 'portal tabs not wired to panels');
    expect(str_contains($login, 'aria-label="Switch between light and dark theme"'), 'theme toggle unlabelled');
    expect(str_contains($login, 'aria-label="Sign in with your Google account"'), 'Google button unlabelled');
    foreach (glob(ASTRA_ROOT . '/portals/*/_nav.php') as $f)
        expect(str_contains(file_get_contents($f), 'aria-label="Switch between light and dark theme"'), basename(dirname($f)) . ' nav theme toggle unlabelled');
    $dock = file_get_contents(ASTRA_ROOT . '/core/dock.php');
    expect(str_contains($dock, "aria-hidden=\"true\" focusable=\"false\"") && str_contains($dock, "aria-label=\"' . htmlspecialchars(\$item['label'])"), 'dock icons/links not labelled');
    expect(str_contains(file_get_contents(ASTRA_ROOT . '/core/theme.js'), "[role=\"button\"], [role=\"radio\"]"), 'no keyboard parity for custom controls');
    $r = cgi('GET', 'auth/login.php?google_error=cancelled');
    expect(str_contains($r['body'], 'role="alert"') && str_contains($r['body'], 'Google sign-in was cancelled.'), 'errors are not announced');
    $r = cgi('GET', 'auth/login.php?google_error=%3Cscript%3Ealert(1)%3C%2Fscript%3E');
    expect(!str_contains($r['body'], '<script>alert(1)'), 'google_error is reflected');
});

t('contrast: text tokens meet WCAG AA 4.5:1 on their surfaces', function () {
    $theme = file_get_contents(ASTRA_ROOT . '/core/theme.css');
    $kit   = file_get_contents(ASTRA_ROOT . '/assets/css/theme-authkit.css');
    $pairs = [
        ['dark text-dim on canvas',        cmp_token($theme, ':root', '--text-dim'),               cmp_token($theme, ':root', '--navy')],
        ['dark text-dim on card',          cmp_token($theme, ':root', '--text-dim'),               cmp_token($theme, ':root', '--navy-card')],
        ['light text-dim on canvas',       cmp_token($theme, '[data-theme="light"]', '--text-dim'), cmp_token($theme, '[data-theme="light"]', '--navy')],
        ['light accent-bright on white',   cmp_token($theme, '[data-theme="light"]', '--accent-bright'), '#ffffff'],
        ['white on light accent',          '#ffffff', cmp_token($theme, '[data-theme="light"]', '--accent')],
        ['white on dark accent',           '#ffffff', cmp_token($theme, ':root', '--accent')],
        ['dark fog-veil on midnight',      cmp_token($kit, ':root', '--color-fog-veil'),           cmp_token($kit, ':root', '--color-midnight-canvas')],
        ['light fog-veil on white',        cmp_token($kit, '[data-theme="light"]', '--color-fog-veil'), '#ffffff'],
        ['light moon-mist on white',       cmp_token($kit, '[data-theme="light"]', '--color-moon-mist'), '#ffffff'],
    ];
    $out = [];
    foreach ($pairs as [$label, $fg, $bg]) {
        expect($fg && $bg, "$label: token not found");
        $ratio = cmp_contrast($fg, $bg);
        expect($ratio >= 4.5, sprintf('%s is %.2f:1', $label, $ratio));
        $out[] = sprintf('%.1f', $ratio);
    }
    return 'min ' . min($out) . ':1';
});

t('session cookie flags and CSP allow only Google as an extra form target', function () {
    $r = cgi('GET', 'auth/login.php');
    $cookie = implode("\n", $r['headers']['set-cookie'] ?? []);
    expect(str_contains($cookie, 'PHPSESSID=') && stripos($cookie, 'HttpOnly') !== false && stripos($cookie, 'SameSite=Strict') !== false, "session cookie flags: $cookie");
    $csp = implode("\n", $r['headers']['content-security-policy'] ?? []);
    expect(str_contains($csp, "form-action 'self' https://accounts.google.com"), 'PHP CSP form-action');
    expect(str_contains(file_get_contents(ASTRA_ROOT . '/.htaccess'), "form-action 'self' https://accounts.google.com;"), '.htaccess CSP form-action');
});

t('google: falls back cleanly when not configured, starts when it is', function () {
    $r = cgi('GET', 'auth/google_auth.php?action=login');
    if (astra_google_configured()) {
        expect(str_starts_with((string)$r['location'], 'https://accounts.google.com/'), 'configured Google did not start');
        return 'configured on this server';
    }
    expect(str_contains((string)$r['location'], 'google_error=not_configured'), 'unconfigured Google did not fall back');
    return 'not configured on this server';
});

t('google: sign-in starts an S256 PKCE request with a sealed Lax state cookie', function () {
    return cmp_with_google(function () {
        $r = cgi('GET', 'auth/google_auth.php?action=login');
        $loc = (string)$r['location'];
        expect(str_starts_with($loc, 'https://accounts.google.com/o/oauth2/v2/auth?'), "no redirect to Google: $loc");
        parse_str((string)parse_url($loc, PHP_URL_QUERY), $qs);
        expect_eq($qs['client_id'] ?? '', 'test-client.apps.googleusercontent.com', 'client_id');
        expect_eq($qs['code_challenge_method'] ?? '', 'S256', 'PKCE method');
        expect(strlen($qs['state'] ?? '') === 64 && strlen($qs['nonce'] ?? '') === 64 && strlen($qs['code_challenge'] ?? '') === 43, 'state/nonce/challenge');
        expect_eq($qs['redirect_uri'] ?? '', GOOGLE_REDIRECT_URI, 'redirect_uri');
        $set = implode("\n", $r['headers']['set-cookie'] ?? []);
        expect(preg_match('~astra_goauth=([^;]+);~', $set, $m) === 1, 'no attempt cookie');
        expect(stripos($set, 'HttpOnly') !== false && stripos($set, 'SameSite=Lax') !== false, "attempt cookie flags: $set");
        $sealed = urldecode($m[1]);
        expect(str_starts_with($sealed, 'astra:v1:'), 'attempt cookie is not encrypted');
        $a = json_decode((string)astra_decrypt($sealed), true);
        expect(($a['state'] ?? '') === $qs['state'] && hash('sha256', $a['verifier'], true) !== '', 'sealed state does not match');
        expect(rtrim(strtr(base64_encode(hash('sha256', $a['verifier'], true)), '+/', '-_'), '=') === $qs['code_challenge'], 'challenge is not S256(verifier)');

        // wrong state, and a forged (unencrypted) cookie, are both refused
        $bad = cgi('GET', 'auth/google_auth.php?state=' . str_repeat('0', 64) . '&code=x', ['headers' => ['Cookie' => 'astra_goauth=' . $m[1]]]);
        expect(str_contains((string)$bad['location'], 'google_error=expired'), 'wrong state accepted');
        $forged = urlencode(json_encode(['state' => 'abc', 'started' => time()]));
        $bad = cgi('GET', 'auth/google_auth.php?state=abc&code=x', ['headers' => ['Cookie' => "astra_goauth=$forged"]]);
        expect(str_contains((string)$bad['location'], 'google_error=expired'), 'forged attempt cookie accepted');
    });
});

t('google: sign-up validates consent and the workspace before leaving Astra', function () {
    return cmp_with_google(function () {
        $s = astra_test_session([]);
        $r = cgi('POST', 'auth/google_auth.php', ['session' => $s, 'post' => [
            'csrf_token' => csrf_of($s), 'action' => 'register', 'flow' => 'client_individual', 'country' => 'India']]);
        expect(str_contains((string)$r['location'], 'google_error=terms_required'), 'sign-up without consent was not refused');
        $taken = qv("SELECT company_name FROM companies WHERE is_internal = 0 LIMIT 1");
        $r = cgi('POST', 'auth/google_auth.php', ['session' => $s, 'post' => [
            'csrf_token' => csrf_of($s), 'action' => 'register', 'accept_terms' => '1', 'flow' => 'client_company',
            'company_name' => $taken, 'company_size' => '11-50']]);
        expect(str_contains((string)$r['location'], 'google_error=company_taken'), 'existing company not refused');
        $r = cgi('POST', 'auth/google_auth.php', ['session' => $s, 'post' => [
            'csrf_token' => csrf_of($s), 'action' => 'register', 'accept_terms' => '1', 'flow' => 'enterprise_solo',
            'company_name' => 'Solo ' . F::$suffix, 'country' => 'India']]);
        expect(str_starts_with((string)$r['location'], 'https://accounts.google.com/'), 'valid sign-up did not go to Google');
        $r = cgi('POST', 'auth/google_auth.php', ['post' => ['action' => 'register', 'accept_terms' => '1', 'flow' => 'enterprise_solo']]);
        expect(!str_starts_with((string)$r['location'], 'https://accounts.google.com/'), 'sign-up without CSRF token went through');
    });
});

t('google: verified identities link by email, never re-bind, and provision new tracks', function () {
    $conn = $GLOBALS['conn'];
    $emp = F::$u['employee'];
    $sub = 'g-' . F::$suffix . '-1';
    [$u, $err] = astra_google_find_user($conn, ['google_id' => $sub, 'email' => $emp['email'], 'name' => 'x', 'picture' => '']);
    expect($u && (int)$u['id'] === $emp['id'] && $err === null, 'existing account not found by email');
    expect_eq(qv("SELECT google_id_bindex FROM users WHERE id = ?", [$emp['id']]), astra_blind_index($sub), 'google id not bound as a blind index');
    [$u, $err] = astra_google_find_user($conn, ['google_id' => 'g-other', 'email' => $emp['email'], 'name' => 'x', 'picture' => '']);
    expect($u === null && $err === 'google_mismatch', 'a second Google account was allowed onto the same Astra account');
    [$u] = astra_google_find_user($conn, ['google_id' => $sub, 'email' => 'changed.' . F::$suffix . '@example.test', 'name' => 'x', 'picture' => '']);
    expect($u && (int)$u['id'] === $emp['id'], 'bound account not found by Google id after an email change');

    $email = 'google.new.' . F::$suffix . '@example.test';
    $made = astra_google_provision($conn, ['google_id' => 'g-' . F::$suffix . '-new', 'email' => $email, 'name' => 'Grace Google', 'picture' => ''],
        ['flow' => 'enterprise_full', 'company_name' => 'G Org ' . F::$suffix, 'company_size' => '11-50', 'country' => '', 'contract_ref' => '',
         'leave_cycle' => 'monthly', 'monthly_general_leaves' => 2, 'monthly_sick_leaves' => 1, 'annual_leave_allowance' => 18]);
    expect(isset($made['id']) && $made['role'] === 'admin', 'provisioning failed: ' . json_encode($made));
    $row = q1("SELECT * FROM users WHERE id = ?", [$made['id']]);
    expect(str_starts_with($row['email'], 'astra:v1:') && str_starts_with($row['name'], 'astra:v1:'), 'Google account PII not encrypted');
    expect($row['google_id_bindex'] === astra_blind_index('g-' . F::$suffix . '-new'), 'new account not bound to its Google id');
    expect($row['terms_version'] === LEGAL_TERMS_VERSION && $row['terms_accepted_at'], 'consent not recorded for the Google account');
    expect(astra_profile_barrier_needed($conn, (int)$made['id']), 'new Google account skips the profile barrier');
    expect_eq(qv("SELECT account_type FROM companies WHERE id = ?", [$row['company_id']]), 'full_org', 'account type');
    $dup = astra_google_provision($conn, ['google_id' => 'g-x', 'email' => 'x' . $email, 'name' => 'X', 'picture' => ''],
        ['flow' => 'enterprise_full', 'company_name' => 'G Org ' . F::$suffix, 'company_size' => '11-50']);
    expect(($dup['error'] ?? '') === 'company_taken', 'duplicate organization provisioned');
    return 'account ' . $made['id'];
});

t('no purple anywhere: every colour literal stays off the violet hue band', function () {
    $skip = ['/_backup', '/tests/', '/Claude outputs', '/PHPMailer', '/uploads', 'secret-pathways-assets', '/.claude', '/.git/'];
    $root = str_replace('\\', '/', realpath(ASTRA_ROOT));
    $prune = ['.git', '.claude', 'tests', 'PHPMailer', 'uploads', 'node_modules', 'secret-pathways-assets', 'Claude outputs', 'backups'];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn($f) => !$f->isDir() || (!in_array($f->getFilename(), $prune, true) && !str_starts_with($f->getFilename(), '_backup'))));
    $bad = []; $files = 0;
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (!preg_match('~\.(php|css|js|html|svg)$~', $p)) continue;
        foreach ($skip as $s) if (str_contains($p, $s)) continue 2;
        $files++;
        // hex, rgb()/rgba(), [r, g, b] arrays, and bare triples in '--x-rgb: r,g,b;' or 'r,g,b' strings
        preg_match_all('~#([0-9a-fA-F]{6})\b|rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})|\[\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\]|(?:-rgb\s*:\s*|[\'"])(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*[;\'"]~', file_get_contents($p), $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            [$r, $g, $b] = !empty($x[1]) ? array_map('hexdec', str_split($x[1], 2))
                         : (($x[2] ?? '') !== '' ? [$x[2], $x[3], $x[4]]
                         : (($x[5] ?? '') !== '' ? [$x[5], $x[6], $x[7]] : [$x[8], $x[9], $x[10]]));
            [$r, $g, $b] = [(int)$r / 255, (int)$g / 255, (int)$b / 255];
            if ($r > 1 || $g > 1 || $b > 1) continue;
            $mx = max($r, $g, $b); $mn = min($r, $g, $b); $d = $mx - $mn; $l = ($mx + $mn) / 2;
            if ($d == 0 || $l <= .12 || $l >= .93) continue;
            $sat = $l > .5 ? $d / (2 - $mx - $mn) : $d / ($mx + $mn);
            $h = $mx == $r ? fmod(($g - $b) / $d, 6) : ($mx == $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4);
            $h = $h * 60 < 0 ? $h * 60 + 360 : $h * 60;
            if ($h >= 245 && $h <= 330 && $sat >= .25) $bad[] = substr($p, strlen($root) + 1) . ': ' . trim($x[0]);
        }
    }
    expect(!$bad, 'purple colours: ' . implode(', ', array_slice(array_unique($bad), 0, 8)));
    return "$files files clean";
});
