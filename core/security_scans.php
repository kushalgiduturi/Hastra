<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/security_scans.php
// Storage and tenancy for the Scan Center (portals/security/scan_center.php).
// Every read and write is keyed by the caller's company_id: one company can
// never see, change or stream another company's scans or findings.

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/audit_chain.php';
require_once __DIR__ . '/sast_scanner.php';
require_once __DIR__ . '/zap_parser.php';

const SCAN_ARCHIVE_MAX = 20971520;  // 20 MB upload
const SCAN_STALE_HOURS = 24;        // unscanned archives older than this are deleted

function astra_security_ready($conn, bool $refresh = false): bool {
    static $ready = null;
    if ($ready === null || $refresh) {
        $r = mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                                  AND TABLE_NAME IN ('security_scans', 'security_findings')");
        $ready = $r && (int)mysqli_fetch_row($r)[0] === 2;
    }
    return $ready;
}

// Who may use the Scan Center, and for which company. Every registered
// account with a company (admin, employee, client, sysadmin); not accounts
// still waiting for approval, and not accounts without a company.
function astra_security_context($conn, int $user_id): ?array {
    $q = mysqli_prepare($conn,
        "SELECT u.role, u.company_id, c.company_name, c.account_type
         FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.id = ?");
    mysqli_stmt_bind_param($q, "i", $user_id);
    mysqli_stmt_execute($q);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    if (!$r || !$r['company_id'] || !in_array($r['role'], ['admin', 'employee', 'client', 'sysadmin'], true)) return null;
    return ['company_id' => (int)$r['company_id'], 'company_name' => $r['company_name'], 'account_type' => $r['account_type'], 'role' => $r['role']];
}

// Archives wait here (outside the web root) until scanned, then are deleted.
function astra_security_temp_dir(): string {
    $d = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'astra_sast';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
function astra_security_sweep_stale($conn): void {
    $rows = mysqli_query($conn, "SELECT id, archive_path FROM security_scans WHERE archive_path IS NOT NULL
                                 AND created_at < NOW() - INTERVAL " . SCAN_STALE_HOURS . " HOUR");
    while ($r = mysqli_fetch_assoc($rows)) {
        if ($r['archive_path'] && is_file($r['archive_path'])) @unlink($r['archive_path']);
        mysqli_query($conn, "UPDATE security_scans SET archive_path = NULL,
                             status = IF(status IN ('queued','running'), 'failed', status),
                             error = IF(status IN ('queued','running'), 'The scan never ran; the upload was discarded.', error)
                             WHERE id = " . (int)$r['id']);
    }
}

function astra_security_create_scan($conn, array $ctx, int $user_id, string $type, string $label, ?string $archive = null): int {
    $status = $type === 'sast_codebase' ? 'queued' : 'running';
    $label = mb_substr($label, 0, 250);
    $q = mysqli_prepare($conn, "INSERT INTO security_scans (company_id, user_id, scan_type, status, source_label, archive_path) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($q, "iissss", $ctx['company_id'], $user_id, $type, $status, $label, $archive);
    mysqli_stmt_execute($q);
    return (int)mysqli_insert_id($conn);
}

function astra_security_get_scan($conn, int $company_id, int $scan_id): ?array {
    $q = mysqli_prepare($conn, "SELECT * FROM security_scans WHERE id = ? AND company_id = ?");
    mysqli_stmt_bind_param($q, "ii", $scan_id, $company_id);
    mysqli_stmt_execute($q);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($q)) ?: null;
}

// Atomically move a queued scan to running (only one stream may run it).
function astra_security_claim_scan($conn, int $company_id, int $scan_id): bool {
    $q = mysqli_prepare($conn, "UPDATE security_scans SET status = 'running' WHERE id = ? AND company_id = ? AND status = 'queued'");
    mysqli_stmt_bind_param($q, "ii", $scan_id, $company_id);
    mysqli_stmt_execute($q);
    return mysqli_stmt_affected_rows($q) === 1;
}

// ── Normalisation ──────────────────────────────────────────────────────────
function astra_security_from_sast(array $f, int $company_id): array {
    $meta = astra_sast_rule_meta($f['rule_id'], $f['detail'] ?? '');
    $line_text = '';
    foreach (explode("\n", $f['code_snippet']) as $l) if (str_starts_with($l, '>')) $line_text = preg_replace('~^>\s*\d+ \| ~', '', $l);
    return [
        'source' => 'sast_engine', 'rule_id' => $f['rule_id'], 'title' => $meta['title'], 'severity' => $f['severity'],
        'cwe_id' => $meta['cwe_id'], 'wasc_id' => null, 'confidence' => null,
        'file_path' => $f['file_path'], 'line_number' => $f['line_number'], 'affected_url' => null, 'vulnerable_param' => null,
        'instance_count' => 1, 'description' => $meta['description'],
        'remediation_steps' => json_encode($meta['remediation'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'code_snippet' => $f['code_snippet'],
        'fingerprint' => hash('sha256', implode('|', [$company_id, 'sast', $f['rule_id'], $f['file_path'], trim($line_text), $f['detail'] ?? ''])),
    ];
}
function astra_security_from_zap(array $a, int $company_id): array {
    $rem = astra_zap_remediation($a);
    $params = array_values(array_unique(array_filter(array_column($a['instances'], 'param'))));
    $evidence = array_values(array_filter(array_column($a['instances'], 'evidence')));
    return [
        'source' => 'owasp_zap', 'rule_id' => 'ZAP-' . ($a['plugin_id'] ?: 'X'), 'title' => mb_substr($a['name'], 0, 250),
        'severity' => $a['severity'], 'cwe_id' => $a['cwe_id'], 'wasc_id' => $a['wasc_id'], 'confidence' => $a['confidence'],
        'file_path' => null, 'line_number' => null,
        'affected_url' => json_encode($a['instances'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'vulnerable_param' => $params ? mb_substr(implode(', ', $params), 0, 128) : null,
        'instance_count' => $a['count'], 'description' => $rem['summary'],
        'remediation_steps' => json_encode(['steps' => $rem['steps'], 'before' => $rem['before'], 'after' => $rem['after'],
            'lang' => $rem['lang'], 'references' => $rem['references'], 'zap_solution' => mb_substr($a['solution'], 0, 1500),
            'sites' => $a['sites']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'code_snippet' => $evidence ? mb_substr(implode("\n", array_slice($evidence, 0, 5)), 0, 1200) : null,
        'fingerprint' => hash('sha256', implode('|', [$company_id, 'zap', $a['plugin_id'], $a['alert_ref'], strtolower($a['name'])])),
    ];
}

// ── Persistence ────────────────────────────────────────────────────────────
function astra_security_store_findings($conn, array $scan, array $rows): array {
    $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
    // A finding already triaged as a false positive stays that way.
    $fp = mysqli_prepare($conn, "SELECT 1 FROM security_findings WHERE company_id = ? AND fingerprint = ? AND status = 'false_positive' LIMIT 1");
    $ins = mysqli_prepare($conn,
        "INSERT INTO security_findings (scan_id, company_id, source, rule_id, title, severity, cwe_id, wasc_id, confidence,
            file_path, line_number, affected_url, vulnerable_param, instance_count, description, remediation_steps,
            code_snippet, fingerprint, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $seen = [];
    foreach ($rows as $r) {
        if (isset($seen[$r['fingerprint']])) continue; // same issue twice in one scan
        $seen[$r['fingerprint']] = true;
        mysqli_stmt_bind_param($fp, "is", $scan['company_id'], $r['fingerprint']);
        mysqli_stmt_execute($fp);
        $status = mysqli_fetch_row(mysqli_stmt_get_result($fp)) ? 'false_positive' : 'open';
        mysqli_stmt_bind_param($ins, "iissssssssississsss", $scan['id'], $scan['company_id'], $r['source'], $r['rule_id'], $r['title'],
            $r['severity'], $r['cwe_id'], $r['wasc_id'], $r['confidence'], $r['file_path'], $r['line_number'], $r['affected_url'],
            $r['vulnerable_param'], $r['instance_count'], $r['description'], $r['remediation_steps'], $r['code_snippet'],
            $r['fingerprint'], $status);
        mysqli_stmt_execute($ins);
        if ($status === 'open') $counts[$r['severity']]++;
    }
    return $counts;
}

function astra_security_finish_scan($conn, array $scan, array $counts, array $stats, array $summary): void {
    $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $q = mysqli_prepare($conn,
        "UPDATE security_scans SET status = 'completed', total_files = ?, scanned_files = ?, skipped_files = ?,
             critical_count = ?, high_count = ?, medium_count = ?, low_count = ?, info_count = ?,
             report_summary = ?, archive_path = NULL, completed_at = NOW()
         WHERE id = ? AND company_id = ?");
    mysqli_stmt_bind_param($q, "iiiiiiiisii", $stats['total'], $stats['scanned'], $stats['skipped'],
        $counts['critical'], $counts['high'], $counts['medium'], $counts['low'], $counts['info'], $json, $scan['id'], $scan['company_id']);
    mysqli_stmt_execute($q);
    if (!empty($scan['archive_path']) && is_file($scan['archive_path'])) @unlink($scan['archive_path']);
}
function astra_security_fail_scan($conn, array $scan, string $error): void {
    $q = mysqli_prepare($conn, "UPDATE security_scans SET status = 'failed', error = ?, archive_path = NULL, completed_at = NOW() WHERE id = ? AND company_id = ?");
    mysqli_stmt_bind_param($q, "sii", $error, $scan['id'], $scan['company_id']);
    mysqli_stmt_execute($q);
    if (!empty($scan['archive_path']) && is_file($scan['archive_path'])) @unlink($scan['archive_path']);
}

// Runs a queued SAST scan end to end; $emit(event, data) streams progress.
function astra_security_run_sast($conn, array $scan, callable $emit): array {
    $last = 0.0;
    try {
        $result = astra_sast_scan_archive((string)$scan['archive_path'], function (array $p) use ($emit, &$last) {
            // Throttle to ~20 updates a second; always send the last file.
            $now = microtime(true);
            if ($now - $last < 0.05 && $p['index'] < $p['total']) return;
            $last = $now;
            $emit('progress', $p);
        });
    } catch (Throwable $e) {
        astra_security_fail_scan($conn, $scan, $e->getMessage());
        throw $e;
    }
    $rows = array_map(fn($f) => astra_security_from_sast($f, (int)$scan['company_id']), $result['findings']);
    $counts = astra_security_store_findings($conn, $scan, $rows);
    $stats = ['total' => $result['total'], 'scanned' => $result['scanned'], 'skipped' => count($result['skipped'])];
    $skip_reasons = [];
    foreach ($result['skipped'] as [, $why]) $skip_reasons[$why] = ($skip_reasons[$why] ?? 0) + 1;
    astra_security_finish_scan($conn, $scan, $counts, $stats, [
        'engine' => 'Hastra SAST', 'skipped_by_reason' => $skip_reasons,
        'skipped_sample' => array_slice(array_map(fn($s) => $s[0] . ' (' . $s[1] . ')', $result['skipped']), 0, 50),
    ]);
    astra_log_chained('SECURITY_SCAN_COMPLETED', "SAST scan #{$scan['id']}: {$stats['scanned']} files, " . array_sum($counts) . ' open findings',
        (int)$scan['user_id'], $conn);
    return ['counts' => $counts, 'stats' => $stats];
}

// Parses and stores a ZAP report synchronously (it's fast).
function astra_security_import_zap($conn, array $ctx, int $user_id, string $label, string $raw): array {
    $parsed = astra_zap_parse($raw);                 // throws InvalidArgumentException with a clear message
    $clusters = astra_zap_cluster($parsed);
    $scan_id = astra_security_create_scan($conn, $ctx, $user_id, 'dast_zap_import', $label);
    $scan = astra_security_get_scan($conn, $ctx['company_id'], $scan_id);
    $counts = astra_security_store_findings($conn, $scan, array_map(fn($a) => astra_security_from_zap($a, $ctx['company_id']), $clusters));
    $raw_alerts = count($parsed['alerts']);
    astra_security_finish_scan($conn, $scan, $counts, ['total' => $raw_alerts, 'scanned' => count($clusters), 'skipped' => 0],
        ['engine' => $parsed['tool'], 'sites' => $parsed['sites'], 'raw_alerts' => $raw_alerts, 'clustered_findings' => count($clusters)]);
    astra_log_chained('SECURITY_ZAP_IMPORTED', "ZAP report \"$label\": $raw_alerts alerts clustered into " . count($clusters) . ' findings', $user_id, $conn);
    return ['scan_id' => $scan_id, 'counts' => $counts, 'raw_alerts' => $raw_alerts, 'findings' => count($clusters)];
}

function astra_security_list_scans($conn, int $company_id, int $limit = 30): array {
    $q = mysqli_prepare($conn, "SELECT id, scan_type, status, source_label, total_files, scanned_files, skipped_files,
        critical_count, high_count, medium_count, low_count, info_count, error, created_at, completed_at
        FROM security_scans WHERE company_id = ? ORDER BY id DESC LIMIT ?");
    mysqli_stmt_bind_param($q, "ii", $company_id, $limit);
    mysqli_stmt_execute($q);
    return mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC);
}
function astra_security_list_findings($conn, int $company_id, ?int $scan_id = null): array {
    $sql = "SELECT f.*, s.scan_type, s.source_label FROM security_findings f JOIN security_scans s ON s.id = f.scan_id
            WHERE f.company_id = ?" . ($scan_id ? " AND f.scan_id = ?" : "") . "
            ORDER BY FIELD(f.severity, 'critical', 'high', 'medium', 'low', 'info'), f.id DESC LIMIT 2000";
    $q = mysqli_prepare($conn, $sql);
    if ($scan_id) mysqli_stmt_bind_param($q, "ii", $company_id, $scan_id); else mysqli_stmt_bind_param($q, "i", $company_id);
    mysqli_stmt_execute($q);
    return mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC);
}
function astra_security_set_status($conn, int $company_id, int $finding_id, string $status, int $user_id): bool {
    if (!in_array($status, ['open', 'resolved', 'false_positive'], true)) return false;
    $q = mysqli_prepare($conn, "UPDATE security_findings SET status = ? WHERE id = ? AND company_id = ?");
    mysqli_stmt_bind_param($q, "sii", $status, $finding_id, $company_id);
    mysqli_stmt_execute($q);
    $ok = mysqli_stmt_affected_rows($q) === 1;
    if ($ok) astra_log_chained('SECURITY_FINDING_TRIAGED', "Finding #$finding_id marked $status", $user_id, $conn);
    return $ok || (bool)astra_security_finding_exists($conn, $company_id, $finding_id);
}
function astra_security_finding_exists($conn, int $company_id, int $finding_id): bool {
    $q = mysqli_prepare($conn, "SELECT 1 FROM security_findings WHERE id = ? AND company_id = ?");
    mysqli_stmt_bind_param($q, "ii", $finding_id, $company_id);
    mysqli_stmt_execute($q);
    return (bool)mysqli_fetch_row(mysqli_stmt_get_result($q));
}
