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

## Interface

The screens use the same spatial UI as the PHP app ("Quiet glass", see
`../docs/spatial-ui/DESIGN.md`), built on Tailwind v4 with Watermelon UI
components used directly.

- `src/styles/spatial.css`: the spatial tokens (the same values as
  `../assets/css/theme-spatial.css`), mapped onto the colour names the
  Watermelon components use, plus the glass recipe and the fallbacks.
- `src/components/watermelon/`: the vendored Watermelon components (button,
  Button 9 loading dots, badge, label, table, Floating Input, Input OTP 10,
  Fluid Tabs), restyled on the tokens. Each file names its source.
- `src/components/spatial/`: the auth shell, the page panel, the brand mark,
  the theme switch, icons and the inline status message.
- The theme key is the PHP app's (`localStorage.astra_theme`), falling back
  to the system preference. Fonts are self-hosted in `public/fonts/`.
- `tests/pilot/spatial-ui.test.ts` pins the tokens (and their parity with
  the PHP app), the fallbacks, the focus ring and AA contrast for every
  text-on-glass pair.

The home page at `/` is the PHP app's hero (`../landing-pages/hastra.html`),
not a React screen. `scripts/sync-landing.mjs` copies it and the files it
loads into `public/landing/` before every `dev` and `build` (the copies are
gitignored), pointing Sign in and Get Started at `/signin` and `/register`
and the Labs and legal links at the PHP app. `public/landing/index.html` is
the frame host, the edge copy of `../index.php`, and `public/_redirects`
serves it at `/`. `tests/pilot/landing.test.ts` pins the link rewrites and
the asset list.

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
npx wrangler pages project create hastra --production-branch main
npx wrangler pages secret put SUPABASE_URL --project-name hastra
npx wrangler pages secret put SUPABASE_SERVICE_ROLE_KEY --project-name hastra
npx wrangler pages secret put HASTRA_DB_ENC_KEY --project-name hastra
npx wrangler pages secret put HASTRA_DB_INDEX_KEY --project-name hastra
npx wrangler pages secret put HASTRA_SESSION_KEY --project-name hastra
npx wrangler pages secret put HASTRA_RECAPTCHA_SECRET --project-name hastra
npx wrangler pages secret put HASTRA_GOOGLE_CLIENT_ID --project-name hastra
npx wrangler pages secret put HASTRA_GOOGLE_CLIENT_SECRET --project-name hastra
npx wrangler pages secret put HASTRA_MAIL_API_KEY --project-name hastra
npx wrangler pages secret put HASTRA_MAIL_FROM --project-name hastra
npx wrangler pages secret put HASTRA_MAIL_NAME --project-name hastra
npm run deploy
```

Then in Google Cloud Console, add
`https://hastra.pages.dev/api/auth/google` as an authorised redirect
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

## Sign-in portals and sign-up tracks

Sign-in has the PHP app's two portals, Enterprise Workspace and Client
Gateway (`?portal=enterprise` opens the first), with Google sign-in,
show-password, remember me and forgot password. Both portals use the same
check; the portal sets the wording and the sign-up link.

Sign-up has the PHP app's four tracks (`functions/_lib/signup.ts`, shared by
the endpoint and the screen; `?track=enterprise` opens the Enterprise tab):

| Track | Asks for | On verification |
| --- | --- | --- |
| Client Gateway, Company | company name, size, contract / PO reference | company created, user is `client` and its IT Manager |
| Client Gateway, Individual / Freelancer | country | "Name (Individual)" workspace, user is `client` |
| Enterprise Workspace, Full Organization | organization name, size, leave policy | company with leave policy, user is `admin` |
| Enterprise Workspace, Solo Enterprise | studio name, country | company created, user is `admin` |

Every track also takes name, email, phone (E.164), a password meeting the
PHP rules (including a special character) and acceptance of the terms. The
role is granted only when the emailed code is accepted, from the track
stored at sign-up (`users.signup`), never from the request. Run
`supabase/migrations/0004_signup_tracks.sql` before deploying this.

## What's simplified for the pilot (tracked, not forgotten)

- **Sign-up** leaves out three PHP pieces: the brand-logo finder (it calls an
  outside logo service), "Sign up with Google" with a chosen track, and the
  biometric attendance webhook secret for full organizations (attendance
  isn't ported).
- **Rate limiting** on login/OTP attempts beyond the 5-attempt OTP lockout
  (the PHP app's per-IP rate limiter in `core/db.php` isn't ported yet).
- **Geo/IP reputation screening** (`core/geo_security.php`'s VPN/proxy
  detection) isn't ported — Workers' `request.cf` gives country for free,
  but the anti-VPN logic itself is deferred.
- Only key version 1 of the encryption envelope is supported (no key
  rotation yet — the PHP app supports up to v8).
