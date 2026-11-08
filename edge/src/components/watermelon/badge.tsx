// Watermelon UI registry item "badge" (registry.watermelon.sh/r/badge.json;
// the destructive look is Badge 8). Spatial pills: a 12% tint of the status
// colour with solid status text, which clears AA on every panel fill in
// both themes (tests/pilot/spatial-ui.test.ts).
import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";

const badgeVariants = cva(
  "inline-flex w-fit shrink-0 items-center justify-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold [&>svg]:size-3 [&>svg]:pointer-events-none",
  {
    variants: {
      variant: {
        default: "bg-[rgba(var(--sp-accent-rgb),.12)] text-sp-accent-text",
        secondary: "bg-secondary text-sp-text-2",
        ok: "bg-[rgba(var(--sp-ok-rgb),.12)] text-sp-ok",
        warn: "bg-[rgba(var(--sp-warn-rgb),.12)] text-sp-warn",
        destructive: "bg-[rgba(var(--sp-bad-rgb),.12)] text-sp-bad",
        info: "bg-[rgba(var(--sp-info-rgb),.12)] text-sp-info",
      },
    },
    defaultVariants: { variant: "default" },
  },
);

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<"span"> & VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "span";
  return <Comp data-slot="badge" className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
