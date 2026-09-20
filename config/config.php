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
define('APP_NAME', 'Astra');
define('MAIL_NAME', 'Astra');

// ── reCAPTCHA ─────────────────────────────────────────────────────────────────
define('RECAPTCHA_SECRET', '6LcjNg4tAAAAAJNVlYxvCELqmb9D_4an_EVsTqAq');


// ── App-wide constants ────────────────────────────────────────────────────────
define('BASE_URL_PATH', '/login/');
// Internal team (the company that runs Astra). Client companies get their own
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