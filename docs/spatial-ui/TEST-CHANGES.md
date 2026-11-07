# Test assertions changed by the spatial UI

The brief (phase 6) asks for every changed assertion and the reason. Tests are updated in the same commit as the surface they pin, so the suite stays green on every commit of the branch; this log collects them for the phase 6 review.

| Phase | Suite | Test (old name) | Change | Why |
|---|---|---|---|---|
| 4a | tests/suites/06_governance.php | "barrier is boxless and the streaming banner is gone from every stylesheet" | Now "barrier is a spatial glass card over an unblurred scrim; the streaming banner is gone". Asserts the modal uses `var(--sp-fill-4)` and `backdrop-filter: blur(var(--sp-blur-4))`, the overlay has no backdrop-filter, and the choices are not outlined boxes. The NOW STREAMING checks are unchanged. | The owner replaced the boxless mandate with the spatial system. The old test required no fill and no shadow on the modal and an underlined choice; the new one pins the glass card and the no-nesting rule instead. |
