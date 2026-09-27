<?php
// ── Database credentials ──────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'myapp');

// ── Mail credentials ──────────────────────────────────────────────────────────
define('MAIL_HOST', '127.0.0.1');
define('MAIL_PORT', 1025);
define('MAIL_AUTH', false);
define('MAIL_SECURE', '');
define('MAIL_FROM', 'noreply@test.com');
define('APP_NAME', 'Hastra');
define('MAIL_NAME', 'Hastra');

// ── reCAPTCHA ─────────────────────────────────────────────────────────────────
// The secret lives outside the code: ASTRA_RECAPTCHA_SECRET in the environment,
// or config/recaptcha.key (one line, git-ignored like every config/*.key).
define('RECAPTCHA_SECRET', getenv('ASTRA_RECAPTCHA_SECRET')
    ?: (is_file(__DIR__ . '/recaptcha.key') ? trim((string) file_get_contents(__DIR__ . '/recaptcha.key')) : ''));


// ── App-wide constants ────────────────────────────────────────────────────────
define('BASE_URL_PATH', '/Hastra/');
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

define('PRIMARY_SYSADMIN_EMAIL', 'kushalgiduturi@gmail.com');

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
define('GOOGLE_REDIRECT_URI', 'http://localhost/Hastra/auth/google_auth.php');