<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/telemetry.php
// First-party product analytics and error tracking. Hastra keeps both on its
// own database instead of sending visitors to PostHog/Sentry, so no third
// party ever receives a page view, an IP address or a stack trace.
//
//  - analytics_events: page views and a short list of named events, recorded
//    only after the visitor opts in to "Analytics" in the cookie banner (the
//    browser side checks that; the server also drops anything carrying a
//    Global Privacy Control or Do Not Track signal). No cookies, no user id,
//    no IP: a visitor is a daily-rotating hash, so "unique visitors today" is
//    countable but nobody can be followed from one day to the next.
//  - app_errors: browser and server errors, scrubbed of emails, long numbers,
//    tokens and secrets before they are stored, de-duplicated by fingerprint.
// Both tables keep 30 days.

const ASTRA_TELEMETRY_RETENTION_DAYS = 30;
const ASTRA_ANALYTICS_EVENTS = ['pageview', 'cta_click', 'signup_start', 'signup_complete', 'labs_run', 'search_open', 'search_go', 'data_deletion_request'];

function astra_environment(): string {
    $env = getenv('HASTRA_ENV');
    if ($env) return substr(preg_replace('/[^a-z0-9_-]/i', '', $env), 0, 20);
    return (getenv('RENDER') || getenv('RENDER_SERVICE_ID')) ? 'production' : 'local';
}

// Removes personal data and secrets from free text before it is stored.
function astra_scrub(?string $s, int $max = 500): string {
    $s = (string)$s;
    $s = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $s);
    $s = preg_replace('/\b(password|passwd|pass|pwd|token|secret|otp|code|key|auth|session|csrf_token|api_key)=([^&\s"\']+)/i', '$1=[redacted]', $s);
    $s = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [redacted]', $s);
    $s = preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', '[jwt]', $s);
    $s = preg_replace('/\b[a-f0-9]{24,}\b/i', '[hex]', $s);
    $s = preg_replace('/\b[A-Za-z0-9+\/_-]{32,}={0,2}/', '[token]', $s);
    $s = preg_replace('/\+?\d[\d\s().-]{7,}\d/', '[number]', $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return mb_substr($s, 0, $max);
}

// Only the path of a URL: no query string, no fragment, no host.
function astra_clean_path(?string $url): string {
    $p = (string)parse_url((string)$url, PHP_URL_PATH);
    if ($p === '' || $p[0] !== '/') $p = '/';
    return astra_scrub(mb_substr($p, 0, 255), 255);
}

function astra_telemetry_ready($conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS analytics_events (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            event         VARCHAR(40)  NOT NULL,
            path          VARCHAR(255) NOT NULL DEFAULT '/',
            referrer_host VARCHAR(120) NOT NULL DEFAULT '',
            utm_source    VARCHAR(80)  NOT NULL DEFAULT '',
            utm_medium    VARCHAR(80)  NOT NULL DEFAULT '',
            utm_campaign  VARCHAR(80)  NOT NULL DEFAULT '',
            visitor_day   CHAR(16)     NOT NULL DEFAULT '',
            device        VARCHAR(10)  NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_analytics_created (created_at),
            KEY idx_analytics_event_created (event, created_at),
            KEY idx_analytics_utm (utm_source, utm_campaign)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS app_errors (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fingerprint CHAR(40)     NOT NULL,
            source      VARCHAR(10)  NOT NULL DEFAULT 'client',
            environment VARCHAR(20)  NOT NULL DEFAULT '',
            message     VARCHAR(500) NOT NULL DEFAULT '',
            location    VARCHAR(255) NOT NULL DEFAULT '',
            path        VARCHAR(255) NOT NULL DEFAULT '/',
            stack       TEXT NULL,
            browser     VARCHAR(40)  NOT NULL DEFAULT '',
            occurrences INT UNSIGNED NOT NULL DEFAULT 1,
            first_seen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_app_errors_fingerprint (fingerprint),
            KEY idx_app_errors_last_seen (last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    } catch (Throwable $e) {
        error_log('telemetry tables unavailable: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

function astra_telemetry_prune($conn): void {
    if (random_int(1, 100) !== 1) return;
    $days = ASTRA_TELEMETRY_RETENTION_DAYS;
    @mysqli_query($conn, "DELETE FROM analytics_events WHERE created_at < NOW() - INTERVAL $days DAY LIMIT 1000");
    @mysqli_query($conn, "DELETE FROM app_errors WHERE last_seen < NOW() - INTERVAL $days DAY LIMIT 1000");
}

// Visitor signals that must switch analytics off regardless of the banner.
function astra_privacy_signal(): bool {
    return ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1' || ($_SERVER['HTTP_DNT'] ?? '') === '1';
}

function astra_browser_family(?string $ua): string {
    $ua = (string)$ua;
    foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $needle => $name) {
        if (str_contains($ua, $needle)) return $name;
    }
    return preg_match('/bot|crawl|spider/i', $ua) ? 'Bot' : 'Other';
}

function astra_track_event($conn, array $e): bool {
    $event = (string)($e['event'] ?? '');
    if (!in_array($event, ASTRA_ANALYTICS_EVENTS, true) || astra_privacy_signal() || !astra_telemetry_ready($conn)) return false;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/bot|crawl|spider|slurp|headless/i', $ua)) return false;

    $path = astra_clean_path($e['path'] ?? '/');
    $refHost = strtolower((string)parse_url((string)($e['ref'] ?? ''), PHP_URL_HOST));
    if ($refHost === strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) $refHost = '';
    $utm = function ($k) use ($e) { return mb_substr(preg_replace('/[^\w .:\/-]/u', '', (string)($e['utm'][$k] ?? '')), 0, 80); };
    $day = substr(hash_hmac('sha256', date('Y-m-d') . '|' . astra_rate_client_ip() . '|' . $ua, defined('ASTRA_INDEX_KEY') ? ASTRA_INDEX_KEY : 'hastra'), 0, 16);
    $device = preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : (preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : 'desktop');

    $src = $utm('utm_source'); $med = $utm('utm_medium'); $camp = $utm('utm_campaign');
    $stmt = mysqli_prepare($conn,
        "INSERT INTO analytics_events (event, path, referrer_host, utm_source, utm_medium, utm_campaign, visitor_day, device)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $refHost = mb_substr($refHost, 0, 120);
    mysqli_stmt_bind_param($stmt, "ssssssss", $event, $path, $refHost, $src, $med, $camp, $day, $device);
    $ok = mysqli_stmt_execute($stmt);
    astra_telemetry_prune($conn);
    return $ok;
}

function astra_log_app_error($conn, string $source, string $message, string $location = '', string $stack = '', string $path = ''): void {
    if (!$conn || !astra_telemetry_ready($conn)) return;
    $source   = $source === 'server' ? 'server' : 'client';
    $message  = astra_scrub($message, 500);
    $location = astra_scrub($location, 255);
    $stack    = astra_scrub($stack, 4000);
    $path     = astra_clean_path($path ?: ($_SERVER['REQUEST_URI'] ?? '/'));
    $env      = astra_environment();
    $browser  = $source === 'client' ? astra_browser_family($_SERVER['HTTP_USER_AGENT'] ?? '') : 'php';
    // same message at the same place counts as one issue
    $fp = sha1($source . '|' . $env . '|' . preg_replace('/\d+/', 'N', $message) . '|' . preg_replace('/:\d+(:\d+)?$/', '', $location));
    try {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO app_errors (fingerprint, source, environment, message, location, path, stack, browser)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_seen = NOW(), path = VALUES(path)");
        mysqli_stmt_bind_param($stmt, "ssssssss", $fp, $source, $env, $message, $location, $path, $stack, $browser);
        mysqli_stmt_execute($stmt);
        astra_telemetry_prune($conn);
    } catch (Throwable $e) {
        error_log('app_errors insert failed: ' . $e->getMessage());
    }
}

// Server-side capture: fatal errors and uncaught exceptions are written to
// app_errors (scrubbed) as well as PHP's own error log.
function astra_register_error_capture(): void {
    static $done = false;
    if ($done || defined('ASTRA_TEST_NO_CAPTURE')) return;
    $done = true;
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        $conn = $GLOBALS['conn'] ?? null;
        if (!$conn instanceof mysqli) return;
        try {
            astra_log_app_error($conn, 'server', $e['message'], basename($e['file']) . ':' . $e['line']);
        } catch (Throwable $ignored) {}
    });
}
