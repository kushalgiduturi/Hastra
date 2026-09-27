<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/sast_scanner.php
// Static analysis over an uploaded source archive (ZIP). Pure: no database,
// no side effects. The archive is never extracted to disk. Each entry is read
// through a capped stream straight from the ZIP, so a malicious archive can
// neither escape a directory (zip-slip) nor execute, and a lying size header
// can't smuggle in a zip bomb.
//
//   $result = astra_sast_scan_archive('/tmp/x.zip', function (array $p) { ... });
//   $result = ['findings' => [...], 'total' => n, 'scanned' => n, 'skipped' => [[path, reason], ...]]
//
// Rules (PHP-aware: comments are stripped first, line numbers preserved;
// "tainted" = a superglobal, or a local variable assigned from one without a
// sanitiser in the same file):
//   SAST-INC   dynamic include/require/readfile paths          CWE-98 / CWE-22
//   SAST-SQL   SQL built by interpolation or concatenation      CWE-89
//   SAST-XSS   tainted data echoed without escaping             CWE-79
//   SAST-EXEC  eval / system / shell with tainted input          CWE-78 / CWE-95
//   SAST-DESER unserialize() of tainted input                    CWE-502
//   SAST-SECRET hardcoded keys, tokens, passwords                CWE-798
//   SAST-CSRF  POST forms / handlers without an anti-CSRF token  CWE-352
//   SAST-GUARD include-only PHP files with no direct-access guard CWE-425

const SAST_MAX_ENTRIES     = 5000;
const SAST_MAX_FILE_BYTES  = 1048576;       // 1 MB per file
const SAST_MAX_TOTAL_BYTES = 104857600;     // 100 MB read in total
const SAST_TEXT_EXT = ['php', 'phtml', 'inc', 'html', 'htm', 'js', 'mjs', 'ts', 'json', 'env', 'ini', 'yml', 'yaml',
                       'xml', 'conf', 'config', 'sql', 'py', 'rb', 'sh', 'txt', 'md', 'twig', 'tpl',
                       'pem', 'key', 'p8', 'ppk'];   // key files: only the secret rules apply
const SAST_SKIP_DIRS = ['vendor', 'node_modules', '.git', 'cache', '.cache', 'dist', 'build', 'storage/framework',
                        '__pycache__', '.idea', '.vscode', 'bower_components'];
// (?<!\\) : "\$_GET" inside a double-quoted string is literal text, not a variable.
const SAST_SUPERGLOBAL = '(?<!\\\\)\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER\[\s*[\'"](?:HTTP_|QUERY_STRING|REQUEST_URI|PHP_SELF)[^\]]*\])';
const SAST_SANITISERS  = '(?:intval|floatval|\(int\)|\(float\)|\(bool\)|basename|filter_var|filter_input|preg_replace|htmlspecialchars|htmlentities|ctype_\w+|is_numeric|abs|max|min|in_array|array_key_exists|md5|sha1|hash|password_hash|json_encode|urlencode|rawurlencode|escapeshellarg|mysqli_real_escape_string|addslashes)\s*\(?';

// ── Archive walk ────────────────────────────────────────────────────────────
function astra_sast_scan_archive(string $zip_path, ?callable $progress = null): array {
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::RDONLY) !== true) throw new RuntimeException('The file is not a readable ZIP archive.');
    $n = $zip->numFiles;
    if ($n > SAST_MAX_ENTRIES) { $zip->close(); throw new RuntimeException("The archive has $n entries; the limit is " . SAST_MAX_ENTRIES . '.'); }

    $findings = []; $skipped = []; $scanned = 0; $read_total = 0; $files = 0;
    $entries = [];
    for ($i = 0; $i < $n; $i++) {
        $st = $zip->statIndex($i);
        if (!$st || str_ends_with($st['name'], '/')) continue;
        $entries[] = [$i, astra_sast_label($st['name'])];
    }
    $files = count($entries);
    foreach ($entries as $k => [$i, $label]) {
        $reason = astra_sast_skip_reason($label);
        $content = null;
        if ($reason === null) {
            $stream = $zip->getStream($zip->getNameIndex($i));
            if (!$stream) { $reason = 'unreadable'; }
            else {
                // Read at most the cap + 1 bytes: the declared size can lie.
                $content = stream_get_contents($stream, SAST_MAX_FILE_BYTES + 1);
                fclose($stream);
                if ($content === false) { $reason = 'unreadable'; $content = null; }
                elseif (strlen($content) > SAST_MAX_FILE_BYTES) { $reason = 'larger than 1 MB'; $content = null; }
                elseif (($read_total += strlen($content)) > SAST_MAX_TOTAL_BYTES) { $zip->close(); throw new RuntimeException('The archive expands past 100 MB of source. Upload a smaller subset.'); }
                elseif (str_contains(substr($content, 0, 8000), "\0")) { $reason = 'binary file'; $content = null; }
            }
        }
        if ($reason !== null) {
            $skipped[] = [$label, $reason];
        } else {
            $scanned++;
            foreach (astra_sast_scan_source($label, $content) as $f) $findings[] = $f;
        }
        if ($progress) $progress([
            'current_file' => $label, 'index' => $k + 1, 'total' => $files, 'scanned' => $scanned,
            'skipped' => count($skipped), 'skip_reason' => $reason,
            'percent' => $files ? round(($k + 1) * 100 / $files, 1) : 100.0, 'issues_found' => count($findings),
        ]);
    }
    $zip->close();
    return ['findings' => $findings, 'total' => $files, 'scanned' => $scanned, 'skipped' => $skipped];
}

// Entry names are only ever labels (nothing is written to disk), but keep
// them tidy and free of traversal segments for display.
function astra_sast_label(string $name): string {
    $name = str_replace('\\', '/', $name);
    $parts = [];
    foreach (explode('/', $name) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    return substr(implode('/', $parts), 0, 250) ?: '(unnamed)';
}

function astra_sast_skip_reason(string $path): ?string {
    $lower = strtolower($path);
    foreach (SAST_SKIP_DIRS as $d) {
        if (str_starts_with($lower, "$d/") || str_contains($lower, "/$d/")) return "$d/ directory";
    }
    if (preg_match('~\.min\.(js|css)$~', $lower)) return 'minified asset';
    $ext = pathinfo($lower, PATHINFO_EXTENSION);
    $base = basename($lower);
    if ($base === '.env' || str_starts_with($base, '.env.')) return null;
    if ($ext === '' || !in_array($ext, SAST_TEXT_EXT, true)) return $ext === '' ? 'no extension' : "not source (.$ext)";
    return null;
}

// ── Per-file analysis ───────────────────────────────────────────────────────
function astra_sast_scan_source(string $path, string $src): array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $is_php = in_array($ext, ['php', 'phtml', 'inc'], true);
    $code = $is_php ? astra_sast_strip_php_comments($src) : $src;
    $lines = preg_split('/\r\n|\r|\n/', $code);
    $raw_lines = preg_split('/\r\n|\r|\n/', $src);
    $out = [];
    $add = function (string $rule, string $sev, int $line, array $extra = []) use (&$out, $path, $raw_lines) {
        $out[] = $extra + ['rule_id' => $rule, 'severity' => $sev, 'file_path' => $path, 'line_number' => $line,
                           'code_snippet' => astra_sast_snippet($raw_lines, $line, $rule === 'SAST-SECRET')];
    };

    // Secrets apply to every text file.
    foreach (astra_sast_secret_hits($lines) as [$ln, $kind, $sev]) $add('SAST-SECRET', $sev, $ln, ['detail' => $kind]);

    if ($is_php || in_array($ext, ['html', 'htm', 'twig', 'tpl'], true)) {
        foreach (astra_sast_csrf_forms($code) as $ln) $add('SAST-CSRF', 'medium', $ln, ['detail' => 'form']);
    }
    if (!$is_php) return $out;

    $taint = astra_sast_tainted_vars($lines);
    $is_tainted = function (string $expr) use ($taint): bool {
        if (preg_match('~' . SAST_SUPERGLOBAL . '~', $expr)) return true;
        foreach ($taint as $v) if (preg_match('~(?<!\\\\)\$' . preg_quote($v, '~') . '\b~', $expr)) return true;
        return false;
    };
    // Variables forced to a number ((int), intval()) are safe to interpolate into SQL.
    $int_safe = [];
    foreach ($lines as $l) {
        if (preg_match('~\$(\w+)\s*=\s*(?:\(int\)|\(float\)|intval\s*\(|floatval\s*\(|count\s*\(|\(bool\))~', $l, $m)) $int_safe[$m[1]] = true;
    }
    // PHP's backtick shell operator, found by token (MySQL `identifier`
    // backticks inside strings are not shell commands).
    $shell_lines = [];
    $tokens = @token_get_all($src);
    $line_no = 1; $in_shell = false;
    foreach ($tokens as $tk) {
        if (is_array($tk)) { $line_no = $tk[2]; if ($in_shell && $tk[0] === T_VARIABLE) $shell_lines[$line_no] = true; continue; }
        if ($tk === '`') $in_shell = !$in_shell;
    }

    foreach ($lines as $i => $line) {
        $ln = $i + 1;
        if (trim($line) === '') continue;

        // Inclusion / file read with a dynamic path
        if (preg_match('~\b(include_once|require_once|include|require)\b\s*\(?\s*(.+?)\s*\)?\s*;~', $line, $m)) {
            $arg = $m[2];
            if ($is_tainted($arg)) $add('SAST-INC', 'critical', $ln, ['detail' => 'user-controlled include']);
            elseif (str_contains($arg, '$') && !preg_match('~^(__DIR__|dirname\(__FILE__\))\s*\.\s*[\'"][^\'"$]*[\'"]$~', $arg)) {
                $add('SAST-INC', 'medium', $ln, ['detail' => 'dynamic include']);
            }
        }
        if (preg_match('~\b(readfile|file_get_contents|fopen|file|show_source|highlight_file)\s*\(([^;]*)~', $line, $m) && $is_tainted($m[2])) {
            $add('SAST-INC', 'high', $ln, ['detail' => "user-controlled path in {$m[1]}()"]);
        }

        // SQL built from strings
        if (preg_match('~(?:\b(?:mysqli_query|mysql_query|pg_query|mysqli_multi_query|sqlite_query)\s*\(|->\s*(?:query|exec|multi_query|prepare)\s*\()(.*)$~i', $line, $m)) {
            $args = $m[1];
            preg_match_all('~(?<!\\\\)\$(\w+)~', preg_replace('~^\s*\$\w+\s*,~', '', $args), $vm); // skip the connection arg
            $vars = array_values(array_diff(array_unique($vm[1]), ['conn', 'db', 'pdo', 'mysqli', 'link', 'this']));
            $interp = $vars && (preg_match('~"[^"]*(?<!\\\\)\$\w+[^"]*"~', $args) || preg_match('~\.\s*(?<!\\\\)\$\w+|(?<!\\\\)\$\w+\s*\.~', $args));
            if ($interp && !array_diff($vars, array_keys($int_safe))) $interp = false; // only numeric values interpolated
            if ($interp) {
                $is_prepare = (bool)preg_match('~->\s*prepare\s*\(~i', $line);
                if ($is_tainted($args)) $add('SAST-SQL', 'critical', $ln, ['detail' => 'user input in SQL']);
                elseif (!$is_prepare) $add('SAST-SQL', 'medium', $ln, ['detail' => 'SQL assembled from variables']);
            }
        }

        // Unescaped output
        if (preg_match('~(?:\becho\b|\bprint\b|<\?=)\s*(.+?)(?:;|\?>|$)~', $line, $m)) {
            $expr = $m[1];
            if ($is_tainted($expr) && !preg_match('~\b(htmlspecialchars|htmlentities|intval|json_encode|urlencode|rawurlencode|number_format|strip_tags|e)\s*\(|\(int\)|\(float\)~', $expr)) {
                $add('SAST-XSS', 'high', $ln, ['detail' => 'tainted output']);
            }
        }

        // Code / command execution
        if (preg_match('~\b(eval|system|exec|shell_exec|passthru|popen|proc_open|assert|pcntl_exec)\s*\((.*)$~', $line, $m) && $is_tainted($m[2])) {
            $add('SAST-EXEC', 'critical', $ln, ['detail' => "{$m[1]}() with user input"]);
        } elseif (isset($shell_lines[$ln])) {
            $add('SAST-EXEC', 'high', $ln, ['detail' => 'backtick shell command with a variable']);
        }

        // Unsafe deserialisation
        if (preg_match('~\bunserialize\s*\((.*)$~', $line, $m) && $is_tainted($m[1]) && !str_contains($m[1], 'allowed_classes')) {
            $add('SAST-DESER', 'high', $ln, ['detail' => 'unserialize() of user input']);
        }
    }

    // File-level: POST handler with no CSRF verification anywhere in the file
    $handles_post = preg_match('~\$_SERVER\s*\[\s*[\'"]REQUEST_METHOD[\'"]\s*\]\s*={2,3}\s*[\'"]POST[\'"]~', $code);
    if ($handles_post && !preg_match('~csrf|_token\b|nonce|verify_token|hash_equals~i', $code)) {
        $ln = 1; foreach ($lines as $i => $l) { if (preg_match('~REQUEST_METHOD~', $l)) { $ln = $i + 1; break; } }
        $add('SAST-CSRF', 'medium', $ln, ['detail' => 'handler']);
    }

    // File-level: include-only file without a direct-access guard
    if (astra_sast_is_library($src) && !astra_sast_has_guard($code)) {
        $ln = 1; foreach ($lines as $i => $l) { if (preg_match('~^\s*(function|class|final class|abstract class)\s~', $l)) { $ln = $i + 1; break; } }
        $add('SAST-GUARD', 'low', $ln, ['detail' => 'library file']);
    }
    return $out;
}

function astra_sast_strip_php_comments(string $src): string {
    $out = '';
    foreach (@token_get_all($src) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $out .= str_repeat("\n", substr_count($t[1], "\n")); // keep line numbers
        } else {
            $out .= is_array($t) ? $t[1] : $t;
        }
    }
    return $out;
}

// Variables assigned from request input without a sanitiser on the same line.
function astra_sast_tainted_vars(array $lines): array {
    $vars = [];
    foreach ($lines as $l) {
        if (preg_match('~\$(\w+)\s*(?:\.=|=)(?!=)\s*(.+)$~', $l, $m) && preg_match('~' . SAST_SUPERGLOBAL . '~', $m[2])
            && !preg_match('~' . SAST_SANITISERS . '~i', $m[2])) {
            $vars[$m[1]] = true;
        }
    }
    unset($vars['_GET'], $vars['_POST'], $vars['_REQUEST'], $vars['_COOKIE'], $vars['_SERVER'], $vars['_FILES']);
    return array_keys($vars);
}

function astra_sast_secret_hits(array $lines): array {
    $hits = [];
    $patterns = [
        ['~-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP |ENCRYPTED )?PRIVATE KEY-----~', 'private key', 'critical'],
        ['~\bAKIA[0-9A-Z]{16}\b~', 'AWS access key id', 'critical'],
        ['~\bgh[pousr]_[A-Za-z0-9]{36,}\b~', 'GitHub token', 'critical'],
        ['~\bsk_live_[0-9a-zA-Z]{20,}\b~', 'Stripe live secret key', 'critical'],
        ['~\bsk-ant-[A-Za-z0-9_\-]{20,}~', 'Anthropic API key', 'critical'],
        ['~\bxox[baprs]-[0-9A-Za-z\-]{10,}~', 'Slack token', 'critical'],
        ['~\bAIza[0-9A-Za-z_\-]{35}\b~', 'Google API key', 'high'],
        ['~\beyJ[A-Za-z0-9_\-]{10,}\.eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}~', 'hardcoded JWT', 'high'],
    ];
    foreach ($lines as $i => $l) {
        foreach ($patterns as [$re, $kind, $sev]) {
            if (preg_match($re, $l)) { $hits[] = [$i + 1, $kind, $sev]; continue 2; }
        }
        // name = "literal" style assignments / defines / config keys
        if (preg_match('~(?:define\s*\(\s*[\'"][A-Z0-9_]*(?:PASS|PASSWORD|SECRET|API_KEY|TOKEN|PRIVATE_KEY)[A-Z0-9_]*[\'"]\s*,|[\'"]?\b[\w\-]*(?:password|passwd|secret|api[_-]?key|access[_-]?token|auth[_-]?token|private[_-]?key|client[_-]?secret)\b[\'"]?\s*(?:=>|=|:))\s*[\'"]([^\'"\s]{8,})[\'"]~i', $l, $m)
            && !astra_sast_is_placeholder($m[1])) {
            $hits[] = [$i + 1, 'hardcoded credential', 'high'];
            continue;
        }
        // Literal password argument to a DB connection
        if (preg_match('~\b(mysqli_connect|new\s+mysqli|new\s+PDO)\s*\((?:[^,]*,){2}\s*[\'"]([^\'"]{4,})[\'"]~i', $l, $m)
            && !astra_sast_is_placeholder($m[2])) {
            $hits[] = [$i + 1, 'database password in code', 'high'];
        }
    }
    return $hits;
}
function astra_sast_is_placeholder(string $v): bool {
    return (bool)preg_match('~^(\$2[aby]\$|\$argon2|your[_-]|change[_-]?me|example|placeholder|xxxx|\*{4}|<|\{\{|%|getenv|env\()~i', $v)
        || preg_match('~^[A-Z_]+$~', $v); // a constant name, not a value
}

// POST forms (in PHP or HTML) that carry no anti-CSRF token field.
function astra_sast_csrf_forms(string $code): array {
    $out = [];
    if (!preg_match_all('~<form\b([^>]*)>(.*?)</form>~is', $code, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return $out;
    foreach ($m as $form) {
        if (!preg_match('~method\s*=\s*[\'"]?post~i', $form[1][0])) continue;
        if (preg_match('~name\s*=\s*[\'"][^\'"]*(csrf|token|nonce)[^\'"]*[\'"]|csrf~i', $form[2][0])) continue;
        $out[] = substr_count(substr($code, 0, $form[0][1]), "\n") + 1;
    }
    return $out;
}

function astra_sast_is_library(string $src): bool {
    if (!preg_match('~^\s*(?:final\s+|abstract\s+)?(function|class|interface|trait)\s+\w+~m', $src)) return false;
    foreach (@token_get_all($src) as $t) {
        if (is_array($t) && $t[0] === T_INLINE_HTML && trim($t[1]) !== '') return false;           // renders HTML
        if (is_array($t) && in_array($t[0], [T_ECHO, T_PRINT, T_EXIT], true)) return false;        // talks to the client
    }
    // Top-level request handling means it's an entry point, not a library.
    return !preg_match('~\$_(GET|POST|REQUEST)\b~', preg_replace('~function\s+\w+\s*\([^)]*\)\s*(?::\s*\??\w+\s*)?\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\}~s', '', $src));
}
function astra_sast_has_guard(string $code): bool {
    $head = implode("\n", array_slice(preg_split('/\R/', $code), 0, 20));
    return (bool)preg_match('~defined\s*\(\s*[\'"]\w+[\'"]\s*\)|SCRIPT_FILENAME|PHP_SAPI|php_sapi_name\s*\(|ABSPATH|http_response_code\s*\(\s*40[34]\s*\)~', $head);
}

function astra_sast_snippet(array $lines, int $line, bool $mask): string {
    $from = max(1, $line - 2); $to = min(count($lines), $line + 2);
    $out = [];
    for ($n = $from; $n <= $to; $n++) {
        $text = rtrim($lines[$n - 1] ?? '');
        if ($mask) $text = astra_sast_mask($text);
        if (mb_strlen($text) > 220) $text = mb_substr($text, 0, 220) . '…';
        $out[] = sprintf('%s%4d | %s', $n === $line ? '>' : ' ', $n, $text);
    }
    return implode("\n", $out);
}
// Never store a secret in the findings table: keep 2 characters, mask the rest.
function astra_sast_mask(string $s): string {
    // Quoted values are masked; UPPER_CASE constant names stay readable.
    $s = preg_replace_callback('~([\'"])([^\'"\s]{6,})\1~', fn($m) => preg_match('~^[A-Z0-9_]+$~', $m[2]) ? $m[0]
        : $m[1] . substr($m[2], 0, 2) . str_repeat('•', min(12, strlen($m[2]) - 2)) . $m[1], $s);
    return preg_replace('~(AKIA|gh[pousr]_|sk_live_|sk-ant-|xox[baprs]-|AIza|eyJ)[A-Za-z0-9_\-\.]+~', '$1••••••••', $s);
}

// ── Explanations & fixes ────────────────────────────────────────────────────
// title, CWE, executive summary, steps, before, after.
function astra_sast_rule_meta(string $rule, string $detail = ''): array {
    $R = [
        'SAST-INC' => ['Unvalidated file inclusion or path', 'CWE-98',
            'A file path is built from data the visitor controls. An attacker can make the page read or run a different file, such as a configuration file with credentials, or (with remote includes enabled) code from their own server.',
            ['Never pass request data into include/require or file functions.',
             'Map the allowed choices to fixed paths with an allow-list, and reject everything else.',
             'Anchor paths to __DIR__, and after resolving with realpath() confirm the result is still inside the intended directory.'],
            "\$page = \$_GET['page'];\ninclude \$page . '.php';",
            "\$pages = ['home' => 'home.php', 'help' => 'help.php'];\n\$page  = \$pages[\$_GET['page'] ?? 'home'] ?? null;\nif (\$page === null) { http_response_code(404); exit; }\ninclude __DIR__ . '/pages/' . \$page;"],
        'SAST-SQL' => ['SQL query built from strings', 'CWE-89',
            'The SQL statement is assembled by pasting values into the query text. If any of those values can come from a visitor, they can rewrite the query: read other customers\' data, log in as anyone, or delete tables.',
            ['Use a prepared statement with placeholders for every value.',
             'Bind values with mysqli_stmt_bind_param() or PDO execute([...]); never concatenate them.',
             'For identifiers that can\'t be bound (column names, sort order), pick from a fixed allow-list.'],
            "\$id = \$_GET['id'];\n\$r  = mysqli_query(\$conn, \"SELECT * FROM users WHERE id = \$id\");",
            "\$stmt = mysqli_prepare(\$conn, 'SELECT * FROM users WHERE id = ?');\nmysqli_stmt_bind_param(\$stmt, 'i', \$_GET['id']);\nmysqli_stmt_execute(\$stmt);\n\$r = mysqli_stmt_get_result(\$stmt);"],
        'SAST-XSS' => ['Unescaped user input in page output', 'CWE-79',
            'Visitor-supplied text is written into the page as raw HTML. An attacker can send a link that runs their script in your users\' browsers, stealing sessions or acting as the victim.',
            ['Escape every dynamic value for the context it lands in: htmlspecialchars() for HTML, json_encode() for JavaScript.',
             'Use ENT_QUOTES and UTF-8 so attribute values are covered too.',
             'Add a Content-Security-Policy header as a second layer.'],
            "echo 'Hello ' . \$_GET['name'];",
            "echo 'Hello ' . htmlspecialchars(\$_GET['name'] ?? '', ENT_QUOTES, 'UTF-8');"],
        'SAST-EXEC' => ['Code or shell command built from input', 'CWE-78',
            'Input reaches a function that runs code or operating-system commands. An attacker can run arbitrary commands on your server, which usually means full compromise.',
            ['Remove eval() entirely; there is almost always a safe data-driven alternative.',
             'Avoid shell calls. If one is unavoidable, pass each argument through escapeshellarg() and use a fixed command.',
             'Validate the input against a strict allow-list before it gets near the call.'],
            "system('convert ' . \$_POST['file'] . ' out.png');",
            "\$file = basename(\$_POST['file'] ?? '');\nif (!preg_match('/^[\\w-]+\\.(png|jpg)\$/', \$file)) exit('Invalid file');\nsystem('convert ' . escapeshellarg(__DIR__ . '/uploads/' . \$file) . ' out.png');"],
        'SAST-DESER' => ['Unsafe deserialisation of input', 'CWE-502',
            'unserialize() on visitor data can instantiate arbitrary classes and trigger their magic methods, which attackers chain into file writes or code execution.',
            ['Exchange data as JSON (json_decode) instead of PHP serialisation.',
             'If unserialize() is unavoidable, pass [\'allowed_classes\' => false].'],
            "\$prefs = unserialize(\$_COOKIE['prefs']);",
            "\$prefs = json_decode(\$_COOKIE['prefs'] ?? '{}', true) ?: [];"],
        'SAST-SECRET' => ['Hardcoded secret or credential', 'CWE-798',
            'A key, token or password is written into the source. Anyone who can read the code (a repository, a backup, a leaked archive) can use it, and rotating it requires a code change.',
            ['Revoke and rotate the exposed credential now: assume it is already known.',
             'Load secrets from environment variables or a key file outside the web root and outside version control.',
             'Add the key file to .gitignore and scan the repository history for earlier copies.'],
            "define('DB_PASS', 'your-db-password');",
            "define('DB_PASS', getenv('APP_DB_PASS') ?: '');  // set in the server environment"],
        'SAST-CSRF' => ['State-changing request without CSRF protection', 'CWE-352',
            'A form or POST handler accepts requests without proving they came from your own page. Another site can make a signed-in user submit it without knowing: changing their email, transferring funds or deleting data.',
            ['Issue a random per-session token (bin2hex(random_bytes(32))) and put it in a hidden field in every POST form.',
             'On every POST, compare it with hash_equals() before doing anything, and reject a mismatch.',
             'Set session cookies with SameSite=Lax or Strict as a second layer.'],
            "<form method=\"post\" action=\"delete.php\">\n  <button>Delete account</button>\n</form>",
            "<form method=\"post\" action=\"delete.php\">\n  <input type=\"hidden\" name=\"csrf_token\" value=\"<?= htmlspecialchars(\$_SESSION['csrf']) ?>\">\n  <button>Delete account</button>\n</form>\n<?php // delete.php\nif (!hash_equals(\$_SESSION['csrf'] ?? '', \$_POST['csrf_token'] ?? '')) { http_response_code(403); exit; }"],
        'SAST-GUARD' => ['Include-only file can be requested directly', 'CWE-425',
            'This file is meant to be included by other pages, but nothing stops a browser from requesting it on its own. That can expose errors, internal paths or half-initialised behaviour.',
            ['Keep library files outside the web root where possible.',
             'Otherwise, add a guard on the first line so a direct request gets a 404.',
             'Or deny the directory in the web server config (Apache: Require all denied).'],
            "<?php\nfunction helper() { /* ... */ }",
            "<?php\nif (realpath(\$_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit; }\nfunction helper() { /* ... */ }"],
    ];
    $r = $R[$rule] ?? ['Security issue', null, 'A potential security issue was detected.', ['Review this code.'], '', ''];
    $title = $r[0];
    if ($rule === 'SAST-SECRET' && $detail) $title = 'Hardcoded ' . $detail;
    if ($rule === 'SAST-CSRF' && $detail === 'handler') $title = 'POST handler without CSRF verification';
    if ($rule === 'SAST-INC' && str_contains($detail, 'path in')) $r[1] = 'CWE-22';
    if ($rule === 'SAST-EXEC' && str_starts_with($detail, 'eval')) $r[1] = 'CWE-95';
    return ['title' => $title, 'cwe_id' => $r[1], 'description' => $r[2],
            'remediation' => ['steps' => $r[3], 'before' => $r[4], 'after' => $r[5], 'lang' => str_contains($r[4], '<form') ? 'html' : 'php', 'references' => []]];
}
