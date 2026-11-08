# Hastra spatial UI: design reference

The interface is "Quiet glass" (direction A in DIRECTIONS.md): a few glass layers float over the 3-D temple world, and everything inside them is flat. This file is the reference for anyone adding or changing a page. The component picks and their sources are in COMPONENTS.md, the research in REFERENCES.md, and the product context in PRODUCT.md at the repo root.

## Principles

1. **Glass only where something floats.** The page panel, the top bar, the dock, the drawer, dialogs, popovers and toasts are glass. Rows, list items, metrics, form groups and table cells are not.
2. **One reading surface per page.** Each page has one depth-2 panel. Sections inside it are a title row and flat content separated by hairlines and space, never a box inside a box.
3. **Depth comes from fill, edge and shadow, not from colour.** A layer higher in the stack is denser, has a brighter top edge and a longer, softer shadow. No glow halos, no coloured shadows.
4. **The world stays the world.** The temple scene is depth 0. By day it is softened and washed so glass never samples hard detail.
5. **Behaviour is untouched.** The spatial layer is CSS plus one small effects script. No PHP logic, routes, forms or scripts changed shape for it.

## The depth stack

| Depth | What | Fill (dark / light) | Blur | Shadow |
|---|---|---|---|---|
| 0 | The world: `#hastra-kage-scene` | scene, plus `--sp-world-wash` | by day `blur(6px)` on the scene itself | none |
| 1 | Insets: fields, tracks, wells | `--sp-fill-1` | none, ever | inner `--sp-inset` |
| 2 | The page panel | `--sp-fill-2` | 18px | `--sp-shadow-2` |
| 3 | Floating bar, raised controls | `--sp-fill-3` | 16px | `--sp-shadow-3` |
| 4 | Dock, drawer, dialogs, popovers, toasts | `--sp-fill-4` | 20px | `--sp-shadow-4` |

Every glass layer carries the specular edge `--sp-edge` (bright top, dim sides and bottom).

### Budget and rules

- Blur never exceeds 24px.
- At most three blurred layers in one viewport (usually: the bar, the panel, and the dock or one dialog).
- Never nest a backdrop-filter: `.sp-glass .sp-glass` loses its blur by rule.
- Dialog backdrops dim (`--sp-dim`) and do not blur; a blurred backdrop would be another full-screen blurred layer.
- `html.sp-ui body` uses `animation-fill-mode: backwards`. A held opacity animation on `<body>` makes it a backdrop root, and glass then samples the page instead of the scene (the old light-theme "mosaic").
- A backdrop-filter or transform on an element makes it the containing block for its `position: fixed` children. Never put glass on an element that holds a fixed sheet or menu; put the glass on a `::before` layer instead (the landing nav does this).

## Tokens

All tokens live in `assets/css/theme-spatial.css` part 1, on `html.sp-ui` (dark, the default) and `html.sp-ui[data-theme="light"]`.

| Group | Tokens | Notes |
|---|---|---|
| Type | `--sp-font-ui` (Onest), `--sp-font-mono` (Share Tech Mono), `--sp-fs-xs` to `--sp-fs-display` | self-hosted fonts only |
| Space | `--sp-1` to `--sp-16` (4px base), `--sp-gutter`, `--sp-panel-pad`, `--sp-bar-inset`, `--sp-bar-h` | |
| Radii | panel 28px, float 24px, card 16px, control 14px, small 10px, pill 999px | |
| Brand | `--sp-accent` (fills, marks, rings), `--sp-accent-text` (small accent text), `--sp-on-accent`, `--sp-ring` | crimson by night, cobalt by day |
| Text | `--sp-text`, `--sp-text-2`, `--sp-text-3`, `--sp-text-disabled` | white / black primary |
| Status | `--sp-ok`, `--sp-warn`, `--sp-bad`, `--sp-bad-solid`, `--sp-info` (plus `-rgb`) | text-safe on the panel |
| Lines | `--sp-hair`, `--sp-hair-strong`, `--sp-hover`, `--sp-press`, `--sp-row-hover` | |
| Fills | `--sp-fill-1` to `--sp-fill-4` (glass), `--sp-solid-1` to `--sp-solid-4` (dense, no blur) | |
| Motion | `--sp-ease-out`, `--sp-ease-spring`, `--sp-ease-press`, `--sp-dur-1` to `--sp-dur-4` | springs sampled into `linear()` |
| Effects | `--sp-mx`, `--sp-my`, `--sp-glow`, `--sp-tilt-max` (3deg) | written by `assets/js/spatial-fx.js` |
| Layers | `--sp-z-world` to `--sp-z-loader` | |

**Legacy bridge.** Part 1 also maps every older token (`--navy-card`, `--text-dim`, `--accent-bright`, `--bg-card`, `--hairline`, the auth-kit glass names and so on) onto spatial tokens, so inline page styles that still use them follow the system. Surface tokens map to the dense fills: an older box has no blur, so a translucent fill would put text straight on the scene.

## Contrast

Glass is translucent, so contrast is measured against the glass as it actually reaches the eye: the fill composited over the brightest the world gets behind it. The reference grounds are measured from the scene (see `tests/suites/15_spatial_ui.php` for the values and how they were sampled). Every text token, on every glass and dense fill, must reach 4.5:1 against that composite in both themes; white on the accent and danger fills must reach 4.5:1 too. The suite computes this on every run.

For a live page, `tests/tools/contrast-audit.js` has two functions: `hastraContrastAudit()` walks visible text, and `hastraGlassTokenAudit()` reads the computed tokens in both themes and reports every text-on-glass pair.

## Fallbacks

| Condition | What happens |
|---|---|
| `html.low-perf-mode` (assets/js/performance-guard.js) | blur off, fills switch to the dense set, pointer glow and tilt off |
| `prefers-reduced-transparency: reduce` | blur off, dense fills |
| `prefers-reduced-motion: reduce` | no tilt, no springs, no slide transitions; state changes are instant |
| `pointer: coarse` | no pointer glow or tilt; 44px touch targets |
| `forced-colors: active` | glass becomes `Canvas` with a `CanvasText` border; shadows and blur off |

## Components

The full set is in theme-spatial.css part 2 (sections 6 to 25) as `.sp-*` classes, and the live style guide is `docs/spatial-ui/styleguide.html`. In short:

- **Bar** (`.sp-bar`, the portals' `.topnav`): an island 12px from the edges, pill at desktop, 20px radius under 768px.
- **Dock and drawer**: depth-4 glass; the dock keeps its magnification from core/dock.js.
- **Panel** (`.sp-panel`, or the portal wrappers `.main`, `.wrap`, `.sc-main`, `.ht-terminal`, `.lx-main`): the page's one reading surface.
- **Metrics**: a strip of numbers with thin dividers, no capsules.
- **Buttons**: primary is a solid accent pill; secondary is a glass pill; ghost is text. Labels are sentence case.
- **Fields**: depth-1 insets with a 14px radius and the global 2px focus ring.
- **Pills and badges**: 999px, a 12% tint of the status colour, solid AA text.
- **Tabs and segmented control**: an inset track with a raised pill that slides (spatial-fx.js positions it).
- **Tables**: the panel is the surface; rows are flat with a hairline and a 4% hover tint.
- **Dialogs**: depth-4, 28px radius, a dim backdrop without blur.

## Craft rules (from the Impeccable craft floor)

No eyebrow labels, no numbered section chips, no coloured side rules, no glow halos, no gradient text, no grid backgrounds, sentence-case labels and buttons. `impeccable detect --json <files>` reports zero findings on every file the rollout touched.

## Files

| File | Role |
|---|---|
| `assets/css/theme-spatial.css` | the whole system: tokens and bridge (part 1), components (part 2), shared chrome (part 3), auth (part 4), portals (part 5), per-group sections (part 6) |
| `assets/css/landing-spatial.css` | the same recipe for the landing page chrome, with its own `--lsp-*` tokens (the landing page keeps its own variables and does not load theme-spatial.css) |
| `edge/src/styles/spatial.css` | the same tokens for the React pilot on Tailwind v4, mapped onto Watermelon UI's colour names; the components are in `edge/src/components/watermelon/` (see COMPONENTS.md) |
| `assets/js/spatial-fx.js` | pointer glow, 3-degree tilt on quick links and dashboard cards, tab and segment indicators |
| `core/theme.js` | adds `html.sp-ui`, applies the theme before first paint, mounts spatial-fx.js |
| `assets/css/sidebar-nav.css`, `core/dock.css` | the bar, drawer and dock |

Every themed page links `theme-spatial.css` last in its `<head>`, so it follows each page's own styles in the cascade.

## Adding a page

1. Link `core/theme.css`, `assets/css/theme-contrast.css`, your page styles, then `assets/css/theme-spatial.css` last.
2. Wrap the content in one panel (`.sp-panel`, or a portal wrapper that already is one).
3. Use the components above; write inline styles only with `--sp-*` tokens.
4. Check both themes at 390, 768 and 1440, with low-perf mode on, and run the suite.
