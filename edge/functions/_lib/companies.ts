// Companies for the self-serve sign-up tracks: the name lookup the PHP app's
// find_company_by_name() does (case-insensitive, exact), and the company a
// verified sign-up creates (create_company() in verify_register.php).
import type { SupabaseClient } from "@supabase/supabase-js";
import { ACCOUNT_TYPE, type Flow, type LeavePolicy } from "./signup";

/** Escapes LIKE wildcards so ilike compares the whole name literally. */
const likeLiteral = (s: string) => s.replace(/[\\%_]/g, (c) => `\\${c}`);

export async function findCompanyByName(db: SupabaseClient, name: string): Promise<{ id: string; name: string } | null> {
  if (!name) return null;
  const { data } = await db.from("companies").select("id, name").ilike("name", likeLiteral(name)).limit(1).maybeSingle();
  return data ?? null;
}

export interface PendingSignup {
  flow: Flow;
  company_name: string;
  company_size: string | null;
  contract_ref: string | null;
  leave_policy: LeavePolicy | null;
}

/**
 * Creates the company for a verified sign-up, owned by `userId`. A client
 * who registers becomes its IT Manager. Returns null when the name was
 * taken meanwhile (the unique index on lower(name) settles a race).
 */
export async function createCompanyFor(db: SupabaseClient, userId: string, s: PendingSignup, role: "admin" | "client") {
  const { data, error } = await db
    .from("companies")
    .insert({
      name: s.company_name,
      account_type: ACCOUNT_TYPE[s.flow],
      size_band: s.company_size,
      contract_ref: s.contract_ref,
      leave_policy: s.leave_policy,
      owner_user_id: userId,
      it_manager_id: role === "client" ? userId : null,
    })
    .select("id, name")
    .single();
  if (error) return null;
  return data as { id: string; name: string };
}
