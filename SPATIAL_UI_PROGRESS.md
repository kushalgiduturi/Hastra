# Spatial UI progress

Brief: `SPATIAL_UI_PROMPT.md` (untracked, repo root). Branch: `spatial-ui`. Never push, never merge.

## Current phase

**Phase 4d done (admin portal).** Next: 4e employee portal (employee_portal, my_tasks, team_lead, testing_portal, deployment_portal).

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

## Phase 3 notes

- theme-spatial.css part 2: every component in the brief's list as opt-in `.sp-*` classes (live pages are unchanged until phase 4 adds the classes). `.is-hover` / `.is-focus` / `.is-active` force states for the guide only. Nested glass loses its blur by rule, so backdrop-filter can never nest.
- assets/js/spatial-fx.js: pointer light, tilt for [data-sp-tilt], and the sliding indicators of .sp-seg / .sp-tabs (MutationObserver on aria-selected). Delegated, passive, rAF. Glow and tilt off under reduced motion, coarse pointer, low-perf. Not yet loaded by core/theme.js (that is phase 4a).
- docs/spatial-ui/styleguide.html: static, not routed; mounts the temple world exactly like the portals (landing-host.js + kage-scene.js, sky-only), `?theme=light`, `?perf=low`.
- Impeccable: detector run on theme-spatial.css, spatial-fx.js, styleguide.html. Fixed: coloured glow shadows on accent controls and the current step (now neutral elevation), white on coral critical pill (new --sp-bad-solid), disabled text contrast, bouncy entrances (dialog/toast now ease-out; spring kept for indicators, switch and press), icon tile above the empty-state heading. Left as intentional: cramped-padding flags on hairline dividers (tabs, metrics, accordion), file chips and the panel (false positive). Two findings come from hastra-atmosphere.css (legacy, phase 4a/4i).
- Impeccable extract/critique/polish as separate commands not run yet: the loop is planned per surface group in phase 4.

## Phase 4a notes

- Top bar (all 7 topnavs), drawer and dock carry `sp-glass sp-d3/d4`; sidebar-nav.css, core/dock.css, tour.css and profile-barrier.css rewritten on spatial tokens (ids, hooks and JS unchanged; dock magnification untouched). Bar is a sticky island, so no page needed extra padding.
- theme-spatial.css part 3 layers the loader, hastra-ux dialogs/palette/fabs/copy/skip link and the consent bar under html.sp-ui; their base files still serve the landing page, so old rules there go in 4i.
- Fixed a phase 2 regression: the loader painted var(--navy), which the bridge maps to transparent.
- `.sp-glass` positioning moved to `:where()` (zero specificity) so fixed/sticky chrome keeps its own position.
- Deleted 95 lines of legacy chrome rules (theme.css light topnav/dock/nav, theme-netflix top bar, drawer, dock).
- core/theme.js now loads assets/js/spatial-fx.js on every themed page.
- Test changed: 06_governance barrier test (see docs/spatial-ui/TEST-CHANGES.md). Suite 310/310. Impeccable detector: 0 findings on the chrome files.
- The long-dash flag was a false positive: an exact Unicode check over every spatial commit finds no en or em dashes (the shell grep matched the box-drawing characters in comment headers).
- Light theme portals show the washed daylight scene more strongly than the baseline did (the veil is present; the baseline likely shot before the scene went live). Re-check when 4c-4f put content on the glass panel.

## Phase 4b notes

- All eight auth cards carry `sp-glass sp-auth-card`; login and register overviews swap `text-fog-scrim` for `sp-glass sp-auth-info`. theme-spatial.css part 4 restyles card, brand, headings, fields, OTP boxes, actions, alerts, step progress, password rules, success screens, the in-card theme switch, the overview, the two-portal switch and registration tabs (segmented), registration steps, navbar preview, logo tiles and drop zone, the Google button, the legal footer strip and the shared ax-select / ax-range controls.
- Deleted the identical 84-line AuthKit !important block from six simple auth pages (45 !important each, now 0) and the card-visual half of the login and register blocks (kept their stage, slider, cursor and layout rules). Removed the dead 40px grid backgrounds and a bouncy icon easing from all auth pages.
- Global light-theme input, OTP and attempt-dot rules in core/theme.css (all !important) now skip anything inside `.sp-glass` via `:not(.sp-glass *)`, so unmigrated pages keep them and spatial surfaces do not fight them. compliance.css lost the Google button !important override (only existed to beat the deleted AuthKit rules).
- register.php: the stacked (<=1000px) overview is no longer sticky, so the form card does not slide over it.
- The intro sequence on login is long; screenshots need about 6 s before the card has fully emerged.
- OTP and forgot-OTP screens were checked through the harness (session with a pending code); reset, set_password and verify_register need token state and were not shot.
- Test changed: 08_ui auth hero test (docs/spatial-ui/TEST-CHANGES.md). Suite 310/310. Detector: only two hidden-image false positives on register (no src until a file is chosen).

## Phase 4c notes

- netflix-bento.css is no longer loaded (core/theme.js loads only theme-netflix). Its job is done by theme-spatial.css part 5: `.main` / `.wrap` is each portal page's one depth-2 glass panel; sections, metrics (strip), quick-link tiles (tilt via spatial-fx), pills, tables (flat rows), toolbars, list rows, fields, modals and the portal cursor (no glow) are styled there. The world wash replaces the veil and the body glow.
- theme-netflix.css lost its 112-line !important component layer (gradient titles, underlined fields, mono uppercase labels/buttons, outlined danger, tabs, alerts, modals, ambient glow); tokens, legacy mapping, scrollbar, overflow guard and brand fill stay.
- core/theme.css: 57 structural light-theme rules (cards, sections, stat cards, quick-links, table rows, filter bars, the sysadmin light-fix section) are guarded with `html:not(.nf-portal)` so they skip spatial portals.
- Part 6: shared portal controls (save/primary, secondary/clear/more/back, delete/danger, modal confirm/cancel, alerts and notes without side rules, company chips, grouped tables) plus sysadmin specifics (security dashboard strip and control rows, migration steps and console, insights segmented range and quiet actions). Gooey search and custom dropdowns (cd-*) restyled globally.
- Dead generic inline rules (grid backgrounds, boxed shell, stat cards, table base) deleted from sysadmin_portal, roles and logs.
- This layer touches every portal page at once. Groups 4d-4g still need their own inline styles migrated (example: admin project_portal project blocks are still boxed in light theme, section titles uppercase).
- delete_user.php is an endpoint (no markup); nothing to restyle.
- Test changed: 12_netflix_portals stack test (TEST-CHANGES.md). Suite 310/310. Detector: 0 findings on the 4c files.

## Phase 4d notes

- Dead generic inline rules stripped from 12 admin pages (scratchpad helper `strip_dead.py`: grid backgrounds, boxed shell, stat cards, table base, section nav).
- Shared button groups in part 6 now include the admin action classes (create, save review/attendance/policy, confirm/add member, expand/toggle, nav, secret, ledger, approve/clarify/retest/reject, remove row/member).
- Part 6 sections 49-50: sentence-case labels and small headings in portal panels, neutral mono ID chips, the audit ledger as the protection strip (fixes the baseline light-theme contrast bug), flat request/project/review cards, add-member row, dropzone, webhook secret, attendance cells and month nav.
- Retired the old per-page plate: `portal-surface-scrim` removed from the four dashboards and its block (with !important light tiles, ember glows and a global gradient confirm button) deleted from hastra-atmosphere.css.
- core/theme.css: the light input/OTP/attempt-dot exclusion now also skips portal panels and modals (`:not(.sp-glass *, .nf-portal :is(.main, .wrap, .modal) *)`), and 21 more structural light rules (project/request/bug/team cards and headers) skip spatial portals.
- doc_editor was not captured (needs a doc id); it inherits the portal layer.
- Test changed: 10_visual_architecture plate test (TEST-CHANGES.md). Suite 310/310. Detector: 0 findings.

## Files done

- PRODUCT.md, docs/spatial-ui/INVENTORY.md, REFERENCES.md, COMPONENTS.md, DIRECTIONS.md, mocks/dashboard.html, mocks/shots/*.png
- .gitignore: `.local/` and `docs/spatial-ui/baseline/`
- Phase 2: assets/css/theme-spatial.css (part 1), core/theme.js (sp-ui class), spatial link in 53 pages
- Phase 3: theme-spatial.css part 2, assets/js/spatial-fx.js, docs/spatial-ui/styleguide.html, styleguide-shots/

## Files remaining

Phase 2 onward, per the brief.

## Open issues

- Baseline bug to fix during rollout: in light theme the admin portal's audit-ledger copy is nearly invisible.
- `C:\xampp\htdocs\_spatial_snap\` holds harness-rendered HTML with local data; delete it after Phase 4 comparisons.
