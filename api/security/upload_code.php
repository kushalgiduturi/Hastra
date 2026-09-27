<?php
// api/security/upload_code.php
// POST multipart: csrf_token, archive=<.zip of the source to scan>.
// Stores the archive outside the web root and queues a SAST scan; the
// browser then opens api/security/stream_scan.php?scan_id=... to run it.
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') scan_api_fail(405, 'POST required.');
scan_api_csrf();

$f = $_FILES['archive'] ?? null;
if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $e = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    scan_api_fail(400, in_array($e, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'The archive is too large (limit 20 MB).' : 'Choose a .zip archive of your source code.');
}
if ($f['size'] > SCAN_ARCHIVE_MAX) scan_api_fail(413, 'The archive is too large (limit 20 MB).');
if (!preg_match('~\.zip$~i', (string)$f['name'])) scan_api_fail(415, 'Upload a .zip archive of your project.');
if (!is_uploaded_file($f['tmp_name'])) scan_api_fail(400, 'Upload failed. Try again.');

$zip = new ZipArchive();
if ($zip->open($f['tmp_name'], ZipArchive::RDONLY) !== true) scan_api_fail(415, "That file isn't a valid ZIP archive.");
$entries = $zip->numFiles;
$zip->close();
if ($entries === 0) scan_api_fail(422, 'The archive is empty.');
if ($entries > SAST_MAX_ENTRIES) scan_api_fail(422, "The archive has $entries entries; the limit is " . SAST_MAX_ENTRIES . '.');

// One active scan at a time per company keeps the server responsive.
astra_security_sweep_stale($conn);
$busy = mysqli_prepare($conn, "SELECT COUNT(*) FROM security_scans WHERE company_id = ? AND status IN ('queued','running') AND created_at > NOW() - INTERVAL 15 MINUTE");
mysqli_stmt_bind_param($busy, "i", $SCAN_CTX['company_id']);
mysqli_stmt_execute($busy);
if ((int)mysqli_fetch_row(mysqli_stmt_get_result($busy))[0] >= 2) scan_api_fail(429, 'Your company already has scans in progress. Wait for them to finish.');

$dest = astra_security_temp_dir() . DIRECTORY_SEPARATOR . 'scan_' . bin2hex(random_bytes(16)) . '.zip';
if (!move_uploaded_file($f['tmp_name'], $dest)) scan_api_fail(500, 'Could not store the upload.');

$label = basename(str_replace('\\', '/', (string)$f['name']));
$scan_id = astra_security_create_scan($conn, $SCAN_CTX, $SCAN_USER, 'sast_codebase', $label, $dest);
scan_api_ok(['scan_id' => $scan_id, 'entries' => $entries, 'label' => $label,
             'stream' => get_base_url() . 'api/security/stream_scan.php?scan_id=' . $scan_id]);
