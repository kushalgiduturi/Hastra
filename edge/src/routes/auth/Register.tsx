import { useMemo, useState } from "react";
import { useNavigate, Link, useSearchParams } from "react-router-dom";
import { motion, MotionConfig } from "motion/react";
import { api } from "../../lib/api";
import { AuthShell } from "../../components/spatial/AuthShell";
import { Message } from "../../components/spatial/Message";
import { PORTAL_TABS, Segmented, type Portal } from "../../components/spatial/PortalTabs";
import { SelectField } from "../../components/spatial/SelectField";
import { IconEye, IconEyeOff, IconShieldCheck } from "../../components/spatial/icons";
import { FloatingInput } from "../../components/watermelon/floating-input";
import { Button } from "../../components/watermelon/button";
import { Badge } from "../../components/watermelon/badge";
import { COUNTRIES } from "../../../functions/_lib/countries";
import {
  COMPANY_SIZES,
  PASSWORD_RULES,
  needsContractRef,
  needsCountry,
  needsLeave,
  needsOrgName,
  needsOrgSize,
  validateSignup,
  type Flow,
} from "../../../functions/_lib/signup";

// The PHP app's legal pages (the edge site has none of its own).
const LEGAL = "https://hastra.onrender.com/legal/";

type Kind = "org" | "solo";

const flowOf = (portal: Portal, kind: Kind): Flow =>
  portal === "client" ? (kind === "org" ? "client_company" : "client_individual") : kind === "org" ? "enterprise_full" : "enterprise_solo";

// auth/register.php's FLOW_COPY. The logo finder is not ported (it calls an
// outside logo service), so the company tracks' step 1 wording drops it.
const FLOW_COPY: Record<
  Flow,
  { heading: string; intro: string; stepLabel: string; step1Heading: string; step1Subtitle: string; step2Heading: string; step2Subtitle: string; orgLabel: string }
> = {
  client_company: {
    heading: "Register your company",
    intro: "You'll be your company's IT Manager on Hastra. After signing in you can upload your team roster, invite everyone, and finish setting up your workspace.",
    stepLabel: "Company details",
    step1Heading: "Tell us about your company",
    step1Subtitle: "Your company's name and size.",
    step2Heading: "Your account",
    step2Subtitle: "You'll sign in with this email once your company is set up.",
    orgLabel: "Company name",
  },
  client_individual: {
    heading: "Register as a client",
    intro: "You're joining as an individual client. You'll be able to submit requirements, track delivery, and settle invoices directly.",
    stepLabel: "Your details",
    step1Heading: "Where are you based?",
    step1Subtitle: "Just enough to set up a personal workspace.",
    step2Heading: "Your account",
    step2Subtitle: "We'll email you a code to confirm this address, then you can sign in straight away.",
    orgLabel: "Company name",
  },
  enterprise_full: {
    heading: "Set up your organization",
    intro: "You'll be the admin of your own Hastra workspace, ready immediately. Invite your team, configure leave policy, and start tracking delivery today.",
    stepLabel: "Organization details",
    step1Heading: "Tell us about your organization",
    step1Subtitle: "Your organization's name and size, and your leave policy.",
    step2Heading: "Your admin account",
    step2Subtitle: "You'll sign in as the admin of this workspace.",
    orgLabel: "Organization name",
  },
  enterprise_solo: {
    heading: "Set up your solo workspace",
    intro: "A lightweight workspace built for a solo developer or studio, with the SDLC pipeline, delivery tracking, and invoicing.",
    stepLabel: "Your studio",
    step1Heading: "Tell us about you",
    step1Subtitle: "A lean setup with just the essentials.",
    step2Heading: "Your admin account",
    step2Subtitle: "You'll sign in as the admin of this workspace.",
    orgLabel: "Studio / developer name",
  },
};

// register.php's overview blocks, one per portal.
const OVERVIEW: Record<Portal, { badge: string; name: string; sub: string; body: string; feats: string[] }> = {
  client: {
    badge: "Client onboarding",
    name: "Client Gateway",
    sub: "Provision your secure client portal",
    body: "Provision your secure client portal in seconds. Collaborate with engineering teams, trace code deliverables live, verify automated compliance reports, and access zero-trust audit records.",
    feats: ["Live deliverable and sprint tracking", "Automated compliance reports", "Tamper-evident audit records"],
  },
  enterprise: {
    badge: "Workspace provisioning",
    name: "Enterprise Deployment",
    sub: "Provision an enterprise security tenant",
    body: "Provision an enterprise security tenant. Integrate custom STIX/TAXII threat intelligence pipelines, establish post-quantum encrypted vaults, and automate organization-wide SOC compliance.",
    feats: ["Multi-seat SOC workspace", "SIEM and threat-intelligence pipelines", "Post-quantum encrypted vaults and compliance specs"],
  },
};

// register.php's subLabels()
const KIND_LABELS: Record<Portal, { id: Kind; label: string }[]> = {
  client: [
    { id: "org", label: "Company" },
    { id: "solo", label: "Individual / Freelancer" },
  ],
  enterprise: [
    { id: "org", label: "Full Organization" },
    { id: "solo", label: "Solo Enterprise" },
  ],
};

const COUNTRY_OPTIONS = COUNTRIES.map((c) => ({ value: c, label: c }));
const LEAVE_CYCLES = [
  { value: "monthly", label: "Monthly allowance" },
  { value: "yearly_rollover", label: "Annual pool" },
];

function Overview({ portal, flow }: { portal: Portal; flow: Flow }) {
  const info = OVERVIEW[portal];
  const copy = FLOW_COPY[flow];
  return (
    <MotionConfig reducedMotion="user">
      <motion.div
        key={portal}
        initial={{ opacity: 0, y: 8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35, ease: [0.16, 1, 0.3, 1] }}
        className="flex flex-col"
      >
        <Badge className="self-start">{info.badge}</Badge>
        <p className="mt-6 text-[clamp(1.875rem,3.4vw,2.5rem)] leading-[1.1] font-semibold tracking-[-0.025em] text-foreground">
          Hastra
          <span className="mt-2 block text-[15px] font-semibold tracking-[0.18em] text-sp-accent-text uppercase">{info.name}</span>
        </p>
        <p className="mt-5 text-[15px] font-medium text-foreground">{info.sub}</p>
        <p className="mt-3 max-w-[52ch] text-[15px] leading-relaxed text-sp-text-2">{info.body}</p>
        <ul className="mt-6 flex flex-col gap-3 text-[14px] text-sp-text-2">
          {info.feats.map((f) => (
            <li key={f} className="flex items-start gap-2.5">
              <IconShieldCheck className="mt-0.5 size-4 shrink-0 text-sp-accent-text" />
              <span>{f}</span>
            </li>
          ))}
        </ul>
        <div className="mt-8 border-t border-border pt-6">
          <p className="text-[15px] font-semibold text-foreground">{copy.heading}</p>
          <p className="mt-2 text-[14px] leading-relaxed text-sp-text-2">{copy.intro}</p>
        </div>
      </motion.div>
    </MotionConfig>
  );
}

function LeaveSlider({ id, label, value, onChange, max }: { id: string; label: string; value: number; onChange: (n: number) => void; max: number }) {
  return (
    <div className="flex flex-col gap-2">
      <div className="flex items-baseline justify-between text-[13px]">
        <label htmlFor={id} className="text-sp-text-2">{label}</label>
        <output htmlFor={id} className="font-semibold text-foreground tabular-nums">{value}</output>
      </div>
      <input
        id={id}
        type="range"
        min={0}
        max={max}
        step={1}
        value={value}
        onChange={(e) => onChange(Number(e.target.value))}
        className="w-full cursor-pointer accent-[var(--sp-accent)]"
      />
    </div>
  );
}

function StepDots({ step, firstLabel }: { step: 1 | 2; firstLabel: string }) {
  const items = [firstLabel, "Your account"];
  return (
    <ol className="flex flex-wrap items-center gap-x-5 gap-y-2 text-[12.5px]" aria-label="Sign-up steps">
      {items.map((label, i) => {
        const n = i + 1;
        const current = n === step;
        const reached = n <= step;
        return (
          <li key={label} aria-current={current ? "step" : undefined} className={`flex items-center gap-2 ${current ? "font-semibold text-foreground" : "text-sp-text-2"}`}>
            <span className={`grid size-6 place-items-center rounded-full text-[11.5px] font-semibold ${reached ? "bg-primary text-primary-foreground" : "sp-inset text-sp-text-2"}`}>{n}</span>
            {label}
          </li>
        );
      })}
    </ol>
  );
}

export default function Register() {
  const [params] = useSearchParams();
  const nav = useNavigate();
  const [portal, setPortal] = useState<Portal>(params.get("track") === "enterprise" ? "enterprise" : "client");
  const [kind, setKind] = useState<Kind>("org");
  const flow = flowOf(portal, kind);
  const copy = FLOW_COPY[flow];
  const [step, setStep] = useState<1 | 2>(1);

  const [companyName, setCompanyName] = useState("");
  const [companySize, setCompanySize] = useState("");
  const [contractRef, setContractRef] = useState("");
  const [country, setCountry] = useState("");
  const [leaveCycle, setLeaveCycle] = useState("monthly");
  const [generalLeaves, setGeneralLeaves] = useState(1);
  const [sickLeaves, setSickLeaves] = useState(1);
  const [annualLeave, setAnnualLeave] = useState(18);

  const [acceptTerms, setAcceptTerms] = useState(false);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [showPassword, setShowPassword] = useState(false);

  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [busy, setBusy] = useState(false);

  const input = useMemo(
    () => ({ flow, name, email, phone, password, confirm, acceptTerms, companyName, companySize, contractRef, country, leaveCycle, generalLeaves, sickLeaves, annualLeave }),
    [flow, name, email, phone, password, confirm, acceptTerms, companyName, companySize, contractRef, country, leaveCycle, generalLeaves, sickLeaves, annualLeave],
  );

  function choose(next: () => void) {
    next();
    setError(null);
    setPending(false);
  }

  function continueToAccount() {
    if (needsOrgName(flow) && !companyName.trim()) {
      return setError(flow === "enterprise_solo" ? "Enter your studio or developer name." : "Enter your organization's name.");
    }
    if (needsOrgSize(flow) && !companySize) return setError("Choose your company's size.");
    if (needsCountry(flow) && !country) return setError("Choose your country from the list.");
    setStep(2);
  }

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setPending(false);
    if (step === 1) return continueToAccount();

    // The server runs the same checks (functions/_lib/signup.ts); this just
    // answers sooner.
    const checked = validateSignup(input);
    if (!checked.ok) return setError(checked.error);

    setBusy(true);
    try {
      await api("/auth/register", input);
      nav(`/otp?purpose=register&email=${encodeURIComponent(checked.plan.email)}`);
    } catch (err) {
      const msg = (err as Error).message;
      setError(msg);
      setPending(msg.startsWith("A verification is already pending"));
    } finally {
      setBusy(false);
    }
  }

  const passwordChecks = PASSWORD_RULES.map((r) => ({ label: r.label, ok: r.test(password) }));
  const eye = (
    <button
      type="button"
      onClick={() => setShowPassword((s) => !s)}
      aria-label={showPassword ? "Hide password" : "Show password"}
      aria-pressed={showPassword}
      className="grid size-11 place-items-center rounded-full text-sp-text-2 transition-colors hover:bg-sp-hover hover:text-foreground"
    >
      {showPassword ? <IconEyeOff className="size-[18px]" /> : <IconEye className="size-[18px]" />}
    </button>
  );

  return (
    <AuthShell
      title={step === 1 ? copy.step1Heading : copy.step2Heading}
      lead={step === 1 ? copy.step1Subtitle : copy.step2Subtitle}
      above={
        <div className="flex flex-col gap-3">
          <Segmented
            options={PORTAL_TABS}
            value={portal}
            onChange={(p) => choose(() => setPortal(p))}
            label="Choose a workspace type"
            idPrefix="reg-portal"
            panelId="reg-panel"
          />
          <Segmented
            options={KIND_LABELS[portal]}
            value={kind}
            onChange={(k) => choose(() => setKind(k))}
            label="Choose an account type"
            idPrefix="reg-kind"
            panelId="reg-panel"
            size="sm"
          />
          <div className="mt-3">
            <StepDots step={step} firstLabel={copy.stepLabel} />
          </div>
        </div>
      }
      tabPanel={{ id: "reg-panel", labelledBy: `reg-portal-${portal}` }}
      aside={<Overview portal={portal} flow={flow} />}
      footer={
        <span className="flex flex-wrap gap-x-2 gap-y-1">
          <span>Already have an account?</span>
          <Link to={portal === "enterprise" ? "/signin?portal=enterprise" : "/signin"} className="font-medium text-sp-accent-text hover:underline">Sign in</Link>
        </span>
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
        {step === 1 ? (
          <>
            {needsOrgName(flow) && (
              <FloatingInput label={copy.orgLabel} value={companyName} onChange={(e) => setCompanyName(e.target.value)} maxLength={150} autoComplete="organization" required />
            )}
            {needsOrgSize(flow) && <SelectField label="Company size" value={companySize} onChange={setCompanySize} options={COMPANY_SIZES} placeholder="Choose size" required />}
            {needsContractRef(flow) && (
              <FloatingInput label="Contract / PO reference (optional)" value={contractRef} onChange={(e) => setContractRef(e.target.value)} maxLength={60} />
            )}
            {needsCountry(flow) && <SelectField label="Country" value={country} onChange={setCountry} options={COUNTRY_OPTIONS} placeholder="Select your country" required />}
            {needsLeave(flow) && (
              <>
                <SelectField label="Employee leave policy" value={leaveCycle} onChange={setLeaveCycle} options={LEAVE_CYCLES} />
                <div className="sp-inset flex flex-col gap-4 rounded-[var(--radius-ctl)] p-4">
                  <LeaveSlider id="leave-general" label="General / month" value={generalLeaves} onChange={setGeneralLeaves} max={30} />
                  <LeaveSlider id="leave-sick" label="Sick / month" value={sickLeaves} onChange={setSickLeaves} max={30} />
                  <LeaveSlider id="leave-annual" label="Annual total" value={annualLeave} onChange={setAnnualLeave} max={90} />
                </div>
              </>
            )}
            {error && <Message tone="error">{error}</Message>}
            <Button type="submit" size="lg" className="mt-2">Continue</Button>
          </>
        ) : (
          <>
            <FloatingInput label="Full name" value={name} onChange={(e) => setName(e.target.value)} maxLength={100} autoComplete="name" required />
            <FloatingInput label="Email" value={email} onChange={(e) => setEmail(e.target.value)} type="email" maxLength={100} autoComplete="email" required />
            <div className="flex flex-col gap-1.5">
              <FloatingInput
                label="Phone number"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                type="tel"
                inputMode="tel"
                maxLength={20}
                autoComplete="tel"
                aria-describedby="phone-hint"
                required
              />
              <p id="phone-hint" className="px-1 text-[12px] text-muted-foreground">Start with + and your country code, for example +91 98765 43210.</p>
            </div>
            <FloatingInput
              label="Password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              type={showPassword ? "text" : "password"}
              maxLength={128}
              autoComplete="new-password"
              aria-describedby="password-rules"
              required
              trailing={eye}
            />
            <ul id="password-rules" className="grid grid-cols-2 gap-x-4 gap-y-1.5 px-1 text-[12.5px]" aria-label="Password requirements">
              {passwordChecks.map((c) => (
                <li key={c.label} className={`flex items-center gap-2 ${c.ok ? "text-sp-ok" : "text-sp-text-2"}`}>
                  <span aria-hidden="true" className={`size-1.5 shrink-0 rounded-full ${c.ok ? "bg-current" : "bg-sp-text-3"}`} />
                  {c.label}
                  <span className="sr-only">{c.ok ? "(met)" : "(not met)"}</span>
                </li>
              ))}
            </ul>
            <FloatingInput
              label="Confirm password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              type={showPassword ? "text" : "password"}
              maxLength={128}
              autoComplete="new-password"
              required
            />
            {confirm !== "" && confirm !== password && <p className="px-1 text-[12.5px] text-sp-bad">Passwords do not match.</p>}
            <label className="flex cursor-pointer items-start gap-2.5 text-[13px] leading-snug text-sp-text-2">
              <input
                type="checkbox"
                checked={acceptTerms}
                onChange={(e) => setAcceptTerms(e.target.checked)}
                className="mt-0.5 size-[18px] shrink-0 cursor-pointer accent-[var(--sp-accent)]"
                required
              />
              <span>
                I accept the{" "}
                <a href={`${LEGAL}terms`} target="_blank" rel="noopener" className="font-medium text-sp-accent-text hover:underline">Terms and Conditions</a>{" "}
                and acknowledge the{" "}
                <a href={`${LEGAL}privacy`} target="_blank" rel="noopener" className="font-medium text-sp-accent-text hover:underline">Privacy Policy</a>.
              </span>
            </label>
            {error && (
              <Message tone="error">
                {error}
                {pending && (
                  <>
                    {" "}
                    <Link to={`/otp?purpose=register&email=${encodeURIComponent(email.trim())}`} className="font-medium underline">Verify here</Link>.
                  </>
                )}
              </Message>
            )}
            <div className="mt-2 flex gap-3">
              <Button type="button" variant="secondary" size="lg" onClick={() => choose(() => setStep(1))}>Back</Button>
              <Button type="submit" size="lg" loading={busy} loadingLabel="Creating" className="flex-1">Create account</Button>
            </div>
          </>
        )}
      </form>
    </AuthShell>
  );
}
