<?php
// api/security/upload_zap.php
// POST multipart: csrf_token, report=<ZAP "Traditional JSON" or "Traditional XML" export>.
// Parses, clusters and stores the findings immediately.
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') scan_api_fail(405, 'POST required.');
scan_api_csrf();

$f = $_FILES['report'] ?? null;
if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    scan_api_fail(400, 'Choose a ZAP report (.json or .xml).');
}
if ($f['size'] > ZAP_MAX_BYTES) scan_api_fail(413, 'The report is too large (limit 10 MB).');
if (!preg_match('~\.(json|xml)$~i', (string)$f['name'])) scan_api_fail(415, 'Upload a ZAP report exported as JSON or XML.');

$raw = (string)file_get_contents($f['tmp_name']);
$label = basename(str_replace('\\', '/', (string)$f['name']));
try {
    $r = astra_security_import_zap($conn, $SCAN_CTX, $SCAN_USER, $label, $raw);
} catch (InvalidArgumentException $e) {
    scan_api_fail(422, $e->getMessage());
}
scan_api_ok($r + ['label' => $label]);
