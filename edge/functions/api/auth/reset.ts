// Port of auth/forgot_otp.php + auth/reset.php, combined: verifies the
// reset OTP and sets the new password in one call.
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { blindIndex, hashPassword } from "../../_lib/crypto";
import { appendAuditLog } from "../../_lib/audit-chain";

const PASSWORD_RULE = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/;
const MAX_ATTEMPTS = 5;

export const onRequestPost: PagesFunction<Env> = async ({ request, env }) => {
  const { email, code, newPassword } = await request.json<{ email: string; code: string; newPassword: string }>();
  if (!email || !code || !newPassword) {
    return Response.json({ error: "email, code and newPassword are required" }, { status: 400 });
  }
  if (!PASSWORD_RULE.test(newPassword)) {
    return Response.json({ error: "Password needs 8+ characters with upper, lower and a digit" }, { status: 400 });
  }

  const db = dbFor(env);
  const emailBindex = await blindIndex("email_bindex", email, env.HASTRA_DB_INDEX_KEY);
  const { data: user } = await db.from("users").select("*").eq("email_bindex", emailBindex).maybeSingle();
  if (!user) return Response.json({ error: "Invalid code" }, { status: 401 });

  if ((user.otp_attempts ?? 0) >= MAX_ATTEMPTS) {
    return Response.json({ error: "Too many attempts. Request a new code." }, { status: 429 });
  }
  if (!user.otp || !user.otp_expiry || new Date(user.otp_expiry).getTime() < Date.now() || user.otp !== code) {
    await db.from("users").update({ otp_attempts: (user.otp_attempts ?? 0) + 1 }).eq("id", user.id);
    return Response.json({ error: "Invalid or expired code" }, { status: 401 });
  }

  await db
    .from("users")
    .update({ password_hash: await hashPassword(newPassword), otp: null, otp_expiry: null, otp_attempts: 0 })
    .eq("id", user.id);

  await appendAuditLog(db, env.HASTRA_DB_INDEX_KEY, {
    userId: user.id,
    username: null,
    action: "password.reset",
    ipAddress: request.headers.get("CF-Connecting-IP"),
    geo: (request as unknown as { cf?: { country?: string } }).cf?.country ?? null,
    severity: "warning",
    incidentType: null,
    details: "password reset via OTP flow",
  });

  return Response.json({ ok: true });
};
