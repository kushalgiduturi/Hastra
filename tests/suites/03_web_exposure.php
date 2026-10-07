<?php
// What the real web server (Apache) exposes. GET requests only; nothing is
// written. "Blocked" means 403 or 404 with none of the file's content.
T::group('Web exposure (Apache)');

function expect_blocked(string $path, array $must_not_contain = []): string {
    $r = web_get($path);
    expect(in_array($r['status'], [403, 404], true), "$path answered HTTP {$r['status']}");
    foreach ($must_not_contain as $needle) expect(!str_contains($r['body'], $needle), "$path leaked '$needle'");
    expect(!preg_match('~<b>(Fatal error|Warning|Notice)</b>~', $r['body']), "$path printed a PHP error");
    return "HTTP {$r['status']}";
}

$core_files = array_map(fn($f) => 'core/' . basename($f), glob(ASTRA_ROOT . '/core/*.php'));
foreach ($core_files as $f) {
    t("direct request blocked: $f", fn() => expect_blocked($f, ['<?php', 'function ']));
}
foreach (glob(ASTRA_ROOT . '/portals/*/_nav.php') as $f) {
    $rel = 'portals/' . basename(dirname($f)) . '/_nav.php';
    t("direct request blocked: $rel", fn() => expect_blocked($rel, ['section-nav', 'sidebar', 'Undefined variable']));
}
foreach (['config/config.php', 'config/astra_db.key', 'config/astra_index.key', 'config/astra_payment_webhook.key',
          'config/audit_chain.anchor', 'config/migrations/v4_enterprise_trust.sql', 'schema_v2.sql', '.git/config',
          '.gitignore', 'tests/runner.php', 'tests/lib/bootstrap.php', 'tools/run_migrations.php',
          'cli/migrate_encryption.php', 'PHPMailer/PHPMailer.php', 'uploads/'] as $p) {
    t("sensitive path blocked: $p", fn() => expect_blocked($p, ['DB_PASS', 'define(', '[core]', 'RECAPTCHA']));
}
foreach (glob(ASTRA_ROOT . '/_backup_*', GLOB_ONLYDIR) as $dir) {
    $name = basename($dir);
    $php = glob($dir . '/{*,*/*,*/*/*}.php', GLOB_BRACE);
    t("backup folder not served: $name/", fn() => expect_blocked("$name/"));
    if ($php) {
        $rel = $name . '/' . str_replace('\\', '/', substr($php[0], strlen($dir) + 1));
        t("backup PHP not executable: $rel", fn() => expect_blocked($rel, ['verify_session', 'theme.css', '<?php']));
    }
}
t('public landing page serves at a clean address; .php addresses redirect to it', function () {
    $r = web_get('');
    expect_eq($r['status'], 200, '/Hastra/');
    foreach (['index.php' => '', 'auth/login.php?error=reauth' => 'auth/login?error=reauth', 'legal/terms.php' => 'legal/terms'] as $from => $to) {
        $h = [];
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        @file_get_contents(ASTRA_WEB_BASE . $from, false, $ctx);
        $loc = ''; $code = 0;
        foreach ($http_response_header ?? [] as $l) { if (preg_match('~^HTTP/\S+\s+(\d{3})~', $l, $m)) $code = (int)$m[1]; if (stripos($l, 'Location:') === 0) $loc = trim(substr($l, 9)); }
        expect($code === 301 && str_ends_with($loc, '/Hastra/' . $to), "$from should 301 to /Hastra/$to, got $code $loc");
    }
});

// ── download.php ────────────────────────────────────────────────────────────
T::group('Download security');
t('no session: 403', function () {
    $r = cgi('GET', 'download.php?file_id=1');
    expect_eq($r['status'], 403, 'status'); expect_clean($r);
});
t('signed in but not on the project: 403', function () {
    $fid = download_fixture('ok');
    $r = cgi('GET', "download.php?file_id=$fid", ['session' => as_user('nogender')]);
    expect_eq($r['status'], 403, 'status'); expect_clean($r);
});
t('stored path traversal outside uploads/: 403, no content', function () {
    $fid = download_fixture('../config/config.php');
    $r = cgi('GET', "download.php?file_id=$fid", ['session' => as_user('admin')]);
    expect(in_array($r['status'], [403, 404], true), "status {$r['status']}");
    expect(!str_contains($r['body'], 'DB_PASS'), 'config contents leaked');
    expect_clean($r);
    return "HTTP {$r['status']}";
});
t('absolute path outside uploads/: blocked', function () {
    $fid = download_fixture(realpath(ASTRA_ROOT . '/config/config.php'));
    $r = cgi('GET', "download.php?file_id=$fid", ['session' => as_user('admin')]);
    expect($r['status'] === 403, "status {$r['status']}");
    expect(!str_contains($r['body'], 'DB_PASS'), 'config contents leaked');
});
t('authorised download of a real upload: 200 with the file', function () {
    $dir = ASTRA_ROOT . '/uploads/hastra-test';
    @mkdir($dir, 0700, true);
    $path = $dir . '/evidence-' . F::$suffix . '.txt';
    file_put_contents($path, 'evidence ' . F::$suffix);
    $fid = download_fixture($path);
    $r = cgi('GET', "download.php?file_id=$fid", ['session' => as_user('employee')]);
    @unlink($path); @rmdir($dir);
    expect_eq($r['status'], 200, 'status');
    expect_eq($r['body'], 'evidence ' . F::$suffix, 'body');
});

function download_fixture(string $path): int {
    static $task = null;
    if ($task === null) {
        q("INSERT INTO tasks (project_id, task_code, title, assigned_to, created_by, status) VALUES (?, ?, 'Audit fixture task', ?, ?, 'pending')",
          [F::$project, 'TST-' . F::$suffix, F::$u['employee']['id'], F::$u['admin']['id']]);
        $task = (int)mysqli_insert_id($GLOBALS['conn']);
    }
    q("INSERT INTO task_files (task_id, file_path, file_name, file_size, uploaded_by) VALUES (?, ?, 'evidence.txt', 16, ?)",
      [$task, $path, F::$u['employee']['id']]);
    return (int)mysqli_insert_id($GLOBALS['conn']);
}
