// The auth screens: one depth-2 glass card on the world, the brand and the
// theme switch in its header. Content inside the card is flat.
import type { ReactNode } from "react";
import { Brand } from "./Brand";
import { ThemeToggle } from "./ThemeToggle";

export function AuthShell({
  title,
  lead,
  children,
  footer,
}: {
  title: string;
  lead?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
}) {
  return (
    <main className="grid min-h-dvh place-items-center px-4 py-10">
      <section className="sp-glass w-full max-w-[420px] rounded-[var(--radius-panel)] p-7 sm:p-9 max-sm:rounded-3xl">
        <header className="mb-8 flex items-center justify-between">
          <Brand />
          <ThemeToggle />
        </header>
        <h1 className="text-[26px] leading-[1.15] font-semibold tracking-[-0.025em] text-foreground">{title}</h1>
        {lead && <p className="mt-2 text-[15px] text-sp-text-2">{lead}</p>}
        <div className="mt-7">{children}</div>
        {footer && <footer className="mt-7 border-t border-border pt-5 text-[13px] text-sp-text-2">{footer}</footer>}
      </section>
    </main>
  );
}
