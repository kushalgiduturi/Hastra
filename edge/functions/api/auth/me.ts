// Lightweight session-check endpoint backing the frontend's route guard —
// lets a layout component know who's signed in (and their role) before
// deciding whether to render a portal or redirect to /signin, without
// piggybacking that check onto a specific data endpoint.
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { requireSession } from "../../_lib/session";
import { dbDecrypt } from "../../_lib/crypto";

export const onRequestGet: PagesFunction<Env> = async ({ request, env }) => {
  const db = dbFor(env);
  const session = await requireSession(request, db, env);
  if (!session) return Response.json({ error: "Unauthorized" }, { status: 401 });

  const { data: user } = await db.from("users").select("id, name, role").eq("id", session.userId).single();
  if (!user) return Response.json({ error: "Unauthorized" }, { status: 401 });

  return Response.json({ userId: user.id, name: await dbDecrypt(user.name, env.HASTRA_DB_ENC_KEY), role: user.role });
};
