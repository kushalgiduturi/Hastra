// Watermelon UI "Fluid Tabs" (animated-components/fluid-tabs): a pill that
// slides between items on a spring (stiffness 280, damping 25, mass .8).
// Adapted for navigation: each tab is a router NavLink, the active pill is
// a depth-3 raised control on a depth-1 track, icons are authored SVG (no
// react-icons), and the slide respects reduced motion via MotionConfig.
import type { FC, ReactNode } from "react";
import { NavLink, useLocation } from "react-router-dom";
import { motion, MotionConfig } from "motion/react";

export interface TabItem {
  to: string;
  label: string;
  icon: ReactNode;
  end?: boolean;
}

export const FluidTabs: FC<{ tabs: TabItem[]; label: string }> = ({ tabs, label }) => {
  const { pathname } = useLocation();
  const isActive = (t: TabItem) => (t.end ? pathname === t.to || pathname === `${t.to}/` : pathname.startsWith(t.to));

  return (
    <MotionConfig reducedMotion="user">
      <nav aria-label={label} className="sp-inset relative flex items-center gap-1 rounded-full p-1">
        {tabs.map((tab) => {
          const active = isActive(tab);
          return (
            <NavLink
              key={tab.to}
              to={tab.to}
              end={tab.end}
              aria-current={active ? "page" : undefined}
              className="group relative rounded-full px-3 py-2 sm:px-4"
            >
              {active && (
                <motion.span
                  layoutId="sp-nav-pill"
                  transition={{ type: "spring", stiffness: 280, damping: 25, mass: 0.8 }}
                  className="absolute inset-0 rounded-full bg-[var(--sp-fill-3)] shadow-[var(--sp-edge),var(--sp-shadow-3)]"
                />
              )}
              <span
                className={`relative z-10 flex items-center gap-2 text-[13px] whitespace-nowrap transition-colors duration-200 ${
                  active ? "font-semibold text-foreground" : "font-medium text-sp-text-2 group-hover:text-foreground"
                }`}
              >
                <span className="flex shrink-0 items-center justify-center [&_svg]:size-4">{tab.icon}</span>
                <span className="max-sm:sr-only">{tab.label}</span>
              </span>
            </NavLink>
          );
        })}
      </nav>
    </MotionConfig>
  );
};
