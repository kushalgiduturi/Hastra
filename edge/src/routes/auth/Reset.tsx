import { useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { api } from "../../lib/api";

export default function Reset() {
  const [params] = useSearchParams();
  const email = params.get("email") ?? "";
  const [code, setCode] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const nav = useNavigate();

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      await api("/auth/reset", { email, code, newPassword });
      nav("/signin");
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main style={{ maxWidth: 360, margin: "4rem auto", fontFamily: "sans-serif" }}>
      <h1>Set a new password</h1>
      <form onSubmit={onSubmit}>
        <label>
          Code
          <input value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" maxLength={6} required style={{ display: "block", width: "100%" }} />
        </label>
        <label>
          New password
          <input value={newPassword} onChange={(e) => setNewPassword(e.target.value)} type="password" required style={{ display: "block", width: "100%" }} />
        </label>
        {error && <p style={{ color: "crimson" }}>{error}</p>}
        <button type="submit" disabled={busy}>{busy ? "Saving…" : "Set password"}</button>
      </form>
    </main>
  );
}
