<?php
// api/security/findings.php
//   GET  ?scan_id=N (optional)                  -> {scans, findings} for the caller's company
//   POST csrf_token, finding_id, status         -> triage (open | resolved | false_positive)
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    scan_api_csrf();
    $id = (int)($_POST['finding_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    if (!$id || !in_array($status, ['open', 'resolved', 'false_positive'], true)) scan_api_fail(400, 'Invalid request.');
    if (!astra_security_set_status($conn, $SCAN_CTX['company_id'], $id, $status, $SCAN_USER)) scan_api_fail(404, 'Finding not found.');
    scan_api_ok(['finding_id' => $id, 'status' => $status]);
}

$scan_id = (int)($_GET['scan_id'] ?? 0) ?: null;
if ($scan_id && !astra_security_get_scan($conn, $SCAN_CTX['company_id'], $scan_id)) scan_api_fail(404, 'Scan not found.');

$findings = array_map(function ($f) {
    return [
        'id' => (int)$f['id'], 'scan_id' => (int)$f['scan_id'], 'source' => $f['source'], 'rule_id' => $f['rule_id'],
        'title' => $f['title'], 'severity' => $f['severity'], 'cwe_id' => $f['cwe_id'], 'wasc_id' => $f['wasc_id'],
        'confidence' => $f['confidence'], 'file_path' => $f['file_path'], 'line_number' => $f['line_number'] !== null ? (int)$f['line_number'] : null,
        'instances' => $f['affected_url'] ? (json_decode($f['affected_url'], true) ?: []) : [],
        'vulnerable_param' => $f['vulnerable_param'], 'instance_count' => (int)$f['instance_count'],
        'description' => $f['description'], 'remediation' => json_decode($f['remediation_steps'], true) ?: ['steps' => []],
        'code_snippet' => $f['code_snippet'], 'status' => $f['status'], 'created_at' => $f['created_at'],
        'scan_label' => $f['source_label'],
    ];
}, astra_security_list_findings($conn, $SCAN_CTX['company_id'], $scan_id));

scan_api_ok(['company' => $SCAN_CTX['company_name'], 'scans' => astra_security_list_scans($conn, $SCAN_CTX['company_id']), 'findings' => $findings]);
