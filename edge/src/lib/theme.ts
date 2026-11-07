// Theme: the same key and values as the PHP app's core/theme.js
// (localStorage "astra_theme" = "dark" | "light"), falling back to the
// system preference. Applied before the first render (src/main.tsx).
export type Theme = "dark" | "light";
const KEY = "astra_theme";

export function readTheme(): Theme {
  try {
    const saved = localStorage.getItem(KEY);
    if (saved === "dark" || saved === "light") return saved;
  } catch {
    /* storage blocked: fall through to the system preference */
  }
  return window.matchMedia?.("(prefers-color-scheme: light)").matches ? "light" : "dark";
}

export function applyTheme(theme: Theme) {
  document.documentElement.dataset.theme = theme;
}

export function saveTheme(theme: Theme) {
  applyTheme(theme);
  try {
    localStorage.setItem(KEY, theme);
  } catch {
    /* the choice still applies for this visit */
  }
}
