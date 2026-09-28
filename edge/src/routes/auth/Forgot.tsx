import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { api } from "../../lib/api";

export default function Forgot() {
  const [email, setEmail] = useState("");
  const [busy, setBusy] = useState(false);
  const nav = useNavigate();

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    await api("/auth/forgot", { email });
    setBusy(false);
    nav(`/reset?email=${encodeURIComponent(email)}`);
  }

  return (
    <main style={{ maxWidth: 360, margin: "4rem auto", fontFamily: "sans-serif" }}>
      <h1>Reset your password</h1>
      <form onSubmit={onSubmit}>
        <label>
          Email
          <input value={email} onChange={(e) => setEmail(e.target.value)} type="email" required style={{ display: "block", width: "100%" }} />
        </label>
        <button type="submit" disabled={busy}>{busy ? "Sending…" : "Send reset code"}</button>
      </form>
    </main>
  );
}
