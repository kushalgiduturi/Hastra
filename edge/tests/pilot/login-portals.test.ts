// The sign-in screen keeps the PHP app's sign-in options (auth/login.php):
// the Enterprise Workspace and Client Gateway portals, Google sign-in,
// show-password, remember me, forgot password, and a sign-up link per portal.
import { describe, expect, it } from "vitest";
import loginRaw from "../../src/routes/auth/Login.tsx?raw";
import tabsRaw from "../../src/components/spatial/PortalTabs.tsx?raw";
import googleRaw from "../../src/components/spatial/GoogleButton.tsx?raw";
import phpLogin from "../../../auth/login.php?raw";

describe("sign-in portals", () => {
  it("offers the same two portals as the PHP sign-in page", () => {
    for (const label of ["Enterprise Workspace", "Client Gateway"]) {
      expect(phpLogin).toContain(label);
      expect(tabsRaw).toContain(`label: "${label}"`);
    }
    expect(tabsRaw).toContain('role="tablist"');
    expect(tabsRaw).toMatch(/ArrowLeft|ArrowRight/);
  });

  it("keeps each portal's overview copy from the PHP page", () => {
    for (const line of [
      "SOC Governance &amp; Cryptographic Control Plane",
      "Autonomous Project Oversight &amp; Delivery Tracking",
      "HMAC-verified, tamper-evident audit ledger",
    ]) {
      expect(phpLogin).toContain(line);
      expect(loginRaw).toContain(line.replace("&amp;", "&"));
    }
  });

  it("signs in with Google through the edge OAuth endpoint", () => {
    expect(loginRaw).toContain("<GoogleButton />");
    expect(googleRaw).toContain('href="/api/auth/google"');
    expect(googleRaw).toContain("Continue with Google");
  });

  it("has show-password, remember me and forgot password", () => {
    expect(loginRaw).toMatch(/Show password/);
    expect(loginRaw).toContain("Remember me");
    expect(loginRaw).toContain('to="/forgot"');
  });

  it("sends each portal to its own sign-up track", () => {
    expect(loginRaw).toContain('to: "/register?track=enterprise"');
    expect(loginRaw).toContain('to: "/register"');
  });
});
