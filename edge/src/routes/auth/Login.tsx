import { useEffect, useRef, useState } from "react";
import { useNavigate, Link, useSearchParams } from "react-router-dom";
import { motion, MotionConfig } from "motion/react";
import { api } from "../../lib/api";
import { readTheme, THEME_EVENT, type Theme } from "../../lib/theme";
import { AuthShell } from "../../components/spatial/AuthShell";
import { Message } from "../../components/spatial/Message";
import { PortalTabs, type Portal } from "../../components/spatial/PortalTabs";
import { GoogleButton, SsoDivider } from "../../components/spatial/GoogleButton";
import { IconEye, IconEyeOff, IconShieldCheck } from "../../components/spatial/icons";
import { FloatingInput } from "../../components/watermelon/floating-input";
import { Button } from "../../components/watermelon/button";
import { Badge } from "../../components/watermelon/badge";

const RECAPTCHA_SCRIPT_ID = "recaptcha-api-script";
const REMEMBER_KEY = "hastra_remember_email";

// The two portals of the PHP app's auth/login.php, with its wording. Both
// sign in through the same check; the portal sets the copy, the overview
// and where "create an account" leads (register's enterprise or client track).
const PORTALS: Record<
  Portal,
  {
    title: string;
    lead: string;
    signup: { prompt: string; label: string; to: string };
    info: { badge: string; name: string; sub: string; body: string; feats: string[] };
  }
> = {
  enterprise: {
    title: "Sign in",
    lead: "Enter your workspace credentials to access Hastra.",
    signup: { prompt: "New organization?", label: "Set up your workspace", to: "/register?track=enterprise" },
    info: {
      badge: "Enterprise workspace",
      name: "Enterprise Core",
      sub: "SOC Governance & Cryptographic Control Plane",
      body: "Direct access to multi-engine post-quantum key encapsulation (ML-KEM/ML-DSA), internal SIEM threat pipelines, and executive compliance controls.",
      feats: ["ML-KEM and ML-DSA post-quantum cryptography", "Internal SIEM threat-intelligence pipelines", "Executive compliance and governance controls"],
    },
  },
  client: {
    title: "Client sign in",
    lead: "Access the project workspace your company was onboarded into.",
    signup: { prompt: "New client company?", label: "Create your workspace", to: "/register" },
    info: {
      badge: "Client portal",
      name: "Client Gateway",
      sub: "Autonomous Project Oversight & Delivery Tracking",
      body: "Complete transparency into deliverables, active security scanners, sprint telemetry, and HMAC-verified audit ledgers for onboarded organizations.",
      feats: ["Deliverables and sprint telemetry in one view", "Live results from active security scanners", "HMAC-verified, tamper-evident audit ledger"],
    },
  },
};

/**
 * Loads Google's reCAPTCHA API once and renders an explicit v2 widget into
 * `container`, in the current theme. Google cannot recolour a widget or
 * render twice into one element, so each render (first load, or a theme
 * switch) goes into a fresh inner slot and replaces the old widget. A
 * half-done challenge is reset by a switch, the same as an expired token.
 */
function useRecaptcha(container: HTMLElement | null) {
  const [widgetId, setWidgetId] = useState<number | null>(null);
  const [theme, setTheme] = useState<Theme>(() => readTheme());

  useEffect(() => {
    const onTheme = (e: Event) => setTheme((e as CustomEvent<Theme>).detail);
    window.addEventListener(THEME_EVENT, onTheme);
    return () => window.removeEventListener(THEME_EVENT, onTheme);
  }, []);

  useEffect(() => {
    if (!container) return;
    const siteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY;
    let cancelled = false; // StrictMode runs effects twice in dev; only the live run renders

    function render() {
      if (cancelled || !window.grecaptcha?.render) return;
      const slot = document.createElement("div");
      container!.replaceChildren(slot);
      setWidgetId(window.grecaptcha.render(slot, { sitekey: siteKey, theme }));
    }

    if (window.grecaptcha?.render) {
      render();
    } else {
      window.onRecaptchaApiLoad = render;
      if (!document.getElementById(RECAPTCHA_SCRIPT_ID)) {
        const script = document.createElement("script");
        script.id = RECAPTCHA_SCRIPT_ID;
        script.src = "https://www.google.com/recaptcha/api.js?onload=onRecaptchaApiLoad&render=explicit";
        script.async = true;
        script.defer = true;
        document.body.appendChild(script);
      }
    }
    return () => {
      cancelled = true;
    };
  }, [container, theme]);

  return widgetId;
}

function readRemembered(): string {
  try {
    return localStorage.getItem(REMEMBER_KEY) ?? "";
  } catch {
    return "";
  }
}

function PortalOverview({ portal }: { portal: Portal }) {
  const info = PORTALS[portal].info;
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
      </motion.div>
    </MotionConfig>
  );
}

export default function Login() {
  const [params] = useSearchParams();
  const [portal, setPortal] = useState<Portal>(params.get("portal") === "enterprise" ? "enterprise" : "client");
  const [email, setEmail] = useState(readRemembered);
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [remember, setRemember] = useState(() => readRemembered() !== "");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const nav = useNavigate();

  const [recaptchaEl, setRecaptchaEl] = useState<HTMLDivElement | null>(null);
  const widgetId = useRecaptcha(recaptchaEl);
  const grecaptchaRef = useRef<Window["grecaptcha"]>();
  grecaptchaRef.current = window.grecaptcha;

  const copy = PORTALS[portal];

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    if (widgetId === null || !grecaptchaRef.current) {
      setError("reCAPTCHA is still loading. Try again in a moment.");
      return;
    }
    const recaptchaToken = grecaptchaRef.current.getResponse(widgetId);
    if (!recaptchaToken) {
      setError("Please complete the reCAPTCHA.");
      return;
    }

    try {
      if (remember) localStorage.setItem(REMEMBER_KEY, email);
      else localStorage.removeItem(REMEMBER_KEY);
    } catch {
      /* storage blocked: sign-in still works, the email just isn't kept */
    }

    setBusy(true);
    try {
      await api("/auth/login", { email, password, recaptchaToken });
      nav(`/otp?purpose=login&email=${encodeURIComponent(email)}`);
    } catch (err) {
      setError((err as Error).message);
      grecaptchaRef.current?.reset(widgetId); // a used/expired token can't be reused
    } finally {
      setBusy(false);
    }
  }

  return (
    <AuthShell
      title={copy.title}
      lead={copy.lead}
      above={<PortalTabs value={portal} onChange={setPortal} panelId="portal-panel" />}
      tabPanel={{ id: "portal-panel", labelledBy: `portal-tab-${portal}` }}
      aside={<PortalOverview portal={portal} />}
      footer={
        <span className="flex flex-wrap gap-x-2 gap-y-1">
          <span>{copy.signup.prompt}</span>
          <Link to={copy.signup.to} className="font-medium text-sp-accent-text hover:underline">{copy.signup.label}</Link>
        </span>
      }
    >
      <div className="flex flex-col gap-5">
        <GoogleButton />
        <SsoDivider />
      </div>
      <form onSubmit={onSubmit} className="mt-5 flex flex-col gap-4">
        <FloatingInput
          label="Email address"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          type="email"
          name="email"
          maxLength={100}
          autoComplete="username"
          required
        />
        <FloatingInput
          label="Password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          type={showPassword ? "text" : "password"}
          name="password"
          maxLength={128}
          autoComplete="current-password"
          required
          trailing={
            <button
              type="button"
              onClick={() => setShowPassword((s) => !s)}
              aria-label={showPassword ? "Hide password" : "Show password"}
              aria-pressed={showPassword}
              className="grid size-11 place-items-center rounded-full text-sp-text-2 transition-colors hover:bg-sp-hover hover:text-foreground"
            >
              {showPassword ? <IconEyeOff className="size-[18px]" /> : <IconEye className="size-[18px]" />}
            </button>
          }
        />
        <div className="flex flex-wrap items-center justify-between gap-3 text-[13px]">
          <label className="inline-flex min-h-11 cursor-pointer items-center gap-2.5 text-sp-text-2">
            <input
              type="checkbox"
              checked={remember}
              onChange={(e) => setRemember(e.target.checked)}
              className="size-[18px] cursor-pointer accent-[var(--sp-accent)]"
            />
            Remember me
          </label>
          <Link to="/forgot" className="inline-flex min-h-11 items-center font-medium text-sp-accent-text hover:underline">Forgot password?</Link>
        </div>
        <div ref={setRecaptchaEl} className="min-h-[78px]" />
        {error && <Message tone="error">{error}</Message>}
        <Button type="submit" size="lg" loading={busy} loadingLabel="Signing in">Sign in</Button>
        <p className="text-center text-[12px] text-muted-foreground">After your password we email you a one-time code.</p>
      </form>
    </AuthShell>
  );
}
