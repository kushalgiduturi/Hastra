// Watermelon UI registry item "button" (registry.watermelon.sh/r/button.json),
// restyled for the spatial UI: pills in sentence case. Primary is a solid
// accent pill with a top highlight; secondary is a glass-tinted pill; ghost
// is text only. `loading` swaps the label for Button 9's dots (button-9.tsx)
// while keeping the width.
import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";

import { cn } from "@/lib/utils";
import { LoadingLabel } from "./button-9";

const buttonVariants = cva(
  "relative inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-full font-semibold transition-[background-color,transform,box-shadow] duration-200 ease-[var(--sp-ease-out)] disabled:pointer-events-none disabled:opacity-55 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 active:scale-[.98]",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-[inset_0_1px_0_rgba(255,255,255,.28),var(--sp-shadow-accent)] hover:bg-[color-mix(in_srgb,var(--sp-accent)_88%,#fff)] hover:-translate-y-px",
        destructive: "bg-destructive text-white hover:bg-[color-mix(in_srgb,var(--sp-bad-solid)_88%,#fff)]",
        secondary: "bg-secondary text-secondary-foreground shadow-[var(--sp-edge)] hover:bg-sp-press",
        ghost: "text-sp-text-2 hover:bg-accent hover:text-foreground",
        link: "rounded-none px-0 text-sp-accent-text underline-offset-4 hover:underline",
      },
      size: {
        default: "h-11 px-5 text-sm",
        sm: "h-9 px-4 text-[13px]",
        lg: "h-12 px-6 text-[15px]",
        icon: "size-10",
      },
    },
    defaultVariants: { variant: "default", size: "default" },
  },
);

function Button({
  className,
  variant = "default",
  size = "default",
  asChild = false,
  loading = false,
  loadingLabel,
  children,
  disabled,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & { asChild?: boolean; loading?: boolean; loadingLabel?: string }) {
  const Comp = asChild ? Slot : "button";
  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      aria-busy={loading || undefined}
      disabled={disabled || loading}
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    >
      {loading ? <LoadingLabel label={loadingLabel ?? "Working"} /> : children}
    </Comp>
  );
}

export { Button, buttonVariants };
