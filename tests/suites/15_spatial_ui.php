<?php
// The spatial UI ("Quiet glass", docs/spatial-ui/DESIGN.md): glass layers over
// the 3-D temple world. This suite pins the system's contract: tokens in both
// themes, the stylesheet on every themed page, the fallbacks, the blur budget,
// no nested glass, no long dashes in the new files, and AA contrast for every
// text-on-glass pair computed from the token values themselves.
T::group('Spatial UI');

function sp_src(string $rel): string { return str_replace("\r\n", "\n", file_get_contents(ASTRA_ROOT . '/' . $rel)); }

// First block that opens with exactly this selector.
function sp_block(string $css, string $selector): string {
    $at = strpos($css, $selector . ' {');
    if ($at === false) return '';
    return substr($css, $at, strpos($css, "\n}", $at) - $at);
}
function sp_token(string $block, string $name): ?string {
    return preg_match('~(?<![\w-])' . preg_quote($name, '~') . ':\s*([^;]+);~', $block, $m) ? trim($m[1]) : null;
}
// '#rrggbb' or 'rgba(r, g, b, a)' to [r, g, b, a]; null for anything else.
function sp_rgba(?string $v): ?array {
    if ($v === null) return null;
    if (preg_match('~^#([0-9a-f]{6})$~i', $v, $m)) return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2)), 1.0];
    if (preg_match('~^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$~', $v, $m))
        return [(float)$m[1], (float)$m[2], (float)$m[3], isset($m[4]) && $m[4] !== '' ? (float)$m[4] : 1.0];
    return null;
}
function sp_over(array $top, array $below): array {
    $a = $top[3];
    return [$top[0] * $a + $below[0] * (1 - $a), $top[1] * $a + $below[1] * (1 - $a), $top[2] * $a + $below[2] * (1 - $a), 1.0];
}
function sp_ratio(array $fg, array $bg): float {
    $l = function (array $c): float {
        $f = fn($v) => ($v /= 255) <= .03928 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4;
        return .2126 * $f($c[0]) + .7152 * $f($c[1]) + .0722 * $f($c[2]);
    };
    [$a, $b] = [$l($fg), $l($bg)];
    return (max($a, $b) + .05) / (min($a, $b) + .05);
}

// The world as it reaches the glass, per theme. Measured on 2026-11-07 from
// full-page captures of every signed-in page at 1440 wide: the scene in the
// side margins outside the panel, Gaussian-blurred at 18px like the panel's
// backdrop, then the luminance extreme that is worst for that theme's text
// (brightest by night, darkest by day).
//   dark:  portal margins p99 #23201a, landing frame p95 #44454a; ground #4a4a4a
//          (only the moon's red disc is brighter: p100 #e86659)
//   light: portal margins p5 #727478 (median #f9fafc); ground #727478
const SP_GROUND_DARK  = [0x4a, 0x4a, 0x4a, 1.0];
const SP_GROUND_LIGHT = [0x72, 0x74, 0x78, 1.0];

t('tokens: every spatial token is defined by night, and the themed ones again by day', function () {
    $css = sp_src('assets/css/theme-spatial.css');
    $dark = sp_block($css, 'html.sp-ui');
    $light = sp_block($css, 'html.sp-ui[data-theme="light"]');
    expect($dark !== '' && $light !== '', 'token blocks not found');
    foreach (['--sp-font-ui', '--sp-font-mono', '--sp-fs-md', '--sp-r-panel', '--sp-r-pill', '--sp-accent', '--sp-accent-text', '--sp-on-accent', '--sp-ring',
              '--sp-text', '--sp-text-2', '--sp-text-3', '--sp-ok', '--sp-warn', '--sp-bad', '--sp-bad-solid', '--sp-info', '--sp-hair',
              '--sp-fill-1', '--sp-fill-2', '--sp-fill-3', '--sp-fill-4', '--sp-solid-1', '--sp-solid-2', '--sp-solid-3', '--sp-solid-4',
              '--sp-blur-2', '--sp-blur-3', '--sp-blur-4', '--sp-edge', '--sp-shadow-2', '--sp-shadow-4', '--sp-ease-spring', '--sp-dur-2',
              '--sp-world-wash', '--sp-dim', '--sp-z-modal'] as $tok)
        expect(sp_token($dark, $tok) !== null, "dark token missing: $tok");
    foreach (['--sp-accent', '--sp-accent-text', '--sp-text', '--sp-text-2', '--sp-text-3', '--sp-ok', '--sp-warn', '--sp-bad', '--sp-info',
              '--sp-hair', '--sp-fill-1', '--sp-fill-2', '--sp-fill-3', '--sp-fill-4', '--sp-solid-1', '--sp-solid-4', '--sp-edge-hi',
              '--sp-shadow-2', '--sp-world-wash', '--sp-world-filter', '--sp-dim'] as $tok)
        expect(sp_token($light, $tok) !== null, "light token missing: $tok");
    expect(sp_token($dark, '--sp-accent') === '#e50914' && sp_token($light, '--sp-accent') === '#2563eb', 'brand accents changed (crimson by night, cobalt by day)');
    expect(sp_token($dark, '--sp-text') === '#ffffff' && sp_token($light, '--sp-text') === '#000000', 'primary type is not pure white / jet black');
    $land = sp_src('assets/css/landing-spatial.css');
    foreach ([':root', '[data-theme="light"]'] as $sel)
        foreach (['--lsp-text', '--lsp-text-2', '--lsp-text-3', '--lsp-accent', '--lsp-accent-text', '--lsp-fill-2', '--lsp-fill-3'] as $tok)
            expect(sp_token(sp_block($land, $sel), $tok) !== null, "landing token missing in $sel: $tok");
});

t('every themed page loads theme-spatial.css after its legacy stylesheets; the landing loads its own last', function () {
    $missing = $order = [];
    $root = str_replace(chr(92), '/', ASTRA_ROOT);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ASTRA_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace(chr(92), '/', $f->getPathname());
        if (substr($p, -4) !== '.php' || preg_match('~/(_backup[^/]*|tests|Claude outputs|tmp|vendor|PHPMailer)/~', $p)) continue;
        $src = file_get_contents($p);
        if (!preg_match('~href="[^"]*core/theme\.css\?v=~', $src)) continue;
        $rel = substr($p, strlen($root) + 1);
        $sp = strpos($src, 'assets/css/theme-spatial.css');
        if ($sp === false) { $missing[] = $rel; continue; }
        if ($sp < strpos($src, 'assets/css/theme-contrast.css')) $order[] = $rel;
    }
    expect(!$missing, 'themed pages without theme-spatial.css: ' . implode(', ', $missing));
    expect(!$order, 'theme-spatial.css loads before theme-contrast.css on: ' . implode(', ', $order));
    expect(str_contains(sp_src('core/theme.js'), "classList.add('sp-ui')"), 'core/theme.js no longer sets html.sp-ui');
    $land = sp_src('landing-pages/hastra.html');
    preg_match_all('~<link rel="stylesheet" href="([^"]+)"~', $land, $m);
    expect(str_contains(end($m[1]), 'assets/css/landing-spatial.css'), 'landing-spatial.css is not the last stylesheet on the landing page');
});

t('fallbacks: low-perf, reduced transparency, reduced motion, coarse pointer and forced colours', function () {
    $css = sp_src('assets/css/theme-spatial.css');
    $lp = sp_block($css, 'html.sp-ui.low-perf-mode');
    expect(str_contains($lp, '--sp-blur-2: 0px') && str_contains($lp, '--sp-fill-2: var(--sp-solid-2)'), 'low-perf mode keeps blur or translucent fills');
    // the light token block outranks html.sp-ui alone, so the fallback must name it too
    expect((bool)preg_match('~@media \(prefers-reduced-transparency: reduce\) \{\s*html\.sp-ui, html\.sp-ui\[data-theme="light"\] \{~', $css), 'reduced transparency does not reach the light theme');
    foreach (['prefers-reduced-transparency: reduce' => '--sp-fill-2: var(--sp-solid-2)', 'prefers-reduced-motion: reduce' => '--sp-tilt-max: 0deg',
              'pointer: coarse' => '--sp-glow-opacity: 0', 'forced-colors: active' => 'background: Canvas !important'] as $mq => $needle) {
        $at = strpos($css, "@media ($mq)");
        expect($at !== false, "no @media ($mq) fallback");
        expect(str_contains(substr($css, $at, 900), $needle), "@media ($mq) fallback lost: $needle");
    }
    $land = sp_src('assets/css/landing-spatial.css');
    expect(str_contains($land, 'html.low-perf-mode { --lsp-blur-2: 0px;') && str_contains($land, '@media (prefers-reduced-transparency: reduce)')
        && str_contains($land, '@media (prefers-reduced-motion: reduce)'), 'landing chrome lost a fallback');
    $fx = sp_src('assets/js/spatial-fx.js');
    expect(str_contains($fx, 'prefers-reduced-motion') && str_contains($fx, '(pointer: coarse)') && str_contains($fx, 'low-perf-mode'), 'spatial-fx.js ignores a fallback');
});

t('blur budget: never above 24px, never nested, and never on the bar that holds the mobile sheet', function () {
    $files = ['assets/css/theme-spatial.css', 'assets/css/landing-spatial.css', 'assets/css/sidebar-nav.css', 'core/dock.css',
              'assets/css/tour.css', 'assets/css/profile-barrier.css', 'core/client_pages.css'];
    foreach ($files as $f) {
        $css = preg_replace('~/\*.*?\*/~s', '', sp_src($f));
        preg_match_all('~blur\(((?:[^()]|\([^()]*\))*)\)~', $css, $m);
        foreach ($m[1] as $arg) {
            $arg = trim($arg);
            if (preg_match('~^var\(--(sp-blur-[234]|lsp-blur-[23]|_blur)\)$~', $arg)) continue;
            expect((bool)preg_match('~^(\d+(?:\.\d+)?)px$~', $arg, $px) && (float)$px[1] <= 24, "$f: blur($arg) is outside the budget");
        }
        preg_match_all('~--(?:sp|lsp)-blur-\d:\s*(\d+(?:\.\d+)?)px~', $css, $v);
        foreach ($v[1] as $px) expect((float)$px <= 24, "$f: a blur token is {$px}px");
    }
    expect(str_contains(sp_src('assets/css/theme-spatial.css'), 'html.sp-ui .sp-glass .sp-glass { -webkit-backdrop-filter: none; backdrop-filter: none; }'), 'nested glass keeps its blur');
    // a backdrop-filter on the landing .nav would trap its fixed mobile menu sheet
    $land = preg_replace('~/\*.*?\*/~s', '', sp_src('assets/css/landing-spatial.css'));
    expect((bool)preg_match('~html body \.nav \{[^}]*backdrop-filter: none;~', $land), 'the landing bar itself is blurred');
    expect((bool)preg_match('~html body \.nav::before \{[^}]*backdrop-filter: blur\(~', $land), 'the landing bar glass is not on its ::before layer');
});

t('no em or en dashes in the spatial files and docs', function () {
    $files = ['assets/css/theme-spatial.css', 'assets/css/landing-spatial.css', 'assets/js/spatial-fx.js', 'assets/css/sidebar-nav.css',
              'core/dock.css', 'assets/css/tour.css', 'assets/css/profile-barrier.css', 'core/client_pages.css', 'PRODUCT.md', 'tests/suites/15_spatial_ui.php'];
    foreach (glob(ASTRA_ROOT . '/docs/spatial-ui/*.{md,html}', GLOB_BRACE) as $d) $files[] = substr(str_replace(chr(92), '/', $d), strlen(str_replace(chr(92), '/', ASTRA_ROOT)) + 1);
    foreach ($files as $f) {
        $src = file_get_contents(ASTRA_ROOT . '/' . $f);
        expect(!str_contains($src, "\u{2014}") && !str_contains($src, "\u{2013}"), "$f contains a long dash");
    }
});

t('retired layers are gone: no boxless or fog stylesheet, file or reference', function () {
    foreach (['assets/css/netflix-bento.css', 'assets/css/theme-fog-boxless.css', 'assets/css/theme-netflix.css'] as $f)
        expect(!file_exists(ASTRA_ROOT . '/' . $f), "$f still exists");
    $root = str_replace(chr(92), '/', ASTRA_ROOT);
    $hits = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ASTRA_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace(chr(92), '/', $f->getPathname());
        if (!preg_match('~\.(php|js|css|html)$~', $p) || preg_match('~/(_backup[^/]*|tests|Claude outputs|tmp|vendor|PHPMailer|node_modules|\.git|docs)/~', $p)) continue;
        $src = file_get_contents($p);
        foreach (['theme-fog-boxless', 'netflix-bento.css', 'theme-netflix', 'text-fog-scrim'] as $needle)
            if (str_contains($src, $needle)) $hits[] = substr($p, strlen($root) + 1) . " ($needle)";
    }
    expect(!$hits, 'references to retired layers: ' . implode(', ', $hits));
    expect(!str_contains(sp_src('core/theme.js'), "'theme-netflix'"), 'core/theme.js still injects a portal stylesheet');
});

t('contrast: every text token clears AA on every glass and dense fill, composited over the world, in both themes', function () {
    $css = sp_src('assets/css/theme-spatial.css');
    $land = sp_src('assets/css/landing-spatial.css');
    $sets = [
        'dark'          => [sp_block($css, 'html.sp-ui'), null, SP_GROUND_DARK, '--sp'],
        'light'         => [sp_block($css, 'html.sp-ui'), sp_block($css, 'html.sp-ui[data-theme="light"]'), SP_GROUND_LIGHT, '--sp'],
        'landing dark'  => [sp_block($land, ':root'), null, SP_GROUND_DARK, '--lsp'],
        'landing light' => [sp_block($land, ':root'), sp_block($land, '[data-theme="light"]'), SP_GROUND_LIGHT, '--lsp'],
    ];
    $fails = []; $n = 0;
    foreach ($sets as $theme => [$base, $over, $ground, $p]) {
        $tok = function (string $name) use ($base, $over) { return sp_rgba(($over !== null ? sp_token($over, $name) : null) ?? sp_token($base, $name)); };
        if ($p === '--sp') {
            $texts = ['text', 'text-2', 'text-3', 'accent-text', 'ok', 'warn', 'bad', 'info'];
            $panel = sp_over($tok('--sp-fill-2'), $ground);
            $surfaces = [];
            foreach (['fill-2', 'fill-3', 'fill-4', 'solid-1', 'solid-2', 'solid-3', 'solid-4'] as $s) $surfaces[$s] = sp_over($tok("--sp-$s"), $ground);
            $surfaces['fill-1 inset in the panel'] = sp_over($tok('--sp-fill-1'), $panel);
        } else {
            $texts = ['text', 'text-2', 'text-3', 'accent-text'];
            $surfaces = ['fill-2' => sp_over($tok('--lsp-fill-2'), $ground), 'fill-3' => sp_over($tok('--lsp-fill-3'), $ground)];
        }
        foreach ($surfaces as $sname => $bg) {
            foreach ($texts as $t) {
                $fg = $tok("$p-$t");
                expect($fg !== null, "$theme: $p-$t is not a plain colour");
                $r = sp_ratio(sp_over($fg, $bg), $bg); $n++;
                if ($r < 4.5) $fails[] = sprintf('%s %s-%s on %s %.2f:1', $theme, $p, $t, $sname, $r);
            }
        }
    }
    foreach (['dark' => [sp_block($css, 'html.sp-ui'), null], 'light' => [sp_block($css, 'html.sp-ui'), sp_block($css, 'html.sp-ui[data-theme="light"]')]] as $theme => [$base, $over]) {
        foreach (['--sp-accent', '--sp-bad-solid'] as $fill) {
            $bg = sp_rgba(($over !== null ? sp_token($over, $fill) : null) ?? sp_token($base, $fill));
            $r = sp_ratio([255, 255, 255, 1.0], $bg); $n++;
            if ($r < 4.5) $fails[] = sprintf('%s white on %s %.2f:1', $theme, $fill, $r);
        }
    }
    expect($n >= 148, "only $n pairs were checked");
    expect(!$fails, implode('; ', $fails));
});
