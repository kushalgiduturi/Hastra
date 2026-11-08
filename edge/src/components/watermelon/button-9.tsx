// Watermelon UI "Button 9" (components/button-9): a label followed by three
// dots. Extracted as the loading state of button.tsx so every form's busy
// button keeps one shape. The original bounces the dots; here they fade in
// a stagger instead (the craft floor rules out bounce easing). They stop
// under reduced motion (src/styles/spatial.css).
export function LoadingLabel({ label }: { label: string }) {
  return (
    <span className="inline-flex items-center gap-1">
      <span>{label}</span>
      <span className="flex translate-y-[3px] gap-[3px]" aria-hidden="true">
        <span className="size-1 animate-pulse rounded-full bg-current [animation-delay:-0.4s]" />
        <span className="size-1 animate-pulse rounded-full bg-current [animation-delay:-0.2s]" />
        <span className="size-1 animate-pulse rounded-full bg-current [animation-delay:0s]" />
      </span>
    </span>
  );
}
