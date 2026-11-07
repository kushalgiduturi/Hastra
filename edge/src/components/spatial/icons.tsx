// Authored icons: 24px grid, 1.75 stroke, round caps, currentColor.
import type { SVGProps } from "react";

const base = (p: SVGProps<SVGSVGElement>) => ({
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 1.75,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
  "aria-hidden": true,
  ...p,
});

export const IconHome = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><path d="M4 10.5 12 4l8 6.5V19a1 1 0 0 1-1 1h-4.5v-5.5h-5V20H5a1 1 0 0 1-1-1z" /></svg>
);
export const IconUsers = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><circle cx="9" cy="8.5" r="3.25" /><path d="M3.5 19.5c.6-3 2.8-4.75 5.5-4.75s4.9 1.75 5.5 4.75" /><path d="M15.5 5.6a3.25 3.25 0 0 1 0 5.8M17 14.9c1.9.5 3.1 2 3.5 4.6" /></svg>
);
export const IconLedger = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><rect x="5" y="3.5" width="14" height="17" rx="2" /><path d="M8.5 8h7M8.5 12h7M8.5 16h4" /></svg>
);
export const IconSun = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><circle cx="12" cy="12" r="4" /><path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4" /></svg>
);
export const IconMoon = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10z" /></svg>
);
export const IconSignOut = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><path d="M14 4.5H6.5a1 1 0 0 0-1 1v13a1 1 0 0 0 1 1H14" /><path d="M10.5 12h10M17 8.5l3.5 3.5-3.5 3.5" /></svg>
);
export const IconShieldCheck = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><path d="M12 3.5 19 6v5.5c0 4.2-2.9 7.6-7 9-4.1-1.4-7-4.8-7-9V6z" /><path d="m9 12 2.2 2.2L15.5 10" /></svg>
);
export const IconAlert = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5v5.5M12 16.4v.1" /></svg>
);
export const IconArrowRight = (p: SVGProps<SVGSVGElement>) => (
  <svg {...base(p)}><path d="M5 12h14M13.5 6.5 19 12l-5.5 5.5" /></svg>
);
