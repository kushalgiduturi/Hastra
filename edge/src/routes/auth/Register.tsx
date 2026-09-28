import { useState } from "react";
import { useNavigate, Link } from "react-router-dom";
import { api } from "../../lib/api";

export default function Register() {
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const nav = useNavigate();

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      await api("/auth/register", { name, email, password });
      nav(`/otp?purpose=register&email=${encodeURIComponent(email)}`);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main style={{ maxWidth: 360, margin: "4rem auto", fontFamily: "sans-serif" }}>
      <h1>Create your Hastra account</h1>
      <form onSubmit={onSubmit}>
        <label>
          Name
          <input value={name} onChange={(e) => setName(e.target.value)} required style={{ display: "block", width: "100%" }} />
        </label>
        <label>
          Email
          <input value={email} onChange={(e) => setEmail(e.target.value)} type="email" required style={{ display: "block", width: "100%" }} />
        </label>
        <label>
          Password
          <input value={password} onChange={(e) => setPassword(e.target.value)} type="password" required style={{ display: "block", width: "100%" }} />
        </label>
        {error && <p style={{ color: "crimson" }}>{error}</p>}
        <button type="submit" disabled={busy}>{busy ? "Creating…" : "Create account"}</button>
      </form>
      <p><Link to="/signin">Already have an account? Sign in</Link></p>
    </main>
  );
}
