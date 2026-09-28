# Hastra Edge (pilot) — Cloudflare Pages + Supabase

This is a **parallel, in-progress rewrite** of Hastra onto Cloudflare Pages
(React/Vite frontend + Pages Functions backend) and Supabase Postgres. It does
not replace the PHP app in `../` — that keeps running unmodified on
Render/XAMPP/Docker. See `C:\Users\Kushal\.claude\plans\proud-hatching-orbit.md`
for the full migration plan and rationale.

**Scope of this pilot**: registration, login, email-OTP 2FA, forgot/reset
password, Google sign-in (PKCE), logout, and one full portal (sysadmin: role
management + the tamper-evident audit log). Everything else — the other 7
portal roles, escrow/milestone sign-off, the SAST scanner, ephemeral
dossiers, Hastra Labs' backend, and the webhook APIs — is deferred to
follow-up passes once this pilot is validated end-to-end.

## Prerequisites

- A free [Supabase](https://supabase.com) project.
- A free [Cloudflare](https://dash.cloudflare.com) account (Pages + Workers).
- Node.js 20+.
- A transactional-email provider with an HTTP API (default wiring is
  [Brevo](https://www.brevo.com)'s free tier — 300 emails/day).

## 1. Set up Supabase

1. Create a project, then run the SQL in `supabase/migrations/0001_pilot_schema.sql`
   (Supabase dashboard → SQL Editor → paste → Run).
2. From Project Settings → API, copy the **Project URL** and the
   **service_role** key (not the anon key — Functions use the service role
   and enforce access control themselves, matching how the PHP app's
   `mysqli` connection has full table access and enforces rules in code).

## 2. Generate the crypto keys

```bash
openssl rand -base64 32   # run three times: ENC key, INDEX key, SESSION key
```

These are **not** interchangeable with the PHP app's `config/astra_db.key` /
`astra_index.key` — the pilot starts a fresh Postgres database, so there's no
existing ciphertext to stay compatible with yet.

## 3. Local development

```bash
cd edge
npm install
cp .dev.vars.example .dev.vars   # fill in Supabase URL/key, the 3 crypto keys, etc.
npm run build                    # or: npx vite build --watch, in a second terminal
npx wrangler pages dev dist --compatibility-date=2026-09-28
```

`wrangler pages dev` serves both the built frontend and `/functions` on
`http://127.0.0.1:8788`; `vite.config.ts` proxies `/api` there when you run
`npm run dev` for frontend-only hot reload.

## 4. Deploy

```bash
npx wrangler pages project create hastra-edge
npx wrangler pages secret put SUPABASE_URL
npx wrangler pages secret put SUPABASE_SERVICE_ROLE_KEY
npx wrangler pages secret put HASTRA_DB_ENC_KEY
npx wrangler pages secret put HASTRA_DB_INDEX_KEY
npx wrangler pages secret put HASTRA_SESSION_KEY
npx wrangler pages secret put HASTRA_RECAPTCHA_SECRET
npx wrangler pages secret put HASTRA_GOOGLE_CLIENT_ID
npx wrangler pages secret put HASTRA_GOOGLE_CLIENT_SECRET
npx wrangler pages secret put HASTRA_MAIL_API_KEY
npm run deploy
```

Then in Google Cloud Console, add
`https://<your-project>.pages.dev/api/auth/google` as an authorised redirect
URI on the OAuth client (a **separate** client from the PHP app's, or add
this as an additional redirect URI on the same one).

## 5. Verify

```bash
npm test
```

Runs the crypto-envelope, blind-index, password-hashing, and audit-chain
tests (`tests/pilot/`). For the full flow, use the deployed site or
`wrangler pages dev` locally: register → check email for the OTP → verify →
sign in → sign in again with a `sysadmin` role set directly in Supabase
(`update users set role = 'sysadmin' where email_bindex = ...`) → visit
`/workspace/sysadmin/roles` and `/workspace/sysadmin/logs`.

## What's simplified for the pilot (tracked, not forgotten)

- **reCAPTCHA** is wired server-side (`functions/_lib/recaptcha.ts`) but the
  actual widget isn't in the `Login` form yet — it sends a placeholder token.
- **Rate limiting** on login/OTP attempts beyond the 5-attempt OTP lockout
  (the PHP app's per-IP rate limiter in `core/db.php` isn't ported yet).
- **Geo/IP reputation screening** (`core/geo_security.php`'s VPN/proxy
  detection) isn't ported — Workers' `request.cf` gives country for free,
  but the anti-VPN logic itself is deferred.
- Only key version 1 of the encryption envelope is supported (no key
  rotation yet — the PHP app supports up to v8).
