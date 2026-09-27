<?php
// tests/runner.php
// End-to-end audit suite for Astra. No external dependencies.
//
//   C:\xampp\php\php.exe tests\runner.php            run everything
//   C:\xampp\php\php.exe tests\runner.php --only=crypto   run tests whose id contains "crypto"
//   C:\xampp\php\php.exe tests\runner.php --keep     keep the test DB afterwards
//
// Isolation: a fresh clone of the database (myapp -> myapp_test) is built for
// every run and dropped afterwards. Endpoints run through php-cgi against the
// clone with tests/lib/bootstrap.php prepended; mail goes to a local SMTP
// sink; no outbound network calls are made. The only requests sent to the
// real web server are read-only GETs that check what the server exposes.
//
// Status: [PASS], [FAIL], or [FIXED & RETESTED] (failed on the previous run,
// passes now; results are kept in tests/.results/last.json). The runner does
// not edit source files itself. Fixes are made in the codebase and verified
// by the next run, which is what flips a test to FIXED & RETESTED.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

$opts = getopt('', ['only:', 'keep']);
putenv('ASTRA_TEST=1');
putenv('ASTRA_TEST_DB=myapp_test');
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/../config/config.php';

$source_db = 'myapp';
if (preg_match("~define\('DB_NAME',\s*'([^']+)'~", (string)file_get_contents(__DIR__ . '/../config/config.php'), $m)) $source_db = $m[1];

require __DIR__ . '/lib/harness.php';
@mkdir(ASTRA_RESULTS, 0700, true);
ini_set('error_log', ASTRA_RESULTS . '/php_error.log'); // tamper alerts etc. go here, not into the matrix
T::$only = $opts['only'] ?? null;
@mkdir(ASTRA_RESULTS . '/mail', 0700, true);
T::$previous = json_decode((string)@file_get_contents(ASTRA_RESULTS . '/last.json'), true) ?: [];

$t_start = hrtime(true);
echo "\nAstra audit suite\n";
echo "  cloning $source_db -> " . DB_NAME . " ... ";
$n = astra_test_clone_db($source_db, DB_NAME);
echo "$n tables\n";

// Local SMTP sink
$GLOBALS['__astra_smtp_port'] = 2525 + random_int(0, 400);
$sink = proc_open([PHP_BINARY, __DIR__ . '/lib/smtp_sink.php', (string)$GLOBALS['__astra_smtp_port'], mail_dir()],
                  [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $sink_pipes);
$ready = fgets($sink_pipes[1]);
if (trim((string)$ready) !== 'READY') { echo "SMTP sink failed to start\n"; exit(2); }
putenv('ASTRA_TEST_SMTP_PORT=' . $GLOBALS['__astra_smtp_port']);
mail_clear();

require __DIR__ . '/../core/db.php';   // connects to the clone
require __DIR__ . '/lib/fixtures.php';
fix_setup();
echo "  fixtures ready (suffix " . F::$suffix . ", project #" . F::$project . ")\n";

foreach (glob(__DIR__ . '/suites/*.php') as $suite) {
    require $suite;
}

// ── Teardown + summary ─────────────────────────────────────────────────────
proc_terminate($sink);
astra_test_cleanup_sessions();
if (!isset($opts['keep'])) astra_test_drop_db(DB_NAME);

$by = ['PASS' => 0, 'FIXED' => 0, 'FAIL' => 0];
// A full run replaces the record; an --only run merges into it, so partial
// runs don't forget the state of tests they skipped.
$save = T::$only ? T::$previous : [];
foreach (T::$results as $r) { $by[$r['status']]++; $save[$r['id']] = $r['status'] === 'FAIL' ? 'FAIL' : 'PASS'; }
file_put_contents(ASTRA_RESULTS . '/last.json', json_encode($save, JSON_PRETTY_PRINT));

$total = count(T::$results);
printf("\n%s\n  %d assertions in %.1fs   PASS %d   FIXED & RETESTED %d   FAIL %d\n",
    str_repeat('─', 72), $total, (hrtime(true) - $t_start) / 1e9, $by['PASS'], $by['FIXED'], $by['FAIL']);
if ($by['FAIL']) {
    echo "\n  Failing:\n";
    foreach (T::$results as $r) if ($r['status'] === 'FAIL') echo "   - {$r['id']}\n       {$r['detail']}\n";
}
echo $by['FAIL'] ? "\n  RESULT: FAILURES PRESENT\n\n" : "\n  RESULT: ALL GREEN (0 failed assertions)\n\n";
exit($by['FAIL'] ? 1 : 0);
