import { useEffect, useRef, useState } from "react";
import { useNavigate, Link } from "react-router-dom";
import { api } from "../../lib/api";

const RECAPTCHA_SCRIPT_ID = "recaptcha-api-script";

/** Loads Google's reCAPTCHA API once and renders an explicit v2 widget into `container`. */
function useRecaptcha(container: HTMLElement | null) {
  const [widgetId, setWidgetId] = useState<number | null>(null);
  const renderedRef = useRef(false); // guards against StrictMode's dev-mode double-invoke

  useEffect(() => {
    if (!container || renderedRef.current) return;
    const siteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY;

    function render() {
      if (!window.grecaptcha || renderedRef.current) return;
      renderedRef.current = true;
      setWidgetId(window.grecaptcha.render(container!, { sitekey: siteKey }));
    }

    if (window.grecaptcha?.render) {
      render();
      return;
    }

    window.onRecaptchaApiLoad = render;
    if (!document.getElementById(RECAPTCHA_SCRIPT_ID)) {
      const script = document.createElement("script");
      script.id = RECAPTCHA_SCRIPT_ID;
      script.src = "https://www.google.com/recaptcha/api.js?onload=onRecaptchaApiLoad&render=explicit";
      script.async = true;
      script.defer = true;
      document.body.appendChild(script);
    }
  }, [container]);

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
      setError("reCAPTCHA is still loading — try again in a moment.");
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
    <main style={{ maxWidth: 360, margin: "4rem auto", fontFamily: "sans-serif" }}>
      <h1>Sign in to Hastra</h1>
      <form onSubmit={onSubmit}>
        <label>
          Email
          <input value={email} onChange={(e) => setEmail(e.target.value)} type="email" required style={{ display: "block", width: "100%" }} />
        </label>
        <label>
          Password
          <input value={password} onChange={(e) => setPassword(e.target.value)} type="password" required style={{ display: "block", width: "100%" }} />
        </label>
        <div ref={setRecaptchaEl} style={{ margin: "1rem 0" }} />
        {error && <p style={{ color: "crimson" }}>{error}</p>}
        <button type="submit" disabled={busy}>{busy ? "Signing in…" : "Sign in"}</button>
      </form>
      <p><Link to="/forgot">Forgot password?</Link> · <Link to="/register">Create an account</Link></p>
    </main>
  );
}
