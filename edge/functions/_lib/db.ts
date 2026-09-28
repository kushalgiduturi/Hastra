import { createClient, type SupabaseClient } from "@supabase/supabase-js";
import type { Env } from "./env";

/** Service-role Supabase client — Functions-only, never sent to the browser. */
export function dbFor(env: Env): SupabaseClient {
  return createClient(env.SUPABASE_URL, env.SUPABASE_SERVICE_ROLE_KEY, {
    auth: { persistSession: false, autoRefreshToken: false },
  });
}
