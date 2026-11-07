import { useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { api } from "../../lib/api";
import { AuthShell } from "../../components/spatial/AuthShell";
import { Message } from "../../components/spatial/Message";
import { FloatingInput } from "../../components/watermelon/floating-input";
import InputOtp10 from "../../components/watermelon/input-otp-10";
import { Button } from "../../components/watermelon/button";

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
    <AuthShell
      title="Set a new password"
      lead={
        email ? (
          <>
            Use the code we sent to
            <span className="block font-semibold [overflow-wrap:anywhere] text-foreground">{email}</span>
          </>
        ) : (
          "Use the code from your email."
        )
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-5">
        <InputOtp10 label="Reset code" value={code} onChange={setCode} autoFocus />
        <FloatingInput label="New password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} type="password" autoComplete="new-password" required />
        {error && <Message tone="error">{error}</Message>}
        <Button type="submit" size="lg" loading={busy} loadingLabel="Saving">Set password</Button>
      </form>
    </AuthShell>
  );
}
