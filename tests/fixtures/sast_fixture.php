<?php
// tests/fixtures/sast_fixture.php
// Builds an in-memory ZIP of known-vulnerable and known-safe sample files for
// the SAST tests. Returns [zip path, expectations]. The samples are strings
// only; nothing here is ever executed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

function sast_fixture_zip(): array {
    $files = [
        // ── vulnerable ────────────────────────────────────────────────────
        'app/view.php' => "<?php\n\$page = \$_GET['page'];\ninclude \$page . '.php';\nreadfile(\$_GET['doc']);\n",
        'app/user.php' => "<?php\n\$id = \$_GET['id'];\n\$r = mysqli_query(\$conn, \"SELECT * FROM users WHERE id = \$id\");\n\$q = \$db->query('SELECT * FROM t WHERE name = ' . \$name);\n",
        'app/hello.php' => "<?php\necho 'Hello ' . \$_GET['name'];\n?>\n<p><?= \$_POST['bio'] ?></p>\n",
        'app/run.php' => "<?php\nsystem('ping ' . \$_POST['host']);\neval(\$_REQUEST['code']);\n",
        'app/prefs.php' => "<?php\n\$p = unserialize(\$_COOKIE['prefs']);\n",
        'config/settings.php' => "<?php\ndefine('DB_PASS', 'Pr0duction-Pa55!');\n\$aws = 'AKIAIOSFODNN7EXAMPLE';\n\$conn = mysqli_connect('db', 'root', 'hunter2hunter2', 'app');\n",
        'keys/deploy.pem' => "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAexamplekeymaterial\n-----END RSA PRIVATE KEY-----\n",
        'app/delete.php' => "<?php\nif (\$_SERVER['REQUEST_METHOD'] === 'POST') { delete_account(\$_SESSION['uid']); }\n?>\n<form method=\"post\"><button>Delete</button></form>\n",
        'lib/helpers.php' => "<?php\nfunction slugify(\$s) { return strtolower(\$s); }\n",
        // ── safe (must produce no findings) ───────────────────────────────
        'safe/user.php' => "<?php\n// \$r = mysqli_query(\$conn, \"SELECT * WHERE id = \$id\"); (a comment, not code)\n\$stmt = mysqli_prepare(\$conn, 'SELECT * FROM users WHERE id = ?');\nmysqli_stmt_bind_param(\$stmt, 'i', \$_GET['id']);\n\$id = (int)\$_GET['id'];\necho 'Hello ' . htmlspecialchars(\$_GET['name'] ?? '', ENT_QUOTES, 'UTF-8');\ninclude __DIR__ . '/partials/header.php';\ndefine('DB_PASS', getenv('APP_DB_PASS'));\n\$hash = '\$2y\$10\$abcdefghijklmnopqrstuv';\n",
        'safe/form.php' => "<?php\nif (\$_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals(\$_SESSION['csrf'], \$_POST['csrf_token'] ?? '')) { save(); }\n?>\n<form method=\"post\"><input type=\"hidden\" name=\"csrf_token\" value=\"x\"><button>Save</button></form>\n<form method=\"get\"><input name=\"q\"></form>\n",
        'safe/lib.php' => "<?php\nif (realpath(\$_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit; }\nfunction ok() { return 1; }\n",
        // ── skipped ───────────────────────────────────────────────────────
        'vendor/lib/big.php' => "<?php eval(\$_GET['x']);",
        'public/app.min.js' => 'var a=1;',
        'public/logo.png' => "\x89PNG\r\n\x1a\n\0\0\0",
        'node_modules/x/index.js' => 'module.exports = 1;',
        '../../escape.php' => "<?php echo \$_GET['x'];", // zip-slip style name: labelled, never written
    ];
    $path = tempnam(sys_get_temp_dir(), 'sastfx') . '.zip';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $body) $zip->addFromString($name, $body);
    $zip->close();
    $expect = [
        // file => [rule => min count]
        'app/view.php'        => ['SAST-INC' => 2],
        'app/user.php'        => ['SAST-SQL' => 2],
        'app/hello.php'       => ['SAST-XSS' => 2],
        'app/run.php'         => ['SAST-EXEC' => 2],
        'app/prefs.php'       => ['SAST-DESER' => 1],
        'config/settings.php' => ['SAST-SECRET' => 3],
        'keys/deploy.pem'     => ['SAST-SECRET' => 1],
        'app/delete.php'      => ['SAST-CSRF' => 2],
        'lib/helpers.php'     => ['SAST-GUARD' => 1],
    ];
    return [$path, $expect, ['safe/user.php', 'safe/form.php', 'safe/lib.php'],
            ['vendor/lib/big.php', 'public/app.min.js', 'public/logo.png', 'node_modules/x/index.js']];
}
