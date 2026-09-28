// Port of auth/login.php: verifies reCAPTCHA + password, then (instead of
// creating a session directly) issues an OTP and hands off to otp.ts for
// the 2FA step, exactly like the PHP flow's login.php -> otp.php handoff.
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { verifyPassword, generateOtp } from "../../_lib/crypto";
import { blindIndex } from "../../_lib/crypto";
import { verifyRecaptcha } from "../../_lib/recaptcha";
import { appendAuditLog } from "../../_lib/audit-chain";
import { sendMail } from "../../_lib/mail";

export const onRequestPost: PagesFunction<Env> = async ({ request, env }) => {
  const { email, password, recaptchaToken } = await request.json<{ email: string; password: string; recaptchaToken: string }>();
  const ip = request.headers.get("CF-Connecting-IP");

  if (!(await verifyRecaptcha(env, recaptchaToken, ip))) {
    return Response.json({ error: "reCAPTCHA verification failed" }, { status: 400 });
  }

  const db = dbFor(env);
  const emailBindex = await blindIndex("email_bindex", email, env.HASTRA_DB_INDEX_KEY);
  const { data: user } = await db.from("users").select("*").eq("email_bindex", emailBindex).maybeSingle();

  // Constant-shape response whether the email exists or the password is
  // wrong, so a caller can't enumerate accounts from response differences.
  const genericFail = () => Response.json({ error: "Invalid email or password" }, { status: 401 });

  if (!user || user.status !== "active") return genericFail();
  if (!(await verifyPassword(password, user.password_hash))) {
    await appendAuditLog(db, env.HASTRA_DB_INDEX_KEY, {
      userId: user.id,
      username: null,
      action: "login.failed_password",
      ipAddress: ip,
      geo: (request as unknown as { cf?: { country?: string } }).cf?.country ?? null,
      severity: "warning",
      incidentType: null,
      details: "password mismatch",
    });
    return genericFail();
  }

  const otp = generateOtp();
  await db
    .from("users")
    .update({ otp, otp_expiry: new Date(Date.now() + 10 * 60 * 1000).toISOString(), otp_attempts: 0 })
    .eq("id", user.id);
  await sendMail(env, email, "Your Hastra sign-in code", `Your sign-in code is ${otp}. It expires in 10 minutes.`);

  return Response.json({ ok: true, otpRequired: true });
};
