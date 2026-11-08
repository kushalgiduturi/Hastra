// The four self-serve sign-up tracks (functions/_lib/signup.ts), checked
// against the PHP app's auth/register.php rules and lists.
import { describe, expect, it } from "vitest";
import { ACCOUNT_TYPE, COMPANY_SIZES, FLOWS, roleFor, validateSignup, type SignupInput } from "../../functions/_lib/signup";
import { COUNTRIES } from "../../functions/_lib/countries";
import phpCountries from "../../../core/countries.php?raw";
import phpOnboarding from "../../../core/onboarding.php?raw";
import phpRegister from "../../../auth/register.php?raw";
import phpVerify from "../../../auth/verify_register.php?raw";
import registerRaw from "../../src/routes/auth/Register.tsx?raw";
import otpRaw from "../../functions/api/auth/otp.ts?raw";
import registerFnRaw from "../../functions/api/auth/register.ts?raw";

const base: SignupInput = {
  name: "Ada Lovelace",
  email: "ada@example.com",
  phone: "+91 98765 43210",
  password: "Str0ng!pass",
  confirm: "Str0ng!pass",
  acceptTerms: true,
};
const err = (i: SignupInput) => {
  const r = validateSignup(i);
  return r.ok ? null : r.error;
};

describe("sign-up tracks", () => {
  it("has the same four flows as register.php", () => {
    for (const f of FLOWS) expect(phpRegister).toContain(`'${f}'`);
    expect(FLOWS).toHaveLength(4);
  });

  it("gives enterprise tracks admin and client tracks client, as register.php does", () => {
    expect(phpRegister).toContain("in_array($flow, ['enterprise_full', 'enterprise_solo'], true) ? 'admin' : 'client'");
    expect(roleFor("enterprise_full")).toBe("admin");
    expect(roleFor("enterprise_solo")).toBe("admin");
    expect(roleFor("client_company")).toBe("client");
    expect(roleFor("client_individual")).toBe("client");
  });

  it("maps each track to verify_register.php's account type", () => {
    for (const [flow, type] of Object.entries(ACCOUNT_TYPE)) expect(phpVerify).toMatch(new RegExp(`'${flow}'\\s*=>\\s*'${type}'`));
  });

  it("uses core/onboarding.php's company sizes and core/countries.php's countries", () => {
    for (const s of COMPANY_SIZES) expect(phpOnboarding).toContain(`'${s.value}'`);
    const phpCount = (phpCountries.match(/'[A-Z]{2}'\s*=>/g) ?? []).length;
    expect(COUNTRIES).toHaveLength(phpCount);
    for (const c of ["India", "United States", "Åland Islands"]) expect(COUNTRIES).toContain(c);
  });

  it("asks each track for exactly its own fields", () => {
    expect(err({ ...base, flow: "client_company" })).toBe("Enter your organization's name.");
    expect(err({ ...base, flow: "client_company", companyName: "Acme" })).toBe("Choose your company's size.");
    expect(err({ ...base, flow: "client_company", companyName: "Acme", companySize: "11-50" })).toBeNull();
    expect(err({ ...base, flow: "client_individual" })).toBe("Choose your country from the list.");
    expect(err({ ...base, flow: "client_individual", country: "India" })).toBeNull();
    expect(err({ ...base, flow: "enterprise_solo", country: "India" })).toBe("Enter your studio or developer name.");
    expect(err({ ...base, flow: "enterprise_solo", country: "India", companyName: "Solo Studio" })).toBeNull();
    expect(err({ ...base, flow: "enterprise_full", companyName: "Acme Corp", companySize: "51-200" })).toBeNull();
  });

  it("names an individual client's workspace after them, like register.php", () => {
    const r = validateSignup({ ...base, flow: "client_individual", country: "India" });
    expect(r.ok && r.plan.companyName).toBe("Ada Lovelace (Individual)");
  });

  it("keeps a leave policy only for a full organization, clamped to register.php's ranges", () => {
    const full = validateSignup({ ...base, flow: "enterprise_full", companyName: "Acme", companySize: "1-10", generalLeaves: 99, sickLeaves: -4, annualLeave: 200, leaveCycle: "yearly_rollover" });
    expect(full.ok && full.plan.leavePolicy).toEqual({ leave_cycle: "yearly_rollover", monthly_general_leaves: 30, monthly_sick_leaves: 0, annual_leave_allowance: 90 });
    const solo = validateSignup({ ...base, flow: "enterprise_solo", companyName: "S", country: "India", generalLeaves: 5 });
    expect(solo.ok && solo.plan.leavePolicy).toBeNull();
  });

  it("applies register.php's account checks in order", () => {
    const ok = { ...base, flow: "client_individual", country: "India" };
    expect(err({ ...ok, acceptTerms: false })).toMatch(/accept the Terms/);
    expect(err({ ...ok, phone: "" })).toBe("All required fields must be filled.");
    expect(err({ ...ok, country: "Atlantis" })).toBe("Choose your country from the list.");
    expect(err({ ...ok, email: "not-an-email" })).toBe("Please enter a valid email address.");
    expect(err({ ...ok, phone: "98765 43210" })).toMatch(/valid phone number/);
    expect(err({ ...ok, confirm: "different" })).toBe("Passwords do not match.");
    expect(err({ ...ok, password: "Str0ngpass", confirm: "Str0ngpass" })).toBe("Password must contain at least 1 special character.");
    expect(err({ ...ok, flow: "client_company", companyName: "Acme", companySize: "1-10", contractRef: "x".repeat(61) })).toMatch(/at most 60/);
  });

  it("falls back to client_company for an unknown flow, never to an admin track", () => {
    const r = validateSignup({ ...base, flow: "superuser", companyName: "Acme", companySize: "1-10" });
    expect(r.ok && r.plan.flow).toBe("client_company");
    expect(r.ok && r.plan.role).toBe("client");
  });

  it("grants the role only when the emailed code is accepted, from the stored track", () => {
    expect(registerFnRaw).not.toMatch(/\brole:/);
    expect(otpRaw).toContain("role = roleFor(signup.flow)");
    expect(otpRaw).toMatch(/purpose === "register" && user\.status !== "pending_verification"/);
    expect(otpRaw).toMatch(/purpose === "login" && user\.status !== "active"/);
  });

  it("shows both portals and both account types on the sign-up screen", () => {
    for (const label of ["Company", "Individual / Freelancer", "Full Organization", "Solo Enterprise"]) {
      expect(registerRaw).toContain(`label: "${label}"`);
    }
    expect(phpRegister).toContain("['Full Organization', 'Solo Enterprise']");
    expect(registerRaw).toContain('params.get("track") === "enterprise"');
  });
});
