// Port of auth/otp.php (login 2FA) and auth/verify_register.php's OTP step.
// One endpoint handles both: `purpose: "register"` activates a pending
// account, `purpose: "login"` upgrades a password-verified request into a
// real session. Both check the same users.otp/otp_expiry columns with a
// 5-attempt lockout (users.otp_attempts), matching the PHP behaviour.
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { dbDecrypt, blindIndex } from "../../_lib/crypto";
import { appendAuditLog } from "../../_lib/audit-chain";
import { createSession } from "../../_lib/session";

const MAX_ATTEMPTS = 5;

export const onRequestPost: PagesFunction<Env> = async (ctx) => {
  const { request, env } = ctx;
  const { email, code, purpose } = await request.json<{ email: string; code: string; purpose: "register" | "login" }>();
  if (!email || !code || !purpose) {
    return Response.json({ error: "email, code and purpose are required" }, { status: 400 });
  }

  const db = dbFor(env);
  const emailBindex = await blindIndex("email_bindex", email, env.HASTRA_DB_INDEX_KEY);
  const { data: user } = await db.from("users").select("*").eq("email_bindex", emailBindex).maybeSingle();
  if (!user) return Response.json({ error: "Invalid code" }, { status: 401 });

  if ((user.otp_attempts ?? 0) >= MAX_ATTEMPTS) {
    return Response.json({ error: "Too many attempts. Request a new code." }, { status: 429 });
  }
  if (!user.otp || !user.otp_expiry || new Date(user.otp_expiry).getTime() < Date.now()) {
    return Response.json({ error: "Code expired. Request a new one." }, { status: 401 });
  }
  if (user.otp !== code) {
    await db.from("users").update({ otp_attempts: (user.otp_attempts ?? 0) + 1 }).eq("id", user.id);
    return Response.json({ error: "Invalid code" }, { status: 401 });
  }

  const patch: Record<string, unknown> = { otp: null, otp_expiry: null, otp_attempts: 0 };
  if (purpose === "register") patch.status = "active";
  await db.from("users").update(patch).eq("id", user.id);

  const name = await dbDecrypt(user.name, env.HASTRA_DB_ENC_KEY);
  await appendAuditLog(db, env.HASTRA_DB_INDEX_KEY, {
    userId: user.id,
    username: name,
    action: purpose === "register" ? "register.verified" : "login.otp_verified",
    ipAddress: request.headers.get("CF-Connecting-IP"),
    geo: (request as unknown as { cf?: { country?: string } }).cf?.country ?? null,
    severity: "info",
    incidentType: null,
    details: "OTP accepted",
  });

  if (purpose === "register") {
    return Response.json({ ok: true, status: "active" });
  }

  const { cookie } = await createSession(db, env, request, user.id);
  return new Response(JSON.stringify({ ok: true, role: user.role }), {
    headers: { "Content-Type": "application/json", "Set-Cookie": cookie },
  });
};
