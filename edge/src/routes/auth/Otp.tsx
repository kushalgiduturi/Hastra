import { useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { api } from "../../lib/api";
import { AuthShell } from "../../components/spatial/AuthShell";
import { Message } from "../../components/spatial/Message";
import InputOtp10 from "../../components/watermelon/input-otp-10";
import { Button } from "../../components/watermelon/button";

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
        // an enterprise sign-up is the workspace admin: open its own portal tab
        nav(result.role === "admin" ? "/signin?portal=enterprise" : "/signin");
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
    <AuthShell
      title="Enter your code"
      lead={
        <>
          We emailed a code to
          <span className="block font-semibold [overflow-wrap:anywhere] text-foreground">{email}</span>
        </>
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-6">
        <InputOtp10 label="Verification code" value={code} onChange={setCode} autoFocus />
        {error && <Message tone="error">{error}</Message>}
        <Button type="submit" size="lg" loading={busy} loadingLabel="Verifying">Verify</Button>
      </form>
    </AuthShell>
  );
}
