// The sign-in portal switch: Enterprise Workspace or Client Gateway, the same
// two portals as the PHP app's auth/login.php. A tablist with roving
// tabindex and arrow, Home and End keys, and the Fluid Tabs pill (spring
// slide on a depth-1 track), which respects reduced motion.
import { useRef } from "react";
import { motion, MotionConfig } from "motion/react";

export type Portal = "enterprise" | "client";

export const PORTAL_TABS: { id: Portal; label: string }[] = [
  { id: "enterprise", label: "Enterprise Workspace" },
  { id: "client", label: "Client Gateway" },
];

export function PortalTabs({ value, onChange, panelId }: { value: Portal; onChange: (p: Portal) => void; panelId: string }) {
  const refs = useRef<Record<Portal, HTMLButtonElement | null>>({ enterprise: null, client: null });

  function onKeyDown(e: React.KeyboardEvent) {
    const keys = ["ArrowLeft", "ArrowRight", "Home", "End"];
    if (!keys.includes(e.key)) return;
    e.preventDefault();
    const next: Portal =
      e.key === "Home" ? "enterprise" : e.key === "End" ? "client" : value === "enterprise" ? "client" : "enterprise";
    onChange(next);
    refs.current[next]?.focus();
  }

  return (
    <MotionConfig reducedMotion="user">
      <div role="tablist" aria-label="Choose sign-in portal" className="sp-inset relative grid grid-cols-2 gap-1 rounded-full p-1">
        {PORTAL_TABS.map((tab) => {
          const active = tab.id === value;
          return (
            <button
              key={tab.id}
              ref={(el) => {
                refs.current[tab.id] = el;
              }}
              type="button"
              role="tab"
              id={`portal-tab-${tab.id}`}
              aria-selected={active}
              aria-controls={panelId}
              tabIndex={active ? 0 : -1}
              onClick={() => onChange(tab.id)}
              onKeyDown={onKeyDown}
              className="group relative min-h-11 rounded-full px-3 py-2"
            >
              {active && (
                <motion.span
                  layoutId="sp-portal-pill"
                  transition={{ type: "spring", stiffness: 280, damping: 25, mass: 0.8 }}
                  className="absolute inset-0 rounded-full bg-[var(--sp-fill-3)] shadow-[var(--sp-edge),var(--sp-shadow-3)]"
                />
              )}
              <span
                className={`relative z-10 block text-[13px] whitespace-nowrap transition-colors duration-200 ${
                  active ? "font-semibold text-foreground" : "font-medium text-sp-text-2 group-hover:text-foreground"
                }`}
              >
                {tab.label}
              </span>
            </button>
          );
        })}
      </div>
    </MotionConfig>
  );
}
