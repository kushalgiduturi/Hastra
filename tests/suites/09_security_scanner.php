<?php
// Scan Center: SAST engine, ZAP parser, API endpoints, tenant isolation.
require_once __DIR__ . '/../fixtures/sast_fixture.php';
require_once __DIR__ . '/../fixtures/zap_fixture.php';

T::group('SAST engine');
t('detects every vulnerable sample with the right rule', function () {
    [$zip, $expect] = sast_fixture_zip();
    $r = astra_sast_scan_archive($zip); @unlink($zip);
    $by = [];
    foreach ($r['findings'] as $f) $by[$f['file_path']][$f['rule_id']] = ($by[$f['file_path']][$f['rule_id']] ?? 0) + 1;
    foreach ($expect as $file => $rules) foreach ($rules as $rule => $min) {
        expect(($by[$file][$rule] ?? 0) >= $min, "$file: expected $min x $rule, got " . json_encode($by[$file] ?? []));
    }
    return count($r['findings']) . ' findings in ' . $r['scanned'] . ' files';
});
t('safe samples produce no findings (prepared SQL, escaping, CSRF token, guard)', function () {
    [$zip, , $safe] = sast_fixture_zip();
    $r = astra_sast_scan_archive($zip); @unlink($zip);
    $bad = array_filter($r['findings'], fn($f) => in_array($f['file_path'], $safe, true));
    expect(!$bad, 'false positives: ' . json_encode(array_map(fn($f) => "{$f['file_path']}:{$f['line_number']} {$f['rule_id']}", array_values($bad))));
});
t('vendor / minified / binary / node_modules files are skipped with a reason', function () {
    [$zip, , , $skip] = sast_fixture_zip();
    $r = astra_sast_scan_archive($zip); @unlink($zip);
    $skipped = array_column($r['skipped'], 0);
    foreach ($skip as $p) expect(in_array($p, $skipped, true), "$p was not skipped");
    expect(!array_filter($r['findings'], fn($f) => str_starts_with($f['file_path'], 'vendor/')), 'vendor code was scanned');
});
t('zip-slip entry names are labels only (normalised, never written)', function () {
    [$zip] = sast_fixture_zip();
    $r = astra_sast_scan_archive($zip); @unlink($zip);
    $paths = array_column($r['findings'], 'file_path');
    expect(!array_filter($paths, fn($p) => str_contains($p, '..')), 'a label kept a ../ segment');
    expect(!is_file(dirname(sys_get_temp_dir()) . '/escape.php'), 'something was written to disk');
});
t('secret values are masked in stored snippets', function () {
    [$zip] = sast_fixture_zip();
    $r = astra_sast_scan_archive($zip); @unlink($zip);
    foreach ($r['findings'] as $f) if ($f['rule_id'] === 'SAST-SECRET') {
        expect(!str_contains($f['code_snippet'], 'Pr0duction-Pa55!') && !str_contains($f['code_snippet'], 'AKIAIOSFODNN7EXAMPLE')
            && !str_contains($f['code_snippet'], 'hunter2hunter2'), 'secret leaked into snippet: ' . $f['code_snippet']);
    }
});
t('zip bomb: a file that inflates past 1 MB is skipped, not read', function () {
    $path = tempnam(sys_get_temp_dir(), 'bomb') . '.zip';
    $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE);
    $z->addFromString('huge.php', str_repeat("\0\0\0\0", 2 * 1024 * 1024)); // 8 MB of zeros, ~8 KB compressed
    $z->addFromString('ok.php', "<?php echo 1;");
    $z->close();
    $size = filesize($path);
    $r = astra_sast_scan_archive($path); @unlink($path);
    $reasons = array_column($r['skipped'], 1, 0);
    expect(($reasons['huge.php'] ?? '') === 'larger than 1 MB', 'bomb not capped: ' . json_encode($reasons));
    return "archive $size bytes, capped at 1 MB read";
});
t('non-ZIP input is refused with a clear error', function () {
    $p = tempnam(sys_get_temp_dir(), 'nz'); file_put_contents($p, 'not a zip');
    try { astra_sast_scan_archive($p); } catch (RuntimeException $e) { @unlink($p); return $e->getMessage(); }
    @unlink($p); throw new TestFailure('accepted a non-ZIP file');
});
t('scanner finds the known patterns in Hastra itself without errors', function () {
    // Sanity: run over the real core/ folder zipped in memory; must not throw
    // or raise warnings, and must recognise Hastra's own guard pattern.
    $path = tempnam(sys_get_temp_dir(), 'self') . '.zip';
    $z = new ZipArchive(); $z->open($path, ZipArchive::CREATE);
    foreach (glob(ASTRA_ROOT . '/core/*.php') as $f) $z->addFile($f, 'core/' . basename($f));
    $z->close();
    $r = astra_sast_scan_archive($path); @unlink($path);
    $guard = array_filter($r['findings'], fn($f) => $f['rule_id'] === 'SAST-GUARD');
    expect(!$guard, 'Hastra core files flagged as unguarded: ' . implode(', ', array_column($guard, 'file_path')));
    return $r['scanned'] . ' core files, ' . count($r['findings']) . ' findings to review';
});

T::group('ZAP parser');
t('JSON and XML exports parse to the same clustered findings', function () {
    $j = astra_zap_cluster(astra_zap_parse(zap_fixture_json()));
    $x = astra_zap_cluster(astra_zap_parse(zap_fixture_xml()));
    $sig = fn($l) => array_map(fn($a) => $a['plugin_id'] . ':' . $a['severity'] . ':' . count($a['instances']), $l);
    expect_eq($sig($x), $sig($j), 'XML vs JSON');
    expect_eq(count($j), 4, 'clusters from 5 raw alerts');
});
t('SARIF, API alert list and bare API array give the same findings as Traditional JSON', function () {
    $sig = fn($l) => array_map(fn($a) => $a['plugin_id'] . ':' . $a['severity'] . ':' . count($a['instances']), $l);
    $want = $sig(astra_zap_cluster(astra_zap_parse(zap_fixture_json())));
    foreach (['SARIF' => zap_fixture_sarif(), 'API {alerts}' => zap_fixture_api(), 'API [..]' => zap_fixture_api(true)] as $fmt => $raw) {
        expect_eq($sig(astra_zap_cluster(astra_zap_parse($raw))), $want, $fmt);
    }
});
t('real ZAP 2.17 outputs parse, when present (tests/.results/zap)', function () {
    $files = glob(ASTRA_ROOT . '/tests/.results/zap/{hastra-zap-report.json,hastra-zap-report.xml,fmt-sarif.json,fmt-traditional-json-plus.json}', GLOB_BRACE);
    if (!$files) return 'no real reports on this machine; skipped';
    $counts = [];
    foreach ($files as $f) $counts[basename($f)] = count(astra_zap_cluster(astra_zap_parse(file_get_contents($f))));
    expect(count(array_unique($counts)) === 1, 'formats disagree: ' . json_encode($counts));
    return json_encode($counts);
});
t('duplicate endpoints merge; clusters span sites', function () {
    $c = array_values(array_filter(astra_zap_cluster(astra_zap_parse(zap_fixture_json())), fn($a) => $a['plugin_id'] === '10038'))[0];
    expect_eq(count($c['instances']), 3, 'unique CSP endpoints (one duplicate removed)');
    expect_eq(count($c['sites']), 2, 'sites');
});
t('severity: riskcode mapping and critical for high-confidence injection', function () {
    $by = array_column(astra_zap_cluster(astra_zap_parse(zap_fixture_json())), 'severity', 'plugin_id');
    expect_eq($by['40018'], 'critical', 'SQL injection'); expect_eq($by['10038'], 'medium', 'CSP');
    expect_eq($by['10010'], 'low', 'cookie'); expect_eq($by['10096'], 'info', 'timestamp');
});
t('XXE / entity payloads are refused before parsing', function () {
    try { astra_zap_parse(zap_fixture_xxe()); } catch (InvalidArgumentException $e) { return $e->getMessage(); }
    throw new TestFailure('XXE document accepted');
});
t('non-ZAP JSON and XML are refused with guidance', function () {
    foreach (['{"hello":1}', '<?xml version="1.0"?><other/>', 'garbage'] as $raw) {
        try { astra_zap_parse($raw); throw new TestFailure("accepted: $raw"); } catch (InvalidArgumentException $e) {}
    }
});
t('every fixture alert gets tailored developer remediation', function () {
    foreach (astra_zap_cluster(astra_zap_parse(zap_fixture_json())) as $a) {
        $r = astra_zap_remediation($a);
        expect($r['summary'] !== '' && count($r['steps']) >= 1, "no remediation for {$a['name']}");
    }
    $csp = astra_zap_remediation(['plugin_id' => '10038', 'cwe_id' => 'CWE-693', 'desc' => '', 'solution' => '', 'reference' => []]);
    expect(str_contains($csp['after'], 'Content-Security-Policy'), 'CSP fix has no header example');
});

// ── API through php-cgi, with tenant isolation ──────────────────────────────
T::group('Scan Center API');
function scan_upload(string $who, string $endpoint, string $field, string $name, string $content, array $extra = []): array {
    $s = $extra['session'] ?? as_user($who);
    $mp = multipart(['csrf_token' => $extra['csrf'] ?? csrf_of($s)], [$field => [$name, $content]]);
    $r = cgi('POST', "api/security/$endpoint", ['session' => $s, 'accept' => 'application/json', 'body' => $mp['body'], 'content_type' => $mp['content_type']]);
    $r['session'] = $s;
    return $r;
}
t('anonymous callers get 401', function () {
    expect_eq(cgi('GET', 'api/security/findings.php')['status'], 401, 'findings');
    expect_eq(cgi('GET', 'api/security/stream_scan.php?scan_id=1')['status'], 401, 'stream');
});
t('accounts without a company are refused', function () {
    fix_make_user('nocompany', 'client', null);
    $r = cgi('GET', 'api/security/findings.php', ['session' => as_user('nocompany')]);
    expect_eq($r['status'], 403, 'status'); expect_clean($r);
});
t('upload without a CSRF token is refused', function () {
    [$zip] = sast_fixture_zip();
    $r = scan_upload('admin', 'upload_code.php', 'archive', 'app.zip', file_get_contents($zip), ['csrf' => 'wrong']); @unlink($zip);
    expect_eq($r['status'], 403, 'status');
});
t('non-ZIP upload is refused', function () {
    $r = scan_upload('admin', 'upload_code.php', 'archive', 'app.zip', 'plain text');
    expect_eq($r['status'], 415, 'status'); expect_clean($r);
});
t('SAST: upload queues a scan, the SSE stream runs it and stores scoped findings', function () {
    [$zip] = sast_fixture_zip();
    $up = scan_upload('admin', 'upload_code.php', 'archive', 'shop-app.zip', file_get_contents($zip)); @unlink($zip);
    expect_clean($up, 'upload');
    $j = json_of($up); expect($j['ok'] ?? false, $up['body']);
    $scan = q1("SELECT * FROM security_scans WHERE id = ?", [$j['scan_id']]);
    expect($scan['status'] === 'queued' && is_file($scan['archive_path']), 'not queued with a stored archive');
    expect(!str_starts_with(str_replace('\\', '/', $scan['archive_path']), str_replace('\\', '/', realpath(ASTRA_ROOT))), 'archive stored under the web root');

    $st = cgi('GET', 'api/security/stream_scan.php?scan_id=' . $j['scan_id'], ['session' => $up['session'], 'accept' => 'text/event-stream']);
    expect_clean($st, 'stream');
    expect(str_contains(implode(',', $st['headers']['content-type'] ?? []), 'text/event-stream'), 'not an event stream');
    foreach (['event: start', 'event: progress', 'event: complete', 'event: done'] as $ev) expect(str_contains($st['body'], $ev), "missing $ev");
    preg_match_all('~event: progress\ndata: (.*)\n~', $st['body'], $m);
    $last = json_decode(end($m[1]), true);
    expect(isset($last['current_file'], $last['percent'], $last['issues_found']) && $last['percent'] == 100, 'progress payload: ' . end($m[1]));

    $scan = q1("SELECT * FROM security_scans WHERE id = ?", [$j['scan_id']]);
    expect_eq($scan['status'], 'completed', 'scan status');
    expect($scan['archive_path'] === null, 'archive path not cleared');
    expect((int)$scan['critical_count'] >= 4, 'critical count ' . $scan['critical_count']);
    $n = (int)qv("SELECT COUNT(*) FROM security_findings WHERE scan_id = ? AND company_id = ?", [$j['scan_id'], F::$internal_company]);
    expect($n >= 15, "only $n findings stored");
    $GLOBALS['__scan_admin'] = (int)$j['scan_id'];
    return "$n findings, {$scan['skipped_files']} files skipped";
});
t('reconnecting to a finished stream replays the result, never rescans', function () {
    $id = $GLOBALS['__scan_admin'] ?? 0; expect($id > 0, 'previous test failed');
    $before = (int)qv("SELECT COUNT(*) FROM security_findings WHERE scan_id = ?", [$id]);
    $st = cgi('GET', "api/security/stream_scan.php?scan_id=$id", ['session' => as_user('admin')]);
    expect(str_contains($st['body'], 'event: complete') && !str_contains($st['body'], 'event: progress'), 'stream re-ran the scan');
    expect_eq((int)qv("SELECT COUNT(*) FROM security_findings WHERE scan_id = ?", [$id]), $before, 'finding count');
});
t('ZAP: JSON and XML uploads import clustered findings; XXE upload refused', function () {
    $a = json_of(scan_upload('admin', 'upload_zap.php', 'report', 'zap-shop.json', zap_fixture_json()));
    $b = json_of(scan_upload('admin', 'upload_zap.php', 'report', 'zap-shop.xml', zap_fixture_xml()));
    expect(($a['findings'] ?? 0) === 4 && ($b['findings'] ?? 0) === 4, 'expected 4 clustered findings each: ' . json_encode([$a, $b]));
    $sarif = json_of(scan_upload('admin', 'upload_zap.php', 'report', 'zap-shop.sarif.json', zap_fixture_sarif()));
    expect(($sarif['findings'] ?? 0) === 4, 'SARIF upload: ' . json_encode($sarif));
    $x = scan_upload('admin', 'upload_zap.php', 'report', 'evil.xml', zap_fixture_xxe());
    expect_eq($x['status'], 422, 'XXE status'); expect_clean($x);
    $f = q1("SELECT * FROM security_findings WHERE scan_id = ? AND rule_id = 'ZAP-10038'", [$a['scan_id']]);
    expect(count(json_decode($f['affected_url'], true)) === 3, 'endpoints not clustered');
});
t('tenant isolation: another company cannot list, stream, open or triage', function () {
    $id = $GLOBALS['__scan_admin'] ?? 0; expect($id > 0, 'previous test failed');
    $cs = as_user('client');
    $list = json_of(cgi('GET', 'api/security/findings.php', ['session' => $cs]));
    expect(!array_filter($list['findings'], fn($f) => $f['scan_id'] === $id), 'client sees the admin company\'s findings');
    expect(!array_filter($list['scans'], fn($s) => (int)$s['id'] === $id), 'client sees the admin company\'s scans');
    expect_eq(cgi('GET', "api/security/findings.php?scan_id=$id", ['session' => $cs])['status'], 404, 'open other scan');
    expect_eq(cgi('GET', "api/security/stream_scan.php?scan_id=$id", ['session' => $cs])['status'], 404, 'stream other scan');
    $fid = (int)qv("SELECT id FROM security_findings WHERE scan_id = ? LIMIT 1", [$id]);
    $cs2 = as_user('client');
    $t = cgi('POST', 'api/security/findings.php', ['session' => $cs2, 'post' => ['csrf_token' => csrf_of($cs2), 'finding_id' => $fid, 'status' => 'resolved']]);
    expect_eq($t['status'], 404, 'triage other finding');
    expect_eq(qv("SELECT status FROM security_findings WHERE id = ?", [$fid]), 'open', 'status changed across tenants');
});
t('triage: false positives stay false positive on the next scan', function () {
    $id = $GLOBALS['__scan_admin'] ?? 0;
    $f = q1("SELECT id, fingerprint FROM security_findings WHERE scan_id = ? AND rule_id = 'SAST-GUARD' LIMIT 1", [$id]);
    $s = as_user('admin');
    $r = cgi('POST', 'api/security/findings.php', ['session' => $s, 'post' => ['csrf_token' => csrf_of($s), 'finding_id' => $f['id'], 'status' => 'false_positive']]);
    expect((json_of($r)['ok'] ?? false), $r['body']);
    [$zip] = sast_fixture_zip();
    $up = json_of(scan_upload('admin', 'upload_code.php', 'archive', 'shop-app-v2.zip', file_get_contents($zip))); @unlink($zip);
    cgi('GET', 'api/security/stream_scan.php?scan_id=' . $up['scan_id'], ['session' => as_user('admin')]);
    expect_eq(qv("SELECT status FROM security_findings WHERE scan_id = ? AND fingerprint = ?", [$up['scan_id'], $f['fingerprint']]), 'false_positive', 'carried status');
});
t('Scan Center page renders for every account type', function () {
    foreach (['admin', 'employee', 'client', 'sysadmin'] as $who) {
        $r = cgi('GET', 'portals/security/scan_center.php', ['session' => as_user($who)]);
        expect_eq($r['status'], 200, "$who status"); expect_clean($r, $who);
        expect(str_contains($r['body'], 'id="dropCode"'), "$who: no scan controls");
    }
    $r = cgi('GET', 'portals/security/scan_center.php', ['session' => as_user('nocompany')]);
    expect(str_contains($r['body'], "isn't linked to a company"), 'company-less account not told why');
});
