// Segmented switches on the Fluid Tabs pill (spring slide on a depth-1
// track, reduced motion respected): a tablist with roving tabindex and
// arrow, Home and End keys.
//   PortalTabs: Enterprise Workspace or Client Gateway, the same two portals
//   as the PHP app's auth/login.php and auth/register.php.
//   Segmented: any other two-or-more way choice (register's Company or
//   Individual / Freelancer).
import { useRef } from "react";
import { motion, MotionConfig } from "motion/react";

export type Portal = "enterprise" | "client";

export const PORTAL_TABS: { id: Portal; label: string }[] = [
  { id: "enterprise", label: "Enterprise Workspace" },
  { id: "client", label: "Client Gateway" },
];

export function Segmented<T extends string>({
  options,
  value,
  onChange,
  label,
  idPrefix,
  panelId,
  size = "md",
}: {
  options: { id: T; label: string }[];
  value: T;
  onChange: (v: T) => void;
  label: string;
  idPrefix: string;
  panelId?: string;
  size?: "md" | "sm";
}) {
  const refs = useRef<Partial<Record<T, HTMLButtonElement | null>>>({});

  function onKeyDown(e: React.KeyboardEvent) {
    if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(e.key)) return;
    e.preventDefault();
    const i = options.findIndex((o) => o.id === value);
    const n = options.length;
    const j = e.key === "Home" ? 0 : e.key === "End" ? n - 1 : e.key === "ArrowRight" ? (i + 1) % n : (i - 1 + n) % n;
    const next = options[j].id;
    onChange(next);
    refs.current[next]?.focus();
  }

  return (
    <MotionConfig reducedMotion="user">
      <div
        role="tablist"
        aria-label={label}
        className="sp-inset relative grid gap-1 rounded-full p-1"
        style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }}
      >
        {options.map((opt) => {
          const active = opt.id === value;
          return (
            <button
              key={opt.id}
              ref={(el) => {
                refs.current[opt.id] = el;
              }}
              type="button"
              role="tab"
              id={`${idPrefix}-${opt.id}`}
              aria-selected={active}
              aria-controls={panelId}
              tabIndex={active ? 0 : -1}
              onClick={() => onChange(opt.id)}
              onKeyDown={onKeyDown}
              className={`group relative rounded-full px-2 sm:px-3 ${size === "sm" ? "min-h-10 py-1.5" : "min-h-11 py-2"}`}
            >
              {active && (
                <motion.span
                  layoutId={`${idPrefix}-pill`}
                  transition={{ type: "spring", stiffness: 280, damping: 25, mass: 0.8 }}
                  className="absolute inset-0 rounded-full bg-[var(--sp-fill-3)] shadow-[var(--sp-edge),var(--sp-shadow-3)]"
                />
              )}
              <span
                className={`relative z-10 block whitespace-nowrap transition-colors duration-200 ${size === "sm" ? "text-[12px] sm:text-[12.5px]" : "text-[12.5px] sm:text-[13px]"} ${
                  active ? "font-semibold text-foreground" : "font-medium text-sp-text-2 group-hover:text-foreground"
                }`}
              >
                {opt.label}
              </span>
            </button>
          );
        })}
      </div>
    </MotionConfig>
  );
}

export function PortalTabs({ value, onChange, panelId }: { value: Portal; onChange: (p: Portal) => void; panelId: string }) {
  return <Segmented options={PORTAL_TABS} value={value} onChange={onChange} label="Choose sign-in portal" idPrefix="portal-tab" panelId={panelId} />;
}
