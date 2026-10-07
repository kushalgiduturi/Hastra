<?php
// UI invariants that can be checked without a browser: layout rules on
// html/body, theme tokens, the globe's light/dark palettes, gooey search
// geometry. Rendering itself is verified in a real browser separately.
T::group('UI: layout & theme');

// Every CSS source: stylesheets + inline <style> blocks in pages.
function ui_css_sources(): array {
    $out = [];
    foreach (array_merge(glob(ASTRA_ROOT . '/assets/css/*.css'), glob(ASTRA_ROOT . '/core/*.css')) as $f) $out[$f] = file_get_contents($f);
    foreach (array_merge(glob(ASTRA_ROOT . '/*.php'), glob(ASTRA_ROOT . '/auth/*.php'), glob(ASTRA_ROOT . '/portals/*/*.php')) as $f) {
        if (preg_match_all('~<style[^>]*>(.*?)</style>~s', file_get_contents($f), $m)) $out[$f] = implode("\n", $m[1]);
    }
    return $out;
}
// Rules whose selector list targets html or body directly.
function ui_root_rules(string $css): array {
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as [$_, $sel, $body]) {
        foreach (array_map('trim', explode(',', $sel)) as $s) {
            if (preg_match('~^(\[data-theme="?\w+"?\]\s*)?(html|body)(\.[\w-]+|:[\w-]+|\[[^\]]+\])*$~', $s)) { $out[] = [$s, $body]; break; }
        }
    }
    return $out;
}

t('no html/body rule clips content or locks the viewport', function () {
    $bad = []; $noted = [];
    foreach (ui_css_sources() as $file => $css) {
        foreach (ui_root_rules($css) as [$sel, $body]) {
            $where = preg_replace('~^.*/Hastra/~', '', str_replace('\\', '/', $file)) . " `$sel`";
            // A state class that locks scrolling while a modal overlay is open
            // (onboarding tour, mobile drawer) is intended, not a layout bug.
            if (preg_match('~^body\.[\w-]*(open|locked|modal)\b~', $sel)) { $noted[] = "$where (overlay scroll lock)"; continue; }
            if (preg_match('~(^|;|\s)overflow(-y)?\s*:\s*hidden~', $body)) $bad[] = "$where: overflow hidden";
            if (preg_match('~(^|;|\s)(height|max-height)\s*:\s*100(vh|%)~', $body)) $bad[] = "$where: fixed height";
            if (preg_match('~overflow-x\s*:\s*(hidden|clip)~', $body)) $noted[] = $where;
        }
    }
    expect(!$bad, implode('; ', array_slice($bad, 0, 4)));
    return $noted ? 'overflow-x only (no vertical clipping) on: ' . implode(', ', $noted) : 'clean';
});
t('dark tokens: #05060f canvas, rgba(186,215,247,0.12) edges, #d1e4fa frost glow', function () {
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/theme-authkit.css');
    $root = preg_match('~:root\s*\{(.*?)\}~s', $css, $m) ? $m[1] : '';
    foreach (['--color-midnight-canvas:\s*#05060f', '--color-glass-edge:\s*rgba\(186,\s*215,\s*247,\s*0\.12\)', '--color-frost-glow:\s*#d1e4fa'] as $re) {
        expect((bool)preg_match("~$re~i", $root), "missing $re");
    }
    expect((bool)preg_match('~\[data-theme="light"\]\s*\{[^}]*--color-midnight-canvas~s', $css), 'no light-theme override of the canvas');
});
t('globe palette follows the theme (light: dark 0, pale base; dark: dark 1)', function () {
    $js = file_get_contents(ASTRA_ROOT . '/assets/js/feature-showcase.js');
    expect(str_contains($js, "dark: isLight ? 0 : 1"), 'dark flag not theme-driven');
    expect(str_contains($js, 'baseColor: isLight ? [0.92, 0.94, 0.97] : [0.1, 0.12, 0.18]'), 'base colours not theme-driven');
    expect(str_contains($js, "attributeFilter: ['data-theme']"), 'no theme observer: the globe would not update on toggle');
});
t('light hero mockup: white card, #d0d7de borders', function () {
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/hero-authkit.css');
    expect((bool)preg_match('~\[data-theme="light"\] \.h10-stage \{[^}]*background:\s*#ffffff[^}]*#d0d7de~s', $css), 'light stage styling missing');
});
t('gooey search: 115px chip expands to 240px', function () {
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/gooey-search.css');
    expect((bool)preg_match('~\.gooey-trigger\s*\{[^}]*width:\s*115px~', $css), 'collapsed width');
    expect((bool)preg_match('~\.gooey-trigger\.expanded\s*\{[^}]*width:\s*240px~', $css), 'expanded width');
    $js = file_get_contents(ASTRA_ROOT . '/assets/js/gooey-search.js');
    expect(str_contains($js, 'focus') && str_contains($js, 'blur'), 'no focus / blur handling');
});
t('landing page copy has no em or en dashes', function () {
    $r = cgi('GET', 'index.php');
    expect_clean($r);
    expect(!preg_match('/[\x{2013}\x{2014}]/u', visible_text($r['body'])), 'dash in visible copy');
    expect(!preg_match('/<(title|meta)[^>]*[\x{2013}\x{2014}]/u', $r['body']), 'dash in title/meta');
});

// ── Light-mode contrast floor (assets/css/theme-contrast.css) ───────────────
function ui_contrast(string $fg, string $bg): float {
    $lum = function (string $hex): float {
        $hex = ltrim($hex, '#');
        $c = array_map(fn($h) => hexdec($h) / 255, str_split($hex, 2));
        $c = array_map(fn($v) => $v <= .03928 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4, $c);
        return .2126 * $c[0] + .7152 * $c[1] + .0722 * $c[2];
    };
    [$a, $b] = [$lum($fg), $lum($bg)];
    return (max($a, $b) + .05) / (min($a, $b) + .05);
}
t('light contrast: stylesheet only touches the light theme and adds no boxes', function () {
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/theme-contrast.css');
    $code = preg_replace('~/\*.*?\*/~s', '', $css);
    preg_match_all('~([^{}]+)\{~', $code, $m);
    foreach ($m[1] as $sel) {
        foreach (array_map('trim', explode(',', $sel)) as $s) {
            expect(str_starts_with($s, 'html[data-theme="light"]'), "rule escapes the light theme: $s");
        }
    }
    expect(!preg_match('~box-shadow\s*:\s*(?!none)~', $code), 'adds a shadow');
    expect(!preg_match('~\bborder(-color)?\s*:\s*[^;]*(rgba?\(|#[0-9a-f]{3})~i', $code), 'adds a visible border');
});
t('light contrast: every themed page loads the stylesheet after core/theme.css', function () {
    $missing = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ASTRA_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace(chr(92), '/', $f->getPathname());
        if (substr($p, -4) !== '.php' || preg_match('~/(_backup[^/]*|tests|Claude outputs)/~', $p)) continue;
        $src = file_get_contents($p);
        if (!preg_match('~href="[^"]*core/theme\.css\?v=~', $src)) continue;
        if (!preg_match('~core/theme\.css[^>]*>\s*<link rel="stylesheet" href="[^"]*assets/css/theme-contrast\.css\?v=~', $src)) $missing[] = substr($p, strlen(str_replace(chr(92), '/', ASTRA_ROOT)) + 1);
    }
    expect(!$missing, 'pages without theme-contrast.css: ' . implode(', ', $missing));
});
t('light contrast: status and accent tokens clear AA on white and on pale fills', function () {
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/theme-contrast.css');
    foreach (['accent-bright', 'green', 'yellow', 'cyan', 'orange', 'accent-primary', 'text-muted'] as $tok) {
        expect((bool)preg_match('~--' . $tok . ':\s*(#[0-9a-f]{6})~i', $css, $mm), "token --$tok not set");
        foreach (['#ffffff', '#eef2ff', '#f1f5f9'] as $bg) {
            expect(ui_contrast($mm[1], $bg) >= 4.5, "--$tok {$mm[1]} is " . round(ui_contrast($mm[1], $bg), 2) . ":1 on $bg");
        }
    }
    expect((bool)preg_match('~placeholder\s*\{[^}]*color:\s*(#[0-9a-f]{6})~is', $css, $pm) && ui_contrast($pm[1], '#eef2ff') >= 4.5, 'placeholder colour below AA on the field');
});
t('light contrast: sign-in and sign-up hero text is dark slate with a feathered wash, not a box', function () {
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/theme-contrast.css');
    expect((bool)preg_match('~\.auth-info\s*\{[^}]*color:\s*#0f172a~', $css), 'hero text not dark slate');
    expect((bool)preg_match('~\.auth-info::before\s*\{[^}]*radial-gradient[^}]*rgba\(248, 250, 252, 0\) 100%~s', $css), 'wash must fade to transparent');
    expect((bool)preg_match('~\.info-body\s*\{[^}]*color:\s*#334155~', $css), 'paragraph not slate 700');
});
