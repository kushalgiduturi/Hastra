import { useState } from "react";
import { useNavigate, Link } from "react-router-dom";
import { api } from "../../lib/api";
import { AuthShell } from "../../components/spatial/AuthShell";
import { FloatingInput } from "../../components/watermelon/floating-input";
import { Button } from "../../components/watermelon/button";

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
    <AuthShell
      title="Reset your password"
      lead="Enter your account email and we send you a reset code."
      footer={
        <span>
          Remembered it? <Link to="/signin" className="font-medium text-sp-accent-text hover:underline">Back to sign in</Link>
        </span>
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4">
        <FloatingInput label="Email" value={email} onChange={(e) => setEmail(e.target.value)} type="email" autoComplete="email" required />
        <Button type="submit" size="lg" loading={busy} loadingLabel="Sending" className="mt-2">Send reset code</Button>
      </form>
    </AuthShell>
  );
}
