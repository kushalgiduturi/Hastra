<?php
// api/security/stream_scan.php?scan_id=N
// Server-Sent Events. Runs a queued SAST scan for the caller's company and
// streams it:
//   event: start     {scan_id, label}
//   event: progress  {current_file, percent, issues_found, index, total, scanned, skipped, skip_reason}
//   event: complete  {counts, stats}
//   event: error     {message}
//   event: done      {}                  (the client closes the stream here)
// A scan that already finished just replays `complete` + `done`, so an
// EventSource reconnect never re-runs it.
require __DIR__ . '/_bootstrap.php';

$scan_id = (int)($_GET['scan_id'] ?? 0);
$scan = $scan_id ? astra_security_get_scan($conn, $SCAN_CTX['company_id'], $scan_id) : null;
if (!$scan || $scan['scan_type'] !== 'sast_codebase') scan_api_fail(404, 'Scan not found.');

// Release the session lock: a long scan must not block the user's other tabs.
session_write_close();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);
@set_time_limit(300);

function sse(string $event, array $data): void {
    echo "event: $event\n", 'data: ', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n\n";
    @flush();
}
echo "retry: 60000\n\n";   // a dropped connection reconnects slowly; the client closes on `done`
echo ':' . str_repeat(' ', 2048) . "\n\n"; // pad past any proxy buffer
@flush();

$counts_of = fn($s) => ['critical' => (int)$s['critical_count'], 'high' => (int)$s['high_count'], 'medium' => (int)$s['medium_count'],
                        'low' => (int)$s['low_count'], 'info' => (int)$s['info_count']];

if ($scan['status'] === 'completed') {
    sse('complete', ['counts' => $counts_of($scan), 'stats' => ['total' => (int)$scan['total_files'], 'scanned' => (int)$scan['scanned_files'], 'skipped' => (int)$scan['skipped_files']]]);
    sse('done', []); exit();
}
if ($scan['status'] === 'failed') { sse('error', ['message' => $scan['error'] ?: 'The scan failed.']); sse('done', []); exit(); }
if (!astra_security_claim_scan($conn, $SCAN_CTX['company_id'], $scan_id)) {
    sse('error', ['message' => 'This scan is already running in another window.']); sse('done', []); exit();
}
if (!$scan['archive_path'] || !is_file($scan['archive_path'])) {
    astra_security_fail_scan($conn, $scan, 'The uploaded archive is no longer available. Upload it again.');
    sse('error', ['message' => 'The uploaded archive is no longer available. Upload it again.']); sse('done', []); exit();
}

sse('start', ['scan_id' => $scan_id, 'label' => $scan['source_label']]);
try {
    $r = astra_security_run_sast($conn, $scan, function (string $event, array $data) {
        if (connection_aborted()) exit(); // the scan row stays 'running' and is swept later
        sse($event, $data);
    });
    sse('complete', $r);
} catch (Throwable $e) {
    sse('error', ['message' => $e instanceof RuntimeException ? $e->getMessage() : 'The scan failed unexpectedly.']);
}
sse('done', []);
