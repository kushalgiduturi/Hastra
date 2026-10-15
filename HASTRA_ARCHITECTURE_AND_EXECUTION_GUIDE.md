# HASTRA ARCHITECTURE, FUNCTION FLOW & EXECUTION MANUAL

Generated from the repository on 2026-10-15 (commit `9cde991`). The source files are the authority: every code listing in section 5 is copied from the file it names, unedited, and every other section describes what that code does today.

**How this manual maps to the brief it was written from.** Hastra is a PHP 8 and MySQL application served by Apache in Docker on Render, with an Aiven MySQL database. Some names in the brief describe a different stack. Each one is mapped to the real component below, and the manual documents the real one:

| Name in the brief | What Hastra actually uses | Why |
|---|---|---|
| `index.html`, `auth/login.html`, `auth/register.html`, `404.html`, `labs/*.html`, `workspace/client/index.html` | `index.php`, `auth/login.php`, `auth/register.php`, `404.php`, `labs/*.php`, `portals/client/client_portal.php` (served at `/workspace/client/`) | Server-rendered PHP pages; Apache rewrites give them clean addresses |
| `robots.txt`, `sitemap.xml` | `robots.php`, `sitemap.php`, rewritten from `/robots.txt` and `/sitemap.xml` | Generated from `APP_URL`, so they are correct on every domain |
| `assets/js/cyberpunk-street-engine.js` (Three.js street) | The temple world in `landing-pages/hastra.html`, hosted behind other pages by `assets/js/kage-scene.js`, flown by `assets/js/view-director.js` | The owner chose to keep the temple scene (October 2026). It already has the Catmull-Rom camera spline, scroll-linked traversal, the `flyTo` API, the ten rings and a night and day lighting mode |
| `assets/js/onboarding-controller.js` with Firestore profile checks | `core/auth_check.php` (barrier markup and rules), `assets/js/profile-barrier.js` (client), `api/complete_profile.php` (endpoint), `users.gender` and `users.profile_focus` in MySQL | The owner chose to keep accounts in MySQL. Firebase is not used for sign-in or profiles |
| `assets/js/firebase-config.js` | Present in the working tree but not loaded by any page and not committed | Community video votes live in MySQL (`labs_video_votes`) |
| `firestore.rules` | `firestore.rules` exists and is complete, ready for deployment if data is ever mirrored to Firestore. Apache refuses to serve it | Kept as a ready asset by the owner's choice |
| `assets/js/page-loader.js` | `initPageLoader()` inside `core/theme.js` | Must run synchronously in `<head>` before first paint, which `theme.js` already does on every page |
| `HEX1` binary envelope | `HLX1` (magic bytes `48 4C 58 31`), files ending `.hlx` | That is the real magic identifier written by `assets/js/pqc-crypto.js` |

---

## 1. High-Level System Architecture & Flowchart

### 1.1 Deployment topology

```
                                   ┌──────────────────────────────────────────┐
  Visitor's browser ──HTTPS──────▶ │ Render edge (TLS, Cloudflare in front)    │
   (theme, petals, UX layer,       └───────────────────┬──────────────────────┘
    WebGL temple, Labs crypto                          │ X-Forwarded-For / CF-Connecting-IP
    all run locally)                                   ▼  (trusted when HASTRA_TRUST_FORWARDED=1)
                                   ┌──────────────────────────────────────────┐
                                   │ Docker container (Render free plan)       │
                                   │  docker/entrypoint.sh                     │
                                   │   1. writes /data/keys/*.key from          │
                                   │      HASTRA_DB_KEY / HASTRA_CRYPTO_KEY /   │
                                   │      HASTRA_SECRET_KEY / HASTRA_WEBHOOK_KEY│
                                   │   2. waits for MySQL, creates schema once  │
                                   │   3. php tools/run_migrations.php          │
                                   │   4. optional one-off purge (opt-in env)   │
                                   │  Apache 2.4 + mod_php 8 + .htaccess routes │
                                   └───────┬───────────────────────┬──────────┘
                                           │ TLS (DB_SSL_CA)        │ SMTP (PHPMailer)
                                           ▼                        ▼
                             ┌──────────────────────────┐   ┌───────────────────┐
                             │ Aiven MySQL 8 (free)      │   │ Mail provider      │
                             │ users, companies, projects│   │ codes, invites,    │
                             │ logs (hash chain), labs_*,│   │ erasure links      │
                             │ analytics_events, app_errors│ └───────────────────┘
                             │ rate_windows, …           │
                             └──────────────────────────┘
  Third parties called from the server: ip-api.com (VPN/proxy screening), Google OAuth,
  Google reCAPTCHA siteverify, YouTube Data API (Labs video ranking), Anthropic (optional docs).
  Kept awake by an UptimeRobot monitor hitting /labs/api/vote?topic=keepalive every 5 minutes.
```

### 1.2 Request lifecycle, end to end

```
Browser requests a page
│
├─▶ Apache .htaccess
│     • blocks dotfiles, tests/, _backup_*/, *.ini|log|sh|sql|rules|md, PHPMailer, config/
│     • 301s "page.php" to "page" (GET only), maps short addresses:
│         /            → index.php            (landing host)
│         /signin      → auth/login.php       /signup → auth/register.php
│         /workspace/… → portals/<role>/…     /labs/… → labs/*.php
│         /privacy/delete-data → legal/delete-data.php
│         /robots.txt → robots.php            /sitemap.xml → sitemap.php
│     • unknown address → 404.php (standalone: no DB, no scripts)
│
├─▶ PHP page: require core/db.php
│     • security headers (CSP, X-Frame-Options, nosniff), MySQL connection (TLS on Aiven)
│     • loads crypto (key ring + blind index), audit chain, session guard, network
│       (trusted client IP), rate limiter, telemetry (+ fatal-error capture), geo screen,
│       companies, onboarding, access, integrity, SEO helpers
│     • secure_session_start(): HttpOnly, SameSite=Strict, Secure on HTTPS;
│       30-minute idle timeout; astra_session_guard() checks the device fingerprint
│       (UA + Accept-Language + IP subnet, HMAC'd) and the user_sessions row
│
├─▶ <head> loads core/theme.js synchronously
│     • initPageLoader(): writes the orbital loader and a route-aware status line
│       ("Initializing defense-grade workspace…", "Loading Hastra Cryptographic Engine…")
│       removed on window load; hard stop after 4 s; route loader on link clicks
│       (shown after 140 ms, failsafe 10 s)
│     • applies the saved theme (localStorage astra_theme) before paint
│     • mounts the atmosphere: performance-guard.js first, then the scene host
│       (kage-scene.js), the cursor engine (sakura-petals-cursor.js + hybrid-hand-cursor.js
│       on public pages; netflix-cursor.js in portals), view-director.js
│     • mounts the usability layer: assets/css/hastra-ux.css + assets/js/hastra-ux.js
│
├─▶ Sign-in (auth/login.php)                         Google ("Continue with Google")
│     email + password + reCAPTCHA                     core/google_oauth.php
│       → VPN/proxy screen (geo_security.php)            PKCE + state + nonce in an
│       → IP lockout (ip_attempts)                        encrypted hastra_goauth cookie
│       → password_verify                                → id_token verified
│       → 6-digit code by email (10 min)                 → account found by
│     auth/otp.php → code verified                         google_id_bindex / email_bindex
│       → session_regenerate_id + astra_session_anchor ◀───┘
│       → verify_session(role) on every portal page
│
├─▶ First authenticated page: render_profile_barrier()  (core/auth_check.php)
│     needs gender? ── no ─▶ portal
│        │ yes
│        ▼
│     astra_profile_role_context(user)
│        ├─ organization member  (companies.account_type ∈ {full_org, client_org},
│        │   or users.role ∈ {employee, pending_employee, sysadmin})
│        │     → role shown read-only: "Role locked by your organization's policy.
│        │       Contact your Organization System Administrator…"
│        │     → POST gender only; api/complete_profile.php returns 403 if focus is sent
│        └─ independent       (solo_enterprise, client_individual, no company)
│              → searchable 11-role catalog, no approval
│              → POST gender + focus; saved to users.profile_focus
│              → astra_focus_landing(focus) redirects straight to the matching lab
│
├─▶ Labs (labs/_boot.php, own session HASTRA_LABS)
│     index → crypto | siem | syllabus | auth (password-free Labs session)
│     trial-gatekeeper.js: one free run per visit, then a free session or account;
│     everything cryptographic runs in the browser (no plaintext is uploaded)
│
└─▶ 3D scene
      landing (index.php) hosts landing-pages/hastra.html in an iframe:
        full document: preloader, hero (monumental HASTRA wordmark + ten rings),
        chapters, scroll-driven camera
      every other themed page: kage-scene.js loads the same file with ?scene=bg
        (render only), view-director.js picks the page's waypoint and flies there
      theme toggle → landing-host.js: night (stock world) or day (DAY_CSS skin +
        day lighting, brass rings, warm sky), mirrored to data-hastra-day
```

### 1.3 3D scene and camera flight

```
   page scroll (landing)                      page change (portals / auth)
         │                                              │
         ▼                                              ▼
  measure(): anchors[] from [data-cam]          view-director.js pageWaypoint()
  sections (centre of each in the viewport)      maps the path to waypoint 0..4
         │                                              │
  progressFor(scrollY) → RIG.prog (0..5)        sessionStorage astra_cam_u = last u
         │                                              │
         ▼                                              ▼
  RIG.smooth = damp(RIG.smooth, RIG.prog,       HastraAtmosphere.flyTo(u, { from,
               5.2, dt)   (critically damped)     duration: 1.5 s + 0.25 s per waypoint })
         │                                              │
         └──────────────────┬───────────────────────────┘
                            ▼
   applyCamera(): u = RIG.smooth / 5
     curveP.getPoint(u)  CatmullRomCurve3 over 6 camera positions, tension 0.42
     curveT.getPoint(u)  CatmullRomCurve3 over 6 look-at targets
     fov  = lerp between waypoint FOVs; fitAspect() steps back on tall screens
     intro dolly (long lens easing in) + pointer parallax (fades with progress)
                            │
                            ▼
   renderer (WebGL) → planar mirror (wet stone) → post-processing → canvas #gl
```

---

## 2. Component Directory: Where Everything Works

### 2.1 Pages and templates

| File | Address | Responsibility | Depends on |
|---|---|---|---|
| `index.php` | `/` | Landing host: SEO and JSON-LD, hosts the landing document full-screen, keeps the theme in sync | `core/seo.php`, `assets/js/landing-host.js` |
| `landing-pages/hastra.html` | (iframe) | The temple world and the marketing document: hero, chapters, arsenal, dossier simulator, footer. Also the render-only background (`?scene=bg`) | `secret-pathways-assets/three.min.js` (Three.js, MIT, 2023 build), COBE globe, `assets/css/fonts.css` |
| `auth/login.php` | `/signin` | Two-portal sign-in (Client Gateway / Enterprise Workspace), sliding overview panel with the text fog, reCAPTCHA, email code | `core/db.php`, `core/legal.php`, `assets/css/theme-authkit.css` |
| `auth/register.php` | `/signup` | Company, individual and enterprise sign-up; country list and dial-code validation; logo lookup (third-party consent) | `core/countries.php`, `core/company.php` |
| `auth/otp.php`, `forgot.php`, `forgot_otp.php`, `reset.php`, `set_password.php`, `verify_register.php` | `/verify`, `/forgot-password`, … | The email-code steps of sign-in, password reset, invitation and sign-up | `astra_mail_send()` |
| `core/google_oauth.php` | `/auth/google_auth` | Google sign-in and sign-up (PKCE, state, nonce) | Google OAuth |
| `portals/index.php` | `/workspace/` | Sends each role to its portal | `verify_session()` |
| `portals/client/*`, `portals/admin/*`, `portals/emlpoyee/*`, `portals/sysadmin/*`, `portals/user/*` | `/workspace/<role>/<page>` | Role portals: projects, requirements, delivery, billing, testing, attendance, leave, roles, logs, security, analytics and errors, profile | `core/dock.php` (sidebar + dock), `core/auth_check.php` |
| `labs/index.php` | `/labs/` | Labs hub: three pillars (icons, no numbered badges), FAQ accordion | `labs/_boot.php` |
| `labs/crypto.php` | `/labs/crypto` | Seal, open, keys, signatures, hashing; result viewport readable in both themes; architecture guides (native popovers) | `assets/js/pqc-crypto.js`, `labs-crypto-ui.js` |
| `labs/siem.php` | `/labs/siem` | Threat feed normalization, UEBA calculator, storage tiering, multi-cloud timeline | `siem-lab.js`, `ueba-calculator.js` |
| `labs/syllabus.php` | `/labs/syllabus` | Syllabus extraction (PDF, DOCX, TXT), view-ranked videos, pace forecast | `doc-extract.js`, `syllabus-engine.js` |
| `labs/auth.php` | `/labs/auth` | Password-free Labs session with a recovery code | `labs_profiles`, `labs_devices` |
| `legal/*.php` | `/legal/<doc>` | Privacy, Terms, Cookies, Refunds; "Last updated" date | `legal/_layout.php` |
| `legal/delete-data.php` | `/privacy/delete-data` | Self-service erasure, confirmed by email | `core/data_deletion.php` |
| `404.php` | any unknown address | Boxless brand 404 over the grid; works with the database down | none |
| `robots.php`, `sitemap.php` | `/robots.txt`, `/sitemap.xml` | Crawler directives and the public URL list, built from `APP_URL` | `config/config.php` |

### 2.2 Styles and theme

| File | Responsibility |
|---|---|
| `core/theme.css` | Design tokens for both themes (dark: `--text #ffffff`, `--accent #e50914`), base components, focus ring, overflow guard, 44 px touch targets, print stylesheet |
| `assets/css/theme-contrast.css` | Light-theme floor: `--text #000000`, `--accent #2563eb`, AA status colours, placeholders, sign-up card opacity |
| `assets/css/theme-fog-boxless.css` | The text fog: `.text-fog-scrim` (Black Fog by night, White Fog by day), feathered on every edge, no border or shadow |
| `assets/css/theme-netflix.css`, `netflix-bento.css` | Portal design system: boxless containers over the temple, hairline tables |
| `assets/css/theme-authkit.css` | Sign-in and sign-up glass card (the one panel the boxless rule allows) |
| `assets/css/hastra-ux.css` | Skip link, dialogs, inline errors, search palette, drawer, floating controls, copy buttons |
| `assets/css/tools-cyber.css` | Labs visual system, guides, result viewport, FAQ |
| `assets/css/fonts.css` + `assets/fonts/` | Self-hosted Share Tech Mono and Onest (SIL OFL), no Google Fonts requests |

### 2.3 Client engines

| File | Responsibility |
|---|---|
| `core/theme.js` | Page loader, theme application, keyboard parity, page transitions, brand intro, atmosphere mounting, UX-layer mounting |
| `assets/js/performance-guard.js` | Sets `window.HASTRA_PERF` and `html.low-perf-mode` on weak devices (4 or fewer CPU cores, 4 GB or less memory, the data-saver flag, or a measured median frame slower than 33 ms); emits `hastra:perf` |
| `assets/js/kage-scene.js` | Scene host: iframes the temple render behind a page, exposes `window.HastraAtmosphere` (`flyTo`, `setScroll`, `u`) |
| `assets/js/view-director.js` | Maps each page to a camera waypoint, flies between pages, runs reveal animations |
| `assets/js/landing-host.js` | Theme bridge into the landing iframe: day skin CSS, day lighting, restore on night |
| `assets/js/sakura-petals-cursor.js` | Single-lobe asymmetrical sakura petals with flip and drift physics (no hearts); off switch |
| `assets/js/hybrid-hand-cursor.js` | Pointer engine that feeds the petal field, card tilt, performance governors |
| `assets/js/hastra-ux.js` | Skip link, accessible dialogs, inline validation, Ctrl/Cmd+K search, drawer, progress line, back to top, contact, copy buttons, error reporting, consent-gated analytics |
| `assets/js/profile-barrier.js` | First-login gender and role selector (boxless, underline active state) |
| `assets/js/cookie-consent.js` | Consent banner state and the third-party embed guard (`HastraConsent`) |
| `assets/js/pqc-crypto.js` | Labs cryptography: AES-256-GCM, ChaCha20-Poly1305, ML-KEM, X-Wing, X25519, RSA-OAEP, ML-DSA, hashing, the HLX1 envelope |
| `assets/js/labs-crypto-ui.js` | Labs crypto page wiring, envelope anatomy view, weak-passphrase dialog |
| `assets/js/trial-gatekeeper.js` | One free Labs run per visit (local storage only, no fingerprint) |

### 2.4 Server components

| File | Responsibility |
|---|---|
| `core/db.php` | Bootstrap: headers, DB, session, CSRF, mail (`astra_mail_send` with compliance footer), includes every core module |
| `core/crypto.php` | Column encryption (AES-256-GCM envelope `hastra:v1:`, versioned key ring) and blind indexes (HMAC-SHA256) |
| `core/session_guard.php` | Device fingerprint binding and revocable sessions (`user_sessions`) |
| `core/audit_chain.php` | Tamper-evident, hash-chained activity log |
| `core/geo_security.php`, `core/network.php` | Trusted client IP, VPN/proxy screening |
| `core/auth_check.php` | Profile barrier: 11-role catalog, organization lock, lab landing |
| `core/access.php`, `core/company.php`, `core/onboarding.php` | Roles, companies, ID blocks, invitations |
| `core/rate_limit.php` | MySQL sliding-window rate limiter |
| `core/telemetry.php` | Consent-gated first-party analytics and scrubbed error tracking |
| `core/data_deletion.php` | Self-service erasure |
| `core/purge_unreadable.php`, `tools/purge_unreadable.php` | Guarded one-off removal of accounts made unreadable by lost keys |
| `core/seo.php` | Meta, canonical, Open Graph and Twitter tags; per-page descriptions |
| `api/*.php` | JSON endpoints: profile completion, tours, search index, analytics, client errors, attendance sync, payment webhook |
| `config/migrations/*.php`, `tools/run_migrations.php` | Idempotent schema migrations, run on every container start |
| `docker/entrypoint.sh`, `Dockerfile`, `docker/apache-hastra.conf` | Container start-up, keys, caching headers |
| `firestore.rules` | Ready-to-deploy Firestore rules (not used by the live site) |

### 2.5 Tests

`tests/runner.php` clones the database, runs `tests/suites/*.php` against the clone through php-cgi, and drops it. 307 checks at the time of writing, including the boxless rule, contrast tokens, the fog, the no-hearts petal, RBAC rules, erasure, telemetry scrubbing and the purge guards.

---

## 3. Function-by-Function Flow Description

### 3.1 Visual scrim and theme engine

**Boxless mandate.** Content never sits in a bordered, filled or shadowed card, tile or pill. Text, icons and controls sit straight on the page over the grid and glow, separated by typography, hairline rules and underlines. Portals enforce this in `netflix-bento.css`; a test fails if a portal container gains a border, fill or shadow. The sign-in and sign-up glass card is the one panel the owner asked to keep; real primary buttons are allowed, and ghost buttons become text links with an accent underline.

**Tokens.**

| Token | Dark (default) | Light (`html[data-theme="light"]`) | Contrast |
|---|---|---|---|
| Primary type `--text`, `--text-primary` | `#ffffff` | `#000000` | 19.6:1 on `#090b10`; 21:1 on white |
| Accent fill `--accent` | `#e50914` Crimson Red | `#2563eb` Cobalt Blue | white text on each: 4.8:1 and 5.2:1 |
| Accent text `--accent-bright` | `#ff4655` | `#2563eb` | 5.8:1; 5.2:1 |
| Fog `--fog-ink`, `--fog-alpha` | `3, 5, 10` at 0.72 (Black Fog) | `248, 250, 252` at 0.86 (White Fog) | |

Crimson `#e50914` is 4.1:1 on the dark background, under the 4.5:1 needed for small text, so in dark mode it colours fills, marks, underlines and focus rings while small accent text uses `#ff4655`. Cobalt passes as text, so light mode uses it everywhere.

**How the fog works.** An element with `.text-fog-scrim` becomes a positioned, isolated stacking context. Its `::before` sits behind the text (`z-index: -1`), extends 2.25 rem above and below and 3 rem to each side, and is filled with `rgba(var(--fog-ink), var(--fog-alpha))` plus a `backdrop-filter` blur. Two linear-gradient masks intersect so each edge fades from opaque to transparent over exactly the padding width. The text always sits on full-strength fog while the edge has no line, which is why it never reads as a box. On weak devices (`html.low-perf-mode`) the blur is dropped and the wash is denser; with `prefers-reduced-transparency` it becomes nearly solid. The landing document uses the same idea in its own CSS (`.hero::before`, `.sec::before`), tuned per section.

**Theme switching.** `core/theme.js` applies the saved theme before first paint, `toggleTheme()` flips `data-theme` on `<html>` and dispatches `hastra:themechange`, and `landing-host.js` mirrors it into the landing iframe as `data-hastra-day` and swaps the lighting.

### 3.2 3D traversal engine (the temple world)

| Brief's function | Real implementation | What it does |
|---|---|---|
| `initSplinePath()` | `buildRig()` in `landing-pages/hastra.html` §8 | Builds `curveP` and `curveT`, two `THREE.CatmullRomCurve3` (tension 0.42) through the six waypoints in `CAM` (position, target, FOV): hero, architecture, gardens, craft, afterlight, footer |
| `buildStreetEnvironment()` | §5 "the world" | Site plan in metres; worship hall with lit lattice bays, pent and main roofs, wings; the blood moon; foreground cut-out layers; the giant HASTRA wordmark; the ten rings halo; atmospherics (haze, leaf fall, cursor wisps); planar mirror for wet stone; post-processing |
| `setThemeMode()` | `landing-host.js` `sync()`, `day(win)`, `night()` | Night is the stock world. Day records every material it changes, injects `DAY_CSS`, retints lights, sky and haze ridges, and switches the rings to brass with amber runes; `restore()` puts every recorded value back |
| `flyToProgress(t, duration)` | `window.HastraAtmosphere.flyTo(u, { from, duration, instant })` in `kage-scene.js` | Eases the background camera along the same spline from `from` to `u`. `view-director.js` calls it with duration 1.5 s plus 0.25 s per waypoint crossed, and stores the final `u` in `sessionStorage` (`astra_cam_u`) so the next page starts where this one stopped |
| scroll-linked `animate()` | §9 `measure()` and `progressFor()`, §14 frame loop | Each `[data-cam]` section gets an anchor (its centre in the viewport). Scroll position maps to `RIG.prog` in 0..5; the frame loop damps `RIG.smooth` toward it (rate 5.2, frame-rate independent) and `applyCamera()` samples both curves at `u = RIG.smooth / 5` |

Extras in `applyCamera()`: `fitAspect()` steps the camera back and widens the FOV on tall screens so the gate is never cropped; an intro dolly eases in from further back; pointer parallax drifts the camera slightly and fades as you scroll. With `prefers-reduced-motion` the camera jumps instead of easing. The render pauses when the tab is hidden. If WebGL fails, `window.__hastraScene = { fallback: true }` and the CSS skin carries the page.

**The ten rings.** Ten engraved torus meshes in a circle behind the wordmark, each tilted about its radial axis so neighbours pass through one another (the depth buffer does the weaving). The circle turns, every ring spins, runes pulse, and lightning arcs between neighbours. Sized from the laid-out word so they frame the letters at every aspect ratio. Night: gunmetal with electric-cyan runes. Day: polished brass with amber runes.

**Hero layout.** Monumental HASTRA wordmark centred with the rings through it; top-left eyebrow, uppercase headline and description; bottom-left scroll cue and chapter index; boxless navigation with a Sign in link and a Get started pill.

### 3.3 Authentication and RBAC engine

| Brief's function | Real implementation | What it does |
|---|---|---|
| `evaluateUserAccess()` | `verify_session($conn, $role)` in `core/db.php`, then `astra_profile_barrier_needed()` and `astra_profile_role_context()` in `core/auth_check.php` | Confirms the session belongs to this device and holds the page's role, then decides whether first-login details are missing and whether the person's role is locked by an organization |
| `renderOnboardingScreen()` | `render_profile_barrier($conn)` | Prints an unclosable, boxless dialog over the portal: gender as three radio buttons with an underline on the active one; for independents a search box and a scrollable list of the 11 roles grouped in four sections; for organization members the assigned role, read-only, with the lock notice |
| underline gender selector | `assets/js/profile-barrier.js`, `assets/css/profile-barrier.css` | `role="radiogroup"` of buttons with `aria-checked`; Enter and Space activate them (keyboard parity in `core/theme.js`); underline accent on the selected option; live search filter over the role list |
| `directAccessRouter()` | `astra_focus_landing($key)` + the `redirect` field returned by `api/complete_profile.php` | Students and faculty land in the Syllabus lab; SOC analysts and threat-intel engineers in the SIEM lab; PQC, security engineers, architects and penetration testers in the Crypto lab; client stakeholders and auditors stay in their workspace |

Server enforcement: `api/complete_profile.php` checks the session, the method and the CSRF token, validates gender, and accepts `focus` only from independents. An organization member who sends `focus` gets HTTP 403. `focus` is checked against the catalog. The value is stored in `users.profile_focus`, never in `users.role`, so choosing a role can never grant access.

### 3.4 Zero-trust cryptographic workbench

| Brief's function | Real implementation (`assets/js/pqc-crypto.js`) |
|---|---|
| `sealEnvelope()` | `encryptPayload(data, { alg, passphrase or recipient, name, type })` returns `{ envelope, header }` |
| `openEnvelope()` | `decryptEnvelope(envelope, { passphrase or secretKey })` (after `parseEnvelope()` reads the header) |
| `hashCredentials()` | `hashCredential()` and `verifyCredential()`: Argon2id, bcrypt and keyed hashes, used by the Hashing tab |

| Primitive | Parameters used |
|---|---|
| AES-256-GCM (passphrase) | PBKDF2-SHA256, 600,000 rounds, random 16-byte salt; random 12-byte nonce; 128-bit tag; header as associated data |
| ChaCha20-Poly1305 | Same flow, RFC 8439 cipher |
| ML-KEM-768 / ML-KEM-1024 (FIPS 203) | Encapsulate to the recipient's public key; shared secret through HKDF-SHA256 with a random salt, bound to algorithm and key id, becomes the AES-256-GCM key |
| X-Wing | ML-KEM-768 combined with X25519; safe if either holds |
| ML-DSA-65 / ML-DSA-87 (FIPS 204) | Signatures of 3,309 and 4,627 bytes; the signature file also records SHA-256 of the message; secret keys can be passphrase-protected and are wiped after use |
| Argon2id (RFC 9106) | 64 MiB memory, 4 passes, 2 lanes, random salt, 32-byte output |
| bcrypt | Cost 12; only the first 72 bytes count |
| Machine tokens | HMAC-SHA256 or keyed BLAKE3 with a server key; PBKDF2-SHA512 where FIPS is required |

**HLX1 envelope.**

```
offset  size        field
0       4 bytes     magic "HLX1"  (0x48 0x4C 0x58 0x31)
4       4 bytes     header length N, unsigned 32-bit big-endian
8       N bytes     JSON header: alg, nonce (IV), salt or KEM ciphertext, key id,
                    file name, size, timestamp
8+N     rest        AEAD ciphertext followed by its authentication tag
associated data = magic ‖ length ‖ header, so changing any of them makes decryption fail
```

The result viewport (`.lx-out`) uses the Labs tokens, so it is dark-on-light by day and light-on-dark by night, with a copy button added by the UX layer. Libraries are the audited `@noble` packages, pinned, plus WebCrypto and hash-wasm. Plaintext, passphrases and keys never leave the browser.

### 3.5 Particle physics and page supervision

**Sakura petals** (`assets/js/sakura-petals-cursor.js`). One sprite drawn once per tint: a single smooth lobe built from three Bézier curves, asymmetrical so it leans, with no notch (a test fails on any heart glyph or two-lobe curve). A pooled particle field (120 petals in the shared engine) spawns petals along the pointer path every 26 px. Each petal lives 1.2 to 2.0 s, is 7 to 14 px, falls with gravity 46, slows with drag 1.5, sways with a sine wind term, and spins and flips in 3D (a cosine of the flip angle scales one axis). Crimson-family tints. It pauses when the page is hidden, shrinks its budget on `hastra:perf` and low-perf mode, and switches off with `localStorage.hastra_cursor_trail = 'off'` or `window.HASTRA_NO_TRAIL = true`.

**Orbital loader** (`initPageLoader()` in `core/theme.js`). Written into the document before paint: two counter-rotating rings and a status line picked from the route (`LOADER_TEXT_MAP`). Removed on `load` with a 260 ms fade; never stays longer than 4 s. On internal link clicks a route loader appears only if the next page takes more than 140 ms, with a 10 s failsafe. Pages can opt out with `data-loader-manual`.

---

## 4. Role-Based Access Control (RBAC) Specification

### 4.1 Two separate concepts

| Field | Meaning | Who sets it |
|---|---|---|
| `users.role` | **Authorization.** Decides which portal and pages a person can open: `sysadmin`, `admin`, `employee`, `pending_employee`, `client` (with `client_role` such as `pm` or `it_manager`), `deleted` | Sign-up flow, organization administrators, the system administrator; the primary sysadmin is restored to whoever holds `PRIMARY_SYSADMIN_EMAIL` at start-up |
| `users.profile_focus` | **Descriptive label** chosen at first login. Personalizes landing and copy. Grants nothing | The person themselves (independents only) |

### 4.2 The 11-role catalog

| Group | Key | Title | First landing |
|---|---|---|---|
| Security operations & defense | `soc_analyst_l1` | SOC Analyst (Tier 1 Triage) | SIEM lab |
| | `soc_analyst_l2` | SOC Incident Responder (Tier 2) | SIEM lab |
| | `threat_intel_engineer` | Threat Intelligence Engineer | SIEM lab |
| | `pqc_cryptographer` | Cryptographic / PQC Engineer | Crypto lab |
| Engineering & architecture | `security_engineer` | DevSecOps / Cloud Security Engineer | Crypto lab |
| | `security_architect` | Enterprise Security Architect | Crypto lab |
| | `penetration_tester` | Penetration Tester / Red Teamer | Crypto lab |
| Academic & research | `student_researcher` | Student / Security Researcher | Syllabus lab |
| | `faculty_instructor` | Academic Faculty / Instructor | Syllabus lab |
| Governance & client delivery | `client_stakeholder` | Client Project Stakeholder | Workspace |
| | `compliance_auditor` | Compliance & Risk Auditor | Workspace |

### 4.3 Independent versus organization rules

```
locked = companies.account_type ∈ { full_org, client_org }
      OR users.role ∈ { employee, pending_employee, sysadmin }

if locked:
    barrier shows the assigned role read-only
    POST /api/complete_profile.php with focus      → 403
    role changes happen only through organization administrators
    (portals/admin/directory.php, portals/sysadmin/roles.php)
else (solo_enterprise, client_individual, or no company):
    barrier shows the searchable 11-role list, no approval step
    POST gender + focus                             → saved, redirect to the lab
```

In the Firestore model (`firestore.rules`), the same rule is expressed with `orgId`: a profile with `orgId == null` may change `role` among the 11 keys; a profile with an `orgId` may change only `displayName` and `gender`, and only that organization's sysadmins may change a member's role in `orgs/{orgId}/members`.

---

## 5. Complete Production Source Code Implementations

Each listing below is the file exactly as committed. The 3D world itself (`landing-pages/hastra.html`, 5,924 lines including its shaders, geometry and copy) is too large to reproduce here; its camera rig and scroll mapping (§8 and §9 of that file) are included in full, together with the scene host and flight director that drive it.

### 5.1 `assets/css/theme-fog-boxless.css`

The dual-theme text fog and the strict type tokens. (87 lines)

```css
/* ========================================================
   HASTRA TEXT FOG + BOXLESS TOKENS
   Loaded after core/theme.css and assets/css/theme-contrast.css on every
   themed page.

   Text that floats over the 3D temple never sits on the raw scene: a
   .text-fog-scrim element carries a feathered fog behind it. By night it is
   Black Fog under pure white type; by day it is White Fog under Jet Black
   type. The fog fades to transparent over a fixed width at all four edges,
   so the text always has a calm ground while there is no border, outline or
   shadow that could read as a box.

   Strict type and accent tokens:
     dark   type #ffffff on Black Fog    accent Crimson Red #e50914
     light  type #000000 on White Fog    accent Cobalt Blue #2563eb
   ======================================================== */

:root {
  --fog-ink: 3, 5, 10;              /* Black Fog: rgb of the night wash */
  --fog-alpha: .72;
  --fog-blur: 8px;
  --color-ice-highlight: #ffffff;   /* sign-in card headings: pure white by night */
}
html[data-theme="light"] {
  --fog-ink: 248, 250, 252;         /* White Fog */
  --fog-alpha: .86;
  --fog-blur: 10px;
}

.text-fog-scrim {
  position: relative;
  isolation: isolate;
}
.text-fog-scrim::before {
  content: "";
  position: absolute;
  inset: var(--fog-inset-y, -2.25rem) var(--fog-inset-x, -3rem);
  z-index: -1;
  pointer-events: none;
  background: rgba(var(--fog-ink), var(--fog-alpha));
  -webkit-backdrop-filter: blur(var(--fog-blur)) saturate(1.1);
          backdrop-filter: blur(var(--fog-blur)) saturate(1.1);
  /* each edge fades to nothing over exactly the padding width, so the text
     itself is always on full-strength fog */
  -webkit-mask-image: linear-gradient(to right, transparent 0, #000 3rem, #000 calc(100% - 3rem), transparent 100%),
                      linear-gradient(to bottom, transparent 0, #000 2.25rem, #000 calc(100% - 2.25rem), transparent 100%);
          mask-image: linear-gradient(to right, transparent 0, #000 3rem, #000 calc(100% - 3rem), transparent 100%),
                      linear-gradient(to bottom, transparent 0, #000 2.25rem, #000 calc(100% - 2.25rem), transparent 100%);
  -webkit-mask-composite: source-in;
          mask-composite: intersect;
}
/* weak devices (assets/js/performance-guard.js): no blur, a slightly denser wash */
html.low-perf-mode .text-fog-scrim::before {
  -webkit-backdrop-filter: none;
          backdrop-filter: none;
  --fog-alpha: .82;
}
html.low-perf-mode[data-theme="light"] .text-fog-scrim::before { --fog-alpha: .9; }
/* people who ask the OS for less transparency get solid ground */
@media (prefers-reduced-transparency: reduce) {
  .text-fog-scrim::before { --fog-alpha: .94; -webkit-backdrop-filter: none; backdrop-filter: none; }
}

/* Night text on the fog: pure white, with a soft dark halo for the edges */
.text-fog-scrim { color: #ffffff; text-shadow: 0 1px 14px rgba(3, 5, 10, .85); }
html[data-theme="light"] .text-fog-scrim { color: #000000; text-shadow: 0 1px 12px rgba(248, 250, 252, .95), 0 0 2px rgba(248, 250, 252, .8); }

/* The sign-in and sign-up overview panels: every line pure white by night
   (sign-up used the dimmed body token), accent words in the text-safe red. */
.auth-info.text-fog-scrim .info-title,
.auth-info.text-fog-scrim .info-sub,
.auth-info.text-fog-scrim .info-body,
.auth-info.text-fog-scrim .info-feats li,
.auth-info.text-fog-scrim .info-flow,
.auth-info.text-fog-scrim .info-flow b,
.auth-info.text-fog-scrim .info-badge { color: #ffffff; }
.auth-info.text-fog-scrim .info-badge { border-color: transparent; font-weight: 700; }
.auth-info.text-fog-scrim .info-title .info-portal { color: var(--accent-bright); }
.auth-info.text-fog-scrim .info-feats li::before { background: var(--accent); }
html[data-theme="light"] .auth-info.text-fog-scrim .info-title,
html[data-theme="light"] .auth-info.text-fog-scrim .info-sub,
html[data-theme="light"] .auth-info.text-fog-scrim .info-body,
html[data-theme="light"] .auth-info.text-fog-scrim .info-feats li,
html[data-theme="light"] .auth-info.text-fog-scrim .info-flow,
html[data-theme="light"] .auth-info.text-fog-scrim .info-flow b,
html[data-theme="light"] .auth-info.text-fog-scrim .info-badge { color: #000000; }
html[data-theme="light"] .auth-info.text-fog-scrim .info-title .info-portal { color: #2563eb; }
```

### 5.2 Light-theme token floor: `assets/css/theme-contrast.css`

Jet Black type, Cobalt Blue accent, AA status colours, placeholders and the light overview-panel type. (90 lines)

```css
/* ========================================================
   HASTRA LIGHT-MODE CONTRAST FLOOR
   Loaded after core/theme.css on every themed page. Everything here is
   scoped to html[data-theme="light"], so the dark theme is untouched.

   Measured with the in-page audit in tests/tools/contrast-audit.js, which
   computes the WCAG ratio of every visible text node. Targets: 4.5:1 for
   body text, 3:1 for large text (AA); most values below clear 7:1 (AAA).

   Boxless by design (see DEPLOY/feedback): nothing here adds a border,
   fill or shadow around content. The hero panels get a feathered wash that
   fades to nothing at its edges instead of a bordered pill.
   ======================================================== */

/* ── Tokens ────────────────────────────────────────────────────────────
   The status and accent colours are used as text across the portals. On
   white the light-theme originals were ~3:1 (green #16a34a, amber #d97706,
   cyan #0891b2, orange #ea580c) and the accent 4.4:1 on tinted fills. These
   are the same hues, darkened until they clear 4.5:1 on white and on the
   pale tinted backgrounds the portals use. */
html[data-theme="light"] {
  /* Strict light tokens: Jet Black type, Cobalt Blue #2563eb accent.
     #2563eb is 5.2:1 on white and 4.6:1 on the palest tinted fill, so it is
     AA as text everywhere; #000000 type is 21:1 (AAA). */
  --text: #000000;
  --text-primary: #000000;
  --lx-text: #000000;
  --color-ice-highlight: #000000;
  --accent: #2563eb;
  --accent-dim: #1d4ed8;
  --accent-rgb: 37,99,235;
  --accent-glow: rgba(37,99,235,0.22);
  --accent-soft: rgba(37,99,235,0.08);
  --border: rgba(37,99,235,0.22);
  --accent-bright: #2563eb;
  --green:  #166534;          /* 7.1:1 */
  --yellow: #92400e;          /* 7.1:1 */
  --cyan:   #0e7490;          /* 5.4:1 */
  --orange: #c2410c;          /* 5.2:1 */
  --accent-primary: #2563eb;  /* Netflix-style portals and Labs */
  --lx-accent: #2563eb;
  --text-muted: #556078;      /* was #64748b, 4.4:1 on the pale card fills */
}

/* ── Form placeholders ─────────────────────────────────────────────────
   register.php hard-codes the dark theme's pale placeholder, which is
   about 1.4:1 on the light field. */
html[data-theme="light"] .card input::placeholder,
html[data-theme="light"] .card textarea::placeholder,
html[data-theme="light"] input::placeholder,
html[data-theme="light"] textarea::placeholder {
  color: #556078 !important;   /* 5.6:1 on #eef2ff, 6.4:1 on white */
  opacity: 1;
}

/* ── Sign-in / sign-up overview panel (left column) ────────────────────
   Jet Black type in daylight. The feathered fog behind it (white by day,
   black by night) lives in assets/css/theme-fog-boxless.css as
   .text-fog-scrim, so both themes share one implementation. */
html[data-theme="light"] .auth-info {
  color: #000000;
  text-shadow: 0 1px 12px rgba(248, 250, 252, .95), 0 0 2px rgba(248, 250, 252, .8);
}
html[data-theme="light"] .auth-info .info-badge {
  color: #000000;
  border-color: transparent;       /* no outlined pill */
  font-weight: 700;
}
html[data-theme="light"] .auth-info .info-title,
html[data-theme="light"] .auth-info .info-sub,
html[data-theme="light"] .auth-info .info-feats li,
html[data-theme="light"] .auth-info .info-flow b {
  color: #000000;
}
html[data-theme="light"] .auth-info .info-sub { font-weight: 700; }
html[data-theme="light"] .auth-info .info-feats li { font-weight: 600; }
html[data-theme="light"] .auth-info .info-title .info-portal { color: #2563eb; }
html[data-theme="light"] .auth-info .info-body,
html[data-theme="light"] .auth-info .info-flow {
  color: #000000;
  font-weight: 500;
}

/* ── Sign-up card ──────────────────────────────────────────────────────
   The glass card is the one panel Hastra keeps. In light mode it was 72%
   white over the scene, so labels and helper text competed with the
   background. Keep the glass look, raise the fill. */
html[data-theme="light"] .reg-stage .card {
  background: rgba(255, 255, 255, .94) !important;
}
```

### 5.3 3D camera rig and scroll mapping: `landing-pages/hastra.html` §8 and §9

The Catmull-Rom spline camera (the brief's `initSplinePath()` and scroll-linked `animate()` input). Six waypoints, two curves, aspect fitting, intro dolly, parallax, and the scroll-to-chapter anchors. (72 lines)

```js
/* ================================================= 8 · the camera rig */
const CAM = [
  { p: [  0.0, 4.05, 13.6 ], t: [  0.0, 6.60, -18.0 ], fov: 36 },  /* 0 hero        */
  { p: [ -5.6, 2.35, 11.6 ], t: [  1.2, 5.60, -14.0 ], fov: 48 },  /* 1 architecture */
  { p: [  1.2, 3.60,  2.2 ], t: [ -0.6, 7.50, -22.0 ], fov: 40 },  /* 2 gardens     */
  { p: [  5.2, 2.10, -3.4 ], t: [ -2.6, 7.00, -20.0 ], fov: 46 },  /* 3 craft       */
  { p: [  0.0, 7.60, -16.0 ], t: [  0.0, 13.0, -40.0 ], fov: 42 }, /* 4 afterlight  */
  { p: [  0.0, 10.5, -20.0 ], t: [  0.0, 3.00, -34.0 ], fov: 46 }  /* 5 footer      */
];
const RIG = { prog: 0, smooth: 0, mx: 0, my: 0, tmx: 0, tmy: 0, intro: 0, focus: -1, focusAmt: 0 };
let curveP, curveT, tmpCam;

function buildRig() {
  curveP = new THREE.CatmullRomCurve3(CAM.map(c => new THREE.Vector3(c.p[0], c.p[1], c.p[2])), false, 'catmullrom', .42);
  curveT = new THREE.CatmullRomCurve3(CAM.map(c => new THREE.Vector3(c.t[0], c.t[1], c.t[2])), false, 'catmullrom', .42);
  tmpCam = new THREE.PerspectiveCamera(CAM[0].fov, vpW() / vpH(), .35, 220);
  camera.layers.enable(1); camera.layers.enable(2);
}
const _p = new THREE.Vector3(), _t = new THREE.Vector3(), _d = new THREE.Vector3();
/* Every waypoint is composed for a wide frame. On a tall one the same
   numbers crop the gate in half, so the rig steps back along its own view
   axis and opens up a little instead of letting the sides fall away. */
function aspectFix() { return clamp((1.62 - vpW() / vpH()) / 1.05, 0, 1); }
function fitAspect(p, t, fov) {
  const nf = aspectFix();
  if (nf <= 0) return fov;
  _d.subVectors(p, t).normalize();
  p.addScaledVector(_d, nf * 8.2);
  p.y += nf * 1.1;
  return fov * (1 + nf * .40);
}
function applyCamera() {
  const N = CAM.length - 1;
  const u = clamp(RIG.smooth / N, 0, 1);
  curveP.getPoint(u, _p); curveT.getPoint(u, _t);
  const i = clamp(Math.floor(RIG.smooth), 0, N - 1), f = clamp(RIG.smooth - i, 0, 1);
  let fov = lerp(CAM[i].fov, CAM[i + 1].fov, f);

  fov = fitAspect(_p, _t, fov);

  /* the opening dolly: a long lens easing in from further back */
  const io = 1 - RIG.intro;
  _p.z += io * 5.6; _p.y += io * 0.65; fov += io * 8;

  /* parallax — a hand-held drift, never enough to break the frame */
  const par = 1 - smooth(0, 1.6, RIG.smooth) * .55;
  _p.x += RIG.mx * 0.62 * par; _p.y += RIG.my * 0.34 * par;
  _t.x -= RIG.mx * 0.20 * par; _t.y -= RIG.my * 0.12 * par;

  camera.position.copy(_p);
  camera.lookAt(_t);
  if (Math.abs(camera.fov - fov) > 1e-4) { camera.fov = fov; camera.updateProjectionMatrix(); }
}

/* ============================================== 9 · scroll ↔ chapters */
const SECS = [].slice.call(document.querySelectorAll('[data-cam]'));
let anchors = [], maxScroll = 1, activeSec = 0;
function measure() {
  maxScroll = Math.max(1, document.documentElement.scrollHeight - vpH());
  anchors = SECS.map((el, i) => {
    if (i === 0) return 0;
    if (i === SECS.length - 1) return maxScroll;
    return clamp(el.offsetTop + el.offsetHeight * .5 - vpH() * .5, 0, maxScroll);
  });
  for (let i = 1; i < anchors.length; i++) anchors[i] = Math.max(anchors[i], anchors[i - 1] + 1);
}
function progressFor(y) {
  if (y <= anchors[0]) return 0;
  for (let i = 0; i < anchors.length - 1; i++)
    if (y <= anchors[i + 1]) return i + (y - anchors[i]) / (anchors[i + 1] - anchors[i]);
  return anchors.length - 1;
}
```

### 5.4 Scene host and flight API: `assets/js/kage-scene.js`

Hosts the temple render behind any page and exposes `window.HastraAtmosphere.flyTo(u, { from, duration, instant })` (the brief's `flyToProgress()`). (101 lines)

```js
/* ═══════════════════════════════════════════════════════════════════════
   The shared scene: the landing page's full 3-D world, hung behind every
   portal, dashboard and auth page as #hastra-kage-scene.

   It is the same document the landing page shows (landing-pages/hastra.html)
   loaded with ?scene=bg, which keeps the render and drops everything else:
   no copy, no preloader, no wordmark, no card windows. The frame is
   same-origin, so this script drives it directly:

     camera   window.HastraAtmosphere (flyTo / setScroll / u) is kept as the
              contract view-director.js already speaks, and forwards to the
              frame's rig. u is a waypoint index 0…5 on the engine's curve.
     pointer  the frame is pointer-transparent, so the hand-held drift is fed
              from this page's pointer.
     theme    assets/js/landing-host.js lays daylight over it exactly as it
              does on the landing page: the moon becomes the sun, the night a
              golden afternoon, the lanterns go cold.

   Layering. The frame is fixed at z-index 0 and sits outside <body>, as
   the root's first child; <body> is lifted to its own stacking layer at
   z-index 1. A z-index 0 layer inside <body> would paint over every block
   that is not itself positioned, and most of Hastra's pages have those.

   Cost. The world is built in the frame's own time slices after this page
   has loaded, so the page's first paint and first input are never behind
   it; the three.js runtime and the document come from the HTTP cache after
   the first visit. The engine's governor drops to half the leaves and
   embers and 0.8x pixels under 50 fps, and parks the loop entirely under
   prefers-reduced-motion once the frame is composed.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__hastraKage || window.top !== window.self) return;
  window.__hastraKage = true;
  const b = document.body;
  if (!b || b.hasAttribute('data-atmosphere-off') || b.hasAttribute('data-atmosphere-scene-off')) return;

  const me = document.currentScript;
  const base = me && me.src ? me.src.replace(/assets\/js\/kage-scene\.js.*$/, '') : '/';
  /* this script's own ?v= (theme.js appends it) busts hastra.html's cache too:
     the iframe has no other signal to re-fetch it after a deploy */
  let v = '';
  try { v = new URL(me.src, location.href).searchParams.get('v') || ''; } catch (e) { /* ignore */ }
  const root = document.documentElement;

  /* ------------------------------------------ the camera contract */
  let bg = null, target = 0, scroll = 0, pending = null;
  const A = window.HastraAtmosphere = {
    flyTo(u, o) {
      target = u;
      if (bg) bg.flyTo(u, o); else pending = [u, o];
    },
    setScroll(v) { scroll = v; if (bg) bg.setScroll(v); },
    get u() { return bg ? bg.u : target + scroll; },
    get ready() { return !!bg; }
  };

  /* ------------------------------------------ the frame */
  const frame = document.createElement('iframe');
  frame.id = 'hastra-kage-scene';
  frame.title = '';
  frame.setAttribute('aria-hidden', 'true');
  frame.setAttribute('tabindex', '-1');
  frame.setAttribute('loading', 'eager');
  root.classList.add('hastra-kage', 'hastra-atmos');
  root.insertBefore(frame, b);
  if (window.HastraSceneHost) HastraSceneHost.attach(frame, null, { skyOnly: root.classList.contains('nf-portal') });

  addEventListener('message', e => {
    if (e.source !== frame.contentWindow || !e.data) return;
    if (e.data.hastra === 'scene-ready') {
      bg = frame.contentWindow.__hastraBg;
      if (!bg) return;
      if (pending) { bg.flyTo(pending[0], pending[1]); pending = null; }
      else bg.flyTo(target, { instant: true });
      bg.setScroll(scroll);
      frame.classList.add('is-live');
      document.dispatchEvent(new CustomEvent('hastra:atmosphere-ready'));
    } else if (e.data.hastra === 'scene-fallback') {
      /* no WebGL: the flat sky in CSS stands in */
      frame.remove();
      root.classList.remove('hastra-kage', 'hastra-atmos');
      root.classList.add('hastra-atmos-static');
    }
  });

  /* the pointer drives the rig's drift; the frame never sees it itself */
  if (!matchMedia('(hover: none)').matches) {
    let nx = 0, ny = 0, queued = false;
    addEventListener('pointermove', e => {
      nx = (e.clientX / innerWidth) * 2 - 1; ny = -((e.clientY / innerHeight) * 2 - 1);
      if (queued) return; queued = true;
      requestAnimationFrame(() => { queued = false; if (bg) bg.pointer(nx, ny); });
    }, { passive: true });
  }

  /* build after this page has painted and settled */
  const go = () => { frame.src = base + 'landing-pages/hastra.html?scene=bg' + (v ? '&v=' + encodeURIComponent(v) : ''); };
  const later = () => (window.requestIdleCallback ? requestIdleCallback(go, { timeout: 600 }) : setTimeout(go, 120));
  if (document.readyState === 'complete') later(); else addEventListener('load', later, { once: true });
})();
```

### 5.5 Page-to-page flight director: `assets/js/view-director.js`

Maps each page to a waypoint, flies along the spline from where the last page stopped, and runs the reveal animations. (122 lines)

```js
/* ═══════════════════════════════════════════════════════════════════════
   View director: ties the atmosphere's camera to where you are in Hastra.

   Every page belongs to one waypoint on the shared scene's rig
   (assets/js/kage-scene.js, the landing world's own camera curve):
   dashboards at the gate, work queues on the approach, the ledger at the
   court, security up at the hall, settings out on the rise, and sign-in
   face on to the hall, where the login hand rises. Crossing between sections is a flight: the last
   page leaves its position on the spline in sessionStorage, and the next
   page's rig starts there and eases along the Catmull-Rom curve to its own
   waypoint. Inside a page, scrolling walks a short way further down the
   same curve.

   It also runs the landing engine's reveal grammar. [data-rv="up"] elements rise in as
   they enter view, and section headers are split into word masks that
   arrive at a reading pace (72 ms apart), with the original text kept as
   the heading's accessible label. Only plain-text headers are split: a
   header holding a badge, icon or link is revealed whole, never rewritten.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.__hastraDirector) return;
  window.__hastraDirector = true;
  const REDUCE = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const KEY = 'astra_cam_u';

  /* page → waypoint, matched on the final path segment */
  const MAP = [
    [/^(login|otp|forgot|forgot_otp|reset|register|verify_register|set_password|signin|signup|verify|verify-email|forgot-password|reset-code|reset-password|set-password)$/, 0],
    /* role homes (/workspace/admin/ …) and the workspace index sit at the gate */
    [/^(workspace|admin|client|sysadmin|employee|user)$/, 0],   /* the hall, face on: the hand rises out of it */
    [/security|scan[_-]center|logs$/, 3],
    [/profile|roles|migrate|docs?$|doc[_-]|settings/, 4],
    [/billing|delivery|dossier|terminal|signoff|ledger|audit/, 2],
    [/task|team|requirement|project|testing|deploy|directory|employee$|create[_-]employee|attendance|leave/, 1],
    [/portal|^index$|^$/, 0]
  ];
  function pageWaypoint() {
    const seg = location.pathname.toLowerCase().split('/').filter(Boolean);
    const page = (seg[seg.length - 1] || '').replace(/\.php$/, '');
    for (const [re, u] of MAP) if (re.test(page)) return u;
    return 0;
  }

  /* ------------------------------------------------------------ the flight */
  function fly() {
    const A = window.HastraAtmosphere; if (!A) return;
    const to = pageWaypoint();
    let from = NaN;
    try { from = parseFloat(sessionStorage.getItem(KEY)); } catch (e) { /* private mode */ }
    if (isFinite(from) && Math.abs(from - to) > .01 && !REDUCE) A.flyTo(to, { from, duration: 1.5 + Math.min(Math.abs(from - to), 3) * .25 });
    else A.flyTo(to, { instant: true });
    const save = () => { try { sessionStorage.setItem(KEY, String(A.u)); } catch (e) { /* ignore */ } };
    addEventListener('pagehide', save);
    /* theme.js holds a link for 160 ms while it fades; save at the click */
    document.addEventListener('click', e => { if (e.target.closest && e.target.closest('a[href]')) save(); }, true);
    document.addEventListener('submit', save, true);

    let ticking = false;
    const onScroll = () => {
      if (ticking) return; ticking = true;
      requestAnimationFrame(() => {
        ticking = false;
        const max = document.documentElement.scrollHeight - innerHeight;
        A.setScroll(max > 40 ? (scrollY / max) * .6 : 0);
      });
    };
    addEventListener('scroll', onScroll, { passive: true }); onScroll();
  }

  /* ------------------------------------------------------------ reveals */
  const HEADERS = '.main h1, .main h2, .sc-main h1, .sc-main h2, .page-header h1, .section-header h2, .section-header h3, .auth-card h1, .card-title';

  function splitWords(el) {
    if (el.dataset.wordReady) return;
    /* plain text only: never rewrite a header that carries markup */
    for (const n of el.childNodes) if (n.nodeType === 1) return false;
    const phrase = el.textContent.replace(/\s+/g, ' ').trim();
    if (!phrase || phrase.length > 140) return false;
    el.dataset.wordReady = 'true';
    el.classList.add('word-reveal');
    el.setAttribute('aria-label', phrase);
    el.textContent = '';
    phrase.split(' ').forEach((word, i) => {
      if (i) el.appendChild(document.createTextNode(' '));
      const mask = document.createElement('span'), inner = document.createElement('span');
      mask.className = 'word-mask'; mask.setAttribute('aria-hidden', 'true');
      inner.className = 'word'; inner.textContent = word;
      inner.style.setProperty('--word-delay', (i * 72) + 'ms');
      mask.appendChild(inner); el.appendChild(mask);
    });
    return true;
  }

  function reveals() {
    if (REDUCE) return;
    document.querySelectorAll(HEADERS).forEach(h => {
      if (h.closest('[data-rv-off]') || h.hasAttribute('data-rv')) return;
      splitWords(h);
      h.setAttribute('data-rv', 'up');
    });
    const items = Array.from(document.querySelectorAll('[data-rv]'));
    /* siblings stagger 85 ms apart, as in the engine's wireReveals */
    const groups = new Map();
    items.forEach(el => { const k = el.parentElement, a = groups.get(k) || []; a.push(el); groups.set(k, a); });
    groups.forEach(a => a.forEach((el, i) => { el.dataset.rvd = i * 85; }));
    const io = new IntersectionObserver(es => es.forEach(e => {
      if (!e.isIntersecting) return;
      io.unobserve(e.target);
      setTimeout(() => e.target.classList.add('rv-in'), +e.target.dataset.rvd || 0);
    }), { rootMargin: '0px 0px -8% 0px', threshold: .04 });
    items.forEach(el => io.observe(el));
    document.documentElement.classList.add('hastra-rv');
  }

  function init() {
    reveals();
    if (window.HastraAtmosphere) fly();                 /* the scene queues the flight until it is up */
    else document.addEventListener('hastra:atmosphere-ready', fly, { once: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
```

### 5.6 Theme bridge for the 3D world: `assets/js/landing-host.js`

The brief's `setThemeMode()`: night is the stock world, day records and retints materials, lights and sky, then restores them. (305 lines)

```js
/* ═══════════════════════════════════════════════════════════════════════
   Landing host: the landing page's side of ThreeUI's LandingPageFrame.

   landing-pages/hastra.html is Hastra's own document, forked from the ThreeUI
   landing engine: its WebGL world, camera rig, cursor wisps and cloth are
   the packaged code, its content is Hastra's. This script reaches into it
   after load through the same two seams ThreeUI's frame uses:

     1. the typography recipe: one stylesheet appended last to the frame's
        <head>, restating the page's selectors with the configured values
        (Onest 400 / 300, 46 / 17, -0.012em).

     2. the scene API the document publishes: window.__hastraScene, with
        { WORLD, WORD, POST, renderer, scene }. Daylight is written through
        it: the blood moon becomes the sun, the night sky a day sky, the fog
        a morning haze, the lanterns go cold and the key and rim lights come
        up. Every value it touches is recorded first, so night is restored
        to exactly what the document built.

   The document is authored light-on-dark, so daylight also appends a skin
   stylesheet that inverts its ink, and mirrors the theme onto the frame's
   root so the document's own light rules and baked textures follow.
   ═══════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ------------------------------------------ 1 · the typography recipe */
  const ONEST = "'Onest', system-ui, -apple-system, 'Helvetica Neue', sans-serif";
  const TYPE = { heading: ONEST, body: ONEST, headingWeight: '400', bodyWeight: '300', primary: '#e0231c',
    headingSize: 46, bodySize: 17, headingLetterSpacing: -0.012 };
  const px = v => Number(v.toFixed(3)) + 'px';
  const RECIPE_CSS = `
:root {
  --vermilion: ${TYPE.primary};
  --ember: #ff5a3c;
}
body { font-family: ${TYPE.body}; }
body, .body, .body-lg, .num { font-weight: ${TYPE.bodyWeight}; }
h1:not(.jp), h2:not(.jp), h3:not(.jp), .display:not(.jp) {
  font-family: ${TYPE.heading};
  font-weight: ${TYPE.headingWeight};
}
.display { letter-spacing: ${TYPE.headingLetterSpacing}em; }
.h-hero { font-size: clamp(26px, 3.05vw, ${px(TYPE.headingSize)}); }
.h-sec { font-size: clamp(30px, 4vw, ${px((TYPE.headingSize * 60) / 46)}); }
.body-lg { font-size: clamp(14px, 1.02vw, ${px(TYPE.bodySize)}); }
.body { font-size: ${px(Math.max(11, TYPE.bodySize - 3))}; }
`;

  /* -------------------------------------------- the daylight skin (DOM) */
  const DAY_CSS = `
html[data-hastra-day] {
  --ink:#d3dfe5; --ink-2:#c6d4db; --bone:#000000; --bone-dim:#34444d; --muted:#5a6a72;
  --line:rgba(18,28,36,.16); --line-soft:rgba(18,28,36,.09);
}
html[data-hastra-day] body { background:#f6ecd2; color:#000000; }
html[data-hastra-day] #gl { background:#f6ecd2; }
html[data-hastra-day] #pre { background:#fbf3de; }
html[data-hastra-day] .pre-bar { background:rgba(18,28,36,.14); }
html[data-hastra-day] #vignette { background:radial-gradient(125% 95% at 50% 42%, transparent 52%, rgba(120,84,30,.14) 100%); }
html[data-hastra-day] #grain { opacity:.03; }
html[data-hastra-day] .eyebrow, html[data-hastra-day] .chip b { text-shadow:0 1px 14px rgba(255,251,238,.85); }
html[data-hastra-day] .display { text-shadow:0 2px 30px rgba(255,251,238,.7); }
html[data-hastra-day] .body-lg { color:#000000; text-shadow:0 1px 18px rgba(255,251,238,.9); }
html[data-hastra-day] .body { color:#000000; text-shadow:0 1px 16px rgba(255,251,238,.9); }
html[data-hastra-day] .gate-copy .lead { color:#000000; text-shadow:0 1px 18px rgba(255,251,238,.9); }
html[data-hastra-day] .hero-sub { text-shadow:0 1px 22px rgba(255,251,238,.95); }
html[data-hastra-day] .chip p, html[data-hastra-day] .les p, html[data-hastra-day] .les .t,
html[data-hastra-day] .foot li a, html[data-hastra-day] .foot-brand p { color:#3d4c54; text-shadow:none; }
html[data-hastra-day] .hero::before { background:linear-gradient(rgba(255,244,214,.70), rgba(255,244,214,.30) 46%, transparent); }
html[data-hastra-day] .sec::before { background:radial-gradient(110% 62% at 30% 50%, rgba(255,248,230,.86), rgba(255,248,230,.60) 42%, rgba(255,248,230,.16) 74%, rgba(255,248,230,0)); }
html[data-hastra-day] #security::before { background:radial-gradient(108% 64% at 50% 50%, rgba(255,248,230,.66), rgba(255,248,230,.40) 50%, rgba(255,248,230,0)); }
html[data-hastra-day] #ledger::before { background:radial-gradient(82% 58% at 50% 46%, rgba(255,248,230,.24), rgba(255,248,230,.62) 56%, rgba(255,248,230,.88) 82%, rgba(255,248,230,0)); }
html[data-hastra-day] .foot::before { background:linear-gradient(rgba(255,248,230,.55), rgba(255,248,230,.94) 40%, rgba(255,248,230,.98)); }
html[data-hastra-day] .nav::before { background:rgba(255,248,230,.66); }
html[data-hastra-day] .hero-side .v { color:rgba(18,28,36,.55); }
html[data-hastra-day] svg path[stroke="#dfe7e0"] { stroke:#15202a; }
html[data-hastra-day] .peek-play svg path[fill="#dfe7e0"] { fill:#f2f5f3; }
html[data-hastra-day] .peek-cap b { color:#121c24; }
html[data-hastra-day] .nav-actions .nav-signin:hover { background:rgba(18,28,36,.08); }
html[data-hastra-day] .cta:hover { color:#eef3f5; }
html[data-hastra-day] .cta:hover svg path { stroke:#eef3f5; }
html[data-hastra-day] .arrowlink:hover .ar svg path { stroke:#eef3f5; }
html[data-hastra-day] .rail i { background:rgba(18,28,36,.26); }
html[data-hastra-day] .cur-dot { border-color:rgba(18,28,36,.42); }
html[data-hastra-day] .cur-dot.act { background:rgba(18,28,36,.06); border-color:rgba(18,28,36,.6); }
html[data-hastra-day] body[data-layout-curriculum="b"] .les { background:rgba(255,251,238,.84); }
html[data-hastra-day] .fg-el > img { filter:saturate(1) brightness(1.06); }
@media (max-width:820px){ html[data-hastra-day] .nav-links { background:rgba(255,249,234,.98); } }
`;

  function injectStyle(doc, id, css) {
    let s = doc.getElementById(id);
    if (!s) { s = doc.createElement('style'); s.id = id; }
    if (s.textContent !== css) s.textContent = css;
    doc.head.appendChild(s);               /* last in <head>: wins at equal specificity */
  }

  /* ------------------------------------------ 2 · the scene, by daylight
     One attach() per frame: the landing page's own, and the background
     scene assets/js/kage-scene.js hangs behind every other page. Each keeps
     its own record of what daylight changed. */
  /* opts.skyOnly (the signed-in portals): daylight changes the sky, the sun
     and the background only; the temple keeps its night palette */
  function attach(frame, onLoad, opts) {
  opts = opts || {};
  const records = [];
  let dayBuilt = null, dayOn = false;

  function rec(obj, key) {
    if (!obj || records.some(r => r.obj === obj && r.key === key)) return;
    const v = obj[key];
    records.push({ obj, key, val: v && typeof v.clone === 'function' && !v.isTexture ? v.clone() : v });
  }
  function put(obj, key, v) { rec(obj, key); obj[key] = v; }
  function tint(obj, r, g, b) { if (!obj || !obj.color) return; rec(obj, 'color'); obj.color = obj.color.clone().setRGB(r, g, b); }

  function restore() {
    for (let i = records.length - 1; i >= 0; i--) {
      const r = records[i];
      r.obj[r.key] = r.val && typeof r.val.clone === 'function' && !r.val.isTexture ? r.val.clone() : r.val;
      if (r.obj.isMaterial) r.obj.needsUpdate = true;
    }
    records.length = 0;
  }

  function canvasTex(win, draw, w, h) {
    const T = win.THREE, c = win.document.createElement('canvas');
    c.width = w; c.height = h; draw(c.getContext('2d'), w, h);
    const t = new T.CanvasTexture(c); t.encoding = T.sRGBEncoding; t.needsUpdate = true;
    return t;
  }

  function buildDay(win) {
    const sky = canvasTex(win, (x, W, H) => {
      let g = x.createLinearGradient(0, 0, 0, H);
      /* a sun-drenched afternoon: soft blue zenith, golden middle air,
         a luminous amber wash along the horizon */
      g.addColorStop(0, '#7eb4e8');
      g.addColorStop(0.45, '#fde68a');
      g.addColorStop(0.85, '#fef08a');
      g.addColorStop(1, '#fffbeb');
      x.fillStyle = g; x.fillRect(0, 0, W, H);
      /* long soft cloud banks, and the warm lift where the sun sits */
      for (let i = 0; i < 26; i++) {
        const cx = Math.random() * W, cy = H * (.12 + Math.random() * .5), r = 40 + Math.random() * 140;
        g = x.createRadialGradient(cx, cy, 0, cx, cy, r);
        g.addColorStop(0, 'rgba(255,255,255,.30)'); g.addColorStop(1, 'rgba(255,255,255,0)');
        x.save(); x.translate(cx, cy); x.scale(2.6, .55); x.translate(-cx, -cy);
        x.fillStyle = g; x.beginPath(); x.arc(cx, cy, r, 0, Math.PI * 2); x.fill(); x.restore();
      }
      /* the sun flare: radial-gradient(circle, rgba(255,245,180,.95) 0%,
         rgba(254,215,102,.45) 45%, transparent 75%) */
      const fr = W * .5;
      g = x.createRadialGradient(W * .75, H * .22, 0, W * .75, H * .22, fr);
      g.addColorStop(0, 'rgba(255,245,180,.95)'); g.addColorStop(.45, 'rgba(254,215,102,.45)'); g.addColorStop(.75, 'rgba(254,215,102,0)');
      x.fillStyle = g; x.fillRect(0, 0, W, H);
    }, 512, 512);
    const sun = canvasTex(win, (x, S) => {
      const g = x.createRadialGradient(S / 2, S / 2, 0, S / 2, S / 2, S / 2);
      g.addColorStop(0, 'rgba(255,255,252,1)'); g.addColorStop(.55, 'rgba(255,252,238,1)');
      g.addColorStop(.86, 'rgba(255,238,196,.95)'); g.addColorStop(1, 'rgba(255,226,170,0)');
      x.fillStyle = g; x.fillRect(0, 0, S, S);
    }, 256, 256);
    const corona = canvasTex(win, (x, S) => {
      const g = x.createRadialGradient(S / 2, S / 2, 0, S / 2, S / 2, S / 2);
      g.addColorStop(0, 'rgba(255,248,226,.95)'); g.addColorStop(.18, 'rgba(255,236,190,.42)');
      g.addColorStop(.5, 'rgba(255,226,170,.10)'); g.addColorStop(1, 'rgba(255,226,170,0)');
      x.fillStyle = g; x.fillRect(0, 0, S, S);
    }, 256, 256);
    return { sky, sun, corona, ridge: new Map() };
  }

  /* the black ridge silhouettes become far hills in haze: same alpha, new ink */
  function hazeRidge(win, mesh, ink) {
    const src = mesh.material.map && mesh.material.map.image;
    if (!src) return null;
    if (dayBuilt.ridge.has(mesh)) return dayBuilt.ridge.get(mesh);
    const t = canvasTex(win, (x, W, H) => {
      x.drawImage(src, 0, 0, W, H);
      x.globalCompositeOperation = 'source-in'; x.fillStyle = ink; x.fillRect(0, 0, W, H);
    }, src.width, src.height);
    t.wrapS = mesh.material.map.wrapS; t.repeat.copy(mesh.material.map.repeat);
    dayBuilt.ridge.set(mesh, t);
    return t;
  }

  function day(win) {
    const K = win.__hastraScene;
    if (!K || !K.WORLD || !win.THREE) return false;
    const T = win.THREE, W = K.WORLD, scene = K.scene;
    if (!dayBuilt) dayBuilt = buildDay(win);
    restore();

    if (opts.skyOnly) {
      rec(scene, 'background'); scene.background = new T.Color(0x9cc6ef);
      put(W.sky.material, 'map', dayBuilt.sky); tint(W.sky.material, 1.05, 1.05, 1.05); W.sky.material.needsUpdate = true;
      put(W.moon.material, 'map', dayBuilt.sun); tint(W.moon.material, 5.2, 4.9, 4.3); W.moon.material.needsUpdate = true;
      put(W.moonHalo.material, 'map', dayBuilt.corona); tint(W.moonHalo.material, 1.9, 1.6, 1.15); W.moonHalo.material.needsUpdate = true;
      return true;
    }

    /* air */
    rec(scene.fog, 'color'); scene.fog.color = new T.Color(0xf3e3b0);
    put(scene.fog, 'density', 0.0082);
    rec(scene, 'background'); scene.background = new T.Color(0xf3e3b0);
    /* sky and the body in it */
    put(W.sky.material, 'map', dayBuilt.sky); tint(W.sky.material, 1.12, 1.12, 1.12); W.sky.material.needsUpdate = true;
    put(W.moon.material, 'map', dayBuilt.sun); tint(W.moon.material, 5.2, 4.9, 4.3); W.moon.material.needsUpdate = true;
    put(W.moonHalo.material, 'map', dayBuilt.corona); tint(W.moonHalo.material, 1.9, 1.6, 1.15); W.moonHalo.material.needsUpdate = true;
    /* the ridges and every other flat silhouette plane */
    scene.traverse(o => {
      if (!o.isMesh || !o.material || !o.material.isMeshBasicMaterial || o === W.sky || o === W.moon) return;
      const g = o.geometry && o.geometry.parameters;
      if (g && (g.width === 300 || g.width === 210) && o.renderOrder === 1) {
        const t = hazeRidge(win, o, g.width === 300 ? '#c9b98a' : '#8f8a64');
        if (t) { put(o.material, 'map', t); tint(o.material, 1, 1, 1); o.material.needsUpdate = true; }
      }
    });
    /* lights: sun where the moon hung, the sky fill up, every lamp cold */
    scene.traverse(o => {
      if (!o.isLight) return;
      if (o.isHemisphereLight) { tint(o, 1, .95, .82); rec(o, 'groundColor'); o.groundColor = new T.Color(0x6b5a3a); put(o, 'intensity', 1.15); }
      /* the key: warm sunlight, 0xfff2cc at 1.6, across the roofs and stairs */
      else if (o === W.key) { rec(o, 'color'); o.color = new T.Color(0xfff2cc); put(o, 'intensity', 1.6); }
      else if (o.isDirectionalLight) { tint(o, 1, .9, .7); put(o, 'intensity', 1.2); }
      else if (o.isPointLight) {
        /* point intensities are rewritten every frame, so the lamps are
           dimmed through their colour instead */
        rec(o, 'color'); o.color = o.color.clone().multiplyScalar(.14);
      }
    });
    /* the lit paper and the lantern panes stop glowing in daylight */
    if (W.paper) tint(W.paper, .86, .8, .7);
    if (W.lanternPane) tint(W.lanternPane, .32, .22, .14);
    if (W.hallHalo) tint(W.hallHalo.material, .12, .12, .12);
    (W.lanternGlows || []).forEach(g => tint(g.material, .15, .15, .15));
    /* the near garden: the cut-outs are painted for night, lift them */
    (W.fg || []).forEach(m => tint(m.material, 2.3, 2.3, 2.15));
    if (W.leaves) put(W.leaves.mesh.material, 'emissiveIntensity', .22);
    if (W.rain) put(W.rain, 'visible', false);
    /* the wordmark swaps to its baked titanium-navy ink inside the document */
    /* embers become drifting pollen */
    if (W.embers) {
      const m = W.embers.material;
      put(m, 'fragmentShader', m.fragmentShader.replace('vec3(1.6,0.78,0.42)', 'vec3(1.0,0.97,0.86)').replace('t.a*vA*0.75', 't.a*vA*0.42'));
      m.needsUpdate = true;
    }
    /* grade */
    const C = K.POST && K.POST.comp && K.POST.comp.uniforms;
    if (C) {
      rec(C.uExp, 'value'); C.uExp.value = .9;
      rec(C.uBloom, 'value'); C.uBloom.value = .16;
      rec(C.uVig, 'value'); C.uVig.value = .42;
      rec(C.uGrain, 'value'); C.uGrain.value = .012;
    }
    if (W.key && W.key.shadow) W.key.shadow.needsUpdate = true;
    return true;
  }

  function night() { restore(); }

  /* ---------------------------------------------------------- wiring */
  const wantDay = () => document.documentElement.getAttribute('data-theme') === 'light';
  let poll = 0;

  function sync() {
    let win, doc;
    try { win = frame.contentWindow; doc = frame.contentDocument; } catch (e) { return; }
    if (!doc || !doc.head) return;
    const d = wantDay();
    injectStyle(doc, 'threeui-page-typography', RECIPE_CSS);
    injectStyle(doc, 'hastra-landing-daylight', DAY_CSS);
    /* mirrored three ways: the skin keys on data-hastra-day, the document's
       own light rules on data-theme="light" and .light */
    doc.documentElement.toggleAttribute('data-hastra-day', d);
    doc.documentElement.setAttribute('data-theme', d ? 'light' : 'dark');
    doc.documentElement.classList.toggle('light', d);
    clearInterval(poll);
    /* the scene publishes itself only once its eleven build jobs are done */
    const go = () => {
      const K = win.__hastraScene;
      if (!K) return false;
      if (K.fallback) return true;              /* no WebGL: the CSS skin is all there is */
      if (d && !dayOn) { dayOn = day(win); }
      else if (!d && dayOn) { night(); dayOn = false; }
      if (K.kick) K.kick();
      return true;
    };
    if (!go()) poll = setInterval(() => { if (go()) clearInterval(poll); }, 150);
  }

  frame.addEventListener('load', () => {
    records.length = 0; dayBuilt = null; dayOn = false;   /* a fresh document */
    sync();
    if (onLoad) onLoad();
  });
  new MutationObserver(sync).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  return { sync, get day() { return dayOn; } };
  }

  window.HastraSceneHost = { attach };
  const landing = document.getElementById('landingFrame');
  if (landing) window.__landingHost = attach(landing, () => { landing.parentElement.dataset.state = 'ready'; });
})();
```

### 5.7 Onboarding and RBAC barrier: `core/auth_check.php`

The brief's `evaluateUserAccess()`, `renderOnboardingScreen()` and `directAccessRouter()`: the 11-role catalog, the organization lock and the lab landing map. (166 lines)

```php
<?php
// core/auth_check.php
// Mandatory profile-completion barrier. Called once per authenticated page
// (see render_sidebar() in core/dock.php, invoked from each portal's
// _nav.php) — renders an unclosable modal over the page for any signed-in
// user who hasn't picked a gender yet, blocking interaction with the
// dashboard underneath until they do. api/complete_profile.php is the
// endpoint the modal's JS posts to.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) { http_response_code(404); exit(); }

// Self-described roles for people who are not part of an organization. This is
// a profile label only: it never grants access (authorization comes from
// users.role, which only an administrator or the sign-up flow sets).
const ASTRA_FOCUS_GROUPS = [
    'Security operations & defense' => [
        'soc_analyst_l1'        => ['SOC Analyst (Tier 1 Triage)',          'IOC normalization and UEBA risk scoring in the SIEM lab.'],
        'soc_analyst_l2'        => ['SOC Incident Responder (Tier 2)',      'Threat hunting and multi-cloud incident timelines in the SIEM lab.'],
        'threat_intel_engineer' => ['Threat Intelligence Engineer',         'STIX 2.1, TAXII and MISP feed normalization.'],
        'pqc_cryptographer'     => ['Cryptographic / PQC Engineer',         'ML-KEM, X-Wing and ML-DSA in the cryptography lab.'],
    ],
    'Engineering & architecture' => [
        'security_engineer'     => ['DevSecOps / Cloud Security Engineer',  'Scan Center results, secure delivery pipelines and key handling.'],
        'security_architect'    => ['Enterprise Security Architect',        'Governance, audit-ledger design and encryption architecture.'],
        'penetration_tester'    => ['Penetration Tester / Red Teamer',      'Vulnerability findings, the Scan Center and the crypto lab.'],
    ],
    'Academic & research' => [
        'student_researcher'    => ['Student / Security Researcher',        'Syllabus accelerator, study planner and the labs.'],
        'faculty_instructor'    => ['Academic Faculty / Instructor',        'Syllabus mapping, pacing forecasts and lab material.'],
    ],
    'Governance & client delivery' => [
        'client_stakeholder'    => ['Client Project Stakeholder',           'Requirements, delivery tracking and milestone sign-off.'],
        'compliance_auditor'    => ['Compliance & Risk Auditor',            'The tamper-evident audit ledger and its verification.'],
    ],
];

// key => title for every role, in catalog order
function astra_focus_options(): array {
    $out = [];
    foreach (ASTRA_FOCUS_GROUPS as $roles) foreach ($roles as $key => [$title]) $out[$key] = $title;
    return $out;
}

// Where a person who has just chosen a role lands: the lab that fits it, or
// null to stay in their workspace. Fixed paths only, never user input.
function astra_focus_landing(string $key): ?string {
    $labs = [
        'student_researcher' => 'syllabus', 'faculty_instructor' => 'syllabus',
        'soc_analyst_l1' => 'siem', 'soc_analyst_l2' => 'siem', 'threat_intel_engineer' => 'siem',
        'pqc_cryptographer' => 'crypto', 'security_engineer' => 'crypto', 'security_architect' => 'crypto', 'penetration_tester' => 'crypto',
    ];
    return isset($labs[$key]) ? get_base_url() . 'labs/' . $labs[$key] : null;
}

// Is this person part of an organization (so their role is managed by its
// administrator), and what is their role called? Organizations are the
// client_org and full_org accounts, plus anyone who is a team member or the
// system administrator. Individuals and solo studios choose for themselves.
function astra_profile_role_context($conn, int $user_id): array {
    $has_type = company_schema_ready($conn) && db_column_exists($conn, 'companies', 'account_type');
    $sql = $has_type
        ? "SELECT u.role, u.client_role, c.account_type FROM users u LEFT JOIN companies c ON c.id = u.company_id WHERE u.id = ?"
        : "SELECT role, client_role, NULL AS account_type FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: ['role' => 'client', 'client_role' => null, 'account_type' => null];
    $locked = in_array($row['account_type'], ['client_org', 'full_org'], true)
           || in_array($row['role'], ['employee', 'pending_employee', 'sysadmin'], true);
    $labels = ['admin' => 'Workspace administrator', 'employee' => 'Team member', 'pending_employee' => 'Team member (pending)',
               'sysadmin' => 'System administrator'];
    $label = $row['role'] === 'client'
        ? (defined('CLIENT_ROLES') && isset(CLIENT_ROLES[$row['client_role']]) ? CLIENT_ROLES[$row['client_role']] : 'Client')
        : ($labels[$row['role']] ?? 'Member');
    return ['locked' => $locked, 'label' => $label];
}

// users.profile_focus holds that label. Created on first use so a deploy needs no manual migration.
function astra_ensure_profile_focus_column($conn): bool {
    if (db_column_exists($conn, 'users', 'profile_focus')) return true;
    try { mysqli_query($conn, "ALTER TABLE users ADD COLUMN profile_focus VARCHAR(40) NULL"); } catch (Throwable $e) { /* another request won the race */ }
    return db_column_exists($conn, 'users', 'profile_focus');
}

function astra_profile_barrier_needed($conn, $user_id) {
    $stmt = mysqli_prepare($conn, "SELECT gender, profile_updated FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row) return false;

    if ($row['gender'] !== null) {
        // Gender is already on file — the barrier is done, full stop, even if
        // profile_updated somehow wasn't flipped (e.g. a manual DB edit).
        // Self-heal that flag here so this check stays a single cheap lookup
        // instead of a permanent OR against a stale column.
        if ((int)$row['profile_updated'] !== 1) {
            $fix = mysqli_prepare($conn, "UPDATE users SET profile_updated = 1 WHERE id = ?");
            mysqli_stmt_bind_param($fix, "i", $user_id);
            mysqli_stmt_execute($fix);
        }
        return false;
    }

    return true;
}

// Renders the barrier overlay if (and only if) the signed-in user still
// needs to complete it. No-op for logged-out visitors or already-complete
// profiles, so it's safe to call unconditionally on every authenticated page.
function render_profile_barrier($conn) {
    if (!isset($_SESSION['user_id'])) return;
    if (!astra_profile_barrier_needed($conn, (int)$_SESSION['user_id'])) return;
    $csrf = generate_csrf_token();
    $role = astra_profile_role_context($conn, (int)$_SESSION['user_id']);
    if (!$role['locked']) astra_ensure_profile_focus_column($conn);
    ?>
    <div id="profileBarrierOverlay" class="profile-barrier-overlay" data-csrf="<?= htmlspecialchars($csrf) ?>" data-endpoint="<?= htmlspecialchars(get_base_url()) ?>api/complete_profile.php" data-needs-focus="<?= $role['locked'] ? '0' : '1' ?>">
      <div class="profile-barrier-modal" role="dialog" aria-modal="true" aria-labelledby="pbTitle">
        <p class="pb-eyebrow">First-time setup</p>
        <h2 id="pbTitle">One more thing before you continue</h2>
        <p class="pb-lead">Finish setting up your Hastra account. You'll only need to do this once.</p>

        <div class="pb-field">
          <span class="pb-label" id="pbGenderLabel">Identity</span>
          <div class="pb-choices" role="radiogroup" aria-labelledby="pbGenderLabel">
            <button type="button" class="pb-opt" role="radio" aria-checked="false" data-gender="male">Male</button>
            <button type="button" class="pb-opt" role="radio" aria-checked="false" data-gender="female">Female</button>
            <button type="button" class="pb-opt" role="radio" aria-checked="false" data-gender="prefer_not_to_say">Prefer not to say</button>
          </div>
        </div>

        <div class="pb-field">
          <span class="pb-label" id="pbRoleLabel">Role</span>
          <?php if ($role['locked']): ?>
          <p class="pb-locked">
            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
            <strong><?= htmlspecialchars($role['label']) ?></strong>
          </p>
          <p class="pb-note">Role locked by your organization's policy. Contact your Organization System Administrator to change your assigned role.</p>
          <?php else: ?>
          <input type="search" id="pbRoleFilter" class="pb-filter" placeholder="Search roles" aria-label="Search roles" autocomplete="off" maxlength="40">
          <div class="pb-rolelist" id="pbRoleList" role="radiogroup" aria-labelledby="pbRoleLabel">
            <?php foreach (ASTRA_FOCUS_GROUPS as $group => $roles): ?>
            <div class="pb-group">
              <div class="pb-grouphead"><?= htmlspecialchars($group) ?></div>
              <?php foreach ($roles as $value => [$title, $desc]): ?>
              <button type="button" class="pb-opt pb-role pb-roleitem" role="radio" aria-checked="false" data-focus="<?= htmlspecialchars($value) ?>">
                <span class="pb-rt"><?= htmlspecialchars($title) ?></span><span class="pb-rd"><?= htmlspecialchars($desc) ?></span>
              </button>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
            <p class="pb-none" id="pbRoleNone" hidden>No role matches that search.</p>
          </div>
          <p class="pb-note"><?= count(astra_focus_options()) ?> roles. Describes how you will use Hastra and where you land first. It does not change what you can access, and no approval is needed.</p>
          <?php endif; ?>
        </div>

        <div id="pbError" class="pb-error" role="alert"></div>
        <button type="button" id="pbSubmit" class="pb-submit" disabled>Continue</button>
      </div>
    </div>
    <link rel="stylesheet" href="<?= get_base_url() ?>assets/css/profile-barrier.css?v=<?= ASSET_VERSION ?>">
    <script src="<?= get_base_url() ?>assets/js/profile-barrier.js?v=<?= ASSET_VERSION ?>" defer></script>
    <?php
}
```

### 5.8 Onboarding client: `assets/js/profile-barrier.js`

Boxless gender and role selection, live role search, and the redirect to the matching lab. (102 lines)

```js
// ── Mandatory profile-completion barrier controller ──────────────────────
// Deliberately has no close/dismiss path — the overlay only disappears once
// the server confirms the gender was saved. No Escape handler, no backdrop
// click-to-close, unlike every other modal in this app.
(function () {
  'use strict';

  function init() {
    var overlay = document.getElementById('profileBarrierOverlay');
    if (!overlay) return;

    var csrf     = overlay.dataset.csrf;
    var endpoint = overlay.dataset.endpoint;
    var error    = document.getElementById('pbError');
    var submit   = document.getElementById('pbSubmit');
    var opts     = overlay.querySelectorAll('.pb-opt');
    var needsFocus = overlay.dataset.needsFocus === '1';   // people outside an organization pick a role
    var selected = null;      // gender
    var focus    = null;      // role label (independent people only)

    function ready() { return !!selected && (!needsFocus || !!focus); }
    function setBusy(busy) {
      opts.forEach(function (b) { b.disabled = busy; });
      submit.disabled = busy || !ready();
    }
    // a row of text choices behaves as one radio group
    function choose(btn) {
      btn.parentNode.querySelectorAll('.pb-opt').forEach(function (b) {
        b.classList.remove('selected'); b.setAttribute('aria-checked', 'false');
      });
      btn.classList.add('selected'); btn.setAttribute('aria-checked', 'true');
    }

    function showError(message) {
      error.textContent = message;
      error.classList.add('show');
    }

    opts.forEach(function (btn) {
      btn.addEventListener('click', function () {
        choose(btn);
        if (btn.dataset.gender) selected = btn.dataset.gender;
        if (btn.dataset.focus) focus = btn.dataset.focus;
        error.classList.remove('show');
        submit.disabled = !ready();
      });
    });

    // role search: hides roles (and whole groups) that do not match
    var filter = document.getElementById('pbRoleFilter');
    if (filter) {
      var items = overlay.querySelectorAll('.pb-roleitem'), groups = overlay.querySelectorAll('.pb-group'), none = document.getElementById('pbRoleNone');
      filter.addEventListener('input', function () {
        var q = filter.value.trim().toLowerCase(), shown = 0;
        items.forEach(function (it) {
          var hit = !q || it.textContent.toLowerCase().indexOf(q) !== -1 || (it.parentNode.querySelector('.pb-grouphead').textContent.toLowerCase().indexOf(q) !== -1);
          it.hidden = !hit; if (hit) shown++;
        });
        groups.forEach(function (g) { g.hidden = !g.querySelector('.pb-roleitem:not([hidden])'); });
        if (none) none.hidden = shown > 0;
      });
    }

    submit.addEventListener('click', function () {
      if (!ready()) return;
      error.classList.remove('show');
      setBusy(true);

      var body = new URLSearchParams();
      body.set('csrf_token', csrf);
      body.set('gender', selected);
      if (needsFocus) body.set('focus', focus);

      fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok) {
            overlay.classList.add('pb-done');
            if (data.redirect) setTimeout(function () { window.location.href = data.redirect; }, 320);
            else setTimeout(function () { overlay.remove(); }, 320);
          } else {
            setBusy(false);
            showError(data.message || 'Something went wrong. Please try again.');
          }
        })
        .catch(function () {
          setBusy(false);
          showError('Network error. Please try again.');
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
```

### 5.9 Onboarding endpoint: `api/complete_profile.php`

Validates and saves gender and role; refuses a role from organization members (403). (72 lines)

```php
<?php
// api/complete_profile.php
// Backend for the mandatory profile-completion barrier (core/auth_check.php).
//   POST gender=<male|female|prefer_not_to_say>&focus=<role label>&csrf_token=<session token>
//     -> { ok, message? }
//   focus is required from people outside an organization and refused from
//   organization members (core/auth_check.php: astra_profile_role_context).
// Session-scoped: a user only ever updates their own row.
include __DIR__ . '/../core/db.php';
secure_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Not signed in.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit();
}

if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Invalid request. Please refresh the page and try again.']);
    exit();
}

$gender = $_POST['gender'] ?? '';
if (!in_array($gender, ['male', 'female', 'prefer_not_to_say'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Choose one of the options.']);
    exit();
}

$user_id    = (int)$_SESSION['user_id'];

// Role label: organization members never choose (their administrator manages
// it, so a posted value is refused, not ignored); everyone else must.
$role  = astra_profile_role_context($conn, $user_id);
$focus = trim((string)($_POST['focus'] ?? ''));
if ($role['locked']) {
    if ($focus !== '') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => "Your role is managed by your organization's administrator."]);
        exit();
    }
} elseif (!isset(astra_focus_options()[$focus]) || !astra_ensure_profile_focus_column($conn)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Choose your role.']);
    exit();
}

$gender_enc = astra_db_encrypt($gender);
if ($role['locked']) {
    $upd = mysqli_prepare($conn, "UPDATE users SET gender = ?, profile_updated = 1 WHERE id = ?");
    mysqli_stmt_bind_param($upd, "si", $gender_enc, $user_id);
} else {
    $upd = mysqli_prepare($conn, "UPDATE users SET gender = ?, profile_focus = ?, profile_updated = 1 WHERE id = ?");
    mysqli_stmt_bind_param($upd, "ssi", $gender_enc, $focus, $user_id);
}

if (mysqli_stmt_execute($upd)) {
    // independent people go straight to the lab that fits the role they chose
    $landing = $role['locked'] ? null : astra_focus_landing($focus);
    echo json_encode(['ok' => true] + ($landing ? ['redirect' => $landing] : []));
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to save. Please try again.']);
}
```

### 5.10 `assets/js/sakura-petals-cursor.js`

Single-lobe asymmetrical sakura petals with flip physics. No heart shapes. (118 lines)

```js
/* Hastra crimson sakura petals: the pointer trail's particle field.

   Replaces the glowing-mote glitter with organic cherry-blossom petals that
   float, flip and drift down, in the Kage sanctuary's crimson and blossom
   pinks. This file only owns the petals (shape, physics, drawing). The pointer
   tracking, the canvas, the frame governor and the touch / reduced-motion
   rules stay with the engines that use it: assets/js/hybrid-hand-cursor.js
   (shared pages) and auth/login.php (the sign-in page's own canvas).

   Never a heart: the petal is a single smooth lobe (no notch, no second lobe).
   The trail can be switched off entirely with
       localStorage.setItem('hastra_cursor_trail', 'off')   (or window.HASTRA_NO_TRAIL = true)
   and back on by removing that key.

   A petal:  7 to 14 px, one of four crimson / rose tints, spawned with a gentle
   outward burst, then pulled down by gravity, slowed by air drag, swayed by a
   sine-wave wind and tumbled in 2D (rotation) and pseudo-3D (a flip that
   squashes it edge-on). It fades in briefly, then linearly to nothing over
   1.2 to 2.0 s, and its pool slot is free the moment it is gone.

   Petals are pre-rendered once to small sprites and stamped with drawImage, so
   the per-frame cost is one transform and one blit per live petal; the pool is
   fixed, so nothing is allocated while the pointer moves.

   Public: window.HastraPetals.create(cap) ->
     { emit(x, y, vx, vy), draw(ctx, dt, dpr, clock) -> live count, setCap(n), live }   (CSS px) */
(function () {
  'use strict';
  if (window.HastraPetals) return;

  const rnd = (a, b) => a + Math.random() * (b - a);
  // the visitor's switch for the whole trail (read on every emit, so it applies at once)
  function trailOff() {
    if (window.HASTRA_NO_TRAIL) return true;
    try { return localStorage.getItem('hastra_cursor_trail') === 'off'; } catch (e) { return false; }
  }

  /* Hastra crimson, blossom pink, deep temple wine, pale sakura */
  const TINTS = [[229, 9, 20, .8], [255, 105, 135, .75], [180, 20, 40, .6], [255, 130, 150, .75]];

  function petalSprite(tint) {
    const S = 48, c = document.createElement('canvas'); c.width = c.height = S;
    const x = c.getContext('2d'), [r, g, b, a] = tint;
    /* ONE smooth lobe, leaning to one side: a curved teardrop with a rounded top and a
       pointed base. The two sides are different curves and there is no dip anywhere, so
       it can never read as a heart when it tumbles. Drawn in a unit box (-.5 to .5). */
    x.translate(S / 2, S / 2); x.scale(S * .86, S * .92);
    x.beginPath();
    x.moveTo(.03, .5);
    x.bezierCurveTo(.38, .3, .44, -.2, .14, -.46);
    x.bezierCurveTo(.05, -.53, -.1, -.5, -.2, -.38);
    x.bezierCurveTo(-.5, -.1, -.28, .34, .03, .5);
    x.closePath();
    /* paler toward the tip, richer toward the broad end */
    const gr = x.createLinearGradient(0, .5, 0, -.45);
    gr.addColorStop(0, `rgba(${Math.min(255, r + 70)},${Math.min(255, g + 90)},${Math.min(255, b + 80)},${a * .8})`);
    gr.addColorStop(1, `rgba(${r},${g},${b},${a})`);
    x.fillStyle = gr; x.fill();
    return c;
  }
  const SPRITES = TINTS.map(petalSprite);

  function create(cap) {
    const list = [];
    for (let i = 0; i < cap; i++) list.push({ life: 1, max: 1 });
    let next = 0, size = cap;
    const field = {
      get cap() { return size; },
      live: 0,
      setCap(n) { size = Math.max(1, Math.min(n, list.length)); next %= size; },
      emit(x, y, vx, vy) {
        if (trailOff()) return;
        const p = list[next]; next = (next + 1) % size;
        const a = rnd(0, Math.PI * 2), burst = rnd(14, 60);        /* the gentle outward burst */
        p.x = x + rnd(-3, 3); p.y = y + rnd(-3, 3);
        p.vx = (vx || 0) + Math.cos(a) * burst; p.vy = (vy || 0) + Math.sin(a) * burst - 10;
        p.life = 0; p.max = rnd(1.2, 2.0);
        p.sz = rnd(7, 14) * rnd(.9, 1.15);                          /* depth: nearer petals read larger */
        p.tint = (Math.random() * SPRITES.length) | 0;
        p.rot = rnd(0, Math.PI * 2); p.spin = rnd(-3, 3);           /* 2D tumble, rad/s */
        p.flip = rnd(0, Math.PI); p.flipV = rnd(2.5, 5);            /* 3D flip, rad/s */
        p.ph = rnd(0, Math.PI * 2);                                 /* wind phase */
      },
      /* advance and paint every live petal; the caller clears the canvas first */
      draw(ctx, dt, dpr, clock) {
        let live = 0;
        for (let i = 0; i < size; i++) {
          const p = list[i];
          if (p.life >= p.max) continue;
          p.life += dt;
          const u = p.life / p.max;
          if (u >= 1) continue;                                     /* dead: the slot is reusable */
          live++;
          p.vx *= 1 - 1.5 * dt; p.vy *= 1 - 1.5 * dt;               /* air drag */
          p.vy += 46 * dt;                                          /* gravity */
          p.vx += Math.sin(clock * 2.2 + p.ph) * 34 * dt;           /* wind turbulence */
          p.x += p.vx * dt; p.y += p.vy * dt;
          p.rot += p.spin * dt; p.flip += p.flipV * dt;
          const a = Math.min(1, u / .08) * (1 - u);                 /* quick fade-in, then linear decay */
          if (a <= .004) continue;
          const sy = .28 + .72 * Math.abs(Math.cos(p.flip));        /* edge-on as it flips */
          const w = p.sz * (1 - .18 * u);
          const c = Math.cos(p.rot) * dpr, s = Math.sin(p.rot) * dpr;
          ctx.globalAlpha = a;
          ctx.setTransform(c, s, -s * sy, c * sy, p.x * dpr, p.y * dpr);
          ctx.drawImage(SPRITES[p.tint], -w / 2, -w / 2, w, w);
        }
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.globalAlpha = 1;
        field.live = live;
        return live;
      },
    };
    return field;
  }

  window.HastraPetals = { create };
})();
```

### 5.11 Orbital page loader: `initPageLoader()` in `core/theme.js`

The brief's `page-loader.js`. Runs synchronously in `<head>` before first paint. (106 lines)

```js
  // ── Global AX preloader ─────────────────────────────────────────────────
  // Injected via document.write() so it lands the instant this (synchronous,
  // <head>-loaded) script runs — before the rest of the page has painted —
  // and removed on window 'load' so it never outlives real content or
  // blocks a click. A page can opt out entirely by adding
  // data-loader-manual to this very <script core/theme.js> tag (body isn't
  // parsed yet at this point, so a <body> attribute can't be read here).
  (function initPageLoader() {
    if (document.currentScript && document.currentScript.hasAttribute('data-loader-manual')) return;

    // Matched against just the final path segment (e.g. "login.php" or
    // "project_portal") — NOT the full pathname, which always contains
    // "login" here since the whole app is served under an /Hastra/ base path.
    const LOADER_TEXT_MAP = [
      [/^crypto$/,                      'Loading Hastra Cryptographic Engine…'],
      [/^siem$/,                        'Loading SOC lab pipelines…'],
      [/^syllabus$/,                    'Preparing the study hub…'],
      [/^labs$/,                        'Opening Hastra Labs…'],
      [/^auth$/,                        'Opening your free session…'],
      [/^(hastra|index|)$/,             'Parsing WebGL shaders…'],
      [/^(login|otp|forgot|signin|verify$|reset)/, 'Initializing defense-grade workspace…'],
      [/^(register|verify_register|signup|verify-email|set-password)/, 'Provisioning your Hastra workspace…'],
      [/project|requirement/,           'Fetching SDLC project pipeline…'],
      [/team|roster|directory/,         'Loading enterprise team matrix…'],
      [/deliver/,                       'Decrypting delivery dossier…'],
      [/attendance/,                    'Syncing attendance records…'],
      [/leave_management/,              'Loading leave policy console…'],
      [/testing_portal|bug/,            'Pulling QA bug queue…'],
      [/deployment/,                    'Preparing deployment pipeline…'],
      [/billing|invoice/,               'Reconciling billing ledger…'],
      [/security/,                      'Running security diagnostics…'],
      [/doc/,                           'Loading documentation…'],
    ];
    function contextualText(pathname) {
      const segments = (pathname || window.location.pathname).toLowerCase().split('/').filter(Boolean);
      const page = (segments[segments.length - 1] || '').replace(/\.(php|html)$/, '');
      for (const [re, text] of LOADER_TEXT_MAP) {
        if (re.test(page)) return text;
      }
      return 'Loading…';
    }

    document.write(
      '<div id="hastra-page-loader">' +
        '<div class="hastra-loader-spinner"></div>' +
        '<p id="hastra-loader-text" class="loader-status-text">' + contextualText() + '</p>' +
      '</div>'
    );

    function hidePageLoader() {
      const el = document.getElementById('hastra-page-loader');
      if (!el) return;
      el.style.opacity = '0';
      el.style.pointerEvents = 'none';
      setTimeout(function() { el.remove(); }, 260);
    }
    window.addEventListener('load', hidePageLoader);
    // Fail-safe: never let a stuck asset (slow font, video, etc.) hold the
    // loader up indefinitely — the rest of the page is interactive either way.
    setTimeout(hidePageLoader, 4000);

    // ── Route transitions ──────────────────────────────────────────────────
    // Following a link to another page of this site shows the same loader once
    // the wait is noticeable, instead of leaving the old page frozen (or blank)
    // until the next one paints. It waits 140 ms so instant navigations never
    // flash it, is skipped for downloads, new tabs, modified clicks and
    // same-page links, and is removed again on pageshow (back/forward cache)
    // or after 10 s, so it can never outstay the navigation it belongs to.
    const SKIP_EXT = /\.(zip|pdf|json|csv|md|txt|hlx|docx?|xlsx?|png|jpe?g|gif|webp|svg|mp4|webm)$/i;
    let routeTimer = 0, routeFail = 0;
    function showRouteLoader(text) {
      if (document.getElementById('hastra-page-loader') || !document.body) return;
      const box = document.createElement('div');
      box.id = 'hastra-page-loader';
      box.setAttribute('role', 'status');
      box.style.opacity = '0';
      const ring = document.createElement('div');
      ring.className = 'hastra-loader-spinner';
      const p = document.createElement('p');
      p.id = 'hastra-loader-text';
      p.className = 'loader-status-text';
      p.textContent = text;
      box.append(ring, p);
      document.body.append(box);
      requestAnimationFrame(function () { box.style.opacity = '1'; });
      routeFail = setTimeout(hidePageLoader, 10000);
    }
    function cancelRouteLoader() {
      clearTimeout(routeTimer); clearTimeout(routeFail);
      hidePageLoader();
    }
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = e.target.closest && e.target.closest('a[href]');
      if (!a || a.hasAttribute('download') || a.hasAttribute('data-no-loader')) return;
      if (a.target && a.target !== '_self') return;
      let u;
      try { u = new URL(a.href, location.href); } catch (err) { return; }
      if (!/^https?:$/.test(u.protocol) || u.origin !== location.origin) return;
      if (u.pathname === location.pathname && u.search === location.search) return;
      if (SKIP_EXT.test(u.pathname) || /download/i.test(u.pathname)) return;
      clearTimeout(routeTimer);
      routeTimer = setTimeout(function () { showRouteLoader(contextualText(u.pathname)); }, 140);
    });
    window.addEventListener('pageshow', function (e) { if (e.persisted) cancelRouteLoader(); });
  })();
```

### 5.12 `assets/js/performance-guard.js`

Detects weak devices and tells the scene, cursor and fog to economize. (63 lines)

```js
/* Hastra performance guard.

   Decides, once and as early as possible, whether this device should run the
   light version of the heavy visuals, and lets the scene, the rings and the
   petal trail read the answer. Classic script, no dependencies, safe to load
   twice (the landing scene runs in an iframe and loads its own copy).

   "Low" when any of these is true:
     - 4 or fewer logical CPU cores        (navigator.hardwareConcurrency)
     - 4 GB of memory or less              (navigator.deviceMemory)
     - a phone or tablet user agent
     - the browser's data-saver flag is on (navigator.connection.saveData)
     - measured: after the page has loaded, the median frame takes longer
       than 33 ms (under 30 fps). This catches slow GPUs that report strong
       hardware, and it is the only signal that can switch the mode on late.

   Effect, read by the consumers:
     window.HASTRA_PERF = { low, reasons[], petalCap, petalStep }
     html.low-perf-mode            (CSS drops the glass blur)
     a "hastra:perf" event on window when the mode flips after start-up
   The landing scene drops to a quarter of its leaves and embers and renders at
   device-pixel-ratio 1; the petal trail keeps fewer petals and spawns them
   less often; Labs panels lose their backdrop blur. */
(function () {
  'use strict';
  if (window.HASTRA_PERF) return;
  const nav = navigator;
  const reasons = [];
  const cores = nav.hardwareConcurrency || 0;
  const mem = nav.deviceMemory || 0;
  if (cores && cores <= 4) reasons.push('cpu:' + cores);
  if (mem && mem <= 4) reasons.push('memory:' + mem);
  const mobile = (nav.userAgentData && nav.userAgentData.mobile) || /Mobi|Android|iPhone|iPad/i.test(nav.userAgent);
  if (mobile) reasons.push('mobile');
  if (nav.connection && nav.connection.saveData) reasons.push('save-data');

  const P = window.HASTRA_PERF = { low: false, reasons: reasons, petalCap: 120, petalStep: 26 };
  function apply(late) {
    P.low = true; P.petalCap = 50; P.petalStep = 44;
    document.documentElement.classList.add('low-perf-mode');
    if (late) { try { window.dispatchEvent(new Event('hastra:perf')); } catch (e) { /* old browser */ } }
  }
  if (reasons.length) apply(false);

  // Measured fallback: only when the hardware looked fine.
  if (P.low || !window.requestAnimationFrame || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  function sample() {
    const dts = [];
    let last = 0;
    (function tick(now) {
      if (document.hidden) { last = 0; return void requestAnimationFrame(tick); }   // a hidden tab proves nothing
      if (last) { const dt = now - last; if (dt < 500) dts.push(dt); }
      last = now;
      if (dts.length < 45) return void requestAnimationFrame(tick);
      dts.sort(function (a, b) { return a - b; });
      const median = dts[dts.length >> 1];
      if (median > 33 && !P.low) { P.reasons.push('slow-frames:' + Math.round(median) + 'ms'); apply(true); }
    })(performance.now());
  }
  // start after load, once the page's own start-up work has settled
  function arm() { setTimeout(sample, 1500); }
  if (document.readyState === 'complete') arm(); else window.addEventListener('load', arm, { once: true });
})();
```

### 5.13 Local cryptography engine: `assets/js/pqc-crypto.js`

AES-256-GCM, ChaCha20-Poly1305, ML-KEM, X-Wing, X25519, RSA-OAEP, ML-DSA, Argon2id, bcrypt, keyed hashes and the HLX1 envelope. Runs entirely in the browser. (567 lines)

```js
/* Hastra Labs — HastraCryptoVault
   Classical, post-quantum and hybrid encryption, ML-DSA signatures, and a
   dual-classification credential hasher. Runs entirely in the browser.

   Primitives
     WebCrypto ............ AES-256-GCM, RSA-OAEP-4096 (SHA-256), HKDF-SHA256,
                            PBKDF2 (SHA-256 / SHA-512), HMAC-SHA256, SHA-256
     @noble/ciphers ....... ChaCha20-Poly1305            (audited, pinned)
     @noble/curves ........ X25519
     @noble/post-quantum .. ML-KEM-768/1024 (FIPS 203), ML-DSA-65/87 (FIPS 204),
                            X-Wing = ML-KEM-768 + X25519 hybrid KEM
     @noble/hashes ........ BLAKE3
     hash-wasm ............ Argon2id, bcrypt
   Libraries are resolved through the page's import map (labs/_boot.php pins
   the versions) and loaded on first use.

   Envelope (.hlx) ─ one self-describing binary file
     "HLX1" | u32 BE header length | header JSON | AEAD ciphertext
   The magic, length and header bytes are the AEAD's associated data, so
   editing any header field (algorithm, KEM ciphertext, file name…) makes
   decryption fail instead of silently changing meaning.

   Content encryption is always an AEAD under a fresh 256-bit key:
     passphrase modes  key = PBKDF2-SHA256(passphrase, 16-byte salt, 600 000)
     RSA-OAEP          random CEK wrapped with the recipient's RSA-4096 key
     X25519            ephemeral-static ECDH, then HKDF-SHA256
     ML-KEM / X-Wing   KEM encapsulation, then HKDF-SHA256
   HKDF binds the algorithm and recipient key id into the derived key. */

import { enc, dec, toHex, toB64, fromB64, concat, ctEqual, randomBytes } from './labs-common.js';

const MAGIC = enc.encode('HLX1');
const PBKDF2_ITER = 600000;          // OWASP 2023 guidance for PBKDF2-HMAC-SHA256
export const MAX_FILE_BYTES = 256 * 1024 * 1024;

const libs = {};
const lib = name => (libs[name] ||= import(name).catch(err => { delete libs[name]; throw new Error(`Could not load the ${name} library (${err.message}). Check your connection.`); }));

// ── Algorithm catalogue ─────────────────────────────────────────────────────
export const ALGORITHMS = {
  'aes-256-gcm':       { family: 'symmetric', label: 'AES-256-GCM', note: 'Passphrase → PBKDF2-SHA256 (600k) → AES-256-GCM. The NIST standard AEAD.' },
  'chacha20-poly1305': { family: 'symmetric', label: 'ChaCha20-Poly1305', note: 'Passphrase → PBKDF2-SHA256 (600k) → ChaCha20-Poly1305 (RFC 8439). Fast without AES hardware.' },
  'rsa-oaep-4096':     { family: 'asymmetric', label: 'RSA-4096-OAEP', note: 'A random AES-256 key wrapped with RSA-OAEP (SHA-256). Classical; broken by a large quantum computer.' },
  'x25519':            { family: 'asymmetric', label: 'X25519 (ECIES)', note: 'Ephemeral-static X25519 ECDH → HKDF-SHA256 → AES-256-GCM. Classical; broken by a large quantum computer.' },
  'ml-kem-768':        { family: 'pqc', label: 'ML-KEM-768 (FIPS 203)', note: 'Module-lattice KEM, NIST security category 3. Quantum-resistant.' },
  'ml-kem-1024':       { family: 'pqc', label: 'ML-KEM-1024 (FIPS 203)', note: 'Module-lattice KEM, NIST category 5 (comparable to AES-256). Quantum-resistant.' },
  'xwing':             { family: 'hybrid', label: 'Hybrid X-Wing (ML-KEM-768 + X25519)', note: 'IETF X-Wing hybrid KEM: secure if either ML-KEM or X25519 holds. Recommended for long-lived secrets.' },
};
export const SIGNATURES = {
  'ml-dsa-65': { label: 'ML-DSA-65 (FIPS 204)', note: 'Module-lattice signatures, NIST category 3.' },
  'ml-dsa-87': { label: 'ML-DSA-87 (FIPS 204)', note: 'Module-lattice signatures, NIST category 5.' },
};
export const needsPassphrase = alg => ALGORITHMS[alg]?.family === 'symmetric';

// ── Small primitives ────────────────────────────────────────────────────────
const sha256 = async bytes => new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
export async function sha256Hex(bytes) { return toHex(await sha256(bytes)); }

async function pbkdf2Bits(passphrase, salt, iterations, hash = 'SHA-256', bits = 256) {
  const base = await crypto.subtle.importKey('raw', typeof passphrase === 'string' ? enc.encode(passphrase) : passphrase, 'PBKDF2', false, ['deriveBits']);
  return new Uint8Array(await crypto.subtle.deriveBits({ name: 'PBKDF2', salt, iterations, hash }, base, bits));
}
async function hkdfBytes(ikm, salt, info, bytes = 32) {
  const base = await crypto.subtle.importKey('raw', ikm, 'HKDF', false, ['deriveBits']);
  return new Uint8Array(await crypto.subtle.deriveBits({ name: 'HKDF', hash: 'SHA-256', salt, info: enc.encode(info) }, base, bytes * 8));
}
const aesKey = (raw, usage) => crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, usage);
async function aesGcm(mode, keyBytes, iv, data, aad) {
  const key = await aesKey(keyBytes, [mode]);
  const fn = mode === 'encrypt' ? crypto.subtle.encrypt : crypto.subtle.decrypt;
  return new Uint8Array(await fn.call(crypto.subtle, { name: 'AES-GCM', iv, additionalData: aad, tagLength: 128 }, key, data));
}

// Key id: first 8 bytes of SHA-256(alg ‖ 0x00 ‖ public key), in hex.
export async function keyId(alg, pub) { return toHex((await sha256(concat(enc.encode(alg), new Uint8Array([0]), pub))).slice(0, 8)); }

// ── Envelope framing ────────────────────────────────────────────────────────
function frame(header) {
  const h = enc.encode(JSON.stringify(header));
  const len = new Uint8Array(4);
  new DataView(len.buffer).setUint32(0, h.length, false);
  return concat(MAGIC, len, h);           // this whole prefix is the AAD
}
export function parseEnvelope(bytes) {
  if (bytes.length < 8 || !ctEqual(bytes.subarray(0, 4), MAGIC)) throw new Error('Not a Hastra Labs envelope (.hlx). The file header is missing.');
  const hlen = new DataView(bytes.buffer, bytes.byteOffset + 4, 4).getUint32(0, false);
  if (hlen > 64 * 1024 || 8 + hlen > bytes.length) throw new Error('The envelope header is damaged.');
  const aad = bytes.subarray(0, 8 + hlen);
  let header;
  try { header = JSON.parse(dec.decode(bytes.subarray(8, 8 + hlen))); } catch { throw new Error('The envelope header is not valid JSON.'); }
  if (header.v !== 1 || !ALGORITHMS[header.alg]) throw new Error('Unsupported envelope version or algorithm.');
  return { header, aad, body: bytes.subarray(8 + hlen) };
}

// ── Key bundles ─────────────────────────────────────────────────────────────
// { kty: 'hastra-labs-pub' | 'hastra-labs-sec', v: 1, alg, kid, pub, sec | secEnc, created }
export async function generateKeyPair(alg) {
  let pub, sec;
  if (alg === 'rsa-oaep-4096') {
    const kp = await crypto.subtle.generateKey({ name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['encrypt', 'decrypt']);
    pub = new Uint8Array(await crypto.subtle.exportKey('spki', kp.publicKey));
    sec = new Uint8Array(await crypto.subtle.exportKey('pkcs8', kp.privateKey));
  } else if (alg === 'x25519') {
    const { x25519 } = await lib('curves');
    sec = x25519.utils.randomSecretKey();
    pub = x25519.getPublicKey(sec);
  } else if (alg === 'ml-kem-768' || alg === 'ml-kem-1024') {
    const m = await lib('ml-kem');
    const kp = (alg === 'ml-kem-768' ? m.ml_kem768 : m.ml_kem1024).keygen();
    pub = kp.publicKey; sec = kp.secretKey;
  } else if (alg === 'xwing') {
    const { ml_kem768_x25519 } = await lib('hybrid');
    const kp = ml_kem768_x25519.keygen();
    pub = kp.publicKey; sec = kp.secretKey;
  } else if (alg === 'ml-dsa-65' || alg === 'ml-dsa-87') {
    const m = await lib('ml-dsa');
    const kp = (alg === 'ml-dsa-65' ? m.ml_dsa65 : m.ml_dsa87).keygen();
    pub = kp.publicKey; sec = kp.secretKey;
  } else throw new Error('No key pair for ' + alg);
  const kid = await keyId(alg, pub);
  const created = new Date().toISOString();
  return {
    publicBundle: { kty: 'hastra-labs-pub', v: 1, alg, kid, pub: toB64(pub), created },
    secretBundle: { kty: 'hastra-labs-sec', v: 1, alg, kid, pub: toB64(pub), sec: toB64(sec), created },
    sizes: { pub: pub.length, sec: sec.length },
  };
}

// Optionally seal the secret key under a passphrase (AES-256-GCM, AAD = kid).
export async function protectSecretBundle(bundle, passphrase) {
  const salt = randomBytes(16), iv = randomBytes(12);
  const key = await pbkdf2Bits(passphrase, salt, PBKDF2_ITER);
  const ct = await aesGcm('encrypt', key, iv, fromB64(bundle.sec), enc.encode('hastra-labs/key/' + bundle.kid));
  const { sec, ...rest } = bundle;
  return { ...rest, secEnc: { kdf: 'PBKDF2-SHA256', iter: PBKDF2_ITER, salt: toB64(salt), iv: toB64(iv), ct: toB64(ct) } };
}
async function openSecretBundle(bundle, passphrase) {
  if (bundle.sec) return fromB64(bundle.sec);
  if (!bundle.secEnc) throw new Error('This key file has no secret key in it.');
  if (!passphrase) throw new Error('This secret key is passphrase-protected. Enter its passphrase.');
  const s = bundle.secEnc;
  const key = await pbkdf2Bits(passphrase, fromB64(s.salt), s.iter || PBKDF2_ITER);
  try { return await aesGcm('decrypt', key, fromB64(s.iv), fromB64(s.ct), enc.encode('hastra-labs/key/' + bundle.kid)); }
  catch { throw new Error('Wrong passphrase for this secret key.'); }
}
export function parseBundle(text, want) {
  let b;
  try { b = JSON.parse(text); } catch { throw new Error('That key file is not valid JSON.'); }
  if (!b || b.v !== 1 || !(b.kty === 'hastra-labs-pub' || b.kty === 'hastra-labs-sec')) throw new Error('Not a Hastra Labs key file.');
  if (want === 'sec' && b.kty !== 'hastra-labs-sec') throw new Error('That is a public key. Decrypting and signing need the secret key file.');
  return b;
}

// ── Encrypt ─────────────────────────────────────────────────────────────────
// opts: { alg, passphrase?, recipient? (public bundle), name, type }
export async function encryptPayload(data, opts) {
  const { alg } = opts;
  if (!ALGORITHMS[alg]) throw new Error('Pick an algorithm.');
  if (data.length > MAX_FILE_BYTES) throw new Error('Files up to 256 MB are supported in the browser.');
  const header = { v: 1, alg, name: opts.name || null, type: opts.type || null, size: data.length, created: new Date().toISOString() };
  let cek;

  if (needsPassphrase(alg)) {
    if (!opts.passphrase) throw new Error('Enter a passphrase.');
    const salt = randomBytes(16);
    Object.assign(header, { kdf: 'PBKDF2-SHA256', iter: PBKDF2_ITER, salt: toB64(salt) });
    cek = await pbkdf2Bits(opts.passphrase, salt, PBKDF2_ITER);
  } else {
    const r = opts.recipient;
    if (!r || r.alg !== alg) throw new Error(`Load a ${ALGORITHMS[alg].label} public key for the recipient.`);
    const rpk = fromB64(r.pub);
    header.kid = r.kid;
    if (alg === 'rsa-oaep-4096') {
      cek = randomBytes(32);
      const key = await crypto.subtle.importKey('spki', rpk, { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['encrypt']);
      header.wrap = toB64(new Uint8Array(await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, key, cek)));
    } else {
      let ss;
      if (alg === 'x25519') {
        const { x25519 } = await lib('curves');
        const esk = x25519.utils.randomSecretKey();
        const epk = x25519.getPublicKey(esk);
        ss = x25519.getSharedSecret(esk, rpk);
        header.epk = toB64(epk);
      } else {
        const kem = alg === 'xwing' ? (await lib('hybrid')).ml_kem768_x25519
          : (await lib('ml-kem'))[alg === 'ml-kem-768' ? 'ml_kem768' : 'ml_kem1024'];
        const { cipherText, sharedSecret } = kem.encapsulate(rpk);
        ss = sharedSecret;
        header.kem = toB64(cipherText);
      }
      const hsalt = randomBytes(32);
      header.hkdf = toB64(hsalt);
      cek = await hkdfBytes(ss, hsalt, `hastra-labs/v1/${alg}/${r.kid}`);
      ss.fill?.(0);
    }
  }

  const iv = randomBytes(12);
  header.iv = toB64(iv);
  const aad = frame(header);
  let body;
  if (alg === 'chacha20-poly1305') {
    const { chacha20poly1305 } = await lib('chacha');
    body = chacha20poly1305(cek, iv, aad).encrypt(data);
  } else {
    body = await aesGcm('encrypt', cek, iv, data, aad);
  }
  cek.fill(0);
  return { envelope: concat(aad, body), header };
}

// ── Decrypt ─────────────────────────────────────────────────────────────────
// opts: { passphrase?, secretBundle?, keyPassphrase? }
export async function decryptEnvelope(bytes, opts = {}) {
  const { header, aad, body } = parseEnvelope(bytes);
  const alg = header.alg;
  let cek;
  if (needsPassphrase(alg)) {
    if (!opts.passphrase) throw new Error('This envelope is passphrase-encrypted. Enter the passphrase.');
    cek = await pbkdf2Bits(opts.passphrase, fromB64(header.salt), header.iter || PBKDF2_ITER);
  } else {
    const b = opts.secretBundle;
    if (!b) throw new Error(`Load the ${ALGORITHMS[alg].label} secret key (key id ${header.kid}).`);
    if (b.alg !== alg) throw new Error(`This envelope needs a ${ALGORITHMS[alg].label} key; the loaded key is ${b.alg}.`);
    if (b.kid !== header.kid) throw new Error(`Wrong key: the envelope was sealed for key ${header.kid}, the loaded key is ${b.kid}.`);
    const sk = await openSecretBundle(b, opts.keyPassphrase);
    try {
      if (alg === 'rsa-oaep-4096') {
        const key = await crypto.subtle.importKey('pkcs8', sk, { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['decrypt']);
        try { cek = new Uint8Array(await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, key, fromB64(header.wrap))); }
        catch { throw new Error('The wrapped key could not be opened with this RSA key.'); }
      } else {
        let ss;
        if (alg === 'x25519') {
          const { x25519 } = await lib('curves');
          ss = x25519.getSharedSecret(sk, fromB64(header.epk));
        } else {
          const kem = alg === 'xwing' ? (await lib('hybrid')).ml_kem768_x25519
            : (await lib('ml-kem'))[alg === 'ml-kem-768' ? 'ml_kem768' : 'ml_kem1024'];
          ss = kem.decapsulate(fromB64(header.kem), sk);   // implicit rejection: a bad ct yields a random secret
        }
        cek = await hkdfBytes(ss, fromB64(header.hkdf), `hastra-labs/v1/${alg}/${header.kid}`);
      }
    } finally { sk.fill(0); }
  }
  try {
    let plain;
    if (alg === 'chacha20-poly1305') {
      const { chacha20poly1305 } = await lib('chacha');
      plain = chacha20poly1305(cek, fromB64(header.iv), aad).decrypt(body);
    } else {
      plain = await aesGcm('decrypt', cek, fromB64(header.iv), body, aad);
    }
    return { plain, header };
  } catch {
    throw new Error(needsPassphrase(alg)
      ? 'Decryption failed: wrong passphrase, or the file was modified.'
      : 'Decryption failed: the file was modified, or this is not the key it was sealed for.');
  } finally { cek?.fill(0); }
}

// ── ML-DSA signatures ───────────────────────────────────────────────────────
export async function signMessage(message, secretBundle, keyPassphrase, name) {
  const alg = secretBundle.alg;
  if (!SIGNATURES[alg]) throw new Error('Load an ML-DSA secret key to sign.');
  const m = await lib('ml-dsa');
  const dsa = alg === 'ml-dsa-65' ? m.ml_dsa65 : m.ml_dsa87;
  const sk = await openSecretBundle(secretBundle, keyPassphrase);
  try {
    const sig = dsa.sign(message, sk);
    return { kty: 'hastra-labs-sig', v: 1, alg, kid: secretBundle.kid, name: name || null, size: message.length,
             sha256: await sha256Hex(message), sig: toB64(sig), created: new Date().toISOString() };
  } finally { sk.fill(0); }
}
export async function verifySignature(message, sigDoc, publicBundle) {
  if (sigDoc?.kty !== 'hastra-labs-sig') throw new Error('Not a Hastra Labs signature file.');
  if (publicBundle.alg !== sigDoc.alg) throw new Error(`The signature is ${sigDoc.alg}; the loaded key is ${publicBundle.alg}.`);
  const m = await lib('ml-dsa');
  const dsa = sigDoc.alg === 'ml-dsa-65' ? m.ml_dsa65 : m.ml_dsa87;
  const digest = await sha256Hex(message);
  const ok = dsa.verify(fromB64(sigDoc.sig), message, fromB64(publicBundle.pub));
  return { ok, keyMatches: publicBundle.kid === sigDoc.kid, digestMatches: digest === sigDoc.sha256, digest };
}

// ── Credential hashing ──────────────────────────────────────────────────────
export const HASHERS = {
  user_password: {
    argon2id: { label: 'Argon2id', params: 'm=65536 KiB, t=4, p=2, 32-byte tag', note: 'Memory-hard (RFC 9106). The first choice for human passwords.' },
    bcrypt:   { label: 'bcrypt',   params: 'cost=12', note: 'Battle-tested and CPU-hard. Only the first 72 bytes of a password count.' },
  },
  app_secret: {
    'hmac-sha256':   { label: 'HMAC-SHA256', params: '256-bit server-side key (pepper)', note: 'Fast keyed hash for high-entropy API keys. Keep the key in a secrets manager.' },
    'pbkdf2-sha512': { label: 'PBKDF2-SHA512', params: '100,000 iterations, 16-byte salt, 64-byte output', note: 'FIPS-approved KDF for machine credentials in regulated stacks.' },
    blake3:          { label: 'BLAKE3 (keyed)', params: '256-bit key, 32-byte output', note: 'Very fast keyed hash for token lookups at high request rates.' },
  },
};

const b64nopad = u8 => toB64(u8).replace(/=+$/, '');
const b64any = s => fromB64(s + '='.repeat((4 - s.length % 4) % 4));

export async function hashCredential(secret, classification, engine, keyHex) {
  const t0 = performance.now();
  const pw = enc.encode(secret);
  let out;
  if (engine === 'argon2id') {
    const hw = await lib('hashwasm');
    const salt = randomBytes(16);
    const encoded = await hw.argon2id({ password: pw, salt, parallelism: 2, iterations: 4, memorySize: 65536, hashLength: 32, outputType: 'encoded' });
    const parts = encoded.split('$');
    out = { salt: toHex(b64any(parts[4])), hash: toHex(b64any(parts[5])), stored: encoded };
  } else if (engine === 'bcrypt') {
    const hw = await lib('hashwasm');
    const salt = randomBytes(16);
    const encoded = await hw.bcrypt({ password: pw.length > 72 ? pw.slice(0, 72) : pw, salt, costFactor: 12, outputType: 'encoded' });
    out = { salt: encoded.slice(7, 29) + '  (bcrypt radix-64)', hash: encoded.slice(29), stored: encoded, truncated: pw.length > 72 };
  } else if (engine === 'hmac-sha256') {
    const key = keyHex ? hexKey(keyHex) : randomBytes(32);
    const k = await crypto.subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    const mac = new Uint8Array(await crypto.subtle.sign('HMAC', k, pw));
    const kid = toHex((await sha256(key)).slice(0, 4));
    out = { key: toHex(key), hash: toHex(mac), stored: `hmac-sha256$kid=${kid}$${toHex(mac)}` };
  } else if (engine === 'pbkdf2-sha512') {
    const salt = randomBytes(16);
    const dk = await pbkdf2Bits(pw, salt, 100000, 'SHA-512', 512);
    out = { salt: toHex(salt), hash: toHex(dk), stored: `$pbkdf2-sha512$i=100000$${b64nopad(salt)}$${b64nopad(dk)}` };
  } else if (engine === 'blake3') {
    const { blake3 } = await lib('blake3');
    const key = keyHex ? hexKey(keyHex) : randomBytes(32);
    const h = blake3(pw, { key });
    const kid = toHex((await sha256(key)).slice(0, 4));
    out = { key: toHex(key), hash: toHex(h), stored: `blake3-keyed$kid=${kid}$${toHex(h)}` };
  } else throw new Error('Unknown hashing engine.');
  const meta = HASHERS[classification]?.[engine] || HASHERS.user_password[engine] || HASHERS.app_secret[engine];
  return { engine, classification, label: meta.label, params: meta.params, ms: Math.round(performance.now() - t0), ...out };
}

function hexKey(h) {
  const s = h.trim().replace(/^0x/, '');
  if (!/^[0-9a-fA-F]{64}$/.test(s)) throw new Error('The key must be 32 bytes written as 64 hex characters.');
  const u = new Uint8Array(32);
  for (let i = 0; i < 32; i++) u[i] = parseInt(s.substr(i * 2, 2), 16);
  return u;
}

// Verifies a candidate against a stored hash string (any engine above).
export async function verifyCredential(candidate, stored, keyHex) {
  stored = stored.trim();
  const pw = enc.encode(candidate);
  if (stored.startsWith('$argon2')) return (await lib('hashwasm')).argon2Verify({ password: pw, hash: stored });
  if (/^\$2[aby]\$/.test(stored)) return (await lib('hashwasm')).bcryptVerify({ password: pw.length > 72 ? pw.slice(0, 72) : pw, hash: stored });
  let m = stored.match(/^\$pbkdf2-sha512\$i=(\d+)\$([A-Za-z0-9+/]+)\$([A-Za-z0-9+/]+)$/);
  if (m) {
    const want = b64any(m[3]);
    const got = await pbkdf2Bits(pw, b64any(m[2]), parseInt(m[1], 10), 'SHA-512', want.length * 8);
    return ctEqual(got, want);
  }
  m = stored.match(/^(hmac-sha256|blake3-keyed)\$kid=([0-9a-f]{8})\$([0-9a-f]{64})$/);
  if (m) {
    if (!keyHex) throw new Error('Keyed hashes need the 64-hex-character key they were made with.');
    const key = hexKey(keyHex);
    if (toHex((await sha256(key)).slice(0, 4)) !== m[2]) throw new Error('That key does not match the key id in the stored hash.');
    let got;
    if (m[1] === 'hmac-sha256') {
      const k = await crypto.subtle.importKey('raw', key, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
      got = new Uint8Array(await crypto.subtle.sign('HMAC', k, pw));
    } else {
      got = (await lib('blake3')).blake3(pw, { key });
    }
    return ctEqual(got, Uint8Array.from(m[3].match(/../g), h => parseInt(h, 16)));
  }
  throw new Error('Unrecognised stored hash format.');
}

export function generateMachineSecret(bytes = 32) {
  return 'hst_' + toB64(randomBytes(bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

// ── Strength estimation ─────────────────────────────────────────────────────
// Pattern-aware, in the spirit of zxcvbn but much smaller: the secret is split
// into the cheapest explanation (common password, dictionary word, keyboard or
// alphabet run, repeat, year/date, or random characters) and each piece is
// charged the bits an attacker would need to guess it.
const COMMON = ('123456 password 123456789 12345678 12345 qwerty 1234567 111111 1234567890 123123 abc123 1234 password1 iloveyou 1q2w3e4r ' +
  '000000 qwerty123 zaq12wsx dragon sunshine princess letmein 654321 monkey 27653 1qaz2wsx 123321 qwertyuiop superman asdfghjkl ' +
  'trustno1 welcome admin login master hello freedom whatever qazwsx football baseball shadow michael jennifer charlie ' +
  'jordan hunter2 passw0rd p@ssw0rd p@ssword changeme secret root toor test guest default india pakistan cricket').split(' ');
const WORDS = ('love life time world house money power music water family friend summer winter spring autumn happy lucky magic ' +
  'secret dream angel tiger lion eagle dragon falcon shadow ghost storm thunder cyber hacker admin system server secure access ' +
  'network cloud data login pass word user guest hello welcome sunshine flower rose apple orange banana mango cherry lemon ' +
  'yellow green black white silver golden diamond star moon planet ocean river mountain forest coffee pizza chocolate cookie ' +
  'monkey donkey horse puppy kitty pepper ginger sugar honey baby sweet heart cool super master king queen prince princess ' +
  'soccer football cricket hockey tennis ninja pirate robot rocket space galaxy matrix phoenix legend hunter killer warrior ' +
  'student college school teacher engineer doctor office company hastra astra temple kyoto tokyo india london paris').split(' ');
const KEYROWS = ['qwertyuiop', 'asdfghjkl', 'zxcvbnm', '1234567890', 'abcdefghijklmnopqrstuvwxyz'];
const LEET = { '0': 'o', '1': 'i', '3': 'e', '4': 'a', '5': 's', '7': 't', '@': 'a', '$': 's', '!': 'i' };
const unleet = s => s.toLowerCase().replace(/[013457@$!]/g, c => LEET[c]);

// A letters-only run that reads like a word (vowels spread through it, no
// long consonant clusters) is priced as one pick from a large dictionary,
// even when it is not in the small list above: that is how an attacker's
// wordlists and mangling rules find "Tr0ub4dor".
function wordLike(w) {
  if (w.length < 5 || w.length > 16 || !/^[a-z]+$/.test(w)) return false;
  const v = (w.match(/[aeiouy]/g) || []).length / w.length;
  return v >= 0.25 && v <= 0.7 && !/[^aeiouy]{4}/.test(w) && !/[aeiouy]{3}/.test(w);
}

// Would a human plausibly have typed this piece as a (mangled) word?
function humanWordPiece(piece) {
  const letters = (piece.match(/[a-z]/gi) || []).length;
  const leet = (piece.match(/[013457@$!]/g) || []).length;
  return letters / piece.length >= 0.7 && leet <= 2 && /[aeiouy]/i.test(piece)
    && /^(?:[A-Z]?[a-z013457@$!]+|[A-Z013457@$!]+)$/.test(piece);
}

// Recognise encoded random tokens by their alphabet rather than by the
// character classes they happen to contain.
function encodedAlphabet(s) {
  if (s.length < 16) return 0;
  if (/^[0-9a-f]+$/i.test(s)) return 16;
  if (/^[A-Z2-7]+=*$/.test(s)) return 32;
  if (/^[A-Za-z0-9_-]+$/.test(s) && /[A-Z]/.test(s) && /[a-z]/.test(s) && /\d/.test(s)) return 64;
  if (/^[A-Za-z0-9+/]+=*$/.test(s) && /[A-Z]/.test(s) && /[a-z]/.test(s) && /\d/.test(s)) return 64;
  return 0;
}

function poolSize(s) {
  const encoded = encodedAlphabet(s);
  if (encoded) return encoded;
  let p = 0;
  if (/[a-z]/.test(s)) p += 26;
  if (/[A-Z]/.test(s)) p += 26;
  if (/[0-9]/.test(s)) p += 10;
  if (/[^a-zA-Z0-9\s]/.test(s) && /[\x21-\x7e]/.test(s.replace(/[a-zA-Z0-9]/g, ''))) p += 33;
  if (/\s/.test(s)) p += 1;
  if (/[^\x00-\x7f]/.test(s)) p += 100;
  return Math.max(p, 1);
}

export function estimateStrength(secret) {
  let s = secret || '';
  if (!s) return { bits: 0, naive: 0, findings: [], rating: 'empty', score: 0 };
  // A fixed type prefix ("hst_", "sk_live_", "ghp_") adds no entropy.
  const prefix = s.match(/^(?:[a-z]{2,6}_){1,2}(?=[A-Za-z0-9_-]{16,}$)/);
  if (prefix) s = s.slice(prefix[0].length);
  const pool = poolSize(s);
  const encoded = encodedAlphabet(s) > 0;
  const naive = s.length * Math.log2(pool);
  const findings = [];
  const lower = unleet(s);

  const common = COMMON.indexOf(lower);
  if (common >= 0) {
    const bits = Math.log2(common + 2) + (s !== lower ? 1 : 0);
    return finish(s, bits, naive, [`“${s}” is one of the most common passwords (rank ${common + 1}).`]);
  }

  // dynamic programming over positions: cheapest cover of the whole string
  const n = s.length;
  const best = new Array(n + 1).fill(Infinity);
  const how = new Array(n + 1).fill(null);
  best[0] = 0;
  const perChar = Math.log2(pool);
  for (let i = 0; i < n; i++) {
    if (best[i] === Infinity) continue;
    const relax = (j, bits, why) => { if (best[i] + bits < best[j]) { best[j] = best[i] + bits; how[j] = { i, why }; } };
    relax(i + 1, perChar, null);
    for (let j = i + 3; j <= n; j++) {
      const piece = s.slice(i, j), pl = lower.slice(i, j);
      if (/^(.)\1+$/.test(piece)) relax(j, perChar + Math.log2(j - i), `repeated “${piece[0]}” ×${j - i}`);
      if (KEYROWS.some(r => r.includes(pl) || r.split('').reverse().join('').includes(pl))) relax(j, Math.log2(26 * 2) + Math.log2(j - i), `sequence “${piece}”`);
      if (j - i === 4 && /^(19|20)\d\d$/.test(piece)) relax(j, Math.log2(130), `year “${piece}”`);
      if (/^\d{6,8}$/.test(piece) && /^(0[1-9]|[12]\d|3[01])(0[1-9]|1[0-2])((19|20)?\d\d)$|^((19|20)\d\d)(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])$/.test(piece)) relax(j, Math.log2(365 * 100), `date “${piece}”`);
      if (j - i >= 4 && !encoded && humanWordPiece(piece)) {
        const w = WORDS.indexOf(pl);
        const leet = [...piece].filter((ch, k) => /[013457@$!]/.test(ch) && /[a-z]/.test(pl[k])).length;
        const caps = piece !== piece.toLowerCase() ? 1 : 0;
        if (w >= 0) relax(j, Math.log2(20000) + caps + leet, `dictionary word “${piece}”`);
        else if (wordLike(pl)) relax(j, Math.log2(50000) + caps + leet, `word-like “${piece}”${leet ? ' (leetspeak doesn’t help much)' : ''}`);
        const c = COMMON.indexOf(pl);
        if (c >= 0) relax(j, Math.log2(c + 2) + 2, `common password “${piece}”`);
      }
    }
  }
  for (let j = n; j > 0; j = how[j]?.i ?? 0) { if (how[j]?.why) findings.unshift(how[j].why); if (!how[j]) break; }
  return finish(s, Math.min(best[n], naive), naive, findings);
}

function finish(s, bits, naive, findings) {
  bits = Math.max(0, bits);
  const rating = bits < 28 ? 'very weak' : bits < 40 ? 'weak' : bits < 60 ? 'fair' : bits < 80 ? 'strong' : bits < 128 ? 'very strong' : 'excellent';
  const score = Math.min(100, Math.round(bits / 128 * 100));
  const guesses = Math.pow(2, Math.max(0, bits - 1)); // average case
  const crack = {
    online:  guesses / 10,        // throttled login form
    fast:    guesses / 1e11,      // unsalted SHA-256 on a GPU rig
    bcrypt:  guesses / 2e4,       // bcrypt cost 12, same rig
    argon2:  guesses / 5e2,       // Argon2id m=64 MiB t=4, same rig
  };
  return { bits, naive, findings, rating, score, crack, length: [...(s || '')].length };
}

export function humanTime(sec) {
  if (!Number.isFinite(sec) || sec > 1e18) return 'longer than the age of the universe';
  if (sec < 1) return 'instantly';
  const u = [[31557600e2, 'century'], [31557600, 'year'], [2629800, 'month'], [86400, 'day'], [3600, 'hour'], [60, 'minute'], [1, 'second']];
  for (const [n, name] of u) if (sec >= n) {
    const v = Math.round(sec / n);
    if (name === 'century' && v > 1e6) return 'millions of centuries';
    return `${v.toLocaleString()} ${name === 'century' ? (v === 1 ? 'century' : 'centuries') : name + (v === 1 ? '' : 's')}`;
  }
  return 'instantly';
}

// ── Self-test: known-answer vectors plus a round trip through every algorithm ──
export async function selfTest(log) {
  const results = [];
  const check = async (name, fn) => {
    const t0 = performance.now();
    try { const ok = await fn(); results.push({ name, ok: !!ok, ms: Math.round(performance.now() - t0) }); }
    catch (e) { results.push({ name, ok: false, err: e.message, ms: Math.round(performance.now() - t0) }); }
    log?.(results[results.length - 1]);
  };
  const msg = enc.encode('Hastra Labs self-test ✓ ' + Date.now());

  await check('KAT · HMAC-SHA256 (RFC 4231 case 2)', async () => {
    const k = await crypto.subtle.importKey('raw', enc.encode('Jefe'), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    return toHex(new Uint8Array(await crypto.subtle.sign('HMAC', k, enc.encode('what do ya want for nothing?')))) ===
      '5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843';
  });
  await check('KAT · PBKDF2-HMAC-SHA512 (c=1)', async () => toHex(await pbkdf2Bits('password', enc.encode('salt'), 1, 'SHA-512', 512)) ===
      '867f70cf1ade02cff3752599a3a53dc4af34c7a669815ae5d513554e1c8cf252c02d470a285a0501bad999bfe943c08f050235d7d68b1da55e63f73b60a57fce');
  await check('KAT · BLAKE3("abc")', async () => toHex((await lib('blake3')).blake3(enc.encode('abc'))) ===
      '6437b3ac38465133ffb63b75273a8db548c558465d79db03fd359c6cd5bd9d85');
  for (const alg of Object.keys(ALGORITHMS)) {
    await check(`Round trip · ${ALGORITHMS[alg].label}`, async () => {
      let opts = { alg, name: 'selftest.txt' }, dopts = {};
      if (needsPassphrase(alg)) { opts.passphrase = dopts.passphrase = 'correct horse battery staple'; }
      else { const kp = await generateKeyPair(alg); opts.recipient = kp.publicBundle; dopts.secretBundle = kp.secretBundle; }
      const { envelope } = await encryptPayload(msg, opts);
      const { plain } = await decryptEnvelope(envelope, dopts);
      const tampered = envelope.slice(); tampered[tampered.length - 5] ^= 1;
      let rejected = false;
      try { await decryptEnvelope(tampered, dopts); } catch { rejected = true; }
      return ctEqual(plain, msg) && rejected;
    });
  }
  for (const alg of Object.keys(SIGNATURES)) {
    await check(`Sign / verify · ${SIGNATURES[alg].label}`, async () => {
      const kp = await generateKeyPair(alg);
      const sig = await signMessage(msg, kp.secretBundle);
      const good = await verifySignature(msg, sig, kp.publicBundle);
      const bad = await verifySignature(concat(msg, new Uint8Array([1])), sig, kp.publicBundle);
      return good.ok && !bad.ok;
    });
  }
  await check('Hash + verify · Argon2id', async () => { const h = await hashCredential('pässword', 'user_password', 'argon2id'); return await verifyCredential('pässword', h.stored) && !(await verifyCredential('password', h.stored)); });
  await check('Hash + verify · bcrypt', async () => { const h = await hashCredential('pässword', 'user_password', 'bcrypt'); return await verifyCredential('pässword', h.stored) && !(await verifyCredential('password', h.stored)); });
  for (const e of ['hmac-sha256', 'pbkdf2-sha512', 'blake3']) {
    await check(`Hash + verify · ${HASHERS.app_secret[e].label}`, async () => {
      const h = await hashCredential('hst_token', 'app_secret', e);
      return await verifyCredential('hst_token', h.stored, h.key) && !(await verifyCredential('hst_tokem', h.stored, h.key));
    });
  }
  return results;
}
```

### 5.14 `firestore.rules`

Ready-to-deploy Firestore rules: user isolation, organization role lock, one vote per user. Not used by the live site. (111 lines)

```js
rules_version = '2';

// Hastra: Cloud Firestore security rules.
//
// Hastra stores accounts, roles and community video votes in MySQL. These
// rules are ready for the day any of that is mirrored to Firestore (the client
// config is in assets/js/firebase-config.js); nothing in the site reads or
// writes Firestore today. Deploy with:  firebase deploy --only firestore:rules
//
// Model
//   users/{uid}                  one profile per Firebase Auth user
//     orgId     set only by trusted server code (Admin SDK): null = independent
//     role      the profile "focus" label; independents choose it themselves,
//               organization members can never change it from a client
//     orgRole   'member' | 'sysadmin' inside the organization (server only)
//   orgs/{orgId}/members/{uid}   organization roster; only that org's
//                                sysadmins may change a member's role
//   videoVotes/{topicId}         public tallies per syllabus topic
//     voters/{uid}               one vote per user per topic, with its direction

service cloud.firestore {
  match /databases/{database}/documents {

    // ── helpers ──────────────────────────────────────────────────────────
    function signedIn() { return request.auth != null; }
    function isSelf(uid) { return signedIn() && request.auth.uid == uid; }
    function profile(uid) { return get(/databases/$(database)/documents/users/$(uid)).data; }
    function isOrgSysadmin(orgId) {
      return signedIn()
        && exists(/databases/$(database)/documents/users/$(request.auth.uid))
        && profile(request.auth.uid).orgId == orgId
        && profile(request.auth.uid).orgRole == 'sysadmin';
    }
    // the 11 roles a person may choose (mirrors ASTRA_FOCUS_GROUPS in core/auth_check.php)
    function validRole(r) {
      return r in ['student_researcher', 'faculty_instructor', 'soc_analyst_l1', 'soc_analyst_l2',
                   'threat_intel_engineer', 'pqc_cryptographer', 'security_engineer',
                   'security_architect', 'penetration_tester', 'compliance_auditor', 'client_stakeholder'];
    }
    function validGender(g) { return g in ['male', 'female', 'prefer_not_to_say']; }
    function onlyChanges(keys) { return request.resource.data.diff(resource.data).affectedKeys().hasOnly(keys); }

    // ── user profiles: private to their owner ─────────────────────────────
    match /users/{uid} {
      allow read: if isSelf(uid);

      // created by the owner as an independent account; organization fields
      // are never self-assigned
      allow create: if isSelf(uid)
        && request.resource.data.keys().hasOnly(['displayName', 'gender', 'role', 'createdAt', 'orgId', 'orgRole'])
        && request.resource.data.get('orgId', null) == null
        && request.resource.data.get('orgRole', null) == null
        && (!('gender' in request.resource.data) || validGender(request.resource.data.gender))
        && (!('role' in request.resource.data) || validRole(request.resource.data.role));

      // independents may change name, gender and role; organization members
      // only name and gender (their role belongs to the organization's sysadmins)
      allow update: if isSelf(uid)
        && request.resource.data.get('orgId', null) == resource.data.get('orgId', null)
        && request.resource.data.get('orgRole', null) == resource.data.get('orgRole', null)
        && (!('gender' in request.resource.data) || validGender(request.resource.data.gender))
        && (resource.data.get('orgId', null) == null
              ? (onlyChanges(['displayName', 'gender', 'role']) && validRole(request.resource.data.get('role', 'student_researcher')))
              : onlyChanges(['displayName', 'gender']));

      allow delete: if isSelf(uid);
    }

    // ── organization roster: role changes by that org's sysadmins only ────
    match /orgs/{orgId}/members/{uid} {
      allow read: if isSelf(uid) || isOrgSysadmin(orgId);
      allow create, delete: if false;           // provisioning is server-side (Admin SDK)
      allow update: if isOrgSysadmin(orgId)
        && onlyChanges(['role'])
        && validRole(request.resource.data.role);
    }

    // ── community video votes: public tallies, one vote per signed-in user ─
    match /videoVotes/{topicId} {
      allow read: if true;
      allow write: if false;                    // tallies change only through the voter docs below
      match /videos/{videoId} {
        allow read: if true;
        // a signed-in user may move a tally by exactly one step, and only in
        // the same batch that writes their own voter record for this topic
        allow update: if signedIn()
          && onlyChanges(['likes', 'dislikes'])
          && (request.resource.data.likes - resource.data.likes) in [-1, 0, 1]
          && (request.resource.data.dislikes - resource.data.dislikes) in [-1, 0, 1]
          && existsAfter(/databases/$(database)/documents/videoVotes/$(topicId)/voters/$(request.auth.uid));
        allow create: if signedIn()
          && request.resource.data.keys().hasOnly(['likes', 'dislikes', 'title'])
          && request.resource.data.likes in [0, 1] && request.resource.data.dislikes in [0, 1];
        allow delete: if false;
      }
      match /voters/{uid} {
        allow read: if isSelf(uid);
        allow create, update: if isSelf(uid)
          && request.resource.data.keys().hasOnly(['videoId', 'dir', 'at'])
          && request.resource.data.dir in ['like', 'dislike', 'none']
          && request.resource.data.at == request.time;
        allow delete: if isSelf(uid);
      }
    }

    // everything else is closed
    match /{document=**} {
      allow read, write: if false;
    }
  }
}
```

