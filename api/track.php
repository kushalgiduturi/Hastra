<?php
// api/track.php
// First-party analytics intake (see core/telemetry.php). The browser only
// calls this after the visitor allows "Analytics" in the cookie banner;
// requests carrying Global Privacy Control / Do Not Track are dropped here too.
// No session, no cookie: the response is always 204 so it reveals nothing.
//
//   POST application/json {event, path, ref, utm: {utm_source, utm_medium, utm_campaign}}
include __DIR__ . '/../core/db.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit(); }
astra_rate_guard($conn, 'track:' . astra_rate_client_ip(), 120, 60);

$raw  = file_get_contents('php://input', false, null, 0, 4096);
$data = json_decode((string)$raw, true);
if (is_array($data)) {
    try { astra_track_event($conn, $data); } catch (Throwable $e) { error_log('track failed: ' . $e->getMessage()); }
}
http_response_code(204);
