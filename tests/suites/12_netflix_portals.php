<?php
// Signed-in portals: the portal scene, cursor and routes. Their look is the
// spatial portal layer (theme-spatial.css part 5); see also 15_spatial_ui.php.
T::group('Netflix portal design');

t('portal tokens come from the spatial bridge: crimson by night, cobalt by day', function () {
    // spatial UI (phase 6): theme-netflix.css is retired; the bridge in
    // theme-spatial.css part 1 maps the portal and legacy tokens onto --sp-*
    $sp = str_replace("\r\n", "\n", file_get_contents(ASTRA_ROOT . '/assets/css/theme-spatial.css'));
    foreach (['--bg-canvas: transparent;', '--accent-primary: var(--sp-accent);', '--text-primary: var(--sp-text);', '--border-subtle: var(--sp-hair);',
              '--navy: transparent;', '--navy-card: var(--sp-solid-2);', '--text: var(--sp-text);', '--accent: var(--sp-accent);'] as $m)
        expect(str_contains($sp, $m), "token not bridged: $m");
    expect(str_contains($sp, '--sp-accent: #e50914;') && str_contains($sp, '--sp-accent: #2563eb;'), 'brand accents changed');
});

t('portal panel: one depth-2 glass surface per page; tables are flat rows with hairlines', function () {
    // spatial UI (phase 6): replaces the boxless netflix-bento.css test
    $sp = str_replace("\r\n", "\n", file_get_contents(ASTRA_ROOT . '/assets/css/theme-spatial.css'));
    $panel = preg_match('~html\.sp-ui\.nf-portal :is\(\.main, \.wrap, \.sc-main, \.ht-terminal, \.workspace-index > main\) \{([^}]*)\}~', $sp, $m) ? $m[1] : '';
    foreach (['border-radius: var(--sp-r-panel);', 'background: var(--sp-fill-2);', 'backdrop-filter: blur(var(--sp-blur-2))', 'box-shadow: var(--sp-edge), var(--sp-shadow-2);'] as $n)
        expect(str_contains($panel, $n), "panel lost: $n");
    expect(str_contains($sp, 'html.sp-ui.nf-portal :is(.main, .wrap) table { width: 100%; border-collapse: collapse;'), 'tables are not flat inside the panel');
});

t('processing state: the submitting button shows it is busy, and stops when motion is reduced', function () {
    $sp = file_get_contents(ASTRA_ROOT . '/assets/css/theme-spatial.css');
    expect(str_contains(file_get_contents(ASTRA_ROOT . '/assets/js/netflix-spotlight.js'), "classList.add('is-processing')"), 'submit does not flag the processing button');
    expect(str_contains($sp, 'html.sp-ui button.is-processing {') && str_contains($sp, '@keyframes sp-processing'), 'the processing button has no busy state');
    expect((bool)preg_match('~prefers-reduced-motion: reduce\)\s*\{\s*html\.sp-ui button\.is-processing \{ animation: none;~', $sp), 'busy state ignores reduced motion');
});

t('kage x cyber: grid, radar, packets, wisps and neon lanterns in the portal scene', function () {
    $js = file_get_contents(ASTRA_ROOT . '/assets/js/kage-cyber.js');
    foreach (['hastra-cyber', 'float scan', 'float ring', 'float sweep', 'const packets = new T.Points', 'const wisps = new T.Points',
              'lanternLights', '229 / 255, 9 / 255, 20 / 255', '37 / 255, 99 / 255, 235 / 255', 'win.Float32Array', 'onBeforeRender'] as $n)
        expect(str_contains($js, $n), "cyber layer lost: $n");
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/hastra-atmosphere.css');
    expect(preg_match('~#hastra-kage-scene \{[^}]*z-index: 0; pointer-events: none;~', $css) === 1, 'scene frame can intercept input');
});

t('precision cursor: dot and trailing ring, no glow halo, off on touch', function () {
    $js  = file_get_contents(ASTRA_ROOT . '/assets/js/netflix-cursor.js');
    $css = file_get_contents(ASTRA_ROOT . '/assets/css/theme-spatial.css');
    expect(str_contains($js, 'DOT = 12, RING = 48, GLOW = 600'), 'cursor geometry drifted');
    expect(str_contains($js, "(hover: none) and (pointer: coarse)") && str_contains($js, 'prefers-reduced-motion'), 'cursor runs on touch or reduced motion');
    // spatial UI: the cursor is styled in theme-spatial.css section 44, without the glow halo
    expect(str_contains($css, '#nf-cursor-glow { display: none; }') && str_contains($css, 'border: 1px solid rgba(var(--sp-accent-rgb, 229, 9, 20), .55);'), 'cursor styling drifted');
    expect(!str_contains($js, 'cursor: none'), 'the system cursor must stay visible');
});

t('theme.js marks portals (and Hastra Labs) for the spatial portal layer and loads no portal stylesheet', function () {
    $js = file_get_contents(ASTRA_ROOT . '/core/theme.js');
    expect(str_contains($js, "const nf = /\\/(portals|workspace|labs)\\/|\\/dashboard(\\.php)?$/.test(location.pathname);"), 'portal detection changed');
    // spatial UI (phase 6): theme-netflix.css and netflix-bento.css are retired;
    // theme.js only adds html.nf-portal and theme-spatial.css (linked by every page) does the rest
    expect(str_contains($js, "document.documentElement.classList.add('nf-portal');"), 'portals are no longer marked');
    expect(!str_contains($js, "'theme-netflix'") && !str_contains($js, "'netflix-bento'"), 'a retired portal stylesheet is still loaded');
    expect(str_contains(file_get_contents(ASTRA_ROOT . '/portals/admin/admin_portal.php'), 'assets/css/theme-spatial.css'), 'portal pages do not link the spatial layer');
    expect(str_contains($js, "? ['landing-host', 'kage-scene', 'kage-cyber', 'netflix-spotlight', 'netflix-cursor', 'handover-seal', 'view-director']"), 'portal script stack changed');
    expect(str_contains($js, ": ['landing-host', 'kage-scene', 'sakura-petals-cursor', 'hybrid-hand-cursor'"), 'non-portal pages lost their scene');
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
// spatial UI (phase 6): the Netflix card contrast pairs moved to 15_spatial_ui.php,
// which computes every text-on-glass pair from the spatial tokens.
