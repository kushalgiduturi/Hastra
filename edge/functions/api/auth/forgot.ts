// Port of auth/forgot.php: looks up the account by blind index and emails
// a reset OTP. Always responds 200 (never reveals whether the email exists).
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { blindIndex, generateOtp } from "../../_lib/crypto";
import { sendMail } from "../../_lib/mail";

export const onRequestPost: PagesFunction<Env> = async ({ request, env }) => {
  const { email } = await request.json<{ email: string }>();
  if (!email) return Response.json({ error: "email is required" }, { status: 400 });

  const db = dbFor(env);
  const emailBindex = await blindIndex("email_bindex", email, env.HASTRA_DB_INDEX_KEY);
  const { data: user } = await db.from("users").select("id").eq("email_bindex", emailBindex).maybeSingle();

  if (user) {
    const otp = generateOtp();
    await db
      .from("users")
      .update({ otp, otp_expiry: new Date(Date.now() + 10 * 60 * 1000).toISOString(), otp_attempts: 0 })
      .eq("id", user.id);
    await sendMail(env, email, "Reset your Hastra password", `Your password-reset code is ${otp}. It expires in 10 minutes.`);
  }

  return Response.json({ ok: true });
};
