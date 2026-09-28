// Port of portals/sysadmin/roles.php: list every user with their decrypted
// display name/email and current role, and let a sysadmin change a role.
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { requireSession } from "../../_lib/session";
import { dbDecrypt } from "../../_lib/crypto";
import { appendAuditLog } from "../../_lib/audit-chain";

async function requireSysadmin(request: Request, env: Env) {
  const db = dbFor(env);
  const session = await requireSession(request, db, env);
  if (!session) return { db, session: null, actor: null };
  const { data: actor } = await db.from("users").select("*").eq("id", session.userId).single();
  if (!actor || actor.role !== "sysadmin") return { db, session, actor: null };
  return { db, session, actor };
}

export const onRequestGet: PagesFunction<Env> = async ({ request, env }) => {
  const { db, actor } = await requireSysadmin(request, env);
  if (!actor) return Response.json({ error: "Forbidden" }, { status: 403 });

  const { data: users, error } = await db.from("users").select("id, name, email, role, status").order("created_at", { ascending: false });
  if (error) return Response.json({ error: error.message }, { status: 500 });

  const decrypted = await Promise.all(
    (users ?? []).map(async (u) => ({
      id: u.id,
      name: await dbDecrypt(u.name, env.HASTRA_DB_ENC_KEY),
      email: await dbDecrypt(u.email, env.HASTRA_DB_ENC_KEY),
      role: u.role,
      status: u.status,
    })),
  );

  return Response.json({ users: decrypted });
};

export const onRequestPost: PagesFunction<Env> = async ({ request, env }) => {
  const { db, actor } = await requireSysadmin(request, env);
  if (!actor) return Response.json({ error: "Forbidden" }, { status: 403 });

  const { userId, role } = await request.json<{ userId: string; role: string }>();
  const ALLOWED_ROLES = new Set(["sysadmin", "admin", "employee", "client", "pending_employee"]);
  if (!ALLOWED_ROLES.has(role)) return Response.json({ error: "Invalid role" }, { status: 400 });

  const { error } = await db.from("users").update({ role }).eq("id", userId);
  if (error) return Response.json({ error: error.message }, { status: 500 });

  await appendAuditLog(db, env.HASTRA_DB_INDEX_KEY, {
    userId: actor.id,
    username: await dbDecrypt(actor.name, env.HASTRA_DB_ENC_KEY),
    action: "sysadmin.role_change",
    ipAddress: request.headers.get("CF-Connecting-IP"),
    geo: (request as unknown as { cf?: { country?: string } }).cf?.country ?? null,
    severity: "warning",
    incidentType: null,
    details: `set user ${userId} role to ${role}`,
  });

  return Response.json({ ok: true });
};
