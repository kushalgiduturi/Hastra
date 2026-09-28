// Port of auth/logout.php.
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { requireSession, destroySession } from "../../_lib/session";
import { appendAuditLog } from "../../_lib/audit-chain";

export const onRequestPost: PagesFunction<Env> = async ({ request, env }) => {
  const db = dbFor(env);
  const session = await requireSession(request, db, env);
  const cookie = await destroySession(request, db);

  if (session) {
    await appendAuditLog(db, env.HASTRA_DB_INDEX_KEY, {
      userId: session.userId,
      username: null,
      action: "logout",
      ipAddress: request.headers.get("CF-Connecting-IP"),
      geo: (request as unknown as { cf?: { country?: string } }).cf?.country ?? null,
      severity: "info",
      incidentType: null,
      details: "user logged out",
    });
  }

  return new Response(JSON.stringify({ ok: true }), {
    headers: { "Content-Type": "application/json", "Set-Cookie": cookie },
  });
};
