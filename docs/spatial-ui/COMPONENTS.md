# Spatial UI components: picks and ports

Hastra is plain PHP with no build step, so Watermelon's React + Tailwind + Motion sources are read, not installed. Each pick below is translated into vanilla CSS on our tokens (theme-spatial.css) and, where behaviour is needed, a few lines in assets/js/spatial-fx.js. Motion springs become CSS `linear()` easing curves sampled from the same stiffness, damping and mass, with a cubic-bezier fallback. React sources are used directly only in the optional edge/ phase.

| Hastra component | Watermelon source | Port plan |
|---|---|---|
| Floating top bar | `blocks/navigation-5` | `.topnav` becomes an island: inset 12px from the viewport edges, radius 999px at desktop and 20px under 768px, depth-3 glass. Keeps brand link, theme toggle, avatar and hamburger with their ids. |
| Sidebar drawer | `animated-components/macos-sidebar` (selection feedback only) | Existing drawer from sidebar-nav.js, restyled as a depth-4 glass sheet with 28px inner radius. Active link gets a filled pill, not a border. No width spring (drawer slides). |
| Dock ornament | `animated-components/dock` | Tray: 24px radius, depth-4 glass, selected dot under the current item. Press: `--sp-ease-press` (stiffness 550, damping 15, mass 1.1). Magnification stays in core/dock.js unchanged. |
| Page panel and sections | `dashboards/astrix-dashboard` composition, `animated-components/activities-card` header | `.sp-panel` depth-2 glass holds the page. Section title row is depth-2.5: same fill, plus a hairline and the specular edge, no extra blur. |
| Stat cards | `blocks/stats-1` and dashboard metric strips | Metrics become a strip of numbers inside the panel with thin vertical dividers. Optional `[data-sp-tilt]` on clickable metrics only. |
| Buttons | `components/button-9`, `button-25` | Primary: solid accent pill, top highlight, depth-3 shadow on hover. Secondary: glass pill. Ghost: text only. Loading keeps width (spinner replaces icon). |
| Tabs and segmented control | `animated-components/fluid-tabs` | Track is a depth-1 inset; the active pill is depth-3 with the top-lit gradient and slides with a CSS transition on `translate` driven by a CSS variable set in spatial-fx.js. Existing tab ids and roles kept. |
| Modal and confirm dialog | `components/dialog-1`, `animated-components/dialog-stack` | depth-4, 94% fill, blur 20px, 28px radius, backdrop dim 40% with no blur (backdrop blur would be a second large blurred layer). Pill primary, quiet cancel. |
| Data table | `components/data-table-1`, `-10` | Container is the glass (or inherits the panel); rows are flat with a hairline divider; hover tints the row 4%. Sticky header gets depth-3 fill without blur. |
| Toast | `animated-components/inline-toast` | Copy-button confirmations grow inline; hastra-ux.js corner toasts become depth-4 pills. |
| Badges, status and priority pills | `components/badge-8` | 999px pills, 12% tinted fill from the status token, solid text colour that passes AA on the panel fill. |
| Inputs, select, custom dropdown | `animated-components/floating-input` (focus treatment only) | Inputs are depth-1 insets (darker by night, whiter by day) with a 14px radius; focus keeps the global 2px ring. Dropdown menus are depth-4 popovers. |
| Empty state | none suitable in the catalogue | Own design: icon, one line, one action, centred in the panel, no box. |
| Pointer glow and tilt | none suitable as React source | Own vanilla module (spatial-fx.js): one delegated pointermove listener, rAF-throttled, writes `--sp-mx` / `--sp-my` on the hovered `.sp-panel` or `[data-sp-tilt]`, tilt capped at 3 degrees. Off under low-perf, reduced motion and coarse pointers. |

Watermelon gaps worth noting: no true backdrop-filter recipes beyond dialog-1, no pointer-glow or tilt component, no empty state. Those are designed here rather than ported.

## Edge pilot (phase 5): used directly

The React pilot in `edge/` installs the sources rather than porting them (Tailwind v4, Radix Slot and Label, class-variance-authority, Motion, input-otp). They live in `edge/src/components/watermelon/`, each file naming its registry source, restyled through the token mapping in `edge/src/styles/spatial.css`.

| Pilot use | Watermelon source | Changes |
|---|---|---|
| Every button | registry `button` | Pills in sentence case: accent primary, glass secondary, text ghost. A `loading` prop. |
| Busy state | `components/button-9` | Three dots after the label; a staggered fade instead of the original bounce (bounce easing is off the craft floor). |
| Status and severity pills | registry `badge`, `components/badge-8` | Status variants (ok, warn, destructive, info) as a 12% tint with solid AA text. |
| Field labels | registry `label` | Token colours. |
| Email, password, name fields | `animated-components/floating-input` | The field is a depth-1 inset and the label rises inside it; a notch cut into a border cannot sit on translucent glass. |
| Sign-in and reset codes | `components/input-otp-10` with the input-otp primitives | Controlled, digits only, six inset slots with the accent dot between the halves; slots shrink under 640px. |
| Roles and audit tables | registry `table`, `components/data-table-1` (header and row treatment) | No box of its own: the panel is the surface, rows flat with hairlines, tabular numbers. |
| Portal section switcher | `animated-components/fluid-tabs` | Router links, a depth-3 pill on a depth-1 track, authored SVG icons in place of react-icons, `MotionConfig reducedMotion="user"`. `outline-none` removed so keyboard focus shows. |

