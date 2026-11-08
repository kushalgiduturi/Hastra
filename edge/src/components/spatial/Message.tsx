// Inline status line for forms and actions: an icon and one sentence in the
// status colour, no box. Errors are announced (role="alert").
import type { ReactNode } from "react";
import { IconAlert, IconShieldCheck } from "./icons";

export function Message({ tone, children }: { tone: "error" | "ok"; children: ReactNode }) {
  const ok = tone === "ok";
  return (
    <p
      role={ok ? "status" : "alert"}
      className={`flex items-start gap-2 text-[13px] leading-snug ${ok ? "text-sp-ok" : "text-sp-bad"}`}
    >
      {ok ? <IconShieldCheck className="mt-px size-4 shrink-0" /> : <IconAlert className="mt-px size-4 shrink-0" />}
      <span>{children}</span>
    </p>
  );
}
