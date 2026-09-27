<?php
// Route crawl: every page, as the role it belongs to, through php-cgi on the
// test clone. Each must answer 200 or a redirect (or its documented refusal),
// raise no PHP warnings / notices / deprecations / fatals, and show no em or
// en dashes in its visible copy (house style).
T::group('Route crawl');

$role_for = [
    'portals/admin'      => 'admin',
    'portals/client'     => 'client',
    'portals/emlpoyee'   => 'employee',
    'portals/sysadmin'   => 'sysadmin',
    'portals/user'       => 'employee',
    'portals/projects'   => 'admin',
    'portals/deliveries' => 'admin',
    'portals/security'   => 'client',
];
$req_id = (int)qv("SELECT requirement_id FROM projects WHERE id = ?", [F::$project]);
$special = [
    'portals/admin/doc_editor.php'         => ['query' => 'project=' . F::$project],
    'portals/client/requirements_diff.php' => ['query' => 'req_id=' . $req_id],
    'portals/client/team.php'              => ['role' => 'itmanager'],
    'portals/sysadmin/migrate.php'         => ['role' => 'primary'],
    'portals/sysadmin/delete_user.php'     => ['expect' => 405, 'why' => 'POST-only endpoint'],
    'dashboard.php'                        => ['role' => 'admin'],
];
$skip = [
    'auth/logout.php' => 'ends the session (covered in Authentication)',
    'download.php'    => 'needs a file id (covered in Download security)',
];

const CRAWL_DATA_PAGES = ['portals/sysadmin/logs.php', 'portals/admin/security.php', 'portals/sysadmin/security_dashboard.php'];

function crawl_check(string $rel, ?string $role, string $query = '', ?int $expect = null): string {
    if ($role && !isset(F::$u[$role])) throw new TestFailure("no '$role' fixture in the clone");
    $r = cgi('GET', $rel . ($query ? "?$query" : ''), $role ? ['session' => as_user($role)] : []);
    if ($expect) expect_eq($r['status'], $expect, 'status');
    else expect(in_array($r['status'], [200, 302, 303], true), "HTTP {$r['status']}" . ($r['stderr'] ? ' / ' . substr($r['stderr'], 0, 160) : ''));
    expect_clean($r, $rel);
    if ($r['status'] === 200 && str_contains($r['body'], '<html')) {
        $html = $r['body'];
        // Record-listing pages show stored data in their tables (for logs.php,
        // sealed audit-ledger entries that must not be rewritten). The dash
        // rule applies to the page's own copy, not to stored records.
        if (in_array($rel, CRAWL_DATA_PAGES, true)) $html = preg_replace('~<tbody\b.*?</tbody>~si', ' ', $html);
        if (preg_match('/.{0,40}[\x{2013}\x{2014}].{0,40}/u', visible_text($html), $m)) {
            throw new TestFailure('visible dash: "' . trim(preg_replace('/\s+/', ' ', $m[0])) . '"');
        }
    }
    return $r['status'] === 200 ? sprintf('%d KB', strlen($r['body']) / 1024)
         : ($r['location'] ? 'redirect -> ' . preg_replace('~^.*/login/~', '', $r['location']) : "HTTP {$r['status']}");
}

$pages = array_merge(glob(ASTRA_ROOT . '/portals/*/*.php'), glob(ASTRA_ROOT . '/auth/*.php'), glob(ASTRA_ROOT . '/*.php'));
foreach ($pages as $abs) {
    $rel = str_replace('\\', '/', substr(realpath($abs), strlen(realpath(ASTRA_ROOT)) + 1));
    if (str_starts_with(basename($rel), '_') || isset($skip[$rel])) continue;
    $sp   = $special[$rel] ?? [];
    $role = $sp['role'] ?? ($role_for[dirname($rel)] ?? null);
    $label = "GET $rel" . ($role ? " as $role" : '') . (isset($sp['why']) ? " ({$sp['why']})" : '');
    t($label, fn() => crawl_check($rel, $role, $sp['query'] ?? '', $sp['expect'] ?? null));
}

// Deliberate refusals, asserted explicitly.
t('team management refused to a client PM (IT Managers only)', fn() => crawl_check('portals/client/team.php', 'client', '', 403));
t('migrations refused to a non-primary sysadmin', fn() => crawl_check('portals/sysadmin/migrate.php', 'sysadmin', '', 403));
t('client pages refuse an employee session', function () {
    $r = cgi('GET', 'portals/client/my_projects.php', ['session' => as_user('employee')]);
    expect(in_array($r['status'], [302, 403], true), "HTTP {$r['status']}");
    expect_clean($r);
    return $r['location'] ? 'redirect -> ' . preg_replace('~^.*/login/~', '', $r['location']) : "HTTP {$r['status']}";
});
t('admin pages refuse a client session', function () {
    $r = cgi('GET', 'portals/admin/admin_portal.php', ['session' => as_user('client')]);
    expect(in_array($r['status'], [302, 403], true), "HTTP {$r['status']}");
});
