// The auth screens: one depth-2 glass card on the world, the brand and the
// theme switch in its header. Content inside the card is flat.
// `above` sits between the header and the title (the sign-in portal tabs).
// `aside` adds a second glass panel beside the card on wide screens and
// below it on narrow ones (the PHP sign-in page's portal overview), so the
// form stays first on a phone.
import type { ReactNode } from "react";
import { Brand } from "./Brand";
import { ThemeToggle } from "./ThemeToggle";

export function AuthShell({
  title,
  lead,
  children,
  footer,
  above,
  aside,
  tabPanel,
}: {
  title: string;
  lead?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  above?: ReactNode;
  aside?: ReactNode;
  /** makes the card body the tab panel of a tablist in `above` */
  tabPanel?: { id: string; labelledBy: string };
}) {
  const card = (
    <section className="sp-glass w-full max-w-[420px] rounded-[var(--radius-panel)] p-7 sm:p-9 max-sm:rounded-3xl">
      <header className="mb-8 flex items-center justify-between">
        <Brand />
        <ThemeToggle />
      </header>
      {above && <div className="mb-7">{above}</div>}
      <div id={tabPanel?.id} role={tabPanel ? "tabpanel" : undefined} aria-labelledby={tabPanel?.labelledBy}>
        <h1 className="text-[26px] leading-[1.15] font-semibold tracking-[-0.025em] text-foreground">{title}</h1>
        {lead && <p className="mt-2 text-[15px] text-sp-text-2">{lead}</p>}
        <div className="mt-7">{children}</div>
        {footer && <footer className="mt-7 border-t border-border pt-5 text-[13px] text-sp-text-2">{footer}</footer>}
      </div>
    </section>
  );

  if (!aside) return <main className="grid min-h-dvh place-items-center px-4 py-10">{card}</main>;

  return (
    <main className="grid min-h-dvh place-items-center px-4 py-10">
      <div className="grid w-full max-w-[420px] gap-5 lg:max-w-[960px] lg:grid-cols-[minmax(0,1fr)_420px] lg:items-stretch">
        <aside className="sp-glass order-2 rounded-[var(--radius-panel)] p-7 sm:p-9 max-sm:rounded-3xl lg:order-1 lg:flex lg:flex-col lg:justify-center lg:p-12">{aside}</aside>
        <div className="order-1 lg:order-2">{card}</div>
      </div>
    </main>
  );
}
