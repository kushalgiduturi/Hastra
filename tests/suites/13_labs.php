<?php
// Hastra Labs: the free community tools and student hub (labs/).
// The browser-side cryptography is exercised by the self-test on
// /labs/crypto (known-answer vectors + tamper checks); this suite pins the
// server contract: routing, isolation, headers, sessions, APIs, storage.
T::group('Hastra Labs');

// A labs session file and the cookie header that carries it.
function labs_test_session(array $data = []): array {
    $tok = bin2hex(random_bytes(32));
    $id = astra_test_session($data + ['labs_init' => 1, 'labs_csrf' => $tok]);
    return [$id, $tok];
}
function labs_cookie(string $id): array { return ['Cookie' => 'HASTRA_LABS=' . $id]; }
// session_regenerate_id() hands out a new id; follow it like a browser would.
function labs_follow(array $resp, string $id): string {
    foreach ($resp['headers']['set-cookie'] ?? [] as $c) if (preg_match('/^HASTRA_LABS=([^;]+)/', $c, $m) && $m[1] !== 'deleted') $id = $m[1];
    $GLOBALS['__astra_test_sessions'][] = $id;
    return $id;
}

t('every labs page renders for an anonymous visitor, outside enterprise sign-in', function () {
    foreach (['labs/index', 'labs/crypto', 'labs/siem', 'labs/syllabus', 'labs/auth'] as $page) {
        $r = cgi('GET', $page);
        expect_clean($r, $page);
        expect($r['status'] === 200, "$page: HTTP {$r['status']}" . ($r['location'] ? " → {$r['location']}" : ''));
        expect(str_contains($r['body'], 'HASTRA <em>LABS</em>'), "$page: no Labs header");
        expect(str_contains($r['body'], '"importmap"') && str_contains($r['body'], '@noble/post-quantum@0.7.1'), "$page: libraries not pinned through the import map");
        $cookies = implode("\n", $r['headers']['set-cookie'] ?? []);
        expect(!str_contains($cookies, 'PHPSESSID'), "$page: started an enterprise session");
        expect(str_contains($cookies, 'HASTRA_LABS=') && str_contains($cookies, 'path=/Hastra/labs/') && stripos($cookies, 'samesite=lax') !== false,
               "$page: labs session cookie missing or not scoped to /labs/");
    }
    return '5 pages';
});

t('the wider CSP (wasm, blob workers, YouTube no-cookie) applies only under /labs/', function () {
    $csp = implode(' ', cgi('GET', 'labs/crypto')['headers']['content-security-policy'] ?? []);
    foreach (["'wasm-unsafe-eval'", 'worker-src \'self\' blob:', 'https://www.youtube-nocookie.com', 'https://i.ytimg.com', "form-action 'self'", "object-src 'none'"] as $need)
        expect(str_contains($csp, $need), "labs CSP lacks $need");
    expect(!str_contains($csp, 'unsafe-eval\'') || str_contains($csp, 'wasm-unsafe-eval'), 'labs CSP allows full eval');
    expect(!preg_match("/'unsafe-eval'/", str_replace("'wasm-unsafe-eval'", '', $csp)), 'labs CSP allows unsafe-eval');
    $enterprise = implode(' ', cgi('GET', 'auth/login')['headers']['content-security-policy'] ?? []);
    expect($enterprise !== '' && !str_contains($enterprise, 'wasm-unsafe-eval') && !str_contains($enterprise, 'youtube'), 'the enterprise CSP was widened');
    // Apache sends its own copy for /labs/: it must match the PHP one exactly.
    preg_match('/Header always set Content-Security-Policy "([^"]+)"/', file_get_contents(ASTRA_ROOT . '/labs/.htaccess'), $m);
    expect(($m[1] ?? '') === LABS_CSP_EXPECTED(), 'labs/.htaccess CSP drifted from LABS_CSP in labs/_boot.php');
});
// LABS_CSP is a run of concatenated "…" literals; join them (no code is evaluated).
function LABS_CSP_EXPECTED(): string {
    preg_match('/const LABS_CSP = (.+?);\n/s', file_get_contents(ASTRA_ROOT . '/labs/_boot.php'), $m);
    preg_match_all('/"([^"]*)"/', $m[1] ?? '', $parts);
    return implode('', $parts[1]);
}

t('include-only files and the admin tools folder stay off the web', function () {
    $boot = web_get('labs/_boot.php');
    expect(in_array($boot['status'], [403, 404], true), "labs/_boot.php answered {$boot['status']}");
    foreach (['tools/backup_db.php', 'tools/run_migrations.php'] as $p) {
        $r = web_get($p);
        expect(in_array($r['status'], [403, 404], true), "$p answered {$r['status']} — tools/ must never be public");
    }
    $hub = web_get('labs/');
    expect($hub['status'] === 200 && str_contains($hub['body'], 'Hastra Labs'), "labs/ answered {$hub['status']}");
    expect(web_get('labs/crypto.php')['status'] === 301, 'labs/crypto.php should 301 to labs/crypto');
    expect_eq(astra_pretty_path('labs/index.php'), 'labs/', 'pretty path of the hub');
    expect_eq(astra_pretty_path('labs/syllabus.php'), 'labs/syllabus', 'pretty path of a module');
    expect_eq(astra_pretty_path('labs/_boot.php'), null, 'partials have no public address');
});

t('community session: CSRF, a one-time recovery code, and nothing reversible at rest', function () {
    $before = (int)qv("SELECT COUNT(*) FROM labs_profiles");
    [$s, $tok] = labs_test_session();
    $r = cgi('POST', 'labs/auth', ['headers' => labs_cookie($s), 'post' => ['action' => 'start', 'display_name' => 'Audit Student']]);
    expect($r['status'] === 303 && (int)qv("SELECT COUNT(*) FROM labs_profiles") === $before, 'profile created without a CSRF token');

    $r = cgi('POST', 'labs/auth', ['headers' => labs_cookie($s), 'post' => ['csrf_token' => $tok, 'action' => 'start', 'display_name' => 'Audit Student', 'remember' => '1']]);
    expect_clean($r, 'start');
    $s = labs_follow($r, $s);
    $sess = astra_test_session_data($s);
    expect(!empty($sess['labs_profile']), 'not signed in after start');
    expect(preg_match('/^HLAB-(?:[0-9A-HJKMNP-TV-Z]{5}-){3}[0-9A-HJKMNP-TV-Z]{5}$/', (string)($sess['labs_new_code'] ?? '')) === 1, 'no well-formed recovery code');
    $code = $sess['labs_new_code'];
    expect(str_contains(implode("\n", $r['headers']['set-cookie'] ?? []), 'hastra_labs_device='), 'remember-device cookie not set');

    $page = cgi('GET', 'labs/auth', ['headers' => labs_cookie($s)]);
    expect(str_contains($page['body'], $code), 'recovery code not shown after creation');
    $again = cgi('GET', 'labs/auth', ['headers' => labs_cookie($s)]);
    expect(!str_contains($again['body'], $code), 'recovery code shown a second time');

    $row = q1("SELECT * FROM labs_profiles WHERE id = ?", [$sess['labs_profile']]);
    expect(preg_match('/^[0-9a-f]{64}$/', $row['code_bindex']) === 1, 'code stored as something other than a blind index');
    expect(!str_contains(implode('|', $row), substr($code, 5, 5)), 'recovery code stored in the clear');
    expect(str_starts_with($row['display_name'], 'hastra:v1:') && astra_db_decrypt($row['display_name']) === 'Audit Student', 'display name not encrypted');
    $dev = q1("SELECT * FROM labs_devices WHERE profile_id = ?", [$row['id']]);
    expect($dev && preg_match('/^[0-9a-f]{64}$/', $dev['token_bindex']), 'device token not blind-indexed');

    // restore: a sloppy, lower-case copy of the code still works; a wrong one does not
    [$s2, $tok2] = labs_test_session();
    $bad = cgi('POST', 'labs/auth', ['headers' => labs_cookie($s2), 'post' => ['csrf_token' => $tok2, 'action' => 'restore', 'code' => 'HLAB-00000-00000-00000-00000']]);
    expect(empty(astra_test_session_data(labs_follow($bad, $s2))['labs_profile']), 'wrong code signed in');
    $good = cgi('POST', 'labs/auth', ['headers' => labs_cookie($s2), 'post' => ['csrf_token' => $tok2, 'action' => 'restore', 'code' => strtolower(str_replace('-', ' ', $code))]]);
    $s2 = labs_follow($good, $s2);
    expect_eq((int)(astra_test_session_data($s2)['labs_profile'] ?? 0), (int)$row['id'], 'restored profile');

    // delete: needs the typed confirmation, then removes the profile and its devices
    $s = labs_follow(cgi('POST', 'labs/auth', ['headers' => labs_cookie($s), 'post' => ['csrf_token' => astra_test_session_data($s)['labs_csrf'], 'action' => 'delete', 'confirm' => 'nope']]), $s);
    expect(q1("SELECT 1 FROM labs_profiles WHERE id = ?", [$row['id']]) !== null, 'deleted without confirmation');
    cgi('POST', 'labs/auth', ['headers' => labs_cookie($s), 'post' => ['csrf_token' => astra_test_session_data($s)['labs_csrf'], 'action' => 'delete', 'confirm' => 'DELETE']]);
    expect(q1("SELECT 1 FROM labs_profiles WHERE id = ?", [$row['id']]) === null, 'profile not deleted');
    expect(q1("SELECT 1 FROM labs_devices WHERE profile_id = ?", [$row['id']]) === null, 'device tokens outlived the profile');
});

t('progress sync: signed-out 401, CSRF 403, encrypted round trip, size cap', function () {
    [$anon, $atok] = labs_test_session();
    $r = cgi('POST', 'labs/api/progress', ['headers' => labs_cookie($anon) + ['X-CSRF-Token' => $atok], 'body' => '{"progress":{}}', 'content_type' => 'application/json']);
    expect_eq($r['status'], 401, 'signed-out save');

    q("INSERT INTO labs_profiles (code_bindex, display_name, created_at) VALUES (?, ?, NOW())", [bin2hex(random_bytes(32)), astra_db_encrypt('Sync Tester')]);
    $p = ['id' => (int)qv("SELECT MAX(id) FROM labs_profiles")];
    [$s, $tok] = labs_test_session(['labs_profile' => $p['id']]);
    $r = cgi('POST', 'labs/api/progress', ['headers' => labs_cookie($s), 'body' => '{"progress":{}}', 'content_type' => 'application/json']);
    expect_eq($r['status'], 403, 'save without CSRF header');

    $progress = ['v' => 1, 'syllabi' => ['abc' => ['title' => 'Networks', 'done' => ['t1' => ['v' => true, 'ts' => 1]]]], 'updatedAt' => 5];
    $r = cgi('POST', 'labs/api/progress', ['headers' => labs_cookie($s) + ['X-CSRF-Token' => $tok], 'body' => json_encode(['progress' => $progress]), 'content_type' => 'application/json']);
    expect_eq($r['status'], 200, 'save');
    $stored = qv("SELECT progress FROM labs_profiles WHERE id = ?", [$p['id']]);
    expect(str_starts_with((string)$stored, 'hastra:v1:') && !str_contains((string)$stored, 'Networks'), 'progress stored in the clear');
    $back = json_of(cgi('GET', 'labs/api/progress', ['headers' => labs_cookie($s), 'accept' => 'application/json']));
    expect($back['signedIn'] === true && ($back['progress']['syllabi']['abc']['title'] ?? '') === 'Networks', 'progress did not round-trip');

    $big = json_encode(['progress' => ['blob' => str_repeat('x', 600 * 1024)]]);
    $r = cgi('POST', 'labs/api/progress', ['headers' => labs_cookie($s) + ['X-CSRF-Token' => $tok], 'body' => $big, 'content_type' => 'application/json']);
    expect_eq($r['status'], 413, 'oversized save');
    q("DELETE FROM labs_profiles WHERE id = ?", [$p['id']]);
});

t('video API: validates topics, falls back to a view-sorted YouTube link, serves its cache', function () {
    expect_eq(cgi('GET', 'labs/api/youtube?topic=a')['status'], 400, 'one-letter topic');
    $keyed = is_file(ASTRA_ROOT . '/config/youtube.key') || getenv('HASTRA_YOUTUBE_API_KEY');
    if (!$keyed) {
        $j = json_of(cgi('GET', 'labs/api/youtube?topic=' . rawurlencode('Impossible travel detection')));
        expect($j['mode'] === 'link' && $j['reason'] === 'no_api_key', 'expected the no-key link fallback');
        expect_eq($j['query'], 'Impossible travel detection full tutorial engineering', 'query');
        expect(str_ends_with($j['searchUrl'], '&sp=CAM%253D'), 'link is not sorted by view count');
    }
    $query = 'Cache probe ' . F::$suffix . ' full tutorial engineering';
    $payload = json_encode(['mode' => 'ranked', 'query' => $query, 'searchUrl' => 'x', 'videos' => [['id' => 'abcdefghijk', 'title' => 'T', 'channel' => 'C', 'views' => 9, 'duration' => 600]]]);
    q("REPLACE INTO labs_video_cache (query_hash, query_text, payload, fetched_at) VALUES (?, ?, ?, NOW())", [hash('sha256', mb_strtolower($query)), $query, $payload]);
    $j = json_of(cgi('GET', 'labs/api/youtube?topic=' . rawurlencode('Cache probe ' . F::$suffix)));
    expect(($j['cached'] ?? false) === true && $j['videos'][0]['id'] === 'abcdefghijk', 'cache not served');
    return $keyed ? 'API key present: live lookups not exercised' : 'no API key: link fallback';
});

t('client code: pinned libraries, no innerHTML, published test vectors on board', function () {
    preg_match_all("~'(https://cdn\.jsdelivr\.net/npm/[^']+)'~", file_get_contents(ASTRA_ROOT . '/labs/_boot.php'), $m);
    expect(count($m[1]) >= 8, 'library list not found');
    foreach ($m[1] as $url) expect(preg_match('~/npm/(@[a-z-]+/)?[a-z0-9-]+@\d+\.\d+\.\d+/~', $url) === 1, "unpinned library: $url");
    foreach (['labs-common', 'pqc-crypto', 'labs-crypto-ui', 'siem-lab', 'ueba-calculator', 'doc-extract', 'syllabus-engine'] as $f) {
        $js = file_get_contents(ASTRA_ROOT . "/assets/js/$f.js");
        expect(!preg_match('/\.(innerHTML|outerHTML)\s*=|insertAdjacentHTML|document\.write/', $js), "$f.js writes raw HTML");
        expect(!preg_match('/\beval\s*\(|new Function\s*\(/', $js), "$f.js evaluates strings as code");
    }
    $pqc = file_get_contents(ASTRA_ROOT . '/assets/js/pqc-crypto.js');
    foreach (['5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843', '6437b3ac38465133ffb63b75273a8db548c558465d79db03fd359c6cd5bd9d85', 'PBKDF2_ITER = 600000'] as $v)
        expect(str_contains($pqc, $v), "pqc-crypto.js lost $v");
    // every module the pages load is in the import map, so each loads exactly once
    $boot = file_get_contents(ASTRA_ROOT . '/labs/_boot.php');
    foreach (glob(ASTRA_ROOT . '/labs/*.php') as $page) {
        if (preg_match("/labs_foot\(\[(.*?)\]\)/", file_get_contents($page), $mm))
            foreach (array_map(fn($s) => trim($s, " '"), explode(',', $mm[1])) as $mod) expect(str_contains($boot, "'$mod'"), basename($page) . ": $mod missing from LABS_MODULES");
    }
});

t('UEBA tiers and scaling maths match the published formulas', function () {
    // Mirrors of the browser formulas, checked against hand-worked values.
    $w = ['time' => .15, 'geo' => .30, 'volume' => .20, 'c2' => .35];
    $s = ['time' => 62, 'geo' => 88, 'volume' => 62, 'c2' => 91];
    $weighted = array_sum(array_map(fn($k) => $w[$k] * $s[$k], array_keys($w)));
    expect(abs($weighted - 79.95) < 1e-9, 'weighted sum');
    $js = file_get_contents(ASTRA_ROOT . '/assets/js/ueba-calculator.js');
    expect(str_contains($js, 'export const BONUS_CAP = 25;') && str_contains($js, "{ max: 30,  key: 'normal'") && str_contains($js, "{ max: 80,  key: 'high'"), 'tier table or cap changed');
    $eps = 15e6; $size = 700; $kept = 0.6;
    expect(abs($eps * 86400 * $size / 1e12 - 907.2) < 0.01, '15M EPS raw TB/day');
    expect(abs($eps * $size * $kept / 1e6 - 6300) < 1e-6, 'post-filter MB/s');
});

t('landing showcase: announced under the hero, three modules linked to /labs/, no external animation kits', function () {
    $doc = file_get_contents(ASTRA_ROOT . '/landing-pages/hastra.html');
    $hero = strpos($doc, 'id="hero"'); $show = strpos($doc, 'id="labs-showcase"'); $arch = strpos($doc, 'id="architecture"');
    expect($hero !== false && $show > $hero && $show < $arch, 'showcase is not between the hero counters and the first chapter');
    foreach (['[ New Labs release // Season 2026 ]', 'Hastra Cyber Arsenal', 'hx-ping', 'hx-rings'] as $s) expect(str_contains($doc, $s), "showcase lost $s");
    foreach (['crypto', 'siem', 'syllabus'] as $m)
        expect(preg_match('~<a class="ring-power-beam[^"]*" href="\.\./labs/' . $m . '" target="_top"~', $doc) === 1, "no ring-power link to labs/$m (framed page needs target=_top)");
    expect(!preg_match('~<(script|link)[^>]+(src|href)="[^"]*(gsap|tailwind|framer|lucide)|\bimport\b[^;]*[\'"][^\'"]*(gsap|tailwind|framer|lucide)~i',
                       $doc . file_get_contents(ASTRA_ROOT . '/assets/js/labs-showcase.js')), 'external animation kit loaded');
    // a menu link that leaves the page must not be fed to querySelector (it crashed the whole landing boot once)
    expect(str_contains($doc, "h.startsWith('#') ? SECS.indexOf(document.querySelector(h)) : -1"), 'nav chapter lookup no longer skips off-page links');
});

t('ten rings: engine, emblem and the sign-in bracelets', function () {
    $js = file_get_contents(ASTRA_ROOT . '/assets/js/shang-chi-rings.js');
    expect(str_contains($js, 'export function createPowerRings') && str_contains($js, "count || (mode === 'circle' ? 10 : 5)"), 'engine lost its ten-ring circle default');
    foreach (['highlight(', 'boost(', 'burst(', 'power(', 'destroy('] as $api) expect(str_contains($js, $api), "engine lost $api");
    $svg = file_get_contents(ASTRA_ROOT . '/assets/images/hastra-rings-logo.svg');
    for ($i = 0; $i < 10; $i++) expect(str_contains($svg, "id=\"rg$i\""), "emblem is missing ring $i");
    expect(substr_count($svg, 'clip-path="url(#xo') === 10 && substr_count($svg, 'clip-path="url(#xi') === 10, 'emblem rings are no longer woven');
    expect(is_file(ASTRA_ROOT . '/tools/generate_rings_logo.php'), 'emblem generator missing');
    $login = file_get_contents(ASTRA_ROOT . '/auth/login.php');
    expect(str_contains($login, 'assets/js/login-rings.js'), 'sign-in page no longer loads its rings');
    $lr = file_get_contents(ASTRA_ROOT . '/assets/js/login-rings.js');
    // full-viewport canvases over the filtered hand video stalled the page for seconds
    expect(!str_contains($lr, '100vw') && str_contains($lr, 'contain:strict') && str_contains($lr, "'revealed'"), 'sign-in rings regressed to full-screen canvases or never wind down');
});

t('the ten-rings halo stands behind the 3-D wordmark in the landing scene', function () {
    $doc = file_get_contents(ASTRA_ROOT . '/landing-pages/hastra.html');
    expect(str_contains($doc, 'const HALO_N = 10;') && str_contains($doc, 'function buildRingHalo()'), 'halo builder missing');
    expect(str_contains($doc, "if (!BG) { buildWordmark(); buildRingHalo(); }"), 'halo not built with the wordmark (or leaks into the background-only scene)');
    expect(str_contains($doc, 'HALO.group.position.set(0, cy, WORD_Z - 1.2)'), 'halo is no longer placed just behind the glyphs');
    expect(str_contains($doc, 'm.renderOrder = 10;') && str_contains($doc, 'mesh.renderOrder = 12;'), 'halo/glyph render order changed');
    expect(str_contains($doc, 'layoutHalo(fw, fw / (vpW() / vpH())'), 'halo is not laid out from the word');
    expect(str_contains($doc, 'updateHalo(dt, easeOut('), 'halo no longer fades with the word');
    expect(str_contains($doc, 'applyHaloTheme();') && str_contains($doc, '0xc49b39'), 'halo has no day (brass) theme');
});
