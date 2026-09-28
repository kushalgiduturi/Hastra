<?php
// Every deployment-specific value can come from the environment (the Docker
// image sets them from .env; see DEPLOY.md). Unset, each falls back to the
// local XAMPP value below, so development needs no configuration at all.
// A constant that is already defined (the test harness pre-defines the
// database and mail ones) is left alone.
if (!function_exists('hastra_env')) {
    function hastra_env(string $key, $default) {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
    function hastra_def(string $name, $value): void {
        if (!defined($name)) define($name, $value);
    }
    // A secret from the environment, else from a git-ignored one-line file in config/.
    function hastra_secret(string $env, string $file): string {
        $v = getenv($env);
        if ($v !== false && $v !== '') return trim($v);
        $f = __DIR__ . '/' . $file;
        return is_file($f) ? trim((string) file_get_contents($f)) : '';
    }
}

// ── Database credentials ──────────────────────────────────────────────────────
hastra_def('DB_HOST', hastra_env('HASTRA_DB_HOST', 'localhost'));
hastra_def('DB_PORT', (int) hastra_env('HASTRA_DB_PORT', 3306));
hastra_def('DB_USER', hastra_env('HASTRA_DB_USER', 'root'));
hastra_def('DB_PASS', hastra_secret('HASTRA_DB_PASS', 'db_password.key'));
hastra_def('DB_NAME', hastra_env('HASTRA_DB_NAME', 'myapp'));
// Path to a CA certificate for TLS to a managed database (e.g. Aiven, PlanetScale).
// Empty (the local XAMPP default) means an unencrypted connection.
hastra_def('DB_SSL_CA', hastra_env('HASTRA_DB_SSL_CA', ''));

// ── Mail ──────────────────────────────────────────────────────────────────────
// Local development talks to an unauthenticated SMTP catcher on port 1025.
// Production needs a real provider: set HASTRA_MAIL_AUTH=1, a user, and the
// password in HASTRA_MAIL_PASS or config/smtp.key.
hastra_def('MAIL_HOST', hastra_env('HASTRA_MAIL_HOST', '127.0.0.1'));
hastra_def('MAIL_PORT', (int) hastra_env('HASTRA_MAIL_PORT', 1025));
hastra_def('MAIL_AUTH', in_array(strtolower((string) hastra_env('HASTRA_MAIL_AUTH', '0')), ['1', 'true', 'yes', 'on'], true));
hastra_def('MAIL_SECURE', hastra_env('HASTRA_MAIL_SECURE', ''));   // '', 'tls' (STARTTLS, 587) or 'ssl' (465)
hastra_def('MAIL_USER', hastra_env('HASTRA_MAIL_USER', ''));
hastra_def('MAIL_PASS', hastra_secret('HASTRA_MAIL_PASS', 'smtp.key'));
hastra_def('MAIL_FROM', hastra_env('HASTRA_MAIL_FROM', 'noreply@test.com'));
define('APP_NAME', 'Hastra');
hastra_def('MAIL_NAME', hastra_env('HASTRA_MAIL_NAME', 'Hastra'));

// ── reCAPTCHA ─────────────────────────────────────────────────────────────────
// The secret lives outside the code: ASTRA_RECAPTCHA_SECRET in the environment,
// or config/recaptcha.key (one line, git-ignored like every config/*.key).
define('RECAPTCHA_SECRET', getenv('ASTRA_RECAPTCHA_SECRET')
    ?: (is_file(__DIR__ . '/recaptcha.key') ? trim((string) file_get_contents(__DIR__ . '/recaptcha.key')) : ''));


// ── App-wide constants ────────────────────────────────────────────────────────
// The path the app is served under: '/Hastra/' locally, usually '/' in
// production. Must match RewriteBase in .htaccess (the Docker build rewrites
// it to match HASTRA_BASE_PATH).
hastra_def('BASE_URL_PATH', hastra_env('HASTRA_BASE_PATH', '/Hastra/'));
// Public origin + base path, e.g. https://hastra.example/ (used for OAuth).
hastra_def('APP_URL', rtrim(hastra_env('HASTRA_APP_URL', 'http://localhost' . BASE_URL_PATH), '/') . '/');
// Internal team (the company that runs Hastra). Client companies get their own
// domain derived from the company name — see core/company.php.
define('INTERNAL_COMPANY_NAME', 'Cycops');
define('EMPLOYEE_EMAIL_DOMAIN', '@cycops.com');
// ── Roster import (P13) ───────────────────────────────────────────────────────
// Python used to parse uploaded rosters ("python", "py" or a full path such as
// C:\Python312\python.exe). Leave empty to always use the built-in PHP reader.
define('PYTHON_BIN', 'python');
// true = let the parser ask Claude about unrecognised column headers
// (needs `pip install anthropic` and ANTHROPIC_API_KEY set for Apache).
define('ROSTER_USE_AI', false);

// ── Documentation drafts (P16) ────────────────────────────────────────────────
// Put an Anthropic API key (one line) in this file to get AI-written drafts.
// Without it, drafts are built from the project records and the GitHub repo.
define('ANTHROPIC_KEY_FILE', __DIR__ . '/anthropic.key');
define('DOC_AI_MODEL', 'claude-sonnet-4-5');

hastra_def('PRIMARY_SYSADMIN_EMAIL', hastra_env('HASTRA_SYSADMIN_EMAIL', 'kushalgiduturi@gmail.com'));

// ── Legal entity (shown in every public footer and the policy documents) ─────
// The person or company operating Hastra. Leave LEGAL_ADDRESS or
// LEGAL_COMPANY_ID empty ('') and that line is simply left out everywhere.
define('LEGAL_ENTITY_NAME',   'Kushal Giduturi');
define('LEGAL_ADDRESS',       '');
define('LEGAL_COMPANY_ID',    '');
define('LEGAL_SUPPORT_EMAIL', 'kushalgiduturi@gmail.com');
define('LEGAL_EFFECTIVE_DATE', '2026-09-26');
// Bump when the Terms or Privacy Policy change materially; stored with each
// user's recorded acceptance so it is clear which text they agreed to.
define('LEGAL_TERMS_VERSION', '2026-09-26');

// ── Google OAuth 2.0 (Sign in with Google) ────────────────────────────────────
// Create an OAuth client (type "Web application") in Google Cloud Console and
// register GOOGLE_REDIRECT_URI as an authorised redirect URI. The secret is
// read from config/google_oauth.key (one line) so it stays out of this file.
// Leave GOOGLE_CLIENT_ID empty to disable the feature.
define('GOOGLE_CLIENT_ID', getenv('ASTRA_GOOGLE_CLIENT_ID') ?: '31608737749-n985in8ems791du3d6fd9o7dafkil53m.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET_FILE', __DIR__ . '/google_oauth.key');
define('GOOGLE_REDIRECT_URI', APP_URL . 'auth/google_auth.php');