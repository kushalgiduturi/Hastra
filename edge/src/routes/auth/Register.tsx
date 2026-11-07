import { useState } from "react";
import { useNavigate, Link } from "react-router-dom";
import { api } from "../../lib/api";
import { AuthShell } from "../../components/spatial/AuthShell";
import { Message } from "../../components/spatial/Message";
import { FloatingInput } from "../../components/watermelon/floating-input";
import { Button } from "../../components/watermelon/button";

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
    <AuthShell
      title="Create your Hastra account"
      lead="We email you a code to confirm the address."
      footer={
        <span>
          Already have an account? <Link to="/signin" className="font-medium text-sp-accent-text hover:underline">Sign in</Link>
        </span>
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4">
        <FloatingInput label="Name" value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" required />
        <FloatingInput label="Email" value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="email" required />
        <FloatingInput label="Password" value={password} onChange={(e) => setPassword(e.target.value)} type="password" autoComplete="new-password" required />
        {error && <Message tone="error">{error}</Message>}
        <Button type="submit" size="lg" loading={busy} loadingLabel="Creating" className="mt-2">Create account</Button>
      </form>
    </AuthShell>
  );
}
