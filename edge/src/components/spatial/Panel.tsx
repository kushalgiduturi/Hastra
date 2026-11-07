// The page panel: each portal page's one depth-2 glass reading surface.
import type { ReactNode } from "react";

export function Panel({ title, lead, actions, children }: { title: string; lead?: ReactNode; actions?: ReactNode; children?: ReactNode }) {
  return (
    <main className="px-3 pt-6 pb-16">
      <section className="sp-glass mx-auto max-w-[1180px] rounded-[var(--radius-panel)] p-6 sm:p-10 max-md:rounded-3xl">
        <header className="flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-[clamp(1.875rem,3.4vw,2.625rem)] leading-[1.1] font-semibold tracking-[-0.025em] text-foreground">{title}</h1>
            {lead && <p className="mt-2 max-w-[65ch] text-[15px] text-sp-text-2">{lead}</p>}
          </div>
          {actions && <div className="flex items-center gap-2">{actions}</div>}
        </header>
        {children && <div className="mt-8">{children}</div>}
      </section>
    </main>
  );
}
