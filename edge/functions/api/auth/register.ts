// Port of auth/register.php: the four self-serve tracks (functions/_lib/
// signup.ts). Creates the account as pending_verification with the chosen
// track held in users.signup, and emails the code; otp.ts creates the
// company and grants the role once the code is accepted, the way
// verify_register.php does. Nothing here grants a role.
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { dbEncrypt, blindIndex, hashPassword, generateOtp } from "../../_lib/crypto";
import { appendAuditLog } from "../../_lib/audit-chain";
import { sendMail } from "../../_lib/mail";
import { validateSignup, TERMS_VERSION, type SignupInput } from "../../_lib/signup";
import { findCompanyByName } from "../../_lib/companies";

export const onRequestPost: PagesFunction<Env> = async ({ request, env }) => {
  let body: SignupInput;
  try {
    body = await request.json<SignupInput>();
  } catch {
    return Response.json({ error: "Invalid request" }, { status: 400 });
  }

  const checked = validateSignup(body);
  if (!checked.ok) return Response.json({ error: checked.error }, { status: 400 });
  const plan = checked.plan;

  const db = dbFor(env);
  const existingCo = await findCompanyByName(db, plan.companyName);
  if (existingCo) {
    return Response.json(
      { error: `${existingCo.name} is already registered on Hastra. Ask its admin or IT Manager to add you to the team.` },
      { status: 409 },
    );
  }

  const emailBindex = await blindIndex("email_bindex", plan.email, env.HASTRA_DB_INDEX_KEY);
  const { data: existing } = await db
    .from("users")
    .select("id, status, otp, otp_expiry")
    .eq("email_bindex", emailBindex)
    .maybeSingle();
  // A pending sign-up whose code has lapsed (or was voided) is replaced by
  // this one; a live code means the earlier sign-up can still be verified.
  const reusable =
    existing?.status === "pending_verification" && (!existing.otp || !existing.otp_expiry || new Date(existing.otp_expiry).getTime() < Date.now());
  if (existing && !reusable) {
    return existing.status === "pending_verification"
      ? Response.json({ error: "A verification is already pending for this email.", pending: true }, { status: 409 })
      : Response.json({ error: "An account with that email already exists." }, { status: 409 });
  }

  const otp = generateOtp();
  const row = {
    name: await dbEncrypt(plan.name, env.HASTRA_DB_ENC_KEY),
    email: await dbEncrypt(plan.email, env.HASTRA_DB_ENC_KEY),
    email_bindex: emailBindex,
    phone: await dbEncrypt(plan.phone, env.HASTRA_DB_ENC_KEY),
    country: plan.country,
    password_hash: await hashPassword(plan.password),
    company_name: plan.companyName,
    status: "pending_verification",
    terms_accepted_at: new Date().toISOString(),
    terms_version: TERMS_VERSION,
    signup: {
      flow: plan.flow,
      company_name: plan.companyName,
      company_size: plan.companySize,
      contract_ref: plan.contractRef,
      leave_policy: plan.leavePolicy,
    },
    otp,
    otp_expiry: new Date(Date.now() + 10 * 60 * 1000).toISOString(),
    otp_attempts: 0,
  };
  const { data: user, error } = reusable
    ? await db.from("users").update(row).eq("id", existing!.id).eq("status", "pending_verification").select("id").single()
    : await db.from("users").insert(row).select("id").single();
  if (error || !user) return Response.json({ error: "Registration failed. Please try again." }, { status: 500 });

  try {
    await sendMail(
      env,
      plan.email,
      "Verify your email",
      `Hi ${plan.name},\n\nThank you for registering!\n\nYour email verification code is: ${otp}\n\nThis code will expire in 10 minutes.\n\nIf you did not register, please ignore this email.`,
    );
  } catch {
    // Same as the PHP flow: no code, no pending sign-up left behind. A new
    // row is removed; a reused one keeps no live code, so it can be retried.
    if (reusable) await db.from("users").update({ otp: null, otp_expiry: null }).eq("id", user.id);
    else await db.from("users").delete().eq("id", user.id).eq("status", "pending_verification");
    return Response.json({ error: "Could not send verification email. Please try again." }, { status: 502 });
  }

  await appendAuditLog(db, env.HASTRA_DB_INDEX_KEY, {
    userId: user.id,
    username: plan.name,
    action: "register.pending_verification",
    ipAddress: request.headers.get("CF-Connecting-IP"),
    geo: (request as unknown as { cf?: { country?: string } }).cf?.country ?? null,
    severity: "info",
    incidentType: null,
    details: `registration submitted (${plan.flow}), awaiting OTP`,
  });

  return Response.json({ ok: true });
};
