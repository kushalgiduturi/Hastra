import { useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { api } from "../../lib/api";

export default function Otp() {
  const [params] = useSearchParams();
  const email = params.get("email") ?? "";
  const purpose = (params.get("purpose") as "register" | "login") ?? "login";
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const nav = useNavigate();

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      const result = await api<{ ok: boolean; role?: string; status?: string }>("/auth/otp", { email, code, purpose });
      if (purpose === "register") {
        nav("/signin");
      } else if (result.role === "sysadmin") {
        nav("/workspace/sysadmin");
      } else {
        nav("/signin"); // other roles aren't ported in the pilot yet
      }
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main style={{ maxWidth: 360, margin: "4rem auto", fontFamily: "sans-serif" }}>
      <h1>Enter your code</h1>
      <p>We emailed a code to {email}.</p>
      <form onSubmit={onSubmit}>
        <input value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" maxLength={6} required style={{ display: "block", width: "100%" }} />
        {error && <p style={{ color: "crimson" }}>{error}</p>}
        <button type="submit" disabled={busy}>{busy ? "Verifying…" : "Verify"}</button>
      </form>
    </main>
  );
}
