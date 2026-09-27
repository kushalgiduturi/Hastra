<?php
// Signed-in portals wear the Netflix "developer series" design system.
T::group('Netflix portal design');

function nf_css(string $f): string { return file_get_contents(ASTRA_ROOT . "/assets/css/$f"); }

t('dual-theme tokens: crimson on cinema black, cobalt on white', function () {
    $css = nf_css('theme-netflix.css');
    foreach (['--bg-canvas: #050505', '--bg-surface: #0b0b0b', '--bg-card: rgba(20, 20, 20, 0.95)', '--accent-primary: #E50914',
              '--border-accent: rgba(229, 9, 20, 0.5)', '--badge-text: #ef4444', '--spotlight-color: rgba(229, 9, 20, 0.18)',
              '--bg-canvas: #f8fafc', '--accent-primary: #2563eb', '--border-subtle: #e2e8f0', '--text-primary: #0f172a',
              '--badge-text: #2563eb', '--spotlight-color: rgba(37, 99, 235, 0.12)'] as $tok)
        expect(str_contains($css, $tok), "missing token $tok");
    expect(str_contains($css, "html.light,\n[data-theme=\"light\"] {"), 'light block not keyed on the theme switch');
    // legacy tokens are remapped so every page's own styles follow the palette
    foreach (['--navy: var(--bg-canvas)', '--text: var(--text-primary)', '--accent: #E50914', '--accent: #2563eb'] as $m)
        expect(str_contains($css, $m), "legacy token not remapped: $m");
});

t('boxless: containers are transparent, borderless and shadowless; tables are hairlines', function () {
    $css = nf_css('netflix-bento.css');
    foreach (['.netflix-bento-card, .card, .panel, .box, .table-card-wrapper, .section', 'background: transparent !important;',
              'border: none !important;', 'box-shadow: none !important;', 'border-collapse: collapse !important;',
              'border-bottom: 1px solid var(--hairline) !important;', "'EPISODE ' counter(nf-ep, decimal-leading-zero)",
              '--hairline-accent: rgba(229, 9, 20, .2)', 'font-variant-numeric: tabular-nums'] as $n)
        expect(str_contains($css, $n), "boxless lost: $n");
    expect(!preg_match('~\.main \.stat-card[^{]*\{[^}]*background:\s*var\(--bg-card\)~', $css), 'metric counters sit in a capsule again');
    expect(str_contains($css, 'inset: 0 !important; z-index: -1; pointer-events: none;') && str_contains($css, 'width: auto !important; height: auto !important;'), 'legibility veil does not cover the viewport');
});

t('laser beam: conic border beam on buttons, active filters and pending role changes', function () {
    $css = nf_css('netflix-bento.css');
    foreach (["@property --beam-angle { syntax: '<angle>'; initial-value: 0deg; inherits: false; }", '@keyframes beam-rotate',
              'conic-gradient(from var(--beam-angle), transparent 0%, transparent 60%, var(--beam-a) 85%, var(--beam-b) 95%, transparent 100%)',
              'mask-composite: exclude;', '.main:has(tr.changed) .btn-save::before', 'tr.changed :is(.role-select, .status-select)',
              '.tab-btn.active', 'button.is-processing', '--beam-a: #E50914', '--beam-a: #2563eb',
              'background-clip: padding-box, border-box !important;'] as $n)
        expect(str_contains($css, $n), "beam lost: $n");
    expect(str_contains(file_get_contents(ASTRA_ROOT . '/assets/js/netflix-spotlight.js'), "classList.add('is-processing')"), 'submit does not flag the processing button');
});

t('kage x cyber: grid, radar, packets, wisps and neon lanterns in the portal scene', function () {
    $js = file_get_contents(ASTRA_ROOT . '/assets/js/kage-cyber.js');
    foreach (['hastra-cyber', 'float scan', 'float ring', 'float sweep', 'const packets = new T.Points', 'const wisps = new T.Points',
              'lanternLights', '229 / 255, 9 / 255, 20 / 255', '37 / 255, 99 / 255, 235 / 255', 'win.Float32Array', 'onBeforeRender'] as $n)
        expect(str_contains($js, $n), "cyber layer lost: $n");
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/hastra-atmosphere.css');
    expect(preg_match('~#hastra-kage-scene \{[^}]*z-index: 0; pointer-events: none;~', $css) === 1, 'scene frame can intercept input');
});

t('precision cursor: 12px dot, 48px trailing ring, 600px glow, off on touch', function () {
    $js  = file_get_contents(ASTRA_ROOT . '/assets/js/netflix-cursor.js');
    $css = nf_css('netflix-bento.css');
    expect(str_contains($js, 'DOT = 12, RING = 48, GLOW = 600'), 'cursor geometry drifted');
    expect(str_contains($js, "(hover: none) and (pointer: coarse)") && str_contains($js, 'prefers-reduced-motion'), 'cursor runs on touch or reduced motion');
    expect(str_contains($css, 'box-shadow: 0 0 15px var(--accent-primary)') && str_contains($css, 'border: 1px solid var(--border-accent)'), 'cursor styling drifted');
    expect(!str_contains($js, 'cursor: none'), 'the system cursor must stay visible');
});

t('theme.js gives portals (and Hastra Labs) the Netflix stack, and nothing else', function () {
    $js = file_get_contents(ASTRA_ROOT . '/core/theme.js');
    expect(str_contains($js, "const nf = /\\/(portals|workspace|labs)\\/|\\/dashboard(\\.php)?$/.test(location.pathname);"), 'portal detection changed');
    expect(str_contains($js, "['theme-netflix', 'netflix-bento']"), 'portal stylesheets not loaded');
    expect(str_contains($js, "? ['landing-host', 'kage-scene', 'kage-cyber', 'netflix-spotlight', 'netflix-cursor', 'handover-seal', 'view-director']"), 'portal script stack changed');
    expect(str_contains($js, ": ['landing-host', 'kage-scene', 'hybrid-hand-cursor'"), 'non-portal pages lost their scene');
    foreach (['/Hastra/workspace/admin/', '/Hastra/workspace/client/my-projects', '/Hastra/portals/projects/signoff', '/Hastra/dashboard.php', '/Hastra/labs/', '/Hastra/labs/crypto'] as $p)
        expect(preg_match('~/(portals|workspace|labs)/|/dashboard(\.php)?$~', $p) === 1, "$p would not be themed");
    foreach (['/Hastra/signin', '/Hastra/', '/Hastra/legal/privacy', '/Hastra/signup'] as $p)
        expect(preg_match('~/(portals|workspace|labs)/|/dashboard(\.php)?$~', $p) === 0, "$p would be themed");
});

t('portal routes and role guards are untouched', function () {
    foreach (['admin/admin_portal' => 'admin', 'emlpoyee/employee_portal' => 'employee', 'emlpoyee/testing_portal' => 'employee',
              'client/client_portal' => 'client', 'client/my_projects' => 'client'] as $page => $role)
        expect(str_contains(file_get_contents(ASTRA_ROOT . "/portals/$page.php"), "verify_session(\$conn, \"$role\")"), "$page lost its $role guard");
    foreach (['admin' => 'portals/admin/admin_portal.php', 'employee' => 'portals/emlpoyee/employee_portal.php', 'client' => 'portals/client/client_portal.php'] as $who => $p) {
        $r = cgi('GET', $p, ['session' => as_user($who)]);
        expect_clean($r, $p);
        expect_eq($r['status'], 200, "$p as $who");
        expect(str_contains($r['body'], 'core/theme.js'), "$p no longer loads core/theme.js");
    }
});

t('contrast: Netflix text colours meet AA on their cards', function () {
    $pairs = [
        ['dark primary on card',  '#ffffff', '#141414'], ['dark badge text on card', '#ef4444', '#141414'],
        ['dark muted (.55) on card', '#8f8f8f', '#141414'],
        ['light primary on white', '#0f172a', '#ffffff'], ['light secondary on white', '#334155', '#ffffff'],
        ['light muted on white', '#64748b', '#ffffff'], ['light badge text on white', '#2563eb', '#ffffff'],
        ['white on dark accent', '#ffffff', '#E50914'], ['white on light accent', '#ffffff', '#2563eb'],
    ];
    foreach ($pairs as [$label, $fg, $bg]) {
        $r = cmp_contrast($fg, $bg);
        expect($r >= 4.5, sprintf('%s is %.2f:1', $label, $r));
    }
});
