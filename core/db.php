<?php

require __DIR__ . '/../config/config.php';

// Bump this whenever core/theme.css, core/theme.js, or any assets/ file
// changes so browsers fetch the new file instead of serving a stale cached copy.
if (!defined('ASSET_VERSION')) {
    define('ASSET_VERSION', '11');
}

header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self'; "
     . "script-src 'self' 'unsafe-inline' https://www.google.com https://www.gstatic.com https://cdn.jsdelivr.net; "
     . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
     . "font-src 'self' https://fonts.gstatic.com; "
     . "img-src 'self' data: https://cdn.jsdelivr.net; "
     . "frame-src https://www.google.com; "
     . "connect-src 'self'; "
     . "object-src 'none'; base-uri 'self'; frame-ancestors 'none'");

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
mysqli_query($conn, "SET time_zone = '+05:30'");
if (!$conn) {
    die("Connection failed.");
}

function secure_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }
    if (!isset($_SESSION["initiated"])) {
        session_regenerate_id(true);
        $_SESSION["initiated"] = true;
    }

    if (isset($_SESSION["user_id"])) {
        if (isset($_SESSION["last_activity"]) &&
            (time() - $_SESSION["last_activity"] > 1800)) {
            session_unset();
            session_destroy();
            if (basename($_SERVER["PHP_SELF"]) !== "login.php") {
                header("Location: " . get_base_url() . "auth/login.php");
                exit();
            }
        }
        $_SESSION["last_activity"] = time();
    }
}

// ── Resolve base URL so redirects work no matter how deep the file is ────────
function get_base_url() {
    return BASE_URL_PATH;
}

function verify_session($conn, $role = null) {
    if (!isset($_SESSION["user_id"])) {
        header("Location: " . get_base_url() . "auth/login.php");
        exit();
    }

    $id    = $_SESSION["user_id"];
    $check = mysqli_prepare($conn, "SELECT id, role FROM users WHERE id = ?");
    mysqli_stmt_bind_param($check, "i", $id);
    mysqli_stmt_execute($check);
    $result = mysqli_stmt_get_result($check);
    $user   = mysqli_fetch_assoc($result);

    if (!$user) {
        session_unset(); session_destroy();
        header("Location: " . get_base_url() . "auth/login.php"); exit();
    }

    if ($role) {
        $allowed = is_array($role) ? $role : [$role];
        if (!in_array($user["role"], $allowed)) {
            session_unset(); session_destroy();
            header("Location: " . get_base_url() . "auth/login.php"); exit();
        }
    }

    if (isset($_GET["id"]) && $_GET["id"] != $_SESSION["user_id"]) {
        session_unset();
        session_destroy();
        header("Location: " . get_base_url() . "auth/login.php");
        exit();
    }
}

function generate_csrf_token() {
    if (!isset($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

function verify_csrf_token() {
    if (!isset($_POST["csrf_token"]) ||
        $_POST["csrf_token"] !== $_SESSION["csrf_token"]) {
        die("Invalid request. <a href='" . get_base_url() . "auth/login.php'>Go back</a>");
    }
}

function check_ip_limit($conn) {
    $ip   = $_SERVER["REMOTE_ADDR"] ?? "unknown";
    $stmt = mysqli_prepare($conn, "SELECT attempts, locked_until FROM ip_attempts WHERE ip = ?");
    mysqli_stmt_bind_param($stmt, "s", $ip);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row    = mysqli_fetch_assoc($result);

    if ($row) {
        if ($row["locked_until"] && strtotime($row["locked_until"]) > time()) {
            $minutes = ceil((strtotime($row["locked_until"]) - time()) / 60);
            return "Too many attempts from your IP. Try again in $minutes minute(s).";
        }
    }
    return "";
}

function increment_ip_attempts($conn) {
    $ip    = $_SERVER["REMOTE_ADDR"] ?? "unknown";
    $check = mysqli_prepare($conn, "SELECT attempts FROM ip_attempts WHERE ip = ?");
    mysqli_stmt_bind_param($check, "s", $ip);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);

    if (mysqli_stmt_num_rows($check) > 0) {
        mysqli_stmt_bind_result($check, $attempts);
        mysqli_stmt_fetch($check);
        $attempts++;

        if ($attempts >= 20) {
            $lock_time = date("Y-m-d H:i:s", strtotime("+30 minutes"));
            $update    = mysqli_prepare($conn, "UPDATE ip_attempts SET attempts = ?, locked_until = ? WHERE ip = ?");
            mysqli_stmt_bind_param($update, "iss", $attempts, $lock_time, $ip);
            mysqli_stmt_execute($update);
        } else {
            $update = mysqli_prepare($conn, "UPDATE ip_attempts SET attempts = ? WHERE ip = ?");
            mysqli_stmt_bind_param($update, "is", $attempts, $ip);
            mysqli_stmt_execute($update);
        }
    } else {
        $insert = mysqli_prepare($conn, "INSERT INTO ip_attempts (ip, attempts) VALUES (?, 1)");
        mysqli_stmt_bind_param($insert, "s", $ip);
        mysqli_stmt_execute($insert);
    }
}

function reset_ip_attempts($conn) {
    $ip   = $_SERVER["REMOTE_ADDR"] ?? "unknown";
    $stmt = mysqli_prepare($conn, "UPDATE ip_attempts SET attempts = 0, locked_until = NULL WHERE ip = ?");
    mysqli_stmt_bind_param($stmt, "s", $ip);
    mysqli_stmt_execute($stmt);
}

function is_private_ip($ip) {
    if (!$ip || $ip === '::1' || $ip === '127.0.0.1') return true;
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

function get_public_ip() {
    $services = [
        'https://api.ipify.org',
        'https://ipecho.net/plain',
        'https://icanhazip.com',
    ];
    foreach ($services as $service) {
        $ip = @file_get_contents($service);
        if ($ip) {
            $ip = trim($ip);
            if (!is_private_ip($ip)) {
                return $ip;
            }
        }
    }
    return null;
}

function get_geo($ip) {
    if (!$ip || is_private_ip($ip)) return null;
    $url      = "http://ip-api.com/json/" . $ip . "?fields=status,city,regionName,country,lat,lon,isp";
    $response = @file_get_contents($url);
    if (!$response) return null;
    $data = json_decode($response, true);
    if (!$data || $data['status'] !== 'success') return null;
    return $data;
}

// $ip_override lets a caller that already resolved (and possibly
// reputation-checked) the client IP — e.g. the anti-VPN gate in
// auth/login.php via astra_get_client_ip() — log against that exact IP
// instead of this function re-resolving it independently.
function log_activity($conn, $user_id, $action, $username = null, $ip_override = null) {
    if ($ip_override) {
        $ip_address = $ip_override;
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip_address = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip_address = $_SERVER['REMOTE_ADDR'];
    }

    $geo_ip   = is_private_ip($ip_address) ? get_public_ip() : $ip_address;
    $geo      = null;
    $geo_data = $geo_ip ? get_geo($geo_ip) : null;
    if ($geo_data) {
        $geo = $geo_data['city'] . ', ' . $geo_data['regionName'] . ', ' . $geo_data['country']
             . ' | ' . number_format($geo_data['lat'], 4) . ', ' . number_format($geo_data['lon'], 4)
             . ' | ' . $geo_data['isp'];
    }

    $geo_enc = astra_db_encrypt($geo);

    $stmt = mysqli_prepare($conn, "INSERT INTO logs (user_id, username, action, ip_address, geo) VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) {
       error_log("Log prepare failed: " . mysqli_error($conn));
       return;
    }
    mysqli_stmt_bind_param($stmt, "issss", $user_id, $username, $action, $ip_address, $geo_enc);
    mysqli_stmt_execute($stmt);
}
// ── Generate unique employee email from name ─────────────────────────────────
// $domain defaults to the internal domain; pass a company's domain for clients.
function generate_employee_email($conn, $full_name, $domain = null) {
    return generate_company_email($conn, $full_name, $domain ?? EMPLOYEE_EMAIL_DOMAIN);
}

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/network.php';
require_once __DIR__ . '/geo_security.php';
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/onboarding.php';
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/integrity.php';
require_once __DIR__ . '/docs.php';
require_once __DIR__ . '/audit.php';  // P17
require_once __DIR__ . '/dock.php';
require_once __DIR__ . '/tours.php';  // P18
require_once __DIR__ . '/leave.php';
require_once __DIR__ . '/auth_check.php';
?>