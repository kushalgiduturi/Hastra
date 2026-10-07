<?php
// The unified visual architecture: the landing engine's runtime and cut-out
// assets stay byte-exact, the landing page hosts Hastra's own document
// same-origin, and every page gets the atmosphere stack. Rendering and motion
// are verified in a real browser separately; this pins what can drift silently.
T::group('Visual architecture');

const ENGINE_HASHES = [
    'secret-pathways-assets/three.min.js'                      => '8a5f7249903b54d30f79f708699d2fed2d6a1d0741a4cd41377d1f01bb5a2271',
    'secret-pathways-assets/foreground/png/stone-wall.webp'    => '41c00f017e4ecf2147ee468d74da955bb4e2dad773f75a575022842eaf7609ce',
    'secret-pathways-assets/foreground/png/pine-tree.webp'     => '79b233716d067bbc64c1507f79e4a30ba5f445995158c78562cc5b81f607ede7',
    'secret-pathways-assets/foreground/png/tall-grass.webp'    => '8db0b5fbd160a7225391a6283a99681e346e205f24191031657285ef85ef12d2',
    'secret-pathways-assets/foreground/png/sakura-branch.webp' => '48564194d40496090dbf3bba2a68785cf91fafb655dc8d43ac16f6678aff196d',
    'secret-pathways-assets/foreground/png/maple-leaves.webp'  => '35a90fec62c1a6bbfbbe73cd5d7b1acb889e80546d404182ef9a45fe417b531f',
    'secret-pathways-assets/foreground/png/stone-lantern.webp' => 'd5f3c881bc9d92b72eaaff2b709614d66e21a19e14025d2b9b16a15ab52df3bc',
    'secret-pathways-assets/foreground/png/garden-bush.webp'   => '707e2516ebc0108041fe0ddc26d8bff0a69dc9700641f837765018b64e9ff15e',
    'secret-pathways-assets/foreground/png/basalt-stones.webp' => '150f1c87e181d651c318168c271bb65c9c8abac6dea6f2421fdd081c5b740471',
    'secret-pathways-assets/foreground/png/hill.webp'          => 'ffba816244bcba98e4e33c6ee56165edfe4048db4af122ef3f5822180a85edbc',
    'secret-pathways-assets/foreground/png/ruins.webp'         => '77006e58f2066e6fa9bfc504df396db49b1c7977858fa52d34dd2dad5feced77',
];

function va_doc(): string { return file_get_contents(ASTRA_ROOT . '/landing-pages/hastra.html'); }

t('landing engine runtime and cut-outs are byte-exact (11 files, SHA-256)', function () {
    foreach (ENGINE_HASHES as $rel => $want) {
        $f = ASTRA_ROOT . '/landing-pages/' . $rel;
        expect(is_file($f), "missing $rel");
        expect_eq(hash_file('sha256', $f), $want, $rel);
    }
    // and every asset the document references is there
    preg_match_all('~(?:src|href)="(secret-pathways-assets/[^"]+)"~', va_doc(), $m);
    foreach (array_unique($m[1]) as $rel) expect(is_file(ASTRA_ROOT . '/landing-pages/' . $rel), "hastra.html references missing $rel");
    return count(ENGINE_HASHES) . ' files verified';
});

t('landing content is Hastra: no retired narrative, glyphs or fonts', function () {
    $doc = va_doc();
    expect(str_contains($doc, '<title>Hastra — Enterprise Software Delivery &amp; Governance</title>'), 'document title');
    foreach (['kage', 'kyoto', 'temple', 'sanmon', 'shrine', 'cypress', 'torii', 'japanese', 'notojp', 'wordmark,'] as $w)
        expect(!preg_match('~\b' . $w . '~i', $doc), "hastra.html still says '$w'");
    expect(!preg_match('~[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]~u', $doc), 'hastra.html still carries kana or kanji');
    expect(!preg_match('~class="[^"]*\bjp\b~', $doc), 'a .jp element survived');
    foreach (["const word = 'HASTRA'", 'Initializing cryptographic workspace...', '<div class="word-fb" aria-hidden="true">HASTRA</div>',
              'href="#architecture"', 'href="#security"', 'href="#governance"', 'href="#ledger"', '<b>HASTRA</b><i>ENTERPRISE GOVERNANCE</i>',
              'id="architecture" data-cam="1"', 'id="security" data-cam="2"', 'id="governance" data-cam="3"', 'id="ledger" data-cam="4"',
              '© 2026 Hastra Delivery Network. All rights reserved.', "addColorStop(0, '#98c0ef')", "addColorStop(1, '#d8ecf8')"] as $needle)
        expect(str_contains($doc, $needle), "hastra.html lost: $needle");
    expect(preg_match('~<p class="hero-sub body"[^>]*>([^<]+)</p>~', $doc, $hm) === 1 && !str_contains($hm[1], '—'), 'hero subtitle carries an em dash');
    expect(!is_file(ASTRA_ROOT . '/landing-pages/kage.html') && !is_file(ASTRA_ROOT . '/assets/js/kage-host.js'), 'retired files are back');
});

// Headers straight off Apache, since the framing policy lives in two places.
function va_head(string $path): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0,
                                             'header' => "User-Agent: " . ASTRA_TEST_UA . "\r\n"]]);
    $body = @file_get_contents(ASTRA_WEB_BASE . $path, false, $ctx);
    return [(string)$body, $http_response_header ?? []];
}

t('landing hosts Hastra same-origin; framing is SAMEORIGIN, never cross-site', function () {
    [$body, $h] = va_head('');
    expect(str_contains($body, 'landing-pages/hastra.html'), 'the landing page no longer frames hastra.html');
    expect(str_contains($body, 'allow-same-origin allow-scripts'), 'frame lost its sandbox permissions');
    expect(str_contains($body, 'allow-top-navigation-by-user-activation'), 'Sign In / Get Started cannot leave the frame');
    $hdr = implode("\n", $h);
    expect(!preg_match('~X-Frame-Options:\s*(DENY|ALLOWALL)~i', $hdr), 'X-Frame-Options would block the host or allow anyone');
    expect(preg_match('~X-Frame-Options:\s*SAMEORIGIN~i', $hdr) === 1, 'X-Frame-Options SAMEORIGIN missing');
    preg_match_all('~Content-Security-Policy:\s*(.+)~i', $hdr, $m);
    expect(count($m[1]) > 0, 'no CSP');
    foreach ($m[1] as $csp) {
        expect(str_contains($csp, "frame-ancestors 'self'"), "CSP without frame-ancestors 'self': $csp");
        expect(preg_match("~frame-src[^;]*'self'~", $csp) === 1, "CSP frame-src lacks 'self' (the landing frame would be blocked)");
    }
    return 'SAMEORIGIN + frame-ancestors self';
});

t('every page mounts the shared scene and pointer stack through core/theme.js', function () {
    $js = file_get_contents(ASTRA_ROOT . '/core/theme.js');
    foreach (['landing-host', 'kage-scene', 'sakura-petals-cursor', 'hybrid-hand-cursor', 'cloth-cards', 'handover-seal', 'view-director', 'hastra-atmosphere.css'] as $n) {
        expect(str_contains($js, $n), "theme.js does not mount $n");
        $file = ASTRA_ROOT . (str_ends_with($n, '.css') ? "/assets/css/$n" : "/assets/js/$n.js");
        expect(is_file($file), "missing $file");
    }
    foreach (['assets/js/hastra-atmosphere.js', 'assets/js/cursor-wisps.js'] as $gone)
        expect(!is_file(ASTRA_ROOT . "/$gone"), "retired $gone is back");
    expect(str_contains(file_get_contents(ASTRA_ROOT . '/index.php'), 'data-atmosphere-off'), 'the landing host must opt out');
    // the scene is the landing document itself, in background mode
    $k = file_get_contents(ASTRA_ROOT . '/assets/js/kage-scene.js');
    expect(str_contains($k, "landing-pages/hastra.html?scene=bg") && str_contains($k, "frame.id = 'hastra-kage-scene'"), 'scene host lost its frame');
    expect(str_contains(va_doc(), "qs('scene', '') === 'bg'") && str_contains(va_doc(), 'window.__hastraBg'), 'hastra.html lost background mode');
    // layering: the scene at 0 outside <body>, <body> lifted to 1 above it
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/hastra-atmosphere.css');
    expect(preg_match('~#hastra-kage-scene\s*\{[^}]*position:\s*fixed;\s*inset:\s*0;\s*width:\s*100%;\s*height:\s*100%;\s*z-index:\s*0;\s*pointer-events:\s*none;~', $css) === 1, 'scene canvas placement drifted');
    expect(str_contains($css, 'html.hastra-kage body { position: relative; z-index: 1; }'), 'body is no longer above the scene');
    expect(str_contains($k, 'root.insertBefore(frame, b)'), 'the scene must hang outside <body>');
});

t('dashboards wear the plate; deliverables carry cloth and the handover seal', function () {
    foreach (['portals/admin/admin_portal.php', 'portals/emlpoyee/employee_portal.php', 'portals/emlpoyee/testing_portal.php', 'portals/client/client_portal.php'] as $p)
        expect(str_contains(file_get_contents(ASTRA_ROOT . "/$p"), 'class="main portal-surface-scrim"'), "$p lost its plate");
    foreach (['portals/deliveries/dossier.php' => 'dossier', 'portals/projects/signoff.php' => 'certificate', 'portals/security/scan_center.php' => 'report'] as $p => $kind)
        expect(str_contains(file_get_contents(ASTRA_ROOT . "/$p"), "data-cloth=\"$kind\""), "$p lost data-cloth=\"$kind\"");
    foreach (['portals/deliveries/dossier.php', 'portals/projects/signoff.php'] as $p)
        expect(str_contains(file_get_contents(ASTRA_ROOT . "/$p"), 'data-handover-seal'), "$p lost its handover seal");
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/hastra-atmosphere.css');
    foreach (['rgba(10, 14, 18, 0.88)', 'rgba(255, 255, 255, 0.92)', 'blur(16px)', 'rgba(223, 231, 224, 0.15)', '#cbd5e1', 'linear-gradient(135deg, var(--accent-bright) 0%, var(--accent) 55%, var(--accent-dim) 100%)'] as $v)
        expect(str_contains($css, $v), "plate spec drifted: $v");
    expect(!preg_match('~#663af3|102,\s*58,\s*243~i', $css), 'violet crept back into the plate');
});

t('login: the card is always centred and clickable, never 3-D-projected', function () {
    $l = file_get_contents(ASTRA_ROOT . '/auth/login.php');
    expect(!str_contains($l, 'login-reveal.mp4') && !str_contains($l, 'login-hand-alpha.webm'), 'login should not reference the removed hand video');
    // the 3-D orbit viewport is retired: a login card's position must never
    // depend on the pointer or a camera orbit — it has to be exactly where
    // a user expects it and clickable without a run-up
    expect(!is_file(ASTRA_ROOT . '/assets/js/spatial-login-reveal.js'), 'the 3-D orbit viewport script is back');
    expect(!str_contains($l, 'spatial-login-reveal') && !str_contains($l, 'id="spatial-viewport"') && !str_contains($l, 'spatial-driven'), 'login still wires up the 3-D orbit viewport');
    // .auth-wrapper is the stable frame: full viewport, flex-centred, never
    // itself animated, so the card is always exactly where it should be
    // regardless of what its own depth transform is doing
    expect(str_contains($l, 'class="auth-wrapper"') && str_contains($l, "display: flex; align-items: center; justify-content: center;"), 'the card lost its centred flex frame');
    // dual-column stage: Client Gateway is the default (info left, card right);
    // Enterprise swaps them with a pixel `translate` on the 650ms ease, and
    // only the info text crossfades
    expect(str_contains($l, 'id="auth-stage"') && str_contains($l, 'id="auth-info"') && str_contains($l, 'id="auth-card"'), 'the dual-column stage markup is gone');
    expect(str_contains($l, '=== "enterprise" ? "enterprise" : "client"'), 'Client Gateway is no longer the default portal');
    expect(str_contains($l, 'translate 650ms var(--slide-ease)') && str_contains($l, 'cubic-bezier(0.16, 1, 0.3, 1)') && str_contains($l, 'function layoutStage()'), 'the card/info slide mechanics drifted');
    expect(str_contains($l, 'Autonomous Project Oversight') && str_contains($l, 'SOC Governance'), 'the portal descriptions are missing');
    expect(str_contains($l, 'translate3d(0, 40px, -280px) rotateX(18deg)') && str_contains($l, 'translate3d(0, 0, 0) rotateX(0deg)'), 'the card no longer zooms in from the crack along its depth axis');
    expect(str_contains($l, 'id="crack-particles"') && str_contains($l, "particles.classList.add('go')"), 'the crack spark burst is gone');
    expect(!str_contains($l, 'id="temple-hand-stage"'), 'the hand-rise stage layer should be removed');
    expect(str_contains($l, 'setTimeout(reveal, 8000)'), 'login reveal lost its last-resort timer');
});

t('cursor trail: crimson sakura petals, no glitter', function () {
    $p = file_get_contents(ASTRA_ROOT . '/assets/js/sakura-petals-cursor.js');
    foreach (['bezierCurveTo', '229, 9, 20', '255, 105, 135', '180, 20, 40', 'rnd(7, 14)', 'rnd(1.2, 2.0)', '46 * dt', 'Math.sin(clock * 2.2', 'Math.abs(Math.cos(p.flip))', 'drawImage'] as $need)
        expect(str_contains($p, $need), "petal engine lost: $need");
    expect(!str_contains($p, 'innerHTML'), 'petal engine drifted');
    $h = file_get_contents(ASTRA_ROOT . '/assets/js/hybrid-hand-cursor.js');
    expect(str_contains($h, 'window.HastraPetals') && !str_contains($h, 'createRadialGradient'), 'the shared engine still draws glitter motes');
    $l = file_get_contents(ASTRA_ROOT . '/auth/login.php');
    expect(str_contains($l, 'sakura-petals-cursor.js') && str_contains($l, 'HastraPetals.create(N)') && !str_contains($l, 'createRadialGradient'), 'the sign-in trail is not petals');
    $lp = file_get_contents(ASTRA_ROOT . '/landing-pages/hastra.html');
    expect(strpos($lp, 'sakura-petals-cursor.js') !== false && strpos($lp, 'sakura-petals-cursor.js') < strpos($lp, 'hybrid-hand-cursor.js'), 'the landing page does not load the petals before the engine');
});

t('no hearts: no heart glyphs or curves in any script, and the petal is one smooth lobe', function () {
    $files = array_merge(glob(ASTRA_ROOT . '/assets/js/*.js'), glob(ASTRA_ROOT . '/core/*.js'), [ASTRA_ROOT . '/landing-pages/hastra.html', ASTRA_ROOT . '/auth/login.php', ASTRA_ROOT . '/auth/register.php']);
    foreach ($files as $f) {
        $src = file_get_contents($f);
        expect(!preg_match('~\x{2764}|\x{2665}|\x{2661}|\x{1F495}|\x{1F496}|\x{1F497}|\x{1F493}|\x{1F49D}~u', $src), basename($f) . ' contains a heart glyph');
        expect(!preg_match('~16\s*\*\s*Math\.pow\(\s*Math\.sin|Math\.pow\(\s*Math\.sin\([^)]*\),\s*3\s*\)\s*\*\s*16~', $src), basename($f) . ' draws a heart curve');
    }
    $p = file_get_contents(ASTRA_ROOT . '/assets/js/sakura-petals-cursor.js');
    expect(substr_count($p, 'x.bezierCurveTo') === 3, 'the petal is no longer a single smooth lobe');
    expect(str_contains($p, "hastra_cursor_trail") && str_contains($p, 'HASTRA_NO_TRAIL'), 'the trail lost its off switch');
});
t('performance: 50 fps governors, touch safety and reduced motion', function () {
    $d = va_doc();
    expect(str_contains($d, 'avg > 1 / 50 && !PERF.eco') && str_contains($d, 'Math.ceil(WORLD.leaves.list.length / div)') && str_contains($d, 'div = weak ? 4 : 2') && str_contains($d, 'PERF.scale = Math.min(PERF.scale, .8)'), 'scene governor drifted');
    expect(str_contains($d, 'if (REDUCE && LV.seeded) return;') && str_contains($d, 'RIG.mx = REDUCE ? 0'), 'scene no longer freezes under reduced motion');
    // simplified back down (a dual botanical/crypto stream with scatter
    // bursts and an orbiting idle ring read as too busy for a cursor) to
    // a quiet trail, now crimson sakura petals (sakura-petals-cursor.js); the
    // governor, touch and reduced-motion guarantees carried over unchanged
    $h = file_get_contents(ASTRA_ROOT . '/assets/js/hybrid-hand-cursor.js');
    expect(str_contains($h, '> 1 / 50') && str_contains($h, 'PETALS / 2') && str_contains($h, 'G.scale = .8'), 'pointer governor drifted');
    expect(str_contains($h, '(hover: none)') && str_contains($h, '(pointer: coarse)') && str_contains($h, 'if (TOUCH || !field) return;'), 'trails not disabled on touch');
    expect(str_contains($h, 'prefers-reduced-motion: reduce') && str_contains($h, 'if (REDUCE) return;'), 'no reduced-motion guard');
});
