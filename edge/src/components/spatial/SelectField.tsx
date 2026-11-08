// A native select dressed like FloatingInput: the same depth-1 inset field
// with its label resting raised inside it, and a chevron. Native, so the
// platform picker, keyboard search and screen readers all work as usual.
import { useId } from "react";

export function SelectField({
  label,
  value,
  onChange,
  options,
  placeholder,
  required,
  name,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  options: readonly { value: string; label: string }[];
  placeholder?: string;
  required?: boolean;
  name?: string;
}) {
  const id = useId();
  return (
    <div className="relative">
      <select
        id={id}
        name={name}
        value={value}
        required={required}
        onChange={(e) => onChange(e.target.value)}
        className={`sp-inset h-14 w-full cursor-pointer appearance-none rounded-[var(--radius-ctl)] border-0 bg-transparent px-4 pt-5 pb-1.5 pr-11 text-[15px] ${
          value ? "text-foreground" : "text-muted-foreground"
        }`}
      >
        {placeholder && (
          <option value="" disabled>
            {placeholder}
          </option>
        )}
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
      <label htmlFor={id} className="pointer-events-none absolute top-[15px] left-4 -translate-y-1/2 text-xs font-medium text-muted-foreground">
        {label}
      </label>
      <svg
        viewBox="0 0 24 24"
        aria-hidden="true"
        className="pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2 text-sp-text-2"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.75}
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="m6 9.5 6 6 6-6" />
      </svg>
    </div>
  );
}
