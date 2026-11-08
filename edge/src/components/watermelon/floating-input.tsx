// Watermelon UI "Floating Input" (animated-components/floating-input),
// adapted for glass: the field is a depth-1 inset (no border) and the label
// rises inside the field instead of cutting a notch into a border, which a
// translucent panel cannot paint behind. Same props as the original: every
// input attribute plus `label`.
import { useId, useState } from "react";
import { cn } from "@/lib/utils";

interface FloatingInputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  label: string;
  /** A control inside the field's right edge, such as a show-password button. */
  trailing?: React.ReactNode;
}

export function FloatingInput({ label, trailing, className, id, onChange, onFocus, onBlur, ...props }: FloatingInputProps) {
  const autoId = useId();
  const inputId = id ?? autoId;
  const [focused, setFocused] = useState(false);
  const [hasValue, setHasValue] = useState(Boolean(props.value ?? props.defaultValue));
  const raised = focused || hasValue || Boolean(props.value);

  return (
    <div className="relative">
      <input
        id={inputId}
        className={cn(
          "peer sp-inset h-14 w-full rounded-[var(--radius-ctl)] border-0 px-4 pt-5 pb-1.5 text-[15px] text-foreground",
          "transition-shadow duration-200",
          trailing && "pr-12",
          className,
        )}
        placeholder=" "
        onFocus={(e) => {
          setFocused(true);
          onFocus?.(e);
        }}
        onBlur={(e) => {
          setFocused(false);
          setHasValue(e.target.value !== "");
          onBlur?.(e);
        }}
        onChange={(e) => {
          setHasValue(e.target.value !== "");
          onChange?.(e);
        }}
        {...props}
      />
      <label
        htmlFor={inputId}
        className={cn(
          "pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[15px] text-muted-foreground transition-all duration-200 ease-[var(--sp-ease-out)]",
          raised && "top-[15px] text-xs font-medium",
          focused && "text-sp-accent-text",
        )}
      >
        {label}
      </label>
      {trailing && <div className="absolute inset-y-0 right-1.5 flex items-center">{trailing}</div>}
    </div>
  );
}
