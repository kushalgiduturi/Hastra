import { useState } from "react";
import { readTheme, saveTheme, type Theme } from "@/lib/theme";
import { Button } from "@/components/watermelon/button";
import { IconMoon, IconSun } from "./icons";

export function ThemeToggle() {
  const [theme, setTheme] = useState<Theme>(() => readTheme());
  const next: Theme = theme === "dark" ? "light" : "dark";
  return (
    <Button
      type="button"
      variant="secondary"
      size="icon"
      aria-label={`Switch to ${next} theme`}
      title={`Switch to ${next} theme`}
      onClick={() => {
        saveTheme(next);
        setTheme(next);
      }}
    >
      {theme === "dark" ? <IconSun /> : <IconMoon />}
    </Button>
  );
}
