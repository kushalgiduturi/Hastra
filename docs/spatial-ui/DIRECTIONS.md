# Spatial UI: Phase 1 directions

One mock, three directions: `docs/spatial-ui/mocks/dashboard.html?dir=a|b|c&theme=dark|light` (open it at http://localhost/Hastra/docs/spatial-ui/mocks/dashboard.html). It shows the admin dashboard over the real temple scene, loaded the same way core/theme.js mounts it (`landing-pages/hastra.html?scene=bg`). Screenshots are in `mocks/shots/` (1440 wide for all three, 390 wide for A). Counts, IDs and client names in the mock are illustrative.

All three share the brand palette (dark: white type, crimson #e50914, small accent text #ff4655; light: black type, cobalt #2563eb), Onest for UI, Share Tech Mono only for IDs and timestamps, the island top bar and the dock ornament.

| | A. Quiet glass (recommended) | B. Layered stack | C. Plate and ornaments |
|---|---|---|---|
| Idea | One glass page panel per page. Everything inside it is flat. Glass only where something floats. | Every section is its own floating glass panel; quick links are raised glass tiles. Closest to visionOS. | Content sits on a near-solid plate; glass is reserved for the bar, dock and popovers. |
| Depth scale | 0 world, 1 inset (inputs, tracks), 2 page panel, 3 bar and raised controls, 4 dock, dialogs, popovers | same five levels, more surfaces at level 2 and 3 | same levels, level 2 nearly opaque |
| Glass recipe, dark | panel `rgba(10,11,16,.64)` blur 18px saturate 1.35; bar .72 blur 16; dialogs .86 blur 20 | panel .50 blur 24 saturate 1.6; tiles .62 blur 20 | plate .90 blur 8; bar .60 blur 18 |
| Glass recipe, light | panel `rgba(255,255,255,.74)` saturate 1.6; bar .80; dialogs .92 | panel .66; tiles .78 | plate .94; bar .66 |
| Edge and shadow | 1px top specular inset plus a dim bottom inset; one soft offset shadow per level, no outline | brighter edge, deeper shadows | faint edge, single shadow |
| Radii | panel 28, controls 14, pills 999, dock and dialogs 24 | panel 32, controls 18 | panel 24, controls 14 |
| Pointer light | faint white glow on the page panel, crimson or cobalt never used for glow | accent glow on every panel | none |
| Tilt | 3 degrees, quick links only (they are navigation targets) | 6 degrees on all tiles | none |
| Motion | 150 to 350ms; `--sp-ease-spring` as CSS `linear()` with one small overshoot; ease-out for everything else | same curves, more moving parts | ease-out only |
| Blurred layers in a viewport | 3 (bar, panel, dock) | 5 to 7: over budget | 3, with the panel blur barely doing work |
| Risk | Least risk of the "AI-made boxes" look the owner rejected before, while still clearly spatial | Highest spatial feel, but reads as a grid of cards and breaks the 3-layer blur budget | Most legible and cheapest, but barely spatial in light theme, where it looks like a plain white card |

## Recommendation: A, Quiet glass

- It is the only direction that meets the performance non-negotiable as drawn: at most three overlapping blurred layers, no nesting, no blur on rows.
- It honours the restraint note: glass where something floats, flat content inside, glow sparse and neutral, tilt only where it means "this goes somewhere".
- It answers Impeccable's craft floor: no grid of same-size cards, no eyebrow chips (the old "EPISODE 0N" labels go), no colored glow halos, numbers as a strip instead of four boxes.
- Protection stays visible but quiet: the audit ledger becomes a calm green-tinted strip with a clear verify action instead of hidden copy (in today's light theme that copy is nearly invisible, see baseline `light-1440/admin__admin_admin_portal.png`).

Continuing with A in Phase 2 unless the owner picks otherwise.

## Findings that shape Phase 2

1. **Why light-theme glass looked like a mosaic.** `body { animation: pageFadeIn .35s ease both }` (core/theme.css) keeps an opacity animation filling on `<body>` for the life of the page. Chromium treats that body as a backdrop root, so any backdrop-filter inside the page can only sample the page itself (the 40px grid on `body::before`), never the scene iframe, which is a sibling of body (kage-scene.js inserts it before `<body>`). Blurring a hard grid gives the mosaic. Fix in Phase 2: fill mode `backwards` (nothing holds after the fade), no hard grid under glass, and the scene frame itself softened in light theme. Verify at 100% and 200% zoom.
2. **Portal theming keys off the URL.** core/theme.js adds `nf-portal` only when the path matches `/(portals|workspace|labs)/` or `/dashboard`. `html.sp-ui` should be set from the same place, for portals, auth, labs, legal and 404, replacing the nf-portal loading path.
3. **hastra-ux.css is appended after the portal stylesheets** by theme.js, so it currently wins ties. theme-spatial.css has to load after it (or hastra-ux rules move into the spatial file during 4a).
4. **Font token mismatch.** core/theme.css's `--font-sans` comment still says "system UI stack" while fonts.css self-hosts Onest. The spatial tokens set Onest for UI explicitly.
