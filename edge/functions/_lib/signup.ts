// The four self-serve sign-up tracks, ported from the PHP app's
// auth/register.php and auth/verify_register.php. Shared by the sign-up
// endpoint (functions/api/auth/register.ts), the account activation in
// otp.ts, and the sign-up screen (src/routes/auth/Register.tsx), so the
// screen and the server check exactly the same rules.
//
//   client_company     Client Gateway, Company
//   client_individual  Client Gateway, Individual / Freelancer
//   enterprise_full    Enterprise Workspace, Full Organization
//   enterprise_solo    Enterprise Workspace, Solo Enterprise / Developer
import { COUNTRIES } from "./countries";

export const FLOWS = ["client_company", "client_individual", "enterprise_full", "enterprise_solo"] as const;
export type Flow = (typeof FLOWS)[number];

/** core/onboarding.php COMPANY_SIZES */
export const COMPANY_SIZES: readonly { value: string; label: string }[] = [
  { value: "1-10", label: "1-10 people" },
  { value: "11-50", label: "11-50 people" },
  { value: "51-200", label: "51-200 people" },
  { value: "201-1000", label: "201-1,000 people" },
  { value: "1000+", label: "More than 1,000 people" },
];

/** config/config.php LEGAL_TERMS_VERSION */
export const TERMS_VERSION = "2026-09-26";

/** verify_register.php's account_type for the company each track creates */
export const ACCOUNT_TYPE: Record<Flow, string> = {
  client_company: "client_org",
  client_individual: "client_individual",
  enterprise_full: "full_org",
  enterprise_solo: "solo_enterprise",
};

export const needsOrgName = (f: Flow) => f === "client_company" || f === "enterprise_full" || f === "enterprise_solo";
export const needsOrgSize = (f: Flow) => f === "client_company" || f === "enterprise_full";
export const needsCountry = (f: Flow) => f === "client_individual" || f === "enterprise_solo";
export const needsLeave = (f: Flow) => f === "enterprise_full";
export const needsContractRef = (f: Flow) => f === "client_company";
/** Enterprise tracks own their workspace as admin; client tracks are clients. */
export const roleFor = (f: Flow) => (f === "enterprise_full" || f === "enterprise_solo" ? "admin" : "client");

export interface LeavePolicy {
  leave_cycle: "monthly" | "yearly_rollover";
  monthly_general_leaves: number;
  monthly_sick_leaves: number;
  annual_leave_allowance: number;
}

export interface SignupInput {
  flow?: string;
  name?: string;
  email?: string;
  phone?: string;
  password?: string;
  confirm?: string;
  acceptTerms?: boolean;
  companyName?: string;
  companySize?: string;
  contractRef?: string;
  country?: string;
  leaveCycle?: string;
  generalLeaves?: number | string;
  sickLeaves?: number | string;
  annualLeave?: number | string;
}

/** What the activation step needs to build the company and the account. */
export interface SignupPlan {
  flow: Flow;
  role: "admin" | "client";
  name: string;
  email: string;
  phone: string;
  password: string;
  companyName: string;
  companySize: string | null;
  contractRef: string | null;
  country: string | null;
  leavePolicy: LeavePolicy | null;
}

const clampInt = (v: unknown, lo: number, hi: number, dflt: number) => {
  const n = Math.trunc(Number(v));
  return Number.isFinite(n) ? Math.max(lo, Math.min(hi, n)) : dflt;
};

/** Spaces, dashes, dots and brackets are dropped, so "+91 98765-43210" passes. */
export const normalizePhone = (p: string) => p.replace(/[\s\-().]/g, "");

export const PASSWORD_RULES: { test: (p: string) => boolean; label: string; message: string }[] = [
  { test: (p) => p.length >= 8, label: "8+ characters", message: "Password must be at least 8 characters." },
  { test: (p) => /[A-Z]/.test(p), label: "Uppercase (A-Z)", message: "Password must contain at least 1 uppercase letter." },
  { test: (p) => /[a-z]/.test(p), label: "Lowercase (a-z)", message: "Password must contain at least 1 lowercase letter." },
  { test: (p) => /[0-9]/.test(p), label: "Number (0-9)", message: "Password must contain at least 1 number." },
  { test: (p) => /[^a-zA-Z0-9]/.test(p), label: "Special character", message: "Password must contain at least 1 special character." },
];

/**
 * auth/register.php's checks, in its order and with its messages. Fields a
 * track does not use are ignored, the same as the PHP form disabling them.
 * The company-name collision and the existing-email checks need the
 * database, so the endpoint runs those after this.
 */
export function validateSignup(input: SignupInput): { ok: true; plan: SignupPlan } | { ok: false; error: string } {
  const flow: Flow = (FLOWS as readonly string[]).includes(input.flow ?? "") ? (input.flow as Flow) : "client_company";
  const name = (input.name ?? "").trim();
  const email = (input.email ?? "").trim();
  const phone = normalizePhone(input.phone ?? "");
  const password = input.password ?? "";
  const confirm = input.confirm ?? "";
  const country = (input.country ?? "").trim();
  const companyName = needsOrgName(flow) ? (input.companyName ?? "").trim() : name !== "" ? `${name} (Individual)` : "";
  const companySize = needsOrgSize(flow) ? (input.companySize ?? "").trim() : "";
  const contractRef = needsContractRef(flow) ? (input.contractRef ?? "").trim() : "";

  const fail = (error: string) => ({ ok: false as const, error });

  if (input.acceptTerms !== true) return fail("Please accept the Terms and Conditions and acknowledge the Privacy Policy to continue.");
  if (!name || !email || !phone || !password || !confirm) return fail("All required fields must be filled.");
  if (name.length > 100 || email.length > 100) return fail("Name and email can be at most 100 characters.");
  if (needsOrgName(flow) && companyName === "") {
    return fail(flow === "enterprise_solo" ? "Enter your studio or developer name." : "Enter your organization's name.");
  }
  if (companyName.length > 150) return fail("The organization name can be at most 150 characters.");
  if (needsOrgSize(flow) && !COMPANY_SIZES.some((s) => s.value === companySize)) return fail("Choose your company's size.");
  if (needsCountry(flow) && !COUNTRIES.includes(country)) return fail("Choose your country from the list.");
  if (contractRef.length > 60) return fail("The contract reference can be at most 60 characters.");
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return fail("Please enter a valid email address.");
  if (!/^\+[1-9]\d{6,14}$/.test(phone)) return fail("Please enter a valid phone number, starting with + and your country code.");
  if (password !== confirm) return fail("Passwords do not match.");
  if (password.length > 128) return fail("Password can be at most 128 characters.");
  for (const rule of PASSWORD_RULES) if (!rule.test(password)) return fail(rule.message);

  const leavePolicy: LeavePolicy | null = needsLeave(flow)
    ? {
        leave_cycle: input.leaveCycle === "yearly_rollover" ? "yearly_rollover" : "monthly",
        monthly_general_leaves: clampInt(input.generalLeaves, 0, 30, 1),
        monthly_sick_leaves: clampInt(input.sickLeaves, 0, 30, 1),
        annual_leave_allowance: clampInt(input.annualLeave, 0, 90, 18),
      }
    : null;

  return {
    ok: true,
    plan: {
      flow,
      role: roleFor(flow),
      name,
      email,
      phone,
      password,
      companyName,
      companySize: companySize || null,
      contractRef: contractRef || null,
      country: needsCountry(flow) ? country : null,
      leavePolicy,
    },
  };
}
