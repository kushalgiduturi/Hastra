// The hero at "/": the PHP app's landing page, synced into public/landing/
// by scripts/sync-landing.mjs and served through public/_redirects.
import { describe, expect, it } from "vitest";
import { LANDING_ASSETS, PHP_APP_URL, rewriteLanding } from "../../scripts/sync-landing.mjs";
import landingRaw from "../../../landing-pages/hastra.html?raw";
import hostHtml from "../../public/landing/index.html?raw";
import redirects from "../../public/_redirects?raw";

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
