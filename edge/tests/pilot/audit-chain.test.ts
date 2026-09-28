import { describe, it, expect } from "vitest";
import type { SupabaseClient } from "@supabase/supabase-js";
import { appendAuditLog, verifyAuditChain } from "../../functions/_lib/audit-chain";

const INDEX_KEY = "OTA5ODc2NTQzMjEwOTg3NjU0MzIxMDk4NzY1NDMyMTA="; // test-only

/**
 * A minimal in-memory stand-in for the pieces of SupabaseClient that
 * audit-chain.ts calls: rpc("peek_audit_cursor"), rpc("commit_audit_log"),
 * and from("logs").select("*").order(...). Mirrors the crash-safe
 * peek-then-atomic-commit semantics of the real Postgres functions in
 * supabase/migrations/0003_atomic_audit_commit.sql, including rejecting a
 * commit whose expected cursor state is stale.
 */
function makeFakeDb() {
  const rows: any[] = [];
  let nextIndex = 0;
  let lastHash = "genesis";

  const client = {
    rpc(name: string, args?: Record<string, unknown>) {
      if (name === "peek_audit_cursor") {
        // PostgREST returns table-returning functions as an array of rows —
        // mirror that here so the test catches the real response shape.
        return Promise.resolve({ data: [{ chain_index: nextIndex, previous_hash: lastHash }], error: null });
      }
      if (name === "commit_audit_log") {
        const a = args as any;
        if (a.p_expected_index !== nextIndex || a.p_expected_prev !== lastHash) {
          return Promise.resolve({ data: null, error: { message: "audit_chain_conflict: cursor moved" } });
        }
        rows.push({
          chain_index: nextIndex,
          previous_hash: lastHash,
          current_hash: a.p_current_hash,
          user_id: a.p_user_id,
          username: a.p_username,
          action: a.p_action,
          ip_address: a.p_ip_address,
          timestamp: a.p_timestamp,
          geo: a.p_geo,
          severity: a.p_severity,
          incident_type: a.p_incident_type,
          details: a.p_details,
        });
        lastHash = a.p_current_hash;
        nextIndex += 1;
        return Promise.resolve({ data: nextIndex - 1, error: null });
      }
      throw new Error(`unexpected rpc ${name}`);
    },
    from(table: string) {
      if (table !== "logs") throw new Error(`unexpected table ${table}`);
      return {
        select() {
          return {
            order() {
              return Promise.resolve({ data: [...rows].sort((a, b) => a.chain_index - b.chain_index), error: null });
            },
          };
        },
      };
    },
  };
  return { client: client as unknown as SupabaseClient, peekCount: () => nextIndex };
}

function entry(i: number) {
  return {
    userId: null,
    username: `user-${i}`,
    action: "test.action",
    ipAddress: "127.0.0.1",
    geo: "US",
    severity: "info" as const,
    incidentType: null,
    details: `entry ${i}`,
  };
}

describe("audit chain", () => {
  it("appends entries with a gapless, hash-linked chain", async () => {
    const { client: db } = makeFakeDb();
    for (let i = 0; i < 5; i++) await appendAuditLog(db, INDEX_KEY, entry(i));

    const result = await verifyAuditChain(db, INDEX_KEY);
    expect(result.ok).toBe(true);
    expect(result.rowCount).toBe(5);
    expect(result.problems).toEqual([]);
  });

  it("detects tampering with a row's details", async () => {
    const { client: db } = makeFakeDb();
    await appendAuditLog(db, INDEX_KEY, entry(0));
    await appendAuditLog(db, INDEX_KEY, entry(1));

    const { data: rows } = await (db.from("logs").select("*") as any).order();
    rows[0].details = "tampered";

    const result = await verifyAuditChain(db, INDEX_KEY);
    expect(result.ok).toBe(false);
    expect(result.problems.some((p) => p.includes("hash mismatch"))).toBe(true);
  });

  it("leaves no gap when a commit loses a race against another writer", async () => {
    // Regression test for the pilot bug: a naive reserve-then-insert design
    // could advance the cursor and then fail to insert, permanently
    // orphaning a chain_index. This simulates two appenders racing off the
    // same peeked cursor state — the second commit must be rejected and
    // retried by appendAuditLog, never silently skip an index.
    const { client: db } = makeFakeDb();
    await appendAuditLog(db, INDEX_KEY, entry(0)); // establishes chain_index 0

    // Fire two concurrent appends "at once" against the same starting state.
    await Promise.all([appendAuditLog(db, INDEX_KEY, entry(1)), appendAuditLog(db, INDEX_KEY, entry(2))]);

    const result = await verifyAuditChain(db, INDEX_KEY);
    expect(result.ok).toBe(true);
    expect(result.rowCount).toBe(3);
  });
});
