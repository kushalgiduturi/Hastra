-- Deny-by-default for the anon/authenticated roles. All application access
-- goes through Pages Functions using the service_role key, which bypasses
-- RLS entirely, so this has no effect on the app — it just closes off any
-- access via the anon/publishable key, which is never sent to the browser
-- by design but costs nothing to block anyway.
ALTER TABLE "public"."companies" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."users" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."sessions" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."logs" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "public"."audit_chain_cursor" ENABLE ROW LEVEL SECURITY;
