<?php
// api/client_error.php
// Browser error intake (window.onerror / unhandledrejection, sent by
// assets/js/hastra-ux.js). Errors are scrubbed of emails, numbers, tokens
// and secrets, then de-duplicated into app_errors (core/telemetry.php).
// Not tied to consent: no visitor data is kept, only the fault.
//
//   POST application/json {message, location, stack, path}
include __DIR__ . '/../core/db.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit(); }
astra_rate_guard($conn, 'clienterr:' . astra_rate_client_ip(), 20, 60);

$data = json_decode((string)file_get_contents('php://input', false, null, 0, 16384), true);
if (is_array($data) && trim((string)($data['message'] ?? '')) !== '') {
    try {
        astra_log_app_error($conn, 'client', (string)$data['message'], (string)($data['location'] ?? ''),
            (string)($data['stack'] ?? ''), (string)($data['path'] ?? ''));
    } catch (Throwable $e) { error_log('client_error failed: ' . $e->getMessage()); }
}
http_response_code(204);
