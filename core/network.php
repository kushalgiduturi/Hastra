<?php
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'network.php') { http_response_code(404); exit(); }
// Astra — client IP resolution.
//
// astra_get_client_ip() is the single source of truth for "what is the
// visitor's IP" wherever that matters for security decisions (VPN gating,
// rate limiting, geo-logging). It deliberately prefers headers set by a
// trusted edge proxy (Cloudflare) over generic forwarding headers, and
// falls back to REMOTE_ADDR last since that's the only one Apache itself
// guarantees.

// A single relaxed validity check — accepts private/reserved ranges too, so
// it can still be used to sanity-check a header value before trusting it as
// "some IP", even though astra_get_client_ip() itself only wants public IPs
// for the security checks that consume it.
function astra_is_valid_ip($ip) {
    return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

// True for RFC1918/loopback/link-local/etc — mirrors is_private_ip() in
// core/db.php but kept local so this file has no dependency ordering
// requirements on db.php.
function astra_is_public_ip($ip) {
    if (!astra_is_valid_ip($ip)) return false;
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// Resolves the visitor's IP address for security-sensitive checks.
//
// Header precedence: Cloudflare's CF-Connecting-IP (only trustworthy when
// Astra actually sits behind Cloudflare), then the leftmost address in
// X-Forwarded-For (the original client, as set by the nearest proxy in the
// chain — later hops append, they don't rewrite), then REMOTE_ADDR.
//
// On localhost (127.0.0.1 / ::1 — i.e. local development, no real public IP
// to inspect) this returns a fallback test IP instead of a loopback address,
// so downstream code (geo lookups, VPN checks) has something meaningful to
// work with instead of silently no-op'ing against ::1.
function astra_get_client_ip(): string {
    $candidates = [];

    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // "client, proxy1, proxy2, ..." — the client is always first.
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidates[] = trim($parts[0]);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = trim($_SERVER['REMOTE_ADDR']);
    }

    foreach ($candidates as $ip) {
        if (astra_is_public_ip($ip)) return $ip;
    }

    // Nothing public found — either genuinely local dev (127.0.0.1 / ::1) or
    // a malformed/absent header set. Fall back to a well-known test IP
    // (Google Public DNS) so geo/VPN lookups downstream have something real
    // to inspect instead of failing outright during local testing.
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote === '127.0.0.1' || $remote === '::1' || $remote === '') {
        return '8.8.8.8';
    }

    // Last resort: whatever REMOTE_ADDR was, even if private/reserved —
    // better than returning nothing.
    return $remote;
}

// True when this request has no real, externally-visible client IP to speak
// of — i.e. local development hitting the app directly over loopback with no
// proxy headers set. astra_inspect_ip() uses this to skip reputation checks
// entirely rather than gambling on whatever fallback IP got invented above.
function astra_is_local_request(): bool {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return false;
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return $remote === '127.0.0.1' || $remote === '::1' || $remote === '';
}
