<?php
// tests/lib/bootstrap.php
// Test-environment prepend. Loaded two ways, both from tests/runner.php only:
//   * required first by the runner itself (CLI), and
//   * passed to every php-cgi request with -d auto_prepend_file=... .
// It never runs under a web server: Apache doesn't use this file, tests/ is
// denied by tests/.htaccess, and the guard below refuses non-test SAPIs.
//
// What it changes, before config/config.php loads:
//   DB_NAME          -> the throwaway clone (never the real database)
//   MAIL_*           -> the local SMTP sink in tests/lib/smtp_sink.php
//   ASTRA_OFFLINE    -> no outbound HTTP (geo / IP reputation lookups)
//   astra_verify_recaptcha() -> deterministic stub ('test-pass' passes)
// config.php then re-defines those constants; PHP keeps the first definition
// and warns, and exactly those "already defined" warnings from config.php are
// ignored. Every other warning, notice, deprecation or fatal is recorded and
// fails the test that caused it.

if (!in_array(PHP_SAPI, ['cli', 'cgi-fcgi'], true)) { http_response_code(404); exit(); }
if (getenv('ASTRA_TEST') !== '1') return;
if (defined('ASTRA_TEST_BOOTSTRAPPED')) return;
define('ASTRA_TEST_BOOTSTRAPPED', true);

$__astra_test_db = getenv('ASTRA_TEST_DB') ?: 'myapp_test';
if (!preg_match('/_test$/', $__astra_test_db)) { fwrite(STDERR, "Refusing: test DB name must end in _test\n"); exit(97); }

define('DB_NAME', $__astra_test_db);
define('MAIL_HOST', '127.0.0.1');
define('MAIL_PORT', (int)(getenv('ASTRA_TEST_SMTP_PORT') ?: 2525));
define('MAIL_AUTH', false);
define('MAIL_SECURE', '');
define('ASTRA_OFFLINE', true);

function astra_verify_recaptcha(string $response): bool { return $response === 'test-pass'; }

$GLOBALS['__astra_test_issues'] = [];
set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return true; // silenced with @ on purpose
    $f = str_replace('\\', '/', $file);
    if (str_ends_with($f, '/config/config.php') && str_contains($str, 'already defined')) return true;
    $GLOBALS['__astra_test_issues'][] = [
        'type' => [E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated',
                   E_USER_WARNING => 'Warning', E_USER_NOTICE => 'Notice', E_USER_DEPRECATED => 'Deprecated',
                   E_STRICT => 'Strict'][$no] ?? "E$no",
        'message' => $str, 'file' => preg_replace('~^.*/Hastra/~', '', $f), 'line' => $line,
    ];
    return true; // handled: keep it out of the response body
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        $GLOBALS['__astra_test_issues'][] = ['type' => 'Fatal', 'message' => $e['message'],
            'file' => preg_replace('~^.*/Hastra/~', '', str_replace('\\', '/', $e['file'])), 'line' => $e['line']];
    }
    if ($out = getenv('ASTRA_TEST_ISSUES_FILE')) {
        file_put_contents($out, json_encode($GLOBALS['__astra_test_issues']));
    }
});
