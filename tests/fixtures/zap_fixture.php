<?php
// tests/fixtures/zap_fixture.php
// Sample OWASP ZAP reports in the shapes ZAP 2.14 exports ("Traditional
// JSON" / "Traditional XML"), with alerts repeated across two sites so the
// clustering is exercised. Returns report strings; nothing is fetched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(); }

function zap_fixture_alerts(): array {
    return [
        'https://shop.example.test' => [
            ['pluginid' => '10038', 'alertRef' => '10038-1', 'alert' => 'Content Security Policy (CSP) Header Not Set', 'riskcode' => '2', 'confidence' => '3',
             'riskdesc' => 'Medium (High)', 'cweid' => '693', 'wascid' => '15', 'desc' => '<p>Content Security Policy (CSP) is an added layer of security.</p>',
             'solution' => '<p>Ensure that your web server sets the Content-Security-Policy header.</p>', 'reference' => '<p>https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP</p>',
             'instances' => [['uri' => 'https://shop.example.test/', 'method' => 'GET', 'param' => '', 'evidence' => ''],
                             ['uri' => 'https://shop.example.test/cart', 'method' => 'GET', 'param' => '', 'evidence' => ''],
                             ['uri' => 'https://shop.example.test/', 'method' => 'GET', 'param' => '', 'evidence' => '']]],
            ['pluginid' => '40018', 'alertRef' => '40018', 'alert' => 'SQL Injection', 'riskcode' => '3', 'confidence' => '3',
             'riskdesc' => 'High (High)', 'cweid' => '89', 'wascid' => '19', 'desc' => '<p>SQL injection may be possible.</p>',
             'solution' => '<p>Use prepared statements.</p>', 'reference' => '',
             'instances' => [['uri' => 'https://shop.example.test/item?id=5', 'method' => 'GET', 'param' => 'id', 'evidence' => "5' OR '1'='1"]]],
            ['pluginid' => '10010', 'alertRef' => '10010', 'alert' => 'Cookie No HttpOnly Flag', 'riskcode' => '1', 'confidence' => '2',
             'riskdesc' => 'Low (Medium)', 'cweid' => '1004', 'wascid' => '13', 'desc' => '<p>A cookie has been set without the HttpOnly flag.</p>',
             'solution' => '<p>Ensure that the HttpOnly flag is set for all cookies.</p>', 'reference' => '',
             'instances' => [['uri' => 'https://shop.example.test/login', 'method' => 'POST', 'param' => 'PHPSESSID', 'evidence' => 'Set-Cookie: PHPSESSID']]],
            ['pluginid' => '10096', 'alertRef' => '10096', 'alert' => 'Timestamp Disclosure - Unix', 'riskcode' => '0', 'confidence' => '1',
             'riskdesc' => 'Informational (Low)', 'cweid' => '200', 'wascid' => '13', 'desc' => '<p>A timestamp was disclosed.</p>', 'solution' => '<p>Manually confirm.</p>',
             'reference' => '', 'instances' => [['uri' => 'https://shop.example.test/app.js', 'method' => 'GET', 'param' => '', 'evidence' => '1700000000']]],
        ],
        'https://api.example.test' => [
            ['pluginid' => '10038', 'alertRef' => '10038-1', 'alert' => 'Content Security Policy (CSP) Header Not Set', 'riskcode' => '2', 'confidence' => '3',
             'riskdesc' => 'Medium (High)', 'cweid' => '693', 'wascid' => '15', 'desc' => '<p>Content Security Policy (CSP) is an added layer of security.</p>',
             'solution' => '<p>Ensure that your web server sets the Content-Security-Policy header.</p>', 'reference' => '',
             'instances' => [['uri' => 'https://api.example.test/docs', 'method' => 'GET', 'param' => '', 'evidence' => '']]],
        ],
    ];
}

function zap_fixture_json(): string {
    $sites = [];
    foreach (zap_fixture_alerts() as $name => $alerts) {
        $sites[] = ['@name' => $name, '@host' => parse_url($name, PHP_URL_HOST), '@port' => '443', '@ssl' => 'true',
                    'alerts' => array_map(fn($a) => $a + ['name' => $a['alert'], 'count' => (string)count($a['instances'])], $alerts)];
    }
    return json_encode(['@programName' => 'ZAP', '@version' => '2.14.0', '@generated' => 'Tue, 23 Sep 2026 10:00:00', 'site' => $sites], JSON_PRETTY_PRINT);
}

function zap_fixture_xml(): string {
    $x = '<?xml version="1.0"?><OWASPZAPReport programName="ZAP" version="2.14.0" generated="Tue, 23 Sep 2026 10:00:00">';
    foreach (zap_fixture_alerts() as $name => $alerts) {
        $x .= '<site name="' . htmlspecialchars($name) . '" host="' . parse_url($name, PHP_URL_HOST) . '" port="443" ssl="true"><alerts>';
        foreach ($alerts as $a) {
            $x .= '<alertitem>';
            foreach (['pluginid', 'alertRef', 'alert', 'riskcode', 'confidence', 'riskdesc', 'desc', 'solution', 'reference', 'cweid', 'wascid'] as $k) {
                $x .= "<$k>" . htmlspecialchars($a[$k], ENT_XML1) . "</$k>";
            }
            $x .= '<name>' . htmlspecialchars($a['alert'], ENT_XML1) . '</name><instances>';
            foreach ($a['instances'] as $i) {
                $x .= '<instance><uri>' . htmlspecialchars($i['uri'], ENT_XML1) . '</uri><method>' . $i['method'] . '</method><param>'
                    . htmlspecialchars($i['param'], ENT_XML1) . '</param><evidence>' . htmlspecialchars($i['evidence'], ENT_XML1) . '</evidence></instance>';
            }
            $x .= '</instances><count>' . count($a['instances']) . '</count></alertitem>';
        }
        $x .= '</alerts></site>';
    }
    return $x . '</OWASPZAPReport>';
}

// SARIF 2.1.0 in the shape ZAP 2.17's "Sarif JSON Report" writes: one rule
// per plugin, one result per occurrence, severity as a level.
function zap_fixture_sarif(): string {
    $levels = ['3' => 'error', '2' => 'warning', '1' => 'note', '0' => 'none'];
    $rules = []; $results = [];
    foreach (zap_fixture_alerts() as $alerts) foreach ($alerts as $a) {
        $rules[$a['pluginid']] = ['id' => $a['pluginid'], 'name' => $a['alert'], 'defaultConfiguration' => ['level' => $levels[$a['riskcode']]],
            'fullDescription' => ['text' => strip_tags($a['desc'])],
            'properties' => ['confidence' => ['1' => 'low', '2' => 'medium', '3' => 'high'][$a['confidence']], 'solution' => ['text' => strip_tags($a['solution'])], 'references' => []],
            'relationships' => [['kinds' => ['superset'], 'target' => ['id' => $a['cweid'], 'toolComponent' => ['name' => 'CWE']]]]];
        foreach ($a['instances'] as $i) {
            $results[] = ['ruleId' => $a['pluginid'], 'level' => $levels[$a['riskcode']], 'message' => ['text' => strip_tags($a['desc'])],
                'locations' => [['physicalLocation' => ['artifactLocation' => ['uri' => $i['uri']], 'region' => ['startLine' => 1, 'snippet' => ['text' => $i['evidence']]]],
                                 'properties' => ['attack' => '']]],
                'webRequest' => ['protocol' => 'HTTP', 'version' => '1.1', 'target' => $i['uri'], 'method' => $i['method']]];
        }
    }
    return json_encode(['$schema' => 'https://json.schemastore.org/sarif-2.1.0.json', 'version' => '2.1.0',
        'runs' => [['tool' => ['driver' => ['name' => 'ZAP', 'semanticVersion' => '2.17.0', 'rules' => array_values($rules)]], 'results' => $results]]]);
}

// The ZAP API's alert list (/JSON/core/view/alerts/): one entry per
// occurrence, risk and confidence as words. $bare = a plain array.
function zap_fixture_api(bool $bare = false): string {
    $risk = ['3' => 'High', '2' => 'Medium', '1' => 'Low', '0' => 'Informational'];
    $conf = ['1' => 'Low', '2' => 'Medium', '3' => 'High'];
    $list = []; $id = 0;
    foreach (zap_fixture_alerts() as $alerts) foreach ($alerts as $a) foreach ($a['instances'] as $i) {
        $list[] = ['sourceid' => '3', 'other' => '', 'method' => $i['method'], 'evidence' => $i['evidence'], 'pluginId' => $a['pluginid'],
            'cweid' => $a['cweid'], 'confidence' => $conf[$a['confidence']], 'wascid' => $a['wascid'], 'description' => strip_tags($a['desc']),
            'messageId' => (string)(100 + $id), 'inputVector' => '', 'url' => $i['uri'], 'tags' => new stdClass(), 'reference' => '',
            'solution' => strip_tags($a['solution']), 'alert' => $a['alert'], 'param' => $i['param'], 'attack' => '', 'name' => $a['alert'],
            'risk' => $risk[$a['riskcode']], 'id' => (string)$id++, 'alertRef' => $a['alertRef']];
    }
    return json_encode($bare ? $list : ['alerts' => $list]);
}

function zap_fixture_xxe(): string {
    return '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///C:/xampp/htdocs/login/config/config.php">]>'
         . '<OWASPZAPReport version="2.14.0"><site name="&x;"><alerts/></site></OWASPZAPReport>';
}
