import { useEffect, useRef, useState } from "react";
import { useNavigate, Link } from "react-router-dom";
import { api } from "../../lib/api";
import { readTheme, THEME_EVENT, type Theme } from "../../lib/theme";
import { AuthShell } from "../../components/spatial/AuthShell";
import { Message } from "../../components/spatial/Message";
import { FloatingInput } from "../../components/watermelon/floating-input";
import { Button } from "../../components/watermelon/button";

const RECAPTCHA_SCRIPT_ID = "recaptcha-api-script";

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

export default function Login() {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const nav = useNavigate();

  const [recaptchaEl, setRecaptchaEl] = useState<HTMLDivElement | null>(null);
  const widgetId = useRecaptcha(recaptchaEl);
  const grecaptchaRef = useRef<Window["grecaptcha"]>();
  grecaptchaRef.current = window.grecaptcha;

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
      title="Sign in to Hastra"
      lead="After your password we email you a one-time code."
      footer={
        <span className="flex flex-wrap justify-between gap-3">
          <Link to="/forgot" className="font-medium text-sp-accent-text hover:underline">Forgot password?</Link>
          <Link to="/register" className="font-medium text-sp-accent-text hover:underline">Create an account</Link>
        </span>
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4">
        <FloatingInput label="Email" value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="email" required />
        <FloatingInput label="Password" value={password} onChange={(e) => setPassword(e.target.value)} type="password" autoComplete="current-password" required />
        <div ref={setRecaptchaEl} className="min-h-[78px]" />
        {error && <Message tone="error">{error}</Message>}
        <Button type="submit" size="lg" loading={busy} loadingLabel="Signing in">Sign in</Button>
      </form>
    </AuthShell>
  );
}
