# Astra

Enterprise software-delivery and governance platform: requirements, projects, testing, deployments, billing and credential handover in one workspace, with field-level encryption and a tamper-evident audit trail.

Plain PHP 8 and MySQL/MariaDB, no framework, no build step. Designed to run under XAMPP.

## Features

**Workspaces and roles**
- Four self-serve tracks: full organization, solo developer/studio, client company, individual client
- Role-scoped portals for sysadmins, admins, employees (team leads, testers) and clients
- Team rosters, attendance (with a biometric/attendance webhook), leave policies and a leave calendar

**Delivery pipeline**
- Versioned client requirements with side-by-side diffs
- Projects, tasks, bug tracking and deployment approvals
- Dual-key milestone sign-off (project manager and client) and invoice-gated escrow release
- Ephemeral dossiers: single-use, time-limited encrypted handovers that are overwritten after viewing
- Security Scan Center: SAST scanning of uploaded source archives and OWASP ZAP report import

**Security**
- AES-256-GCM column encryption with versioned keys, and HMAC blind indexes for lookups
- HMAC-chained, tamper-evident activity log with in-app verification
- Argon2id passwords, emailed one-time codes, lockouts and per-network rate limits
- VPN/proxy screening at sign-in, honeytoken intrusion traps, session binding to device and network
- Google sign-in (OAuth 2.0 authorization code with PKCE)

**Compliance and accessibility**
- Privacy, terms, cookie and refund policies, cookie-consent banner and third-party embed guard
- Recorded, versioned terms acceptance at sign-up
- WCAG 2.1 AA work: focus rings, labelled controls, keyboard support, contrast-checked colours

**Interface**
- Animated landing page and login rendered over a three.js temple scene
- Dark (crimson) and light (cobalt) themes

## Requirements

- XAMPP with PHP 8.0+ (extensions: `mysqli`, `openssl`, `curl`, `mbstring`) and MariaDB 10.4+ / MySQL 8
- Apache with `mod_rewrite` and `mod_headers`
- A local SMTP catcher for development mail, e.g. [Mailpit](https://github.com/axllent/mailpit) on port 1025

## Setup

1. **Place the code** at `C:\xampp\htdocs\login` (the app is served under `/login/`; to change that, edit `BASE_URL_PATH` in `config/config.php` and `RewriteBase` in `.htaccess`).
2. **Create the database** `myapp`, import `schema_v2.sql`, then run every migration:
   ```bash
   C:\xampp\php\php.exe tools\run_migrations.php
   ```
   Migrations are idempotent. A signed-in sysadmin can also run them from the Database Migration page.
3. **Configure** `config/config.php`: database credentials, mail settings, `PRIMARY_SYSADMIN_EMAIL`, and the `LEGAL_*` details shown in footers and policies.
4. **Add secrets** as one-line files in `config/`. Every `config/*.key` file is git-ignored and blocked from web access:

   | File | Purpose |
   | --- | --- |
   | `recaptcha.key` | reCAPTCHA v2 secret (or set `ASTRA_RECAPTCHA_SECRET`) |
   | `google_oauth.key` | Google OAuth client secret (or set `ASTRA_GOOGLE_CLIENT_SECRET`) |
   | `astra_db.key`, `astra_index.key` | Encryption and blind-index keys, generated automatically on first run |

   Back up the encryption keys: without `astra_db.key` the encrypted columns cannot be read.
5. **Google sign-in (optional).** Create a "Web application" OAuth client in Google Cloud Console, set `GOOGLE_CLIENT_ID` in `config/config.php` (or `ASTRA_GOOGLE_CLIENT_ID`), and register `http://localhost/login/auth/google_auth.php` as an authorised redirect URI.
6. Open `http://localhost/login/`.

## Tests

```bash
C:\xampp\php\php.exe tests\runner.php
```

The suite clones the database into a throwaway `myapp_test`, runs every endpoint through `php-cgi` against the clone with a local SMTP sink, and drops the clone afterwards. It covers encryption, authentication, registration, governance, web exposure, the security scanner, visual architecture, compliance and the portal design system. Use `--only=<text>` to run matching tests.

## Project layout

| Path | Contents |
| --- | --- |
| `auth/` | Sign-in, registration, one-time codes, password reset, Google OAuth callback |
| `portals/` | Role portals: `sysadmin/`, `admin/`, `emlpoyee/`, `client/`, `projects/`, `deliveries/`, `security/`, `user/` |
| `core/` | Shared modules: database and sessions, crypto, audit chain, escrow, access control, theme |
| `api/` | Webhooks and JSON endpoints (attendance sync, payments, scan streaming) |
| `assets/` | Stylesheets, scripts and media |
| `landing-pages/` | The landing page and its three.js scene assets |
| `legal/` | Policy documents and the cookie-consent banner |
| `config/` | Configuration, migrations and (ignored) key files |
| `tests/` | End-to-end suite and fixtures |

## Security notes

- Keep secrets in `config/*.key` or environment variables, never in `config/config.php`.
- For production, serve over HTTPS (session and sign-in cookies are then marked `Secure` automatically) and replace the local database and mail settings.
- The legal documents are templates based on how the code handles data; have them reviewed before relying on them.
