# Spatial UI progress

Brief: `SPATIAL_UI_PROMPT.md` (untracked, repo root). Branch: `spatial-ui`. Never push, never merge.

## Current phase

**Phase 1 done, waiting for the owner's pick of direction** (the brief says to show the Phase 0 inventory and the Phase 1 direction, then proceed). Recommended: A, Quiet glass (`docs/spatial-ui/DIRECTIONS.md`). Next: Phase 2 tokens in `assets/css/theme-spatial.css`.

## Tool status (2026-11-07)

| Tool | Status |
|---|---|
| Watermelon UI | MCP server registered by the owner and connected (`search`, `get_inspiration`, `get_component` used). Skill in `.claude/skills/watermelon-ui`. |
| Impeccable 4.8.0 | Launcher works. `teach` (init) done: PRODUCT.md written from a one-round interview (main users: delivery team; stage: real product, pre-launch; positioning: secure by default). No automatic detector hook: run `impeccable detect --json <files>` after UI edits. |
| Mobbin | Dropped by the owner (paid plan required). REFERENCES.md says so. |
| Playwright | Global `@playwright/cli` bundle; its expected browser build (1246) is missing, so scripts pass `executablePath` to `ms-playwright/chromium_headless_shell-1248`. |

## Phase 0 results

- Test baseline on `spatial-ui` (no source changes yet): **310 assertions, PASS 310, FAIL 0** (82s).
- Inventory: `docs/spatial-ui/INVENTORY.md` (63 rendered pages, 7 partials, 6 markup-less endpoints; 50 pages carry inline `<style>`, about 8,400 lines). The brief's "73 PHP pages" counted partials and endpoints differently; the inventory lists every file.
- Baseline screenshots: `docs/spatial-ui/baseline/<theme>-<width>/` (dark and light at 1440, 768, 390). Gitignored on purpose: they show local DB names and emails.
  - Public pages (auth, legal, 404, landing, labs/auth) shot live from Apache.
  - Signed-in pages rendered through the test harness (myapp_test clone, fixture accounts, php-cgi, same mechanism as tests/runner.php) into static HTML under `C:\xampp\htdocs\_spatial_snap\portals\` with a `<base>` tag, then shot. The `/portals/` path matters: core/theme.js only applies the portal theme on that path.
  - Not captured (need params or account states the fixtures lack): admin doc_editor (404 without a doc id), client project_view and signoff (redirect with the ids tried), client team (needs an IT manager), newuser_portal (redirects for a profiled user).
  - Scripts lived in the session scratchpad; recreate from this description if needed.

## Test accounts

Owner asked for self-made local test credentials. One account per role (sysadmin, admin, employee, client) created in the local dev DB `myapp`, mirroring `tests/lib/fixtures.php`. Credentials: `.local/test-accounts.md` (gitignored; Apache returns 403 for it). Sign-in still needs the real reCAPTCHA and an emailed OTP, so these are for the owner's manual review; screenshots use the harness instead. No session files were written by hand.

## Decisions

- **Mobbin dropped** (owner). REFERENCES.md built from Watermelon plus `.claude/skills/awesome-design-md`.
- **Direction A, Quiet glass, recommended**: glass only where something floats, flat content inside, max 3 blurred layers, neutral sparse glow, 3-degree tilt on quick links only.
- **Light-theme mosaic root cause found**: body's filling opacity animation makes body a backdrop root, so glass samples the grid instead of the scene iframe. Fix in Phase 2 (see DIRECTIONS.md, Findings).
- Impeccable craft floor applies except where the brief pins otherwise (brief wins on glass and on 24-32px panel radii). The "EPISODE 0N" eyebrow chips go.
- `master` stays boxless (and its tests pin that) until this branch is merged.

## Environment notes

- Start MySQL and Apache before tests or screenshots:
  `C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone` and `C:\xampp\apache\bin\httpd.exe` (background).
- Tests: `C:\xampp\php\php.exe -d output_buffering=0 tests\runner.php`.
- The shell tool collapses `\\` in strings; write Python helpers to files instead of heredocs when backslashes matter.

## Files done

- PRODUCT.md, docs/spatial-ui/INVENTORY.md, REFERENCES.md, COMPONENTS.md, DIRECTIONS.md, mocks/dashboard.html, mocks/shots/*.png
- .gitignore: `.local/` and `docs/spatial-ui/baseline/`

## Files remaining

Phase 2 onward, per the brief.

## Open issues

- Baseline bug to fix during rollout: in light theme the admin portal's audit-ledger copy is nearly invisible.
- `C:\xampp\htdocs\_spatial_snap\` holds harness-rendered HTML with local data; delete it after Phase 4 comparisons.
