// The Hastra mark, as drawn in the PHP app's portal bars: crimson by night,
// cobalt by day (the gradient swaps with the theme in CSS).
import { useId } from "react";

export function BrandMark({ className = "size-7" }: { className?: string }) {
  const id = useId().replace(/:/g, "");
  return (
    <svg viewBox="0 0 48 48" className={className} aria-hidden="true">
      <defs>
        <linearGradient id={`${id}-g`} x1="4" y1="45" x2="45" y2="3" gradientUnits="userSpaceOnUse">
          <stop offset="0" style={{ stopColor: "var(--sp-brand-a, #7a1224)" }} />
          <stop offset="1" style={{ stopColor: "var(--sp-brand-b, #e11d3c)" }} />
        </linearGradient>
      </defs>
      <path fill={`url(#${id}-g)`} d="M8 5H16V43H8V5ZM32 5H40V43H32V5ZM4 21H44V27H4V21Z" />
      <path fill={`url(#${id}-g)`} d="M24 15L30 24L24 33L18 24Z" />
    </svg>
  );
}

export function Brand() {
  return (
    <span className="flex items-center gap-2.5">
      <BrandMark />
      <span className="text-[15px] font-semibold tracking-[-0.01em] text-foreground">Hastra</span>
    </span>
  );
}
