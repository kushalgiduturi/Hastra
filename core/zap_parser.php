<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/zap_parser.php
// OWASP ZAP report ingestion: parses the "Traditional JSON" and
// "Traditional XML" report formats (current and older layouts), clusters
// duplicate alerts across sites into one finding per alert type with every
// affected endpoint listed once, and attaches developer remediation from the
// knowledge base below (keyed by ZAP plugin id, with a CWE fallback).
// Pure: no database access.
//
// XML safety: any document declaring a DOCTYPE or ENTITY is rejected before
// parsing (blocks XXE and entity-expansion "billion laughs"), and libxml runs
// with network access off and entity substitution off.

const ZAP_MAX_BYTES     = 10485760; // 10 MB
const ZAP_MAX_INSTANCES = 200;      // endpoints kept per clustered finding

// Returns ['site' => [...names], 'alerts' => [normalized...], 'tool' => 'ZAP 2.x']
function astra_zap_parse(string $raw): array {
    if (strlen($raw) > ZAP_MAX_BYTES) throw new InvalidArgumentException('The report is larger than 10 MB.');
    $raw = ltrim($raw, "\xEF\xBB\xBF \t\r\n");
    if ($raw === '') throw new InvalidArgumentException('The file is empty.');
    return ($raw[0] === '{' || $raw[0] === '[') ? astra_zap_parse_json($raw) : astra_zap_parse_xml($raw);
}

// ZAP writes JSON in several shapes; all are accepted:
//   Traditional JSON / Traditional JSON Plus   {"@programName", "site": [...]}
//   SARIF JSON                                 {"$schema", "version", "runs": [...]}
//   ZAP API alert list                          {"alerts": [ {pluginId, alert, risk, url, ...} ]}
//   a bare array of API alerts                  [ {pluginId, alert, risk, url, ...} ]
function astra_zap_parse_json(string $raw): array {
    $doc = json_decode($raw, true, 128);
    if (!is_array($doc)) throw new InvalidArgumentException('Not valid JSON: ' . json_last_error_msg());
    if (isset($doc['runs']) && is_array($doc['runs'])) return astra_zap_parse_sarif($doc);
    $api = isset($doc['alerts']) && is_array($doc['alerts']) && !isset($doc['site']) ? $doc['alerts']
         : (astra_zap_is_list($doc) && isset($doc[0]) && is_array($doc[0]) && (isset($doc[0]['pluginId']) || isset($doc[0]['alert'])) ? $doc : null);
    if ($api !== null) return astra_zap_parse_api($api);
    $sites = $doc['site'] ?? null;
    if ($sites === null) {
        throw new InvalidArgumentException("This JSON isn't a format ZAP produces that Hastra recognises. Accepted: Traditional JSON, Traditional JSON Plus, "
            . "SARIF JSON, and the alert list from ZAP's API (/JSON/core/view/alerts/). XML: Traditional XML.");
    }
    if (isset($sites['@name'])) $sites = [$sites];
    $out = ['sites' => [], 'alerts' => [], 'tool' => trim('ZAP ' . ($doc['@version'] ?? ''))];
    foreach ($sites as $site) {
        $name = (string)($site['@name'] ?? '');
        $out['sites'][] = $name;
        foreach ($site['alerts'] ?? [] as $a) {
            $inst = [];
            foreach ($a['instances'] ?? [] as $i) $inst[] = astra_zap_instance($i['uri'] ?? '', $i['method'] ?? '', $i['param'] ?? '', $i['evidence'] ?? '');
            if (!$inst && !empty($a['uri'])) $inst[] = astra_zap_instance($a['uri'], $a['method'] ?? '', $a['param'] ?? '', $a['evidence'] ?? '');
            $out['alerts'][] = astra_zap_alert($a, $name, $inst);
        }
    }
    return $out;
}

function astra_zap_is_list(array $a): bool { return $a === [] || array_keys($a) === range(0, count($a) - 1); }

// SARIF 2.1.0 as written by ZAP's "Sarif JSON Report". Rules carry the
// description, solution, confidence and CWE; results carry the level, URL,
// evidence and (for plugins with several alerts, like CSP) which variant.
function astra_zap_parse_sarif(array $doc): array {
    $out = ['sites' => [], 'alerts' => [], 'tool' => ''];
    $levels = ['error' => 3, 'warning' => 2, 'note' => 1, 'none' => 0];
    $conf = ['false positive' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'confirmed' => 4];
    foreach ($doc['runs'] as $run) {
        $driver = $run['tool']['driver'] ?? [];
        $out['tool'] = trim(($driver['name'] ?? 'ZAP') . ' ' . ($driver['semanticVersion'] ?? $driver['version'] ?? ''));
        $rules = [];
        foreach ($driver['rules'] ?? [] as $r) $rules[(string)($r['id'] ?? '')] = $r;
        foreach ($run['results'] ?? [] as $res) {
            $rid  = (string)($res['ruleId'] ?? '');
            $rule = $rules[$rid] ?? [];
            [$plugin, $ref] = array_pad(explode('-', $rid, 2), 2, '');
            $cwe = '';
            foreach ($rule['relationships'] ?? [] as $rel) {
                if (strtoupper($rel['target']['toolComponent']['name'] ?? '') === 'CWE') $cwe = (string)($rel['target']['id'] ?? '');
            }
            $msg  = trim((string)($res['message']['text'] ?? ''));
            $name = (string)($rule['name'] ?? $rule['shortDescription']['text'] ?? $rid);
            // SARIF folds CSP variants into one rule; the message names the variant.
            if ($plugin === '10055' && $msg !== '') { $name = 'CSP: ' . rtrim($msg, '.'); $ref = substr(md5($msg), 0, 8); }
            $inst = [];
            foreach ($res['locations'] ?? [] as $loc) {
                $pl = $loc['physicalLocation'] ?? [];
                $inst[] = astra_zap_instance((string)($pl['artifactLocation']['uri'] ?? ''), (string)($res['webRequest']['method'] ?? 'GET'),
                    (string)($loc['properties']['param'] ?? $res['properties']['param'] ?? ''), (string)($pl['region']['snippet']['text'] ?? ''));
            }
            $site = $inst ? (parse_url($inst[0]['uri'], PHP_URL_SCHEME) . '://' . parse_url($inst[0]['uri'], PHP_URL_HOST)) : '';
            if ($site !== '' && !in_array($site, $out['sites'], true)) $out['sites'][] = $site;
            $out['alerts'][] = astra_zap_alert([
                'pluginid' => $plugin, 'alertRef' => $ref, 'alert' => $name,
                'riskcode' => (string)($levels[$res['level'] ?? $rule['defaultConfiguration']['level'] ?? 'warning'] ?? 2),
                'confidence' => (string)($conf[strtolower((string)($rule['properties']['confidence'] ?? 'medium'))] ?? 2),
                'desc' => (string)($rule['fullDescription']['text'] ?? ''), 'solution' => (string)($rule['properties']['solution']['text'] ?? ''),
                'reference' => implode(' ', (array)($rule['properties']['references'] ?? [])), 'cweid' => $cwe, 'count' => count($inst),
            ], $site, $inst);
        }
    }
    return $out;
}

// ZAP API alerts (/JSON/core/view/alerts/, /JSON/alert/view/alerts/): one
// entry per occurrence, with risk and confidence as words.
function astra_zap_parse_api(array $alerts): array {
    $out = ['sites' => [], 'alerts' => [], 'tool' => 'ZAP API'];
    $risk = ['high' => 3, 'medium' => 2, 'low' => 1, 'informational' => 0, 'info' => 0];
    $conf = ['false positive' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'confirmed' => 4];
    foreach ($alerts as $a) {
        if (!is_array($a)) continue;
        $uri = (string)($a['url'] ?? '');
        $site = $uri !== '' ? parse_url($uri, PHP_URL_SCHEME) . '://' . parse_url($uri, PHP_URL_HOST) : '';
        if ($site !== '' && !in_array($site, $out['sites'], true)) $out['sites'][] = $site;
        $out['alerts'][] = astra_zap_alert([
            'pluginid' => (string)($a['pluginId'] ?? ''), 'alertRef' => (string)($a['alertRef'] ?? ''),
            'alert' => (string)($a['alert'] ?? $a['name'] ?? ''),
            'riskcode' => (string)($risk[strtolower((string)($a['risk'] ?? ''))] ?? 0),
            'confidence' => (string)($conf[strtolower((string)($a['confidence'] ?? 'medium'))] ?? 2),
            'desc' => (string)($a['description'] ?? ''), 'solution' => (string)($a['solution'] ?? ''),
            'reference' => (string)($a['reference'] ?? ''), 'cweid' => (string)($a['cweid'] ?? ''), 'wascid' => (string)($a['wascid'] ?? ''),
            'count' => 1,
        ], $site, [astra_zap_instance($uri, (string)($a['method'] ?? 'GET'), (string)($a['param'] ?? ''), (string)($a['evidence'] ?? ''))]);
    }
    return $out;
}

function astra_zap_parse_xml(string $raw): array {
    if (preg_match('~<!DOCTYPE|<!ENTITY~i', $raw)) {
        throw new InvalidArgumentException('XML with a DOCTYPE or entity declarations is refused (XXE protection). ZAP reports never need one.');
    }
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if ($xml === false) throw new InvalidArgumentException('Not well-formed XML.');
    if ($xml->getName() !== 'OWASPZAPReport') throw new InvalidArgumentException("This XML isn't a ZAP report (root element is <{$xml->getName()}>).");
    $out = ['sites' => [], 'alerts' => [], 'tool' => trim('ZAP ' . (string)$xml['version'])];
    foreach ($xml->site as $site) {
        $name = (string)$site['name'];
        $out['sites'][] = $name;
        foreach ($site->alerts->alertitem ?? [] as $a) {
            $arr = [];
            foreach ($a->children() as $k => $v) if ($k !== 'instances') $arr[$k] = (string)$v;
            $inst = [];
            foreach ($a->instances->instance ?? [] as $i) {
                $inst[] = astra_zap_instance((string)$i->uri, (string)$i->method, (string)$i->param, (string)$i->evidence);
            }
            if (!$inst && isset($arr['uri'])) $inst[] = astra_zap_instance($arr['uri'], $arr['method'] ?? '', $arr['param'] ?? '', $arr['evidence'] ?? '');
            $out['alerts'][] = astra_zap_alert($arr, $name, $inst);
        }
    }
    return $out;
}

function astra_zap_instance(string $uri, string $method, string $param, string $evidence): array {
    return ['method' => strtoupper(trim($method)) ?: 'GET', 'uri' => trim($uri), 'param' => trim($param),
            'evidence' => mb_substr(trim($evidence), 0, 300)];
}

function astra_zap_alert(array $a, string $site, array $instances): array {
    $risk = (int)($a['riskcode'] ?? -1);
    if ($risk < 0 && preg_match('~^(High|Medium|Low|Informational)~i', (string)($a['riskdesc'] ?? ''), $m)) {
        $risk = ['high' => 3, 'medium' => 2, 'low' => 1, 'informational' => 0][strtolower($m[1])];
    }
    $conf = (int)($a['confidence'] ?? 2);
    $cwe  = (string)($a['cweid'] ?? '');
    $severity = [3 => 'high', 2 => 'medium', 1 => 'low', 0 => 'info'][$risk] ?? 'info';
    // ZAP has no "critical": a high-risk, high-confidence injection or
    // execution class is raised to critical.
    if ($severity === 'high' && $conf >= 3 && in_array($cwe, ['89', '78', '77', '94', '95', '98', '22', '611', '917'], true)) $severity = 'critical';
    return [
        'plugin_id'  => (string)($a['pluginid'] ?? $a['pluginId'] ?? ''),
        'alert_ref'  => (string)($a['alertRef'] ?? ''),
        'name'       => html_entity_decode(trim((string)($a['alert'] ?? $a['name'] ?? 'Unnamed alert')), ENT_QUOTES),
        'severity'   => $severity,
        'riskdesc'   => (string)($a['riskdesc'] ?? ''),
        'confidence' => [0 => 'false positive', 1 => 'low', 2 => 'medium', 3 => 'high', 4 => 'confirmed'][$conf] ?? 'medium',
        'cwe_id'     => ($cwe !== '' && $cwe !== '-1' && $cwe !== '0') ? 'CWE-' . $cwe : null,
        'wasc_id'    => (($w = (string)($a['wascid'] ?? '')) !== '' && $w !== '-1' && $w !== '0') ? 'WASC-' . $w : null,
        'desc'       => astra_zap_text($a['desc'] ?? ''),
        'solution'   => astra_zap_text($a['solution'] ?? ''),
        'reference'  => array_values(array_filter(preg_split('~\s+~', astra_zap_text($a['reference'] ?? '')), fn($u) => preg_match('~^https?://~', $u))),
        'site'       => $site,
        'instances'  => $instances,
        'count'      => max((int)($a['count'] ?? 0), count($instances)),
    ];
}

// ZAP text fields are HTML fragments: keep the words, drop the markup.
function astra_zap_text($html): string {
    $t = preg_replace(['~</p>\s*<p>~i', '~<br\s*/?>~i'], "\n", (string)$html);
    return trim(html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

// One finding per alert type across all sites; endpoints de-duplicated.
function astra_zap_cluster(array $parsed): array {
    $clusters = []; $seen = [];
    foreach ($parsed['alerts'] as $a) {
        $key = $a['plugin_id'] !== '' ? $a['plugin_id'] . '|' . $a['alert_ref'] : 'name|' . strtolower($a['name']);
        if (!isset($clusters[$key])) { $clusters[$key] = $a; $clusters[$key]['instances'] = []; $clusters[$key]['count'] = 0; $clusters[$key]['sites'] = []; $seen[$key] = []; }
        $c = &$clusters[$key];
        $c['sites'][$a['site']] = true;
        $c['count'] += $a['count'];
        foreach ($a['instances'] as $i) {
            $ik = $i['method'] . ' ' . $i['uri'] . ' ' . $i['param'];
            if (isset($seen[$key][$ik])) continue;
            $seen[$key][$ik] = true;
            if (count($c['instances']) < ZAP_MAX_INSTANCES) $c['instances'][] = $i;
        }
        if (astra_zap_rank($a['severity']) > astra_zap_rank($c['severity'])) $c['severity'] = $a['severity'];
        unset($c);
    }
    foreach ($clusters as &$c) { $c['sites'] = array_keys($c['sites']); $c['count'] = max($c['count'], count($c['instances'])); }
    unset($c);
    $list = array_values($clusters);
    usort($list, fn($x, $y) => astra_zap_rank($y['severity']) <=> astra_zap_rank($x['severity']) ?: $y['count'] <=> $x['count']);
    return $list;
}
function astra_zap_rank(string $s): int { return ['info' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4][$s] ?? 0; }

// ── Developer remediation knowledge base ───────────────────────────────────
// [summary (plain language), steps[], before, after, lang]
function astra_zap_remediation(array $alert): array {
    $kb = astra_zap_kb();
    $pid = $alert['plugin_id'];
    $cwe = $alert['cwe_id'] ? substr($alert['cwe_id'], 4) : '';
    // One plugin can raise several different alerts (10055 covers every CSP
    // weakness), so pick by the alert itself before falling back to the plugin.
    $e = astra_zap_variant($kb, $pid, (string)($alert['name'] ?? '')) ?? $kb['plugin'][$pid] ?? ($kb['cwe'][$cwe] ?? null);
    if (!$e) {
        $e = [$alert['desc'] !== '' ? mb_substr($alert['desc'], 0, 600) : 'ZAP flagged this as a weakness in the running application.',
              array_values(array_filter(preg_split('~\n+~', $alert['solution'] ?: 'Review the affected endpoints and apply the fix ZAP describes.'))), '', '', 'text'];
    }
    return ['summary' => $e[0], 'steps' => $e[1], 'before' => $e[2], 'after' => $e[3], 'lang' => $e[4],
            'references' => array_slice($alert['reference'], 0, 5)];
}

// Alert-specific advice for plugins that report several distinct problems.
function astra_zap_variant(array $kb, string $pid, string $name): ?array {
    if ($pid !== '10055') return null;
    $n = strtolower($name);
    // SARIF names variants by message ("script-src includes unsafe-inline").
    if (str_contains($n, 'unsafe-inline')) {
        if (str_contains($n, 'script-src')) return $kb['variant']['csp_script_inline'];
        if (str_contains($n, 'style-src'))  return $kb['variant']['csp_style_inline'];
    }
    foreach ([
        'no fallback'     => 'csp_nofallback',
        'script-src unsafe-inline' => 'csp_script_inline',
        'style-src unsafe-inline'  => 'csp_style_inline',
        'unsafe-eval'     => 'csp_eval',
        'wildcard'        => 'csp_wildcard',
        'unsafe-hashes'   => 'csp_script_inline',
    ] as $needle => $key) {
        if (str_contains($n, $needle)) return $kb['variant'][$key];
    }
    return null;
}

function astra_zap_kb(): array {
    static $kb = null;
    if ($kb) return $kb;
    $csp = ["Browsers aren't told which scripts and sources your pages may load. If an attacker ever injects markup (XSS), nothing stops their script from running and sending your users' data elsewhere.",
        ['Send a Content-Security-Policy header on every HTML response.',
         'Start strict (default-src \'self\') and add only the origins you really use (fonts, CDNs, analytics).',
         'Roll it out with Content-Security-Policy-Report-Only first to catch breakage, then enforce.',
         'Avoid \'unsafe-inline\' for scripts; move inline scripts to files or use nonces.'],
        "# No policy: any injected <script> runs",
        "# Apache (.htaccess or vhost)\nHeader always set Content-Security-Policy \"default-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'\"\n\n// or in PHP, before any output\nheader(\"Content-Security-Policy: default-src 'self'; script-src 'self'; object-src 'none'; frame-ancestors 'none'\");", 'apache'];
    $xfo = ["Other websites can load your pages inside an invisible frame and trick users into clicking buttons they can't see (clickjacking), such as confirming a payment or changing settings.",
        ['Forbid framing with Content-Security-Policy: frame-ancestors \'none\' (or \'self\' if you frame your own pages).',
         'Also send X-Frame-Options: DENY for older browsers.'],
        "# Pages can be framed by any site",
        "Header always set X-Frame-Options \"DENY\"\nHeader always set Content-Security-Policy \"frame-ancestors 'none'\"", 'apache'];
    $nosniff = ["Browsers may guess a file's type from its contents. An uploaded text file that looks like HTML or JavaScript can then be executed as such.",
        ['Send X-Content-Type-Options: nosniff on every response.', 'Serve every file with its correct Content-Type.'],
        "# Browser may 'sniff' uploads as HTML/JS", "Header always set X-Content-Type-Options \"nosniff\"", 'apache'];
    $hsts = ["Nothing forces browsers to stay on HTTPS. Someone on the same network can downgrade a visitor to plain HTTP and read or alter everything, including session cookies.",
        ['Redirect all HTTP traffic to HTTPS.', 'Send Strict-Transport-Security with a long max-age once HTTPS works everywhere.', 'Add includeSubDomains when every subdomain is HTTPS-only.'],
        "# HTTPS works, but browsers aren't told to insist on it",
        "RewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]\nHeader always set Strict-Transport-Security \"max-age=31536000; includeSubDomains\"", 'apache'];
    $cookie = ["A session or auth cookie is missing protective flags. Without HttpOnly, injected scripts can read it; without Secure it travels over plain HTTP; without SameSite other sites can make the browser send it along with forged requests.",
        ['Set HttpOnly, Secure and SameSite=Lax (or Strict) on every cookie that identifies a user.',
         'Configure it once for PHP sessions, before session_start().',
         'Set the same flags on any cookie you create with setcookie().'],
        "session_start();\nsetcookie('remember', \$token);",
        "session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);\nsession_start();\nsetcookie('remember', \$token, ['expires' => time() + 2592000, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);", 'php'];
    $csrf = ["Forms change data without a secret token, so another site can make a signed-in user submit them unknowingly: changing an email, transferring money, deleting records.",
        ['Generate a random token per session: $_SESSION[\'csrf\'] = bin2hex(random_bytes(32)).',
         'Add it as a hidden field to every state-changing form.',
         'On POST, compare with hash_equals() and reject mismatches before doing anything.',
         'Set session cookies SameSite=Lax as a second layer.'],
        "<form method=\"post\" action=\"/profile\">\n  <input name=\"email\">\n</form>",
        "<form method=\"post\" action=\"/profile\">\n  <input type=\"hidden\" name=\"csrf_token\" value=\"<?= htmlspecialchars(\$_SESSION['csrf']) ?>\">\n  <input name=\"email\">\n</form>\n<?php\nif (!hash_equals(\$_SESSION['csrf'] ?? '', \$_POST['csrf_token'] ?? '')) { http_response_code(403); exit; }", 'php'];
    $xss = ["User input is reflected into the page without escaping. An attacker can send a link that runs their JavaScript in your users' browsers, stealing sessions or acting on their behalf.",
        ['Escape every dynamic value for its context: htmlspecialchars($v, ENT_QUOTES, \'UTF-8\') in HTML, json_encode() inside scripts.',
         'Validate input types (numbers, dates, fixed choices) on the server.',
         'Add a strict Content-Security-Policy as defence in depth.'],
        "<p>Results for <?= \$_GET['q'] ?></p>",
        "<p>Results for <?= htmlspecialchars(\$_GET['q'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>", 'php'];
    $sqli = ["The application builds database queries from user input. An attacker can read or change any data in the database, bypass login, or in some setups run commands on the server.",
        ['Replace every query that includes input with a prepared statement and bound parameters.',
         'Use an allow-list for anything that can\'t be a parameter (column names, sort direction).',
         'Run the app with a database user that has only the privileges it needs.'],
        "\$r = mysqli_query(\$conn, \"SELECT * FROM orders WHERE id = \" . \$_GET['id']);",
        "\$stmt = mysqli_prepare(\$conn, 'SELECT * FROM orders WHERE id = ?');\nmysqli_stmt_bind_param(\$stmt, 'i', \$_GET['id']);\nmysqli_stmt_execute(\$stmt);", 'php'];
    $redirect = ["A redirect target comes from the request. Attackers use your trusted domain to bounce victims to phishing sites.",
        ['Only redirect to relative paths on your own site, or to an allow-list of hosts.', 'Reject absolute URLs and protocol-relative (//) targets.'],
        "header('Location: ' . \$_GET['next']);",
        "\$next = \$_GET['next'] ?? '/';\nif (!preg_match('~^/(?!/)~', \$next)) \$next = '/';\nheader('Location: ' . \$next);", 'php'];
    $version = ["The server announces its software and version. That tells attackers exactly which known exploits to try.",
        ['Hide the Apache version and OS: ServerTokens Prod and ServerSignature Off (httpd.conf).',
         'Stop PHP advertising itself: expose_php = Off (php.ini).',
         'Remove the X-Powered-By header.'],
        "Server: Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12\nX-Powered-By: PHP/8.2.12",
        "# httpd.conf\nServerTokens Prod\nServerSignature Off\n# php.ini\nexpose_php = Off\n# .htaccess\nHeader always unset X-Powered-By", 'apache'];
    $errors = ["Error pages reveal internal details (stack traces, file paths, SQL). That maps the application for an attacker.",
        ['Turn off display_errors in production and log errors instead.', 'Show a generic error page to users.'],
        "display_errors = On", "; php.ini (production)\ndisplay_errors = Off\nlog_errors = On\nerror_log = \"C:/xampp/php/logs/php_error.log\"", 'ini'];
    $traversal = ["A file path parameter lets an attacker step outside the intended folder (../../) and read system or configuration files.",
        ['Never build file paths from raw input; map ids to known files.', 'If you must accept a name, apply basename() and confirm realpath() stays inside the allowed directory.'],
        "readfile('files/' . \$_GET['name']);",
        "\$base = realpath(__DIR__ . '/files');\n\$path = realpath(\$base . '/' . basename(\$_GET['name'] ?? ''));\nif (\$path === false || strpos(\$path, \$base . DIRECTORY_SEPARATOR) !== 0) { http_response_code(404); exit; }\nreadfile(\$path);", 'php'];
    $cors = ["The server lets any website read its responses with the visitor's cookies attached, so a malicious page can pull private data from your API.",
        ['Allow only your own front-end origins in Access-Control-Allow-Origin.', 'Never combine a wildcard or reflected Origin with Access-Control-Allow-Credentials: true.'],
        "header('Access-Control-Allow-Origin: ' . \$_SERVER['HTTP_ORIGIN']);\nheader('Access-Control-Allow-Credentials: true');",
        "\$allowed = ['https://app.example.com'];\n\$origin = \$_SERVER['HTTP_ORIGIN'] ?? '';\nif (in_array(\$origin, \$allowed, true)) {\n    header('Access-Control-Allow-Origin: ' . \$origin);\n    header('Vary: Origin');\n}", 'php'];
    $cache = ["Pages with personal or authenticated data may be stored by browsers or shared caches, so the next person on a shared computer or proxy can see them.",
        ['Send Cache-Control: no-store on authenticated and personal pages.', 'Keep long caching for static, public assets only.'],
        "# No cache headers on account pages", "header('Cache-Control: no-store, max-age=0');\nheader('Pragma: no-cache');", 'php'];
    $sri = ["Scripts load from a third-party CDN without an integrity check. If that CDN is compromised, altered code runs on your site.",
        ['Add integrity (SRI hash) and crossorigin attributes to external script and stylesheet tags.', 'Or self-host the files.'],
        "<script src=\"https://cdn.example.com/lib.js\"></script>",
        "<script src=\"https://cdn.example.com/lib.js\"\n        integrity=\"sha384-…\" crossorigin=\"anonymous\"></script>", 'html'];
    $perm = ["The site doesn't restrict powerful browser features (camera, microphone, geolocation), so injected or embedded content could request them.",
        ['Send a Permissions-Policy header disabling features you don\'t use.'],
        "# No Permissions-Policy", "Header always set Permissions-Policy \"camera=(), microphone=(), geolocation=()\"", 'apache'];

    $info = ["A response exposes internal details (debug text, internal addresses, identifiers, comments). On its own it rarely causes harm, but it helps an attacker map the system.",
        ['Look at the evidence on each endpoint and confirm whether the value is sensitive.',
         'Remove debug output, internal hostnames and developer comments from production responses.',
         'If the value is harmless by design (for example a public build timestamp), mark this finding as a false positive.'],
        '', '', 'text'];
    $timestamp = ["A response contains what looks like a Unix timestamp. Usually this is harmless (a build or cache-busting value); it only matters if it reveals something like an account creation time or an internal schedule.",
        ['Check the evidence value on each endpoint.', 'If it is a build or cache value, mark this as a false positive.', 'If it reveals user or server state, remove it from the response.'],
        '', '', 'text'];

    // ── CSP variants (plugin 10055) ───────────────────────────────────────
    $csp_nofallback = ["Your Content-Security-Policy exists but leaves out directives that do not inherit from default-src. Without form-action, an injected form can post your users' input to another site; without frame-ancestors, other sites can frame your pages.",
        ['Add form-action \'self\' so forms can only submit to your own site.',
         'Add frame-ancestors \'none\' (or \'self\') so no other site can frame your pages.',
         'Set these in every place that sends a CSP (web server config and application code), because browsers enforce all policies they receive.'],
        "Content-Security-Policy: default-src 'self'; script-src 'self'",
        "Content-Security-Policy: default-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'", 'apache'];
    $csp_script_inline = ["script-src allows 'unsafe-inline', so any script injected into a page runs. That switches off the main protection a CSP gives against XSS.",
        ['Move inline <script> blocks and onclick="" handlers into .js files.',
         'Where an inline script must stay, give it a per-request nonce and allow only that nonce in script-src.',
         'Remove \'unsafe-inline\' from script-src once no page depends on it. Test with Content-Security-Policy-Report-Only first.'],
        "header(\"Content-Security-Policy: script-src 'self' 'unsafe-inline'\");\n?>\n<script>init();</script>\n<button onclick=\"save()\">Save</button>",
        "\$nonce = base64_encode(random_bytes(16));\nheader(\"Content-Security-Policy: script-src 'self' 'nonce-\$nonce'\");\n?>\n<script nonce=\"<?= \$nonce ?>\">init();</script>\n<button id=\"save\">Save</button>\n<script src=\"/js/save.js\"></script>  <!-- attaches the click handler -->", 'php'];
    $csp_style_inline = ["style-src allows 'unsafe-inline'. This is much lower risk than inline scripts, but an attacker who can inject markup can use CSS to restyle pages (fake login prompts, hidden overlays) or leak some data through selectors.",
        ['Treat this as lower priority than script-src.',
         'Move <style> blocks and style="" attributes into stylesheets over time.',
         'Then drop \'unsafe-inline\' from style-src, or allow specific blocks with nonces or hashes.'],
        "Content-Security-Policy: style-src 'self' 'unsafe-inline'",
        "Content-Security-Policy: style-src 'self' 'nonce-<per-request nonce>'", 'apache'];
    $csp_eval = ["script-src allows 'unsafe-eval', letting scripts turn strings into code (eval, new Function). Injected data can then become executable.",
        ['Find and replace eval(), new Function() and string arguments to setTimeout/setInterval.', 'Remove \'unsafe-eval\' from script-src.'],
        "setTimeout(\"refresh(\" + id + \")\", 500);", "setTimeout(() => refresh(id), 500);", 'js'];
    $csp_wildcard = ["A CSP directive allows any source (*) or a very broad one, so it barely restricts what pages can load.",
        ['Replace wildcards with the exact origins you use.', 'Avoid https: or * on script-src and object-src.'],
        "Content-Security-Policy: script-src *", "Content-Security-Policy: script-src 'self' https://cdn.jsdelivr.net", 'apache'];
    $powered = ["Every response advertises the PHP version (X-Powered-By). That tells attackers which PHP vulnerabilities to try.",
        ['Remove the header in the web server: Header always unset X-Powered-By.',
         'Or stop PHP adding it: expose_php = Off in php.ini, or header_remove(\'X-Powered-By\') early in your bootstrap.'],
        "X-Powered-By: PHP/8.0.30",
        "# .htaccess\nHeader always unset X-Powered-By\n\n// PHP bootstrap, before any output\nheader_remove('X-Powered-By');", 'apache'];
    $server = ["The Server header reveals the exact Apache, OpenSSL and PHP versions, which narrows down which known exploits apply.",
        ['In httpd.conf (server level; not possible in .htaccess), set ServerTokens Prod and ServerSignature Off.', 'Restart Apache.'],
        "Server: Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.0.30",
        "# httpd.conf\nServerTokens Prod\nServerSignature Off\n# the header becomes just: Server: Apache", 'apache'];
    $xdomain = ["Pages load JavaScript from other domains. Whatever that domain serves runs with full access to your page, so a compromise of the provider compromises you.",
        ['Keep third-party scripts to providers you trust and actually need.',
         'Self-host libraries where you can (for example a pinned copy of a charting or 3D library).',
         'For files that never change, add integrity (SRI) hashes. Services that change their script (such as reCAPTCHA) can\'t use SRI; restrict them in CSP instead.'],
        "<script src=\"https://cdn.example.com/lib@1.2.3/lib.js\"></script>",
        "<script src=\"/assets/vendor/lib-1.2.3.js\"></script>  <!-- self-hosted, pinned -->", 'html'];
    $session = ["ZAP noticed how the site hands out session identifiers. This is informational: it tells ZAP where the session is so it can track it, not a flaw.",
        ['No change needed unless another finding says the cookie lacks HttpOnly, Secure or SameSite.', 'Mark this as a false positive to hide it.'], '', '', 'text'];
    $attr = ["A value from the URL or a form ends up inside an HTML attribute. If it isn't escaped, an attacker could break out of the attribute and add script (for example \" onmouseover=\"...).",
        ['Check the listed parameter and the page that echoes it.', 'Escape with htmlspecialchars($v, ENT_QUOTES, \'UTF-8\') so quotes are encoded.', 'Better, validate the value against the allowed options.'],
        "<input value=\"<?= \$_GET['q'] ?>\">",
        "<input value=\"<?= htmlspecialchars(\$_GET['q'] ?? '', ENT_QUOTES, 'UTF-8') ?>\">", 'php'];

    return $kb = [
        'variant' => ['csp_nofallback' => $csp_nofallback, 'csp_script_inline' => $csp_script_inline, 'csp_style_inline' => $csp_style_inline,
                      'csp_eval' => $csp_eval, 'csp_wildcard' => $csp_wildcard],
        'plugin' => [
            '10096' => $timestamp, '10027' => $info, '10062' => $info, '2' => $info, '3' => $info,
            '10112' => $session, '10031' => $attr,
            '10038' => $csp, '10055' => $csp, '10020' => $xfo, '10021' => $nosniff, '10035' => $hsts,
            '10010' => $cookie, '10011' => $cookie, '10054' => $cookie, '90033' => $cookie,
            '10202' => $csrf, '20012' => $csrf,
            '40012' => $xss, '40014' => $xss, '40016' => $xss, '40017' => $xss, '40026' => $xss,
            '40018' => $sqli, '40019' => $sqli, '40020' => $sqli, '40021' => $sqli, '40022' => $sqli, '40024' => $sqli,
            '10028' => $redirect, '20019' => $redirect,
            '10036' => $server, '10037' => $powered, '90022' => $errors, '10023' => $errors,
            '6'     => $traversal, '40009' => $traversal,
            '10098' => $cors, '40040' => $cors,
            '10049' => $cache, '10015' => $cache,
            '10017' => $xdomain, '90003' => $sri, '10063' => $perm,
        ],
        'cwe' => [
            '693' => $csp, '1021' => $xfo, '319' => $hsts, '614' => $cookie, '1004' => $cookie, '1275' => $cookie,
            '352' => $csrf, '79' => $xss, '89' => $sqli, '601' => $redirect, '200' => $info, '209' => $errors,
            '497' => $version, '22' => $traversal, '264' => $cors, '942' => $cors, '525' => $cache, '829' => $sri,
        ],
    ];
}
