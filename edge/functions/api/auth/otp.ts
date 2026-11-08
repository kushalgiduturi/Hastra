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
import { roleFor } from "../../_lib/signup";
import { createCompanyFor, findCompanyByName, type PendingSignup } from "../../_lib/companies";

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

  // A register code only activates a pending account, and a login code only
  // signs in an active one: a code from one flow never opens the other.
  if (purpose === "login" && user.status !== "active") return Response.json({ error: "Invalid code" }, { status: 401 });
  if (purpose === "register" && user.status !== "pending_verification") {
    return Response.json({ error: "This account is already verified. Sign in instead." }, { status: 409 });
  }

  const patch: Record<string, unknown> = { otp: null, otp_expiry: null, otp_attempts: 0 };
  let role: string = user.role;
  if (purpose === "register") {
    patch.status = "active";
    // verify_register.php: the track's company is created now, owned by this
    // account, and the role comes from the track (never from the request).
    const signup = user.signup as PendingSignup | null;
    if (signup?.flow) {
      role = roleFor(signup.flow);
      const company = (await findCompanyByName(db, signup.company_name)) ? null : await createCompanyFor(db, user.id, signup, role as "admin" | "client");
      if (!company) {
        // The pending account stays (its audit entry points at it), with no
        // live code, so this email can sign up again with another track.
        await db.from("users").update({ otp: null, otp_expiry: null, otp_attempts: 0 }).eq("id", user.id);
        return Response.json(
          { error: `${signup.company_name} was registered on Hastra while you were verifying. Ask its IT Manager to add you to the team.` },
          { status: 409 },
        );
      }
      Object.assign(patch, {
        role,
        company_id: company.id,
        company_name: company.name,
        client_role: role === "client" ? "it_manager" : null,
        signup: null,
      });
    }
  }
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
    return Response.json({ ok: true, status: "active", role });
  }

  const { cookie } = await createSession(db, env, request, user.id);
  return new Response(JSON.stringify({ ok: true, role: user.role }), {
    headers: { "Content-Type": "application/json", "Set-Cookie": cookie },
  });
};
