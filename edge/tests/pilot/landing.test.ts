// The hero at "/": the PHP app's landing page, synced into public/landing/
// by scripts/sync-landing.mjs and served through public/_redirects.
import { describe, expect, it } from "vitest";
import { LANDING_ASSETS, PHP_APP_URL, SITE_ICONS, rewriteLanding } from "../../scripts/sync-landing.mjs";
import landingRaw from "../../../landing-pages/hastra.html?raw";
import hostHtml from "../../public/landing/index.html?raw";
import shellHtml from "../../index.html?raw";
import redirects from "../../public/_redirects?raw";
import phpSeo from "../../../core/seo.php?raw";

// Keys only: nothing is loaded, this just lists what exists in ../assets.
const PHP_ASSETS = Object.keys(import.meta.glob("../../../assets/{css,fonts,images,js}/*")).map((k) => k.replace("../../../assets/", ""));
const out = rewriteLanding(landingRaw);
const hrefs = [...out.matchAll(/href="([^"#][^"]*)"/g)].map((m) => m[1]);

describe("landing page sync", () => {
  it("leaves no link or asset path relative to the PHP app", () => {
    expect(out).not.toMatch(/(href|src)="\.\.\//);
    expect(out).not.toMatch(/["']\.\.\/assets\//);
  });

  it("sends sign in and sign up to the edge routes", () => {
    expect(hrefs).toContain("/signin");
    expect(hrefs).toContain("/register");
    expect(hrefs).not.toContain("/signup");
  });

  it("sends the labs and legal pages to the PHP app, in the top window", () => {
    const away = [...out.matchAll(/<a [^>]*href="(https:\/\/[^"]+)"[^>]*>/g)];
    expect(away.length).toBeGreaterThan(0);
    for (const [tag, url] of away) {
      expect(url.startsWith(`${PHP_APP_URL}labs/`) || url.startsWith(`${PHP_APP_URL}legal/`)).toBe(true);
      expect(tag).toContain('target="_top"');
    }
  });

  it("drops the cookie settings button, which has no banner to open here", () => {
    expect(out).not.toMatch(/<button[^>]*foot-consent/);
  });

  it("copies every local file the document loads, and each one exists", () => {
    const loaded = [...out.matchAll(/["']\/landing\/assets\/([^"'?]+)/g)].map((m) => m[1]);
    expect(loaded.length).toBeGreaterThan(0);
    for (const file of loaded) expect(LANDING_ASSETS).toContain(file);
    for (const file of LANDING_ASSETS) expect(PHP_ASSETS).toContain(file);
  });

  it("serves the hero at / inside the same sandboxed frame as index.php", () => {
    expect(redirects).toMatch(/^\/ \/landing\/ 200$/m);
    expect(hostHtml).toContain('src="/landing/hastra"');
    expect(hostHtml).toContain("allow-top-navigation-by-user-activation");
    expect(hostHtml).toContain('src="/landing/assets/js/landing-host.js"');
  });

  it("uses no long dashes in the files it adds", () => {
    const longDash = new RegExp(`[${String.fromCharCode(0x2013, 0x2014)}]`);
    expect(hostHtml).not.toMatch(longDash);
  });
});

// Repo files the icon copy reads from (keys only, nothing is loaded).
const REPO_FILES = Object.keys(import.meta.glob(["../../../favicon.ico", "../../../assets/images/*"])).map((k) => k.replace("../../../", ""));

describe("site meta and icons", () => {
  it("gives the home page the PHP app's description, social card and icons", () => {
    const tags = [
      'name="description"',
      'rel="canonical" href="https://hastra.pages.dev/"',
      'property="og:title"',
      'property="og:description"',
      'property="og:image" content="https://hastra.pages.dev/icons/og-preview.png"',
      'property="og:image:width" content="1200"',
      'name="twitter:card" content="summary_large_image"',
      'rel="icon" type="image/svg+xml" href="/icons/hastra-logo.svg"',
      'rel="alternate icon" href="/favicon.ico"',
      'rel="apple-touch-icon"',
    ];
    for (const t of tags) expect(hostHtml).toContain(t);
    for (const t of ["og:image:alt", "twitter:image", "theme-color"]) expect(phpSeo).toContain(t);
  });

  it("gives the app shell a description and icons, kept out of search like the PHP sign-in", () => {
    expect(shellHtml).toContain('name="description"');
    expect(shellHtml).toContain('content="noindex, follow"');
    expect(shellHtml).toContain('href="/favicon.ico"');
    expect(shellHtml).toContain('href="/icons/hastra-logo.svg"');
  });

  it("copies every icon it links from the PHP app, and /favicon.ico is one of them", () => {
    for (const from of Object.keys(SITE_ICONS)) expect(REPO_FILES).toContain(from);
    expect(SITE_ICONS["favicon.ico"]).toBe("favicon.ico");
    for (const html of [hostHtml, shellHtml]) {
      for (const [, href] of html.matchAll(/href="\/(icons\/[^"]+|favicon\.ico)"/g)) expect(Object.values(SITE_ICONS)).toContain(href);
    }
  });
});
