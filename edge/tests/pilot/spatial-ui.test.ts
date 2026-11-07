// The spatial UI in the edge pilot (src/styles/spatial.css and the screens).
// Mirrors the PHP app's tests/suites/15_spatial_ui.php: tokens in both
// themes and in step with theme-spatial.css, the fallbacks, the blur budget,
// no long dashes, every screen on the shared shells, and AA contrast for
// every text-on-glass pair computed from the token values.
import { describe, expect, it } from "vitest";
// Sources come in through Vite's ?raw imports (typed by vite/client), so the
// test needs no Node type definitions and compiles under `tsc -b`.
import phpRaw from "../../../assets/css/theme-spatial.css?raw";
import indexHtml from "../../index.html?raw";
import selfRaw from "./spatial-ui.test.ts?raw";

const SOURCES = import.meta.glob("../../src/**/*.{ts,tsx,css}", { query: "?raw", import: "default", eager: true }) as Record<string, string>;
const norm = (s: string) => s.replace(/\r\n/g, "\n");
const read = (p: string) => {
  const hit = SOURCES[`../../${p}`];
  if (hit === undefined) throw new Error(`no source ${p}`);
  return norm(hit);
};
const files = (dir: string) => Object.keys(SOURCES).filter((k) => k.startsWith(`../../${dir}/`)).map((k) => k.slice(6));
const css = read("src/styles/spatial.css");
const php = norm(phpRaw);

function block(src: string, selector: string): string {
  const at = src.indexOf(`${selector} {`);
  if (at < 0) return "";
  return src.slice(at, src.indexOf("\n}", at));
}
function token(b: string, name: string): string | null {
  const m = b.match(new RegExp(`(?<![\\w-])${name.replace(/[-]/g, "\\-")}:\\s*([^;]+);`));
  return m ? m[1].trim() : null;
}
type RGBA = [number, number, number, number];
function rgba(v: string | null): RGBA | null {
  if (!v) return null;
  let m = v.match(/^#([0-9a-f]{6})$/i);
  if (m) return [parseInt(m[1].slice(0, 2), 16), parseInt(m[1].slice(2, 4), 16), parseInt(m[1].slice(4, 6), 16), 1];
  m = v.match(/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$/);
  return m ? [+m[1], +m[2], +m[3], m[4] ? +m[4] : 1] : null;
}
const over = (t: RGBA, b: RGBA): RGBA => [t[0] * t[3] + b[0] * (1 - t[3]), t[1] * t[3] + b[1] * (1 - t[3]), t[2] * t[3] + b[2] * (1 - t[3]), 1];
const lum = (c: RGBA) => {
  const f = (v: number) => ((v /= 255) <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
  return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
};
const ratio = (a: RGBA, b: RGBA) => {
  const [x, y] = [lum(a), lum(b)];
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
};

const dark = block(css, ":root");
const light = block(css, ':root[data-theme="light"]');
const tok = (theme: "dark" | "light", name: string) => rgba((theme === "light" ? token(light, name) : null) ?? token(dark, name));

// The ground under the glass. The PHP app sits on the 3-D temple and uses
// grounds measured from it; the pilot has no scene, only the fixed lit field
// in --sp-world (a base colour with soft radial glows). So the ground here is
// computed from that token: the brightest point it can reach by night and
// the darkest by day (each glow at full strength, alone and all together),
// which bounds whatever any panel can sit on.
function worldGround(theme: "dark" | "light"): RGBA {
  const w = token(theme === "light" ? light : dark, "--sp-world")!;
  const base = rgba(w.match(/#[0-9a-f]{6}/i)![0])!;
  const glows = [...w.matchAll(/rgba\([^)]+\)/g)].map((m) => rgba(m[0])!);
  const options = [base, ...glows.map((g) => over(g, base)), glows.reduce((acc, g) => over(g, acc), base)];
  return options.reduce((pick, c) => ((theme === "dark" ? lum(c) > lum(pick) : lum(c) < lum(pick)) ? c : pick));
}
const GROUND = { dark: worldGround("dark"), light: worldGround("light") };

describe("spatial tokens", () => {
  it("defines every token by night and the themed ones again by day", () => {
    for (const t of ["--sp-accent", "--sp-accent-text", "--sp-text", "--sp-text-2", "--sp-text-3", "--sp-ok", "--sp-warn", "--sp-bad", "--sp-info",
                     "--sp-hair", "--sp-fill-2", "--sp-fill-3", "--sp-fill-4", "--sp-solid-2", "--sp-inset-fill", "--sp-inset", "--sp-edge", "--sp-world"]) {
      expect(token(dark, t), `dark ${t}`).not.toBeNull();
      expect(token(light, t), `light ${t}`).not.toBeNull();
    }
    for (const t of ["--sp-blur-2", "--sp-blur-3", "--sp-blur-4", "--sp-shadow-2", "--sp-font-ui"]) expect(token(dark, t), t).not.toBeNull();
  });

  it("stays in step with the PHP app's theme-spatial.css", () => {
    const phpDark = block(php, "html.sp-ui");
    const phpLight = block(php, 'html.sp-ui[data-theme="light"]');
    for (const t of ["--sp-accent", "--sp-accent-text", "--sp-text", "--sp-text-2", "--sp-text-3", "--sp-ok", "--sp-warn", "--sp-bad", "--sp-bad-solid", "--sp-info", "--sp-fill-2", "--sp-fill-3", "--sp-fill-4"]) {
      expect(token(dark, t), `dark ${t}`).toBe(token(phpDark, t));
      expect(token(light, t), `light ${t}`).toBe(token(phpLight, t));
    }
  });

  it("maps Watermelon's colour names onto the spatial tokens", () => {
    const theme = block(css, "@theme inline");
    for (const [name, value] of [["--color-background", "var(--sp-fill-2)"], ["--color-foreground", "var(--sp-text)"], ["--color-primary", "var(--sp-accent)"],
                                 ["--color-muted-foreground", "var(--sp-text-3)"], ["--color-border", "var(--sp-hair)"], ["--color-ring", "var(--sp-ring)"]])
      expect(token(theme, name), name).toBe(value);
  });
});

describe("glass rules", () => {
  it("keeps every blur at or under 24px and never nests one", () => {
    for (const m of css.replace(/\/\*[\s\S]*?\*\//g, "").matchAll(/--sp-blur-\d:\s*([\d.]+)px/g)) expect(+m[1]).toBeLessThanOrEqual(24);
    expect(css).toContain(".sp-glass .sp-glass { -webkit-backdrop-filter: none; backdrop-filter: none; }");
  });

  it("has the reduced-transparency, reduced-motion and forced-colours fallbacks", () => {
    // both selectors, or the more specific light block keeps its translucent fills
    expect(css).toMatch(/@media \(prefers-reduced-transparency: reduce\) \{\s*:root, :root\[data-theme="light"\] \{ --sp-blur-2: 0px;/);
    // keyboard focus stays visible: one base ring, and no control opts out
    // (Tailwind v4's outline-none would also defeat focus-visible:outline-*)
    expect(css).toContain(":focus-visible { outline: 2px solid var(--sp-ring); outline-offset: 3px; }");
    for (const f of files("src")) expect(read(f), `${f} hides the focus ring`).not.toMatch(/\boutline-none\b/);
    expect(css).toMatch(/@media \(prefers-reduced-motion: reduce\)/);
    expect(css).toMatch(/@media \(forced-colors: active\) \{[\s\S]*?background: Canvas !important/);
    expect(read("src/components/watermelon/fluid-tabs.tsx")).toContain('reducedMotion="user"');
  });
});

describe("screens", () => {
  const screens = files("src/routes");
  it("puts every screen on the shared auth or portal shell, with no inline styles", () => {
    for (const f of screens) {
      const src = read(f);
      expect(/AuthShell|Panel/.test(src), `${f} has no shell`).toBe(true);
      expect(src, `${f} has an inline style`).not.toMatch(/style=\{\{/);
    }
    expect(read("src/components/Nav.tsx")).not.toMatch(/style=\{\{/);
  });

  it("loads nothing from another host except the existing reCAPTCHA script", () => {
    for (const [f, src] of [...files("src").map((f) => [f, read(f)]), ["index.html", indexHtml]]) {
      for (const m of src.matchAll(/https?:\/\/[^\s"'`)]+/g)) expect(m[0], f).toMatch(/^https:\/\/www\.google\.com\/recaptcha\/api\.js/);
    }
  });

  it("has no em or en dashes in the app source or styles", () => {
    for (const [f, src] of [...files("src").map((f) => [f, read(f)]), ["tests/pilot/spatial-ui.test.ts", selfRaw]]) {
      expect(src.includes("\u2014") || src.includes("\u2013"), `${f} contains a long dash`).toBe(false);
    }
  });
});

describe("contrast", () => {
  it("clears AA for every text token on every glass, dense and inset fill, in both themes", () => {
    const texts = ["--sp-text", "--sp-text-2", "--sp-text-3", "--sp-accent-text", "--sp-ok", "--sp-warn", "--sp-bad", "--sp-info"];
    const fails: string[] = [];
    let n = 0;
    for (const theme of ["dark", "light"] as const) {
      const g = GROUND[theme];
      const panel = over(tok(theme, "--sp-fill-2")!, g);
      const surfaces: Record<string, RGBA> = { "inset in the panel": over(tok(theme, "--sp-inset-fill")!, panel) };
      for (const s of ["--sp-fill-2", "--sp-fill-3", "--sp-fill-4", "--sp-solid-2", "--sp-solid-3", "--sp-solid-4"]) surfaces[s] = over(tok(theme, s)!, g);
      for (const [sname, bg] of Object.entries(surfaces)) {
        for (const t of texts) {
          const fg = tok(theme, t);
          expect(fg, `${theme} ${t}`).not.toBeNull();
          const r = ratio(over(fg!, bg), bg);
          n++;
          if (r < 4.5) fails.push(`${theme} ${t} on ${sname} ${r.toFixed(2)}:1`);
        }
        // badges: status text on a 12% tint of itself, on that surface
        for (const s of ["ok", "warn", "bad", "info"]) {
          const fg = tok(theme, `--sp-${s}`)!;
          const pill = over([fg[0], fg[1], fg[2], 0.12], bg);
          const r = ratio(fg, pill);
          n++;
          if (r < 4.5) fails.push(`${theme} ${s} badge on ${sname} ${r.toFixed(2)}:1`);
        }
      }
      for (const fill of ["--sp-accent", "--sp-bad-solid"]) {
        const r = ratio([255, 255, 255, 1], tok(theme, fill)!);
        n++;
        if (r < 4.5) fails.push(`${theme} white on ${fill} ${r.toFixed(2)}:1`);
      }
    }
    // 2 themes x 7 surfaces x (8 text tokens + 4 badges) + 4 white-on-fill
    expect(n).toBe(172);
    expect(fails).toEqual([]);
  });
});
