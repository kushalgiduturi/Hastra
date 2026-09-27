<?php
// tests/lib/harness.php
// Test framework + environment for tests/runner.php. No external deps:
// plain PHP, the bundled php-cgi, and the local MySQL server.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

const ASTRA_ROOT     = __DIR__ . '/../..';
const ASTRA_PHPCGI   = 'C:/xampp/php/php-cgi.exe';
const ASTRA_BOOT     = __DIR__ . '/bootstrap.php';
const ASTRA_RESULTS  = __DIR__ . '/../.results';
const ASTRA_WEB_BASE = 'http://localhost/Hastra/';
const ASTRA_TEST_UA  = 'HastraTestRunner/1.0 (+tests/runner.php)';
const ASTRA_TEST_LANG = 'en-IN,en;q=0.9';

final class TestFailure extends Exception {}

// ── Framework ────────────────────────────────────────────────────────────────
final class T {
    public static array $results = [];
    public static array $previous = [];
    public static string $group = '';
    public static ?string $only = null;

    public static function group(string $name): void { self::$group = $name; }
}

function t(string $name, callable $fn): void {
    $id = T::$group . ' :: ' . $name;
    if (T::$only && stripos($id, T::$only) === false) return;
    $GLOBALS['__astra_test_issues'] = [];
    $t0 = hrtime(true);
    $status = 'PASS'; $detail = '';
    try {
        $r = $fn();
        if (is_string($r)) $detail = $r;
    } catch (TestFailure $e) {
        $status = 'FAIL'; $detail = $e->getMessage();
    } catch (Throwable $e) {
        $status = 'FAIL';
        $detail = get_class($e) . ': ' . $e->getMessage() . ' @ ' . preg_replace('~^.*/Hastra/~', '', str_replace('\\', '/', $e->getFile())) . ':' . $e->getLine();
    }
    if ($status === 'PASS' && $GLOBALS['__astra_test_issues']) {
        $i = $GLOBALS['__astra_test_issues'][0];
        $status = 'FAIL';
        $detail = "in-process {$i['type']}: {$i['message']} ({$i['file']}:{$i['line']})";
    }
    $ms = (hrtime(true) - $t0) / 1e6;
    if ($status === 'PASS' && (T::$previous[$id] ?? null) === 'FAIL') $status = 'FIXED';
    T::$results[] = ['id' => $id, 'group' => T::$group, 'name' => $name, 'status' => $status, 'ms' => $ms, 'detail' => $detail];

    $label = ['PASS' => "\033[32m[PASS]\033[0m", 'FIXED' => "\033[36m[FIXED & RETESTED]\033[0m", 'FAIL' => "\033[31m[FAIL]\033[0m"][$status];
    printf("  %s %7.1fms  %s%s\n", $label, $ms, $name, $detail !== '' ? "\n" . str_repeat(' ', 20) . "\033[90m$detail\033[0m" : '');
}

function expect($cond, string $msg = 'expectation failed'): void { if (!$cond) throw new TestFailure($msg); }
function expect_eq($actual, $expected, string $label): void {
    if ($actual !== $expected) throw new TestFailure("$label: expected " . var_export($expected, true) . ", got " . var_export($actual, true));
}
function expect_clean(array $resp, string $label = 'response'): void {
    if (!empty($resp['issues'])) {
        $i = $resp['issues'][0];
        throw new TestFailure("$label raised PHP {$i['type']}: {$i['message']} ({$i['file']}:{$i['line']})" . (count($resp['issues']) > 1 ? ' +' . (count($resp['issues']) - 1) . ' more' : ''));
    }
    if (preg_match('~<b>(Fatal error|Warning|Notice|Deprecated|Parse error)</b>:|^(Fatal error|Warning|Notice|Deprecated): ~m', $resp['body'], $m)) {
        throw new TestFailure("$label printed a PHP {$m[1]}{$m[2]} into the page");
    }
}

// ── Test database ───────────────────────────────────────────────────────────
function astra_test_clone_db(string $src, string $dst): int {
    if (!preg_match('/_test$/', $dst) || $src === $dst) throw new RuntimeException("Refusing to clone into '$dst'");
    $c = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
    mysqli_query($c, "DROP DATABASE IF EXISTS `$dst`");
    mysqli_query($c, "CREATE DATABASE `$dst` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    mysqli_query($c, "SET FOREIGN_KEY_CHECKS = 0");
    $tables = mysqli_fetch_all(mysqli_query($c,
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$src' AND TABLE_TYPE = 'BASE TABLE'"));
    foreach ($tables as [$t]) {
        $ddl = mysqli_fetch_row(mysqli_query($c, "SHOW CREATE TABLE `$src`.`$t`"))[1];
        mysqli_select_db($c, $dst);
        if (!mysqli_query($c, $ddl)) throw new RuntimeException("clone $t: " . mysqli_error($c));
        if (!mysqli_query($c, "INSERT INTO `$dst`.`$t` SELECT * FROM `$src`.`$t`")) throw new RuntimeException("copy $t: " . mysqli_error($c));
    }
    mysqli_query($c, "SET FOREIGN_KEY_CHECKS = 1");
    mysqli_close($c);
    return count($tables);
}
function astra_test_drop_db(string $dst): void {
    if (!preg_match('/_test$/', $dst)) return;
    $c = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
    mysqli_query($c, "DROP DATABASE IF EXISTS `$dst`");
    mysqli_close($c);
}

function q($sql, array $params = []) {
    $conn = $GLOBALS['conn'];
    if (!$params) { $r = mysqli_query($conn, $sql); if ($r === false) throw new RuntimeException("SQL: " . mysqli_error($conn) . " :: $sql"); return $r; }
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) throw new RuntimeException("SQL prepare: " . mysqli_error($conn) . " :: $sql");
    mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...array_map(fn($v) => $v === null ? null : (string)$v, $params));
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    return $r === false ? $stmt : $r;
}
function q1($sql, array $params = []): ?array { $r = q($sql, $params); return $r instanceof mysqli_result ? (mysqli_fetch_assoc($r) ?: null) : null; }
function qv($sql, array $params = []) { $row = q1($sql, $params); return $row ? array_values($row)[0] : null; }

// ── Sessions ────────────────────────────────────────────────────────────────
// Writes a PHP session file the php-cgi request will pick up, fingerprinted
// for the request's User-Agent / Accept-Language / IP so the session guard
// accepts it (or deliberately not, when a test wants it to trip).
function astra_test_session(array $data, array $client = []): string {
    $ua   = $client['ua'] ?? ASTRA_TEST_UA;
    $lang = $client['lang'] ?? ASTRA_TEST_LANG;
    $ip   = $client['ip'] ?? '127.0.0.1';
    $saved = [$_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null];
    $_SERVER['HTTP_USER_AGENT'] = $ua; $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $lang; $_SERVER['REMOTE_ADDR'] = $ip;
    $fp = astra_session_fingerprint();
    [$_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['REMOTE_ADDR']] = $saved;

    $data += ['initiated' => true, 'last_activity' => time(), 'csrf_token' => bin2hex(random_bytes(32))];
    if (isset($data['user_id']) && !array_key_exists('auth_fp', $data)) { $data['auth_fp'] = $fp['hash']; $data['auth_fp_touched'] = time(); }
    $id = 'hastratest' . bin2hex(random_bytes(12));
    $enc = '';
    foreach ($data as $k => $v) $enc .= $k . '|' . serialize($v);
    file_put_contents(rtrim(ini_get('session.save_path'), '/\\') . '/sess_' . $id, $enc);
    $GLOBALS['__astra_test_sessions'][] = $id;
    return $id;
}
function astra_test_session_data(string $id): array {
    $raw = @file_get_contents(rtrim(ini_get('session.save_path'), '/\\') . '/sess_' . $id);
    if ($raw === false || $raw === '') return [];
    $out = []; $offset = 0;
    while ($offset < strlen($raw) && ($p = strpos($raw, '|', $offset)) !== false) {
        $key = substr($raw, $offset, $p - $offset);
        $rest = substr($raw, $p + 1);
        $val = @unserialize($rest);
        $len = strlen(serialize($val));
        $out[$key] = $val;
        $offset = $p + 1 + $len;
    }
    return $out;
}
function astra_test_cleanup_sessions(): void {
    foreach ($GLOBALS['__astra_test_sessions'] ?? [] as $id) @unlink(rtrim(ini_get('session.save_path'), '/\\') . '/sess_' . $id);
}

// ── php-cgi requests (in-process CGI, test DB, no Apache) ──────────────────
function cgi(string $method, string $path, array $o = []): array {
    [$script, $qs] = array_pad(explode('?', $path, 2), 2, '');
    if (!str_ends_with($script, '.php')) $script .= '.php';
    $file = realpath(ASTRA_ROOT . '/' . $script);
    if (!$file) throw new RuntimeException("No such script: $script");

    $body = $o['body'] ?? (isset($o['post']) ? http_build_query($o['post']) : '');
    $issues_file = tempnam(sys_get_temp_dir(), 'ati');
    $env = getenv() + [];
    $env = array_merge($env, [
        'ASTRA_TEST' => '1', 'ASTRA_TEST_DB' => DB_NAME, 'ASTRA_TEST_SMTP_PORT' => (string)$GLOBALS['__astra_smtp_port'],
        'ASTRA_TEST_ISSUES_FILE' => $issues_file,
        'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD' => $method, 'SCRIPT_FILENAME' => $file, 'SCRIPT_NAME' => '/Hastra/' . $script,
        'PHP_SELF' => '/Hastra/' . $script, 'REQUEST_URI' => '/Hastra/' . $path, 'QUERY_STRING' => $qs,
        'DOCUMENT_ROOT' => 'C:/xampp/htdocs', 'SERVER_NAME' => 'localhost', 'SERVER_PORT' => '80', 'HTTP_HOST' => 'localhost',
        'REMOTE_ADDR' => $o['ip'] ?? '127.0.0.1', 'HTTP_USER_AGENT' => $o['ua'] ?? ASTRA_TEST_UA,
        'HTTP_ACCEPT_LANGUAGE' => $o['lang'] ?? ASTRA_TEST_LANG,
        'HTTP_ACCEPT' => $o['accept'] ?? 'text/html',
        'CONTENT_LENGTH' => (string)strlen($body),
        'CONTENT_TYPE' => $o['content_type'] ?? ($body !== '' ? 'application/x-www-form-urlencoded' : ''),
    ]);
    if (!empty($o['session'])) $env['HTTP_COOKIE'] = 'PHPSESSID=' . $o['session'];
    foreach ($o['headers'] ?? [] as $h => $v) $env['HTTP_' . strtoupper(str_replace('-', '_', $h))] = $v;

    $t0 = hrtime(true);
    $p = proc_open([ASTRA_PHPCGI, '-d', 'auto_prepend_file=' . realpath(ASTRA_BOOT), '-d', 'display_errors=0',
                    '-d', 'log_errors=0', '-d', 'html_errors=0', '-d', 'default_socket_timeout=3'],
                   [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($file), $env);
    fwrite($pipes[0], $body); fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($p);

    $split = strpos($raw, "\r\n\r\n");
    $head = $split === false ? '' : substr($raw, 0, $split);
    $resp = ['status' => 200, 'headers' => [], 'location' => null, 'body' => $split === false ? $raw : substr($raw, $split + 4),
             'exit' => $exit, 'stderr' => trim($err), 'ms' => (hrtime(true) - $t0) / 1e6];
    foreach (preg_split('/\r\n/', $head) as $line) {
        if (!str_contains($line, ':')) continue;
        [$k, $v] = array_map('trim', explode(':', $line, 2));
        $resp['headers'][strtolower($k)][] = $v;
        if (strcasecmp($k, 'Status') === 0) $resp['status'] = (int)$v;
        if (strcasecmp($k, 'Location') === 0) { $resp['location'] = $v; if ($resp['status'] === 200) $resp['status'] = 302; }
    }
    $resp['issues'] = json_decode((string)@file_get_contents($issues_file), true) ?: [];
    @unlink($issues_file);
    return $resp;
}
// multipart/form-data body for file uploads through cgi().
// $files: field => [filename, content]
function multipart(array $fields, array $files): array {
    $b = '----hastratest' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($fields as $k => $v) $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
    foreach ($files as $k => [$name, $content]) {
        $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"$name\"\r\nContent-Type: application/octet-stream\r\n\r\n$content\r\n";
    }
    return ['body' => $body . "--$b--\r\n", 'content_type' => "multipart/form-data; boundary=$b"];
}
function csrf_of(string $session): string { return astra_test_session_data($session)['csrf_token'] ?? ''; }
function json_of(array $resp): array {
    $j = json_decode($resp['body'], true);
    if (!is_array($j)) throw new TestFailure('not JSON: ' . substr(trim(strip_tags($resp['body'])), 0, 120));
    return $j;
}

// ── Mail sink ───────────────────────────────────────────────────────────────
function mail_dir(): string { return ASTRA_RESULTS . '/mail'; }
function mail_clear(): void { foreach (glob(mail_dir() . '/*.eml') ?: [] as $f) @unlink($f); }
function mail_last_to(string $addr): ?string {
    $files = glob(mail_dir() . '/*.eml') ?: [];
    rsort($files);
    foreach ($files as $f) {
        $m = file_get_contents($f);
        if (stripos($m, $addr) !== false) return quoted_printable_decode($m);
    }
    return null;
}
// The 6-digit code from the message BODY, next to the word OTP / code.
// (Matching anywhere was flaky: Message-ID headers can contain 6 digits.)
function mail_code_to(string $addr): ?string {
    $m = mail_last_to($addr);
    if (!$m) return null;
    $body = substr($m, (int)strpos($m, "\r\n\r\n"));
    return preg_match('/\b(?:OTP|code)\b[^0-9]{0,24}(\d{6})\b/i', $body, $x) ? $x[1] : null;
}

// ── Apache (real web server) probe: GET only, never mutates ────────────────
function web_get(string $path): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0,
                                             'header' => "User-Agent: " . ASTRA_TEST_UA . "\r\n"]]);
    $body = @file_get_contents(ASTRA_WEB_BASE . $path, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $status = (int)$m[1];
    return ['status' => $status, 'body' => (string)$body];
}

// ── Visible text of a rendered page (for copy checks) ──────────────────────
function visible_text(string $html): string {
    $h = preg_replace(['~<!--.*?-->~s', '~<(script|style|template|svg)\b.*?</\1>~si'], ' ', $html);
    return html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
