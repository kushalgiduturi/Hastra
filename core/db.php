<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }

require __DIR__ . '/../config/config.php';

// Bump this whenever core/theme.css, core/theme.js, or any assets/ file
// changes so browsers fetch the new file instead of serving a stale cached copy.
if (!defined('ASSET_VERSION')) {
    define('ASSET_VERSION', '124');
}

header_remove('X-Powered-By'); // don't advertise the PHP version
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Content-Security-Policy: default-src 'self'; "
     . "script-src 'self' 'unsafe-inline' https://www.google.com https://www.gstatic.com https://cdn.jsdelivr.net; "
     . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
     . "font-src 'self' https://fonts.gstatic.com; "
     . "img-src 'self' data: https://cdn.jsdelivr.net https://logo.clearbit.com https://www.google.com https://*.gstatic.com https://icons.duckduckgo.com; "
     . "frame-src 'self' https://www.google.com; "
     . "connect-src 'self'; "
     . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self' https://accounts.google.com");

// A managed database (Aiven, PlanetScale, etc.) needs TLS and a non-default
// port; local XAMPP leaves DB_SSL_CA empty and connects plainly as before.
if (DB_SSL_CA !== '') {
    $conn = mysqli_init();
    mysqli_ssl_set($conn, null, null, DB_SSL_CA, null, null);
    mysqli_real_connect($conn, DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, null, MYSQLI_CLIENT_SSL);
} else {
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
}
if (!$conn) {
    die("Connection failed.");
}
mysqli_query($conn, "SET time_zone = '+05:30'");


// SMTP credentials for every PHPMailer instance (called right after SMTPAuth
// is set). Local development uses an unauthenticated catcher; a production
// provider needs MAIL_AUTH with MAIL_USER / MAIL_PASS (config/config.php).
function astra_mail_auth($mail): void {
    if (!MAIL_AUTH) return;
    $mail->Username = MAIL_USER;
    $mail->Password = MAIL_PASS;
}

function astra_is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function secure_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => astra_is_https(),
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
                header("Location: " . APP_URL . "signin");
                exit();
            }
        }
        $_SESSION["last_activity"] = time();

        // Fingerprint anchor + hijack killswitch (core/session_guard.php).
        // Exits the request if this session no longer belongs to its owner.
        global $conn;
        astra_session_guard($conn);
    }
}

// ── Resolve base URL so redirects work no matter how deep the file is ────────
function get_base_url() {
    return BASE_URL_PATH;
}

function verify_session($conn, $role = null) {
    if (!isset($_SESSION["user_id"])) {
        header("Location: " . APP_URL . "signin");
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
        header("Location: " . APP_URL . "signin"); exit();
    }

    if ($role) {
        $allowed = is_array($role) ? $role : [$role];
        if (!in_array($user["role"], $allowed)) {
            session_unset(); session_destroy();
            header("Location: " . APP_URL . "signin"); exit();
        }
    }

    if (isset($_GET["id"]) && $_GET["id"] != $_SESSION["user_id"]) {
        session_unset();
        session_destroy();
        header("Location: " . APP_URL . "signin");
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
        die("Invalid request. <a href='" . get_base_url() . "signin'>Go back</a>");
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
    if (defined('ASTRA_OFFLINE')) return null;
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
    if (!$ip || is_private_ip($ip) || defined('ASTRA_OFFLINE')) return null;
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

    // Appended to the tamper-evident ledger (core/audit_chain.php).
    astra_chain_append($conn, [
        'user_id'    => $user_id,
        'username'   => $username,
        'action'     => $action,
        'ip_address' => $ip_address,
        'geo'        => astra_db_encrypt($geo),
    ]);
}
// ── Generate unique employee email from name ─────────────────────────────────
// $domain defaults to the internal domain; pass a company's domain for clients.
function generate_employee_email($conn, $full_name, $domain = null) {
    return generate_company_email($conn, $full_name, $domain ?? EMPLOYEE_EMAIL_DOMAIN);
}

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/audit_chain.php';
require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/escrow.php';
require_once __DIR__ . '/security_scans.php';
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
require_once __DIR__ . '/gooey_search.php';
require_once __DIR__ . '/authkit.php';
require_once __DIR__ . '/canary.php';
require_once __DIR__ . '/ephemeral_dossier.php';
require_once __DIR__ . '/milestone_signoff.php';
require_once __DIR__ . '/requirement_versions.php';
require_once __DIR__ . '/google_oauth.php';
require_once __DIR__ . '/legal.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/auth_check.php';

// Enforced on every single request, after every helper above has loaded but
// before any page-specific logic runs: an IP still inside a honeytoken
// lockdown window is rejected outright. astra_canary_check() (the trip
// itself) is called explicitly by the specific entry points that accept a
// user-supplied identifier — this call is what makes the resulting block
// actually stick past the request that triggered it.
astra_canary_guard($conn);

// ── Short addresses ─────────────────────────────────────────────────────────
// Every page answers at one short address (.htaccess maps it to the file).
// A page reached any other way (/portals/…, /auth/login, underscores, .php)
// is sent there with a 301, so bookmarks and old links keep working.
function astra_pretty_path(string $script): ?string {
    $auth = ['login' => 'signin', 'register' => 'signup', 'otp' => 'verify', 'verify_register' => 'verify-email',
             'forgot_otp' => 'reset-code', 'forgot' => 'forgot-password', 'reset' => 'reset-password',
             'set_password' => 'set-password', 'logout' => 'signout'];
    if (preg_match('~^auth/([a-z_]+)\.php$~', $script, $m)) return $auth[$m[1]] ?? null;
    if ($script === 'portals/index.php') return 'workspace/';
    if (preg_match('~^portals/([a-z]+)/([a-z][a-z_]*)\.php$~', $script, $m)) {
        $dir = $m[1] === 'emlpoyee' ? 'employee' : $m[1];
        if ($m[2] === "{$dir}_portal") return "workspace/$dir/";
        return "workspace/$dir/" . str_replace('_', '-', $m[2]);
    }
    if (preg_match('~^legal/([a-z][a-z_]*)\.php$~', $script, $m)) return 'legal/' . $m[1];
    if ($script === 'labs/index.php') return 'labs/';
    if (preg_match('~^labs/((?:api/)?[a-z][a-z_]*)\.php$~', $script, $m)) return 'labs/' . $m[1];
    return null;
}
(function () {
    if (PHP_SAPI === 'cli' || getenv('ASTRA_TEST') === '1' || headers_sent()) return;
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) return;
    $base = get_base_url();
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (strncmp($script, $base, strlen($base)) !== 0) return;
    $pretty = astra_pretty_path(substr($script, strlen($base)));
    if ($pretty === null) return;
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = rawurldecode((string)parse_url($uri, PHP_URL_PATH));
    if ($path === $base . $pretty) return;
    $qs = (string)parse_url($uri, PHP_URL_QUERY);
    header('Location: ' . APP_URL . $pretty . ($qs !== '' ? '?' . $qs : ''), true, 301);
    exit();
})();
?>
