# Spatial UI references

## Deviation from the brief: no Mobbin

The brief (tool set A) asks for Mobbin screen research cited by `mobbin_url`. The owner dropped Mobbin on 2026-11-07 because the connected account needs a paid plan ("Mobbin MCP requires a paid plan"). This file is therefore built from:

1. the Watermelon UI catalogue, reached through its MCP server (`https://mcp.watermelon.sh/mcp`, tools `search`, `get_inspiration`, `get_component`), and
2. the design-language references already in the repo under `.claude/skills/awesome-design-md`.

No other product's branding, copy or assets are taken. What is taken is treatment: how a surface separates from what is behind it, how depth is expressed, and how motion behaves.

## What the research says about glass

Most catalogue entries that call themselves glass are opaque white or zinc cards with a hairline and a soft shadow. Real translucency is rare and, where it exists, is used sparingly: one floating bar, one dialog. That matches the restraint note in SPATIAL_UI_PROGRESS.md and drives the recommended direction: glass is reserved for things that float (nav, dock, dialogs, popovers, the main page panel), and the content inside a panel is flat.

## References and what each contributed

| Source | Entry | Taken | Not taken |
|---|---|---|---|
| Watermelon `blocks/navigation-5` | https://ui.watermelon.sh/block/navigation-5 | The island bar: a single floating pill with logo, actions and a menu trigger, separated from the page by a gap instead of a full-width edge. The mega menu is a second island that docks under the bar with a large radius (2.5rem). | Full-width mega menu content, marketing CTA. |
| Watermelon `components/dialog-1` | https://ui.watermelon.sh/components/dialog | Dialog recipe: 24px radius, 95% fill with a medium blur so text never depends on what is behind it, pill primary action next to a quieter cancel. Confirms that a dialog over live content should be nearly opaque. | Zinc palette (we keep crimson and cobalt). |
| Watermelon `animated-components/dock` | https://ui.watermelon.sh/animated-components/dock | Spring constants for press feedback (stiffness 550, damping 15, mass 1.1: a short overshoot), the selected dot under the active icon, 24px radius on the tray. | Its hover effect is a lift, not magnification. Hastra keeps the distance-based magnification in core/dock.js. |
| Watermelon `animated-components/fluid-tabs` | https://ui.watermelon.sh/animated-components/fluid-tabs | Segmented control with a sliding pill indicator (spring stiffness 280, damping 25), active label weight change, top-lit gradient on the pill. | The blur flash on the label when it becomes active (reads as a glitch over the scene). |
| Watermelon `animated-components/activities-card` | https://ui.watermelon.sh/animated-components/activities-card | A raised header strip on a card: the header sits one depth step above the body. Useful for section title rows. | Expand/collapse choreography. |
| Watermelon `dashboards/astrix-dashboard`, `agndex-dashboard`, `tallie-dashboard` | https://ui.watermelon.sh/dashboard/astrix-dashboard (and siblings) | Composition: one page panel holding a header row, a metric strip and a table; metrics as plain numbers in a row rather than four separate boxes; tables with a single container and quiet row dividers. All three ship light and dark themes designed together. | Sidebars that are always open (Hastra keeps the drawer). |
| Watermelon `components/data-table-1` and `-10` | https://ui.watermelon.sh/components/data-table | Row checkbox column, right-aligned numerals, pagination row inside the table container. Container gets the surface, rows do not. | Nothing else. |
| Watermelon `components/button-9`, `button-25` | https://ui.watermelon.sh/components/button | Loading states that keep the button width stable (label plus dots, or spinner then result). | Colour-coded result states (we use the existing status tokens). |
| Watermelon `animated-components/inline-toast` | https://ui.watermelon.sh/animated-components/inline-toast | Toast that grows from the trigger instead of a corner, for copy confirmations. | Corner toasts stay where hastra-ux.js puts them. |
| `.claude/skills/awesome-design-md` (Apple, Linear, Vercel entries) | local | Depth through light rather than lines: a top specular edge plus a shadow that grows with elevation; very few borders; type scale doing the hierarchy. | Their fonts and colours. |

## Rules carried into the design

- Glass only where something floats. Inside a panel, rows, list items and cards are flat (no blur, at most a 4% tint on hover).
- A dialog or popover is nearly opaque (90 to 96% fill). Translucency is for chrome, not for reading.
- One top specular edge per surface, not a full outline.
- Springs are short: 150 to 350ms, one overshoot at most.
