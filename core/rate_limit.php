<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// core/rate_limit.php
// Sliding-window rate limiting on MySQL, for any endpoint that needs it.
// Hastra already runs on a database it owns, so this needs no Redis/Upstash
// account: each (bucket, window) pair is one row, and the estimate weights
// the previous window by how much of it still overlaps the sliding window
// (the standard two-counter approximation, accurate to a few percent).
//
//   if (!astra_rate_allow($conn, 'track:' . $ip, 60, 60)) { http_response_code(429); exit; }
//
// Keys are hashed before storage, so the table never holds raw IPs or emails.

function astra_rate_table_ready($conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS rate_windows (
            bucket_hash  CHAR(64)     NOT NULL,
            window_start INT UNSIGNED NOT NULL,
            hits         INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket_hash, window_start),
            KEY idx_rate_windows_start (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready = true;
    } catch (Throwable $e) {
        error_log('rate_windows unavailable: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

// Records one hit and returns true while the caller is under $limit hits per
// sliding $window seconds. Fails open (allows) if the table can't be used, so a
// database hiccup never locks everyone out of a page.
function astra_rate_allow($conn, string $key, int $limit, int $window): bool {
    if ($limit < 1 || $window < 1 || !astra_rate_table_ready($conn)) return true;
    $hash = hash_hmac('sha256', $key, defined('ASTRA_INDEX_KEY') ? ASTRA_INDEX_KEY : 'hastra-rate');
    $now  = time();
    $cur  = intdiv($now, $window) * $window;
    $prev = $cur - $window;
    try {
        $ins = mysqli_prepare($conn,
            "INSERT INTO rate_windows (bucket_hash, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1");
        mysqli_stmt_bind_param($ins, "si", $hash, $cur);
        mysqli_stmt_execute($ins);

        $sel = mysqli_prepare($conn, "SELECT window_start, hits FROM rate_windows WHERE bucket_hash = ? AND window_start IN (?, ?)");
        mysqli_stmt_bind_param($sel, "sii", $hash, $prev, $cur);
        mysqli_stmt_execute($sel);
        $res = mysqli_stmt_get_result($sel);
        $hits = [$prev => 0, $cur => 0];
        while ($r = mysqli_fetch_assoc($res)) $hits[(int)$r['window_start']] = (int)$r['hits'];

        // housekeeping on roughly 1 request in 50
        if (random_int(1, 50) === 1) {
            $old = $now - 3 * max($window, 3600);
            $del = mysqli_prepare($conn, "DELETE FROM rate_windows WHERE window_start < ? LIMIT 500");
            mysqli_stmt_bind_param($del, "i", $old);
            mysqli_stmt_execute($del);
        }
    } catch (Throwable $e) {
        error_log('rate limit check failed: ' . $e->getMessage());
        return true;
    }
    $overlap  = 1 - (($now - $cur) / $window);
    $estimate = $hits[$prev] * $overlap + $hits[$cur];
    return $estimate <= $limit;
}

// Convenience for JSON endpoints: sends 429 with Retry-After and stops.
function astra_rate_guard($conn, string $key, int $limit, int $window): void {
    if (astra_rate_allow($conn, $key, $limit, $window)) return;
    http_response_code(429);
    header('Retry-After: ' . $window);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Too many requests. Wait a moment and try again.']);
    exit();
}

// The client address the rest of the app trusts (see core/geo_security.php).
function astra_rate_client_ip(): string {
    if (function_exists('astra_get_client_ip')) return astra_get_client_ip();
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}
