// Port of auth/register.php + auth/verify_register.php (combined for the
// pilot: this endpoint creates the pending-verification user and sends the
// OTP in one call; the client then calls otp.ts to verify and activate).
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { dbEncrypt, blindIndex, hashPassword, generateOtp } from "../../_lib/crypto";
import { appendAuditLog } from "../../_lib/audit-chain";
import { sendMail } from "../../_lib/mail";

const PASSWORD_RULE = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/;

export const onRequestPost: PagesFunction<Env> = async ({ request, env }) => {
  const body = await request.json<{ name: string; email: string; password: string; companyName?: string }>();
  const { name, email, password, companyName } = body;

  if (!name || !email || !password) {
    return Response.json({ error: "name, email and password are required" }, { status: 400 });
  }
  if (!PASSWORD_RULE.test(password)) {
    return Response.json({ error: "Password needs 8+ characters with upper, lower and a digit" }, { status: 400 });
  }

  const db = dbFor(env);
  const emailBindex = await blindIndex("email_bindex", email, env.HASTRA_DB_INDEX_KEY);

  const { data: existing } = await db.from("users").select("id").eq("email_bindex", emailBindex).maybeSingle();
  if (existing) {
    return Response.json({ error: "An account with this email already exists" }, { status: 409 });
  }

  const otp = generateOtp();
  const otpExpiry = new Date(Date.now() + 10 * 60 * 1000).toISOString();

  const { data: user, error } = await db
    .from("users")
    .insert({
      name: await dbEncrypt(name, env.HASTRA_DB_ENC_KEY),
      email: await dbEncrypt(email, env.HASTRA_DB_ENC_KEY),
      email_bindex: emailBindex,
      password_hash: await hashPassword(password),
      company_name: companyName ?? null,
      status: "pending_verification",
      otp,
      otp_expiry: otpExpiry,
    })
    .select("id")
    .single();
  if (error) return Response.json({ error: error.message }, { status: 500 });

  await sendMail(env, email, "Verify your Hastra account", `Your verification code is ${otp}. It expires in 10 minutes.`);

  await appendAuditLog(db, env.HASTRA_DB_INDEX_KEY, {
    userId: user.id,
    username: name,
    action: "register.pending_verification",
    ipAddress: request.headers.get("CF-Connecting-IP"),
    geo: (request as unknown as { cf?: { country?: string } }).cf?.country ?? null,
    severity: "info",
    incidentType: null,
    details: "registration submitted, awaiting OTP",
  });

  return Response.json({ ok: true, userId: user.id });
};
