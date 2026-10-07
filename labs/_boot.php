<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }
// Hastra Labs — shared bootstrap for the free community tools and student hub.
//
// Labs is deliberately separate from the enterprise product:
//   • its own session cookie (HASTRA_LABS, scoped to /labs/, SameSite=Lax), so
//     a community session can never be mistaken for an enterprise one and
//     signing in here never touches the users table;
//   • no passwords and no email: a community profile is unlocked by a random
//     100-bit recovery code, and only its HMAC blind index is stored;
//   • a wider Content-Security-Policy than the rest of the site, and only here:
//     'wasm-unsafe-eval' for Argon2id/bcrypt (hash-wasm), blob: workers for the
//     PDF reader, and YouTube's no-cookie player for the video tutor.
//
// Every cryptographic tool runs in the browser. Plaintext, passwords and keys
// are never posted to this server.
require_once __DIR__ . '/../core/db.php';

const LABS_CSP = "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' https://cdn.jsdelivr.net; "
    . "worker-src 'self' blob:; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "font-src 'self' https://fonts.gstatic.com; "
    . "img-src 'self' data: blob: https://i.ytimg.com; "
    . "frame-src 'self' https://www.youtube-nocookie.com; "
    . "connect-src 'self' https://cdn.jsdelivr.net; "
    . "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'";
header('Content-Security-Policy: ' . LABS_CSP);   // replaces core/db.php's
header('Referrer-Policy: strict-origin-when-cross-origin');

// Third-party libraries, pinned. Loaded by the browser from jsDelivr as ES
// modules; bump a version only after re-running the self-tests on /labs/crypto.
const LABS_LIBS = [
    'ml-kem'  => 'https://cdn.jsdelivr.net/npm/@noble/post-quantum@0.7.1/ml-kem.js/+esm',
    'ml-dsa'  => 'https://cdn.jsdelivr.net/npm/@noble/post-quantum@0.7.1/ml-dsa.js/+esm',
    'hybrid'  => 'https://cdn.jsdelivr.net/npm/@noble/post-quantum@0.7.1/hybrid.js/+esm',
    'curves'  => 'https://cdn.jsdelivr.net/npm/@noble/curves@2.4.0/ed25519.js/+esm',
    'chacha'  => 'https://cdn.jsdelivr.net/npm/@noble/ciphers@2.4.0/chacha.js/+esm',
    'blake3'  => 'https://cdn.jsdelivr.net/npm/@noble/hashes@2.4.0/blake3.js/+esm',
    'hashwasm'=> 'https://cdn.jsdelivr.net/npm/hash-wasm@4.12.0/+esm',
    'pdfjs'   => 'https://cdn.jsdelivr.net/npm/pdfjs-dist@6.3.289/build/pdf.min.mjs',
    'pdfjs-worker' => 'https://cdn.jsdelivr.net/npm/pdfjs-dist@6.3.289/build/pdf.worker.min.mjs',
];

const LABS_SESSION_NAME   = 'HASTRA_LABS';
const LABS_DEVICE_COOKIE  = 'hastra_labs_device';
const LABS_DEVICE_DAYS    = 60;
const LABS_CODE_ALPHABET  = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford base32

function labs_base(): string { return get_base_url() . 'labs/'; }
function labs_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function labs_schema_ready($conn): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $r = mysqli_query($conn, "SHOW TABLES LIKE 'labs_profiles'");
    return $ready = ($r && mysqli_num_rows($r) > 0);
}

// ── Community session ────────────────────────────────────────────────────────

// Is this browser signed in to a Hastra enterprise account? Answered by a
// read-only peek at the enterprise session (no cookie sent, nothing written,
// closed straight away) taken just before the Labs session takes over the
// request, so the free-trial gate can honour a real account without the two
// sessions ever being mixed.
function labs_enterprise_signed_in(): bool { return !empty($GLOBALS['labs_ent_signed']); }
function labs_peek_enterprise_session(): bool {
    $name = session_name();
    $sid  = $_COOKIE[$name] ?? '';
    if ($name === LABS_SESSION_NAME || !is_string($sid) || !preg_match('/^[A-Za-z0-9,-]{22,128}$/', $sid)) return false;
    session_id($sid);
    if (!@session_start(['read_and_close' => true, 'use_cookies' => 0, 'use_strict_mode' => 0])) { session_id(''); return false; }
    $in = !empty($_SESSION['user_id']) && (time() - (int)($_SESSION['last_activity'] ?? 0)) <= 1800;
    $_SESSION = [];
    session_id('');
    return $in;
}

function labs_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $GLOBALS['labs_ent_signed'] = labs_peek_enterprise_session();
    session_name(LABS_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => labs_base(),
        'secure'   => astra_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['labs_init'])) {
        session_regenerate_id(true);
        $_SESSION['labs_init'] = 1;
    }
}

function labs_csrf_token(): string {
    labs_session_start();
    if (empty($_SESSION['labs_csrf'])) $_SESSION['labs_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['labs_csrf'];
}

// Accepts the token from a form field or the X-CSRF-Token header (fetch).
function labs_csrf_ok(): bool {
    labs_session_start();
    $sent = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return !empty($_SESSION['labs_csrf']) && $sent !== '' && hash_equals($_SESSION['labs_csrf'], $sent);
}

// ── Recovery codes ───────────────────────────────────────────────────────────
// HLAB-XXXXX-XXXXX-XXXXX-XXXXX: 20 Crockford base32 characters = 100 bits.
function labs_code_generate(): string {
    $a = LABS_CODE_ALPHABET;
    $raw = '';
    for ($i = 0; $i < 20; $i++) $raw .= $a[random_int(0, 31)];
    return 'HLAB-' . implode('-', str_split($raw, 5));
}

// Forgiving about case, spaces, dashes and the look-alikes Crockford folds.
function labs_code_normalize(string $input): ?string {
    $s = strtoupper(preg_replace('/[\s\-_]+/', '', $input));
    if (str_starts_with($s, 'HLAB')) $s = substr($s, 4);
    $s = strtr($s, ['O' => '0', 'I' => '1', 'L' => '1']);
    if (strlen($s) !== 20 || strspn($s, LABS_CODE_ALPHABET) !== 20) return null;
    return $s;
}

function labs_code_bindex(string $normalized): string {
    return astra_blind_index('hastra-labs/profile:' . $normalized);
}

// ── Profiles ─────────────────────────────────────────────────────────────────
function labs_profile_create($conn, string $display_name): array {
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $display_name));
    $name = mb_substr($name !== '' ? $name : 'Student', 0, 40);
    for ($try = 0; $try < 3; $try++) {
        $code = labs_code_generate();
        $bindex = labs_code_bindex(labs_code_normalize($code));
        $enc = astra_db_encrypt($name);
        $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO labs_profiles (code_bindex, display_name, created_at, last_seen_at) VALUES (?, ?, NOW(), NOW())");
        mysqli_stmt_bind_param($stmt, 'ss', $bindex, $enc);
        mysqli_stmt_execute($stmt);
        if (mysqli_stmt_affected_rows($stmt) === 1) {
            return ['id' => (int)mysqli_insert_id($conn), 'name' => $name, 'code' => $code];
        }
    }
    throw new RuntimeException('Could not allocate a recovery code.');
}

function labs_profile_by_code($conn, string $code): ?array {
    $norm = labs_code_normalize($code);
    if ($norm === null) return null;
    $bindex = labs_code_bindex($norm);
    $stmt = mysqli_prepare($conn, "SELECT id, display_name FROM labs_profiles WHERE code_bindex = ?");
    mysqli_stmt_bind_param($stmt, 's', $bindex);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? ['id' => (int)$row['id'], 'name' => (string)astra_db_decrypt($row['display_name'])] : null;
}

function labs_profile_by_id($conn, int $id): ?array {
    $stmt = mysqli_prepare($conn, "SELECT id, display_name FROM labs_profiles WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return $row ? ['id' => (int)$row['id'], 'name' => (string)astra_db_decrypt($row['display_name'])] : null;
}

// The signed-in community profile, from the session or a remembered device.
function labs_current_profile($conn): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;
    labs_session_start();
    if (!labs_schema_ready($conn)) return $cached = null;

    $profile = null;
    if (!empty($_SESSION['labs_profile'])) {
        $profile = labs_profile_by_id($conn, (int)$_SESSION['labs_profile']);
        if (!$profile) unset($_SESSION['labs_profile']);
    }
    if (!$profile && !empty($_COOKIE[LABS_DEVICE_COOKIE]) && is_string($_COOKIE[LABS_DEVICE_COOKIE])) {
        $tok = $_COOKIE[LABS_DEVICE_COOKIE];
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $tok)) {
            $bi = astra_blind_index('hastra-labs/device:' . $tok);
            $stmt = mysqli_prepare($conn, "SELECT profile_id FROM labs_devices WHERE token_bindex = ? AND expires_at > NOW()");
            mysqli_stmt_bind_param($stmt, 's', $bi);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
            if ($row) {
                $profile = labs_profile_by_id($conn, (int)$row[0]);
                if ($profile) {
                    session_regenerate_id(true);
                    $_SESSION['labs_profile'] = $profile['id'];
                }
            }
        }
    }
    if ($profile && (time() - (int)($_SESSION['labs_seen'] ?? 0)) > 300) {
        $stmt = mysqli_prepare($conn, "UPDATE labs_profiles SET last_seen_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $profile['id']);
        mysqli_stmt_execute($stmt);
        $_SESSION['labs_seen'] = time();
    }
    return $cached = $profile;
}

function labs_sign_in($conn, int $profile_id, bool $remember): void {
    labs_session_start();
    session_regenerate_id(true);
    $_SESSION['labs_profile'] = $profile_id;
    $_SESSION['labs_csrf'] = bin2hex(random_bytes(32));   // new privilege level, new token
    if ($remember) {
        $tok = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $bi = astra_blind_index('hastra-labs/device:' . $tok);
        $stmt = mysqli_prepare($conn, "INSERT INTO labs_devices (token_bindex, profile_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))");
        $days = LABS_DEVICE_DAYS;
        mysqli_stmt_bind_param($stmt, 'sii', $bi, $profile_id, $days);
        mysqli_stmt_execute($stmt);
        setcookie(LABS_DEVICE_COOKIE, $tok, [
            'expires' => time() + LABS_DEVICE_DAYS * 86400, 'path' => labs_base(),
            'secure' => astra_is_https(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
}

function labs_sign_out($conn): void {
    labs_session_start();
    if (!empty($_COOKIE[LABS_DEVICE_COOKIE]) && is_string($_COOKIE[LABS_DEVICE_COOKIE])) {
        $bi = astra_blind_index('hastra-labs/device:' . $_COOKIE[LABS_DEVICE_COOKIE]);
        $stmt = mysqli_prepare($conn, "DELETE FROM labs_devices WHERE token_bindex = ?");
        mysqli_stmt_bind_param($stmt, 's', $bi);
        mysqli_stmt_execute($stmt);
    }
    setcookie(LABS_DEVICE_COOKIE, '', ['expires' => time() - 3600, 'path' => labs_base(),
        'secure' => astra_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['labs_init'] = 1;
}

// ── Rate limiting ────────────────────────────────────────────────────────────
// Fixed window. The bucket name is hashed, and callers pass a blind index for
// anything identifying (IP addresses), so this table stores no raw IPs.
function labs_rate_hit($conn, string $bucket, int $limit, int $window_secs): bool {
    if (!labs_schema_ready($conn)) return true;
    $key = hash('sha256', $bucket);
    $start = intdiv(time(), $window_secs) * $window_secs;
    $stmt = mysqli_prepare($conn, "INSERT INTO labs_rate (bucket, window_start, hits) VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE hits = IF(window_start = VALUES(window_start), hits + 1, 1), window_start = VALUES(window_start)");
    mysqli_stmt_bind_param($stmt, 'si', $key, $start);
    mysqli_stmt_execute($stmt);
    $q = mysqli_prepare($conn, "SELECT hits FROM labs_rate WHERE bucket = ?");
    mysqli_stmt_bind_param($q, 's', $key);
    mysqli_stmt_execute($q);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($q));
    return $row && (int)$row[0] <= $limit;
}

function labs_client_key(): string {
    return astra_blind_index('hastra-labs/ip:' . astra_get_client_ip());
}

function labs_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

// ── Layout ───────────────────────────────────────────────────────────────────
const LABS_TABS = [
    'crypto'   => ['01', 'Cryptography & File Armor',        'crypto'],
    'siem'     => ['02', 'SIEM / Threat Intel & SOC Lab',    'siem'],
    'syllabus' => ['03', 'Syllabus Accelerator & Video Tutor', 'syllabus'],
];

// The pinned libraries, plus every Labs module mapped to its versioned URL.
// Without the second part a relative `import './labs-common.js'` and the
// page's `<script src="labs-common.js?v=N">` would be two different module
// instances (the query string makes a different URL), each wiring its own
// handlers, and a new release could run next to a stale cached import.
const LABS_MODULES = ['labs-common', 'trial-gatekeeper', 'pqc-crypto', 'labs-crypto-ui', 'siem-lab', 'ueba-calculator', 'doc-extract', 'syllabus-engine'];
function labs_import_map(): array {
    $map = LABS_LIBS;
    foreach (LABS_MODULES as $m) {
        $path = get_base_url() . 'assets/js/' . $m . '.js';
        $map[$path] = $path . '?v=' . ASSET_VERSION;
    }
    return $map;
}

const LABS_DESCRIPTIONS = [
    'hub'      => 'Free, browser-native security tools and a study coach: post-quantum cryptography, SIEM and threat-intel labs, and a syllabus accelerator. Nothing sensitive leaves your browser.',
    'crypto'   => 'Encrypt text or files with AES-256-GCM, ChaCha20-Poly1305, RSA-4096, X25519, ML-KEM or the X-Wing hybrid, sign with ML-DSA and hash credentials with Argon2id. All in your browser.',
    'siem'     => 'Normalize STIX 2.1, TAXII and MISP feeds, score UEBA risk, size a 15M-EPS pipeline across storage tiers and correlate multi-cloud logs on one timeline.',
    'syllabus' => 'Drop in PDF, Word or text syllabi: every unit and topic gets the most-viewed tutorial, a finish-date forecast and a pace that keeps you on track.',
    'auth'     => 'Start a free Hastra Labs session to sync your study progress between devices.',
];

function labs_head(string $title, string $active, ?array $profile): void {
    $b = get_base_url();
    $lb = labs_base();
    $v = ASSET_VERSION;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= labs_e($title) ?> · Hastra Labs</title>
<?php astra_seo_meta([
    'title' => $title . ' · Hastra Labs',
    'description' => LABS_DESCRIPTIONS[$active] ?? LABS_DESCRIPTIONS['hub'],
    'path' => 'labs/' . (in_array($active, ['crypto', 'siem', 'syllabus', 'auth'], true) ? $active : ''),
    'robots' => $active === 'auth' ? 'noindex, follow' : 'index, follow',
]); ?>
<meta name="csrf-token" content="<?= labs_e(labs_csrf_token()) ?>">
<meta name="labs-base" content="<?= labs_e($lb) ?>">
<meta name="labs-signed-in" content="<?= ($profile || labs_enterprise_signed_in()) ? '1' : '0' ?>">
<script src="<?= $b ?>core/theme.js?v=<?= $v ?>"></script>
<link rel="stylesheet" href="<?= $b ?>core/theme.css?v=<?= $v ?>"><link rel="stylesheet" href="<?= $b ?>assets/css/theme-contrast.css?v=<?= $v ?>">
<link rel="stylesheet" href="<?= $b ?>assets/css/tools-cyber.css?v=<?= $v ?>">
<script type="importmap"><?= json_encode(['imports' => labs_import_map()], JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body class="lx-body" data-labs-page="<?= labs_e($active) ?>">
<a class="lx-skip" href="#lx-main">Skip to content</a>
<header class="lx-top" role="banner">
  <a class="lx-brand" href="<?= $lb ?>" aria-label="Hastra Labs home">
    <img src="<?= $b ?>assets/images/hastra-logo.svg" alt="Hastra logo" width="34" height="34">
    <span class="lx-brand-tx"><b>HASTRA <em>LABS</em></b><i>Free cryptography, SOC &amp; study tools</i></span>
  </a>
  <div class="lx-top-actions">
    <?php if ($profile): ?>
      <a class="lx-chip lx-chip--on" href="<?= $lb ?>auth" title="Community session">
        <span class="lx-dot" aria-hidden="true"></span><?= labs_e($profile['name']) ?>
      </a>
    <?php else: ?>
      <a class="lx-chip" href="<?= $lb ?>auth">Free session</a>
    <?php endif; ?>
    <button id="themeToggleBtn" type="button" onclick="toggleTheme()" class="lx-chip lx-theme" aria-label="Switch between light and dark theme"><span class="theme-icon" aria-hidden="true"></span><span class="theme-label"></span></button>
    <a class="lx-enterprise" href="<?= $b ?>signin">Enterprise sign-in <span aria-hidden="true">&rarr;</span></a>
  </div>
</header>
<nav class="lx-tabs" aria-label="Labs tools">
  <?php foreach (LABS_TABS as $key => [$num, $label, $slug]): ?>
    <a class="lx-tab<?= $active === $key ? ' is-active btn-beam' : '' ?>" href="<?= $lb . $slug ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>>
      <span class="lx-tab-num"><?= $num ?></span> <?= labs_e($label) ?>
    </a>
  <?php endforeach; ?>
</nav>
<main id="lx-main" class="lx-main" tabindex="-1">
<?php
}

function labs_foot(array $modules = []): void {
    $b = get_base_url();
    $v = ASSET_VERSION;
    ?>
</main>
<div class="lx-footwrap"><?php astra_legal_footer('page'); ?></div>
<?php astra_consent_banner(); ?>
<div class="lx-toast" id="lx-toast" role="status" aria-live="polite"></div>
<?php foreach ($modules as $m): ?>
<script type="module" src="<?= $b ?>assets/js/<?= labs_e($m) ?>.js?v=<?= $v ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
