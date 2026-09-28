// Port of portals/sysadmin/logs.php: paginated audit-chain view + an
// on-demand chain-integrity check (core/verify_audit.php equivalent).
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { requireSession } from "../../_lib/session";
import { verifyAuditChain } from "../../_lib/audit-chain";

export const onRequestGet: PagesFunction<Env> = async ({ request, env }) => {
  const db = dbFor(env);
  const session = await requireSession(request, db, env);
  if (!session) return Response.json({ error: "Unauthorized" }, { status: 401 });
  const { data: actor } = await db.from("users").select("role").eq("id", session.userId).single();
  if (actor?.role !== "sysadmin") return Response.json({ error: "Forbidden" }, { status: 403 });

  const url = new URL(request.url);
  if (url.searchParams.get("verify") === "1") {
    const result = await verifyAuditChain(db, env.HASTRA_DB_INDEX_KEY);
    return Response.json(result);
  }

  const page = Number(url.searchParams.get("page") ?? "0");
  const pageSize = 50;
  const { data: rows, error } = await db
    .from("logs")
    .select("chain_index, username, action, ip_address, timestamp, severity, incident_type")
    .order("chain_index", { ascending: false })
    .range(page * pageSize, page * pageSize + pageSize - 1);
  if (error) return Response.json({ error: error.message }, { status: 500 });

  return Response.json({ rows });
};
