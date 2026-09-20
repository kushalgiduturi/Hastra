<?php
// api/geo_country.php
// Tiny same-origin endpoint so client-side phone widgets (intl-tel-input)
// can auto-detect the visitor's country without a third-party fetch, which
// the app's CSP (connect-src 'self') would block anyway. Reuses the same
// trusted IP resolution chain as the anti-VPN gate (core/network.php).
include __DIR__ . '/../core/db.php';
header('Content-Type: application/json');

$code = 'us';
if (!astra_is_local_request()) {
    $ip  = astra_get_client_ip();
    $url = "http://ip-api.com/json/" . rawurlencode($ip) . "?fields=status,countryCode";
    $ctx = stream_context_create(['http' => ['timeout' => 2]]);
    $resp = @file_get_contents($url, false, $ctx);
    $data = $resp ? json_decode($resp, true) : null;
    if ($data && ($data['status'] ?? '') === 'success' && !empty($data['countryCode'])) {
        $code = strtolower($data['countryCode']);
    }
}

echo json_encode(['country' => $code]);
