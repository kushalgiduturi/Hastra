# Hastra

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

**Hastra Labs: free community tools and student hub** (`/labs/`, no enterprise account needed)
- *Cryptography & File Armor:* encrypt text or any file with AES-256-GCM, ChaCha20-Poly1305, RSA-4096-OAEP, X25519, ML-KEM-768/1024 (FIPS 203) or the X-Wing hybrid (ML-KEM-768 + X25519); ML-DSA-65/87 signatures (FIPS 204); a credential hasher that separates human passwords (Argon2id, bcrypt) from machine tokens (HMAC-SHA256, PBKDF2-SHA512, keyed BLAKE3), with a verifier and a pattern-aware strength estimate; a built-in self-test with published known-answer vectors
- *SIEM / Threat Intel & SOC Lab:* STIX 2.1 / TAXII 2.1 / MISP feed normalizer with refanging, SHA-256 dedup keys and MITRE ATT&CK mapping; UEBA composite risk scoring; a 15M-EPS pipeline and storage-tier sizing calculator; an M365 + CloudTrail + Kubernetes correlator that draws cross-cloud incident chains
- *Syllabus Accelerator & Video Tutor:* PDF/DOCX/TXT syllabi parsed in the browser into units and topics, a view-ranked tutorial per topic with like and dislike-to-swap, completion forecasting against a target date, streaks, and Markdown/JSON mastery reports
- Everything sensitive runs in the browser. An optional, password-free community session (a recovery code, stored only as a blind index) syncs study progress across devices

## Requirements

- XAMPP with PHP 8.0+ (extensions: `mysqli`, `openssl`, `curl`, `mbstring`) and MariaDB 10.4+ / MySQL 8
- Apache with `mod_rewrite` and `mod_headers`
- A local SMTP catcher for development mail, e.g. [Mailpit](https://github.com/axllent/mailpit) on port 1025

## Setup

1. **Place the code** at `C:\xampp\htdocs\Hastra` (the app is served under `/Hastra/`; to change that, edit `BASE_URL_PATH` in `config/config.php` and `RewriteBase` in `.htaccess`).
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
   | `youtube.key` | YouTube Data API v3 key for the Labs video tutor (optional; or set `HASTRA_YOUTUBE_API_KEY`) |

   Back up the encryption keys: without `astra_db.key` the encrypted columns cannot be read.
5. **Google sign-in (optional).** Create a "Web application" OAuth client in Google Cloud Console, set `GOOGLE_CLIENT_ID` in `config/config.php` (or `ASTRA_GOOGLE_CLIENT_ID`), and register `http://localhost/Hastra/auth/google_auth.php` as an authorised redirect URI.
6. **Labs video ranking (optional).** Without a key, each syllabus topic links to YouTube's own most-viewed results. With a key (Google Cloud Console → enable *YouTube Data API v3* → create an API key, restricted to that API), topics show in-page view-ranked tutorials. Results are cached for 7 days and fresh lookups are capped at 90 a day, which stays inside the free 10,000-unit quota.
7. Open `http://localhost/Hastra/` (enterprise) or `http://localhost/Hastra/labs/` (free tools).

## Deployment

Production runs as a Docker stack (app on PHP 8.2 + Apache, MariaDB, and Caddy for automatic HTTPS) on any Linux VPS. Every deployment setting comes from `.env` (see `.env.example`); local XAMPP needs none of it. Step-by-step instructions, including backups of the encryption keys, are in [DEPLOY.md](DEPLOY.md).

## Tests

```bash
C:\xampp\php\php.exe tests\runner.php
```

The suite clones the database into a throwaway `myapp_test`, runs every endpoint through `php-cgi` against the clone with a local SMTP sink, and drops the clone afterwards. It covers encryption, authentication, registration, governance, web exposure, the security scanner, visual architecture, compliance, the portal design system and Hastra Labs. Use `--only=<text>` to run matching tests.

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
| `labs/` | Hastra Labs: the public hub, its three tool pages, the community session and the progress / video APIs |
| `config/` | Configuration, migrations and (ignored) key files |
| `tests/` | End-to-end suite and fixtures |

## Security notes

- Keep secrets in `config/*.key` or environment variables, never in `config/config.php`.
- For production, serve over HTTPS (session and sign-in cookies are then marked `Secure` automatically) and replace the local database and mail settings.
- The legal documents are templates based on how the code handles data; have them reviewed before relying on them.
- Hastra Labs has its own session cookie (`HASTRA_LABS`, scoped to `/labs/`) and, only under `/labs/`, a wider Content-Security-Policy: `'wasm-unsafe-eval'` for Argon2id/bcrypt, `blob:` workers for the PDF reader, and YouTube's no-cookie player. Its libraries load from jsDelivr at pinned versions; bump them in `labs/_boot.php` only after the self-test on `/labs/crypto` passes.
