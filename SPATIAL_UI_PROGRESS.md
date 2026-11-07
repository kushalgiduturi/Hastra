# Spatial UI progress

Brief: `SPATIAL_UI_PROMPT.md` (untracked, repo root). Branch: `spatial-ui`. Never push, never merge.

## Current phase

**Phase 2 done (tokens, fallbacks, wiring).** Owner picked direction A, Quiet glass, on 2026-11-07. Next: Phase 3, the component library (theme-spatial.css part 2), assets/js/spatial-fx.js and docs/spatial-ui/styleguide.html, then STOP for the owner's approval before any rollout.

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

## Phase 2 notes

- `assets/css/theme-spatial.css` part 1: depth scale, glass fills/blur/saturate per depth, specular edge and shadow stacks, radii, spacing and type scale (Onest UI, Share Tech Mono for IDs), motion tokens (springs as CSS `linear()`), pointer-glow and tilt variables, z-index map, legacy token bridge, and fallbacks for low-perf, reduced transparency, reduced motion, coarse pointer and forced colours.
- Legacy surface tokens (`--navy-card`, `--bg-card`...) map to DENSE fills, not glass: an unmigrated box has no blur, so translucency would put text on the scene. `--gradient-headline` maps to a flat text-colour paint (headlines clip to it; `none` made them vanish).
- Mosaic fix: `html.sp-ui body { animation-fill-mode: backwards }`.
- Wiring: core/theme.js always adds `html.sp-ui` before first paint; the 53 pages that link theme-fog-boxless.css now also link theme-spatial.css as the last element in `<head>` (theme.js sits above every stylesheet, so anything it injected would load first, not last). 404.php is standalone by design (no scripts) and is handled in phase 4i.
- Deliberate sequencing choice: the brief's phase 2 says to replace the nf-portal / netflix-bento loading path, but removing it before the components exist would strip every portal back to the old boxed core/theme.css look and break the boxless tests mid-branch. Those files stay loaded and their rules are deleted surface by surface in phase 4 (non-negotiable 3), with the files themselves removed in phase 6.
- Tests after phase 2: 310 / 310 pass.

## Files done

- PRODUCT.md, docs/spatial-ui/INVENTORY.md, REFERENCES.md, COMPONENTS.md, DIRECTIONS.md, mocks/dashboard.html, mocks/shots/*.png
- .gitignore: `.local/` and `docs/spatial-ui/baseline/`
- Phase 2: assets/css/theme-spatial.css (part 1), core/theme.js (sp-ui class), spatial link in 53 pages

## Files remaining

Phase 2 onward, per the brief.

## Open issues

- Baseline bug to fix during rollout: in light theme the admin portal's audit-ledger copy is nearly invisible.
- `C:\xampp\htdocs\_spatial_snap\` holds harness-rendered HTML with local data; delete it after Phase 4 comparisons.
