// Copies the PHP app's landing page (../landing-pages/hastra.html) and the
// files it loads into public/landing/, so the edge site serves the same hero
// at "/" (see public/_redirects and public/landing/index.html). The PHP copy
// stays the only source: this runs before every build and dev server, and
// its output is gitignored.
//
// The document is used as it is, except for its links:
//   ../assets/...       -> /landing/assets/... (copied below)
//   ../signin, ../signup -> the edge routes /signin and /register
//   ../labs/, ../legal/  -> the PHP app, which is the only place they exist
// and the footer's "Cookie settings" item is dropped: it opens the PHP app's
// consent banner, and the edge site sets no optional cookies to consent to.
import { cpSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const EDGE = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const REPO = resolve(EDGE, "..");
const OUT = join(EDGE, "public", "landing");

export const PHP_APP_URL = "https://hastra.onrender.com/";

/** Files under ../assets the document and its scripts and styles load. */
export const LANDING_ASSETS = [
  "css/fonts.css",
  "css/labs-showcase.css",
  "css/landing-spatial.css",
  "fonts/onest-latin.woff2",
  "fonts/onest-latin-ext.woff2",
  "fonts/share-tech-mono-latin.woff2",
  "images/hastra-logo.svg",
  "js/hybrid-hand-cursor.js",
  "js/labs-showcase.js",
  "js/landing-host.js",
  "js/performance-guard.js",
  "js/sakura-petals-cursor.js",
  "js/shang-chi-rings.js",
  "js/ueba-calculator.js",
];

/**
 * The PHP app's favicon set and social preview image, served from the edge
 * site's root (repo path -> path under public/). The home page and the app
 * shell (index.html) link them; /favicon.ico is where browsers and crawlers
 * look first.
 */
export const SITE_ICONS = {
  "favicon.ico": "favicon.ico",
  "assets/images/hastra-logo.svg": "icons/hastra-logo.svg",
  "assets/images/favicon-32.png": "icons/favicon-32.png",
  "assets/images/apple-touch-icon.png": "icons/apple-touch-icon.png",
  "assets/images/og-preview.png": "icons/og-preview.png",
};

export function rewriteLanding(html) {
  return html
    .replace(/(["'])\.\.\/assets\//g, "$1/landing/assets/")
    .replace(/href="\.\.\/signin"/g, 'href="/signin"')
    .replace(/href="\.\.\/signup"/g, 'href="/register"')
    .replace(/href="\.\.\/(labs|legal)\//g, `href="${PHP_APP_URL}$1/`)
    .replace(/[ \t]*<li><button type="button" class="foot-consent"[^\n]*<\/button><\/li>\r?\n?/g, "");
}

export function syncLanding() {
  mkdirSync(OUT, { recursive: true });
  const html = readFileSync(join(REPO, "landing-pages", "hastra.html"), "utf8");
  writeFileSync(join(OUT, "hastra.html"), rewriteLanding(html));
  for (const file of LANDING_ASSETS) {
    const to = join(OUT, "assets", file);
    mkdirSync(dirname(to), { recursive: true });
    cpSync(join(REPO, "assets", file), to);
  }
  cpSync(join(REPO, "landing-pages", "secret-pathways-assets"), join(OUT, "secret-pathways-assets"), { recursive: true });
  for (const [from, to] of Object.entries(SITE_ICONS)) {
    const dest = join(EDGE, "public", to);
    mkdirSync(dirname(dest), { recursive: true });
    cpSync(join(REPO, from), dest);
  }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  syncLanding();
  console.log("landing page synced into public/landing/");
}
