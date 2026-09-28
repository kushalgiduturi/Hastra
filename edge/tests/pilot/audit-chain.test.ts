import { describe, it, expect } from "vitest";
import type { SupabaseClient } from "@supabase/supabase-js";
import { appendAuditLog, verifyAuditChain } from "../../functions/_lib/audit-chain";

const INDEX_KEY = "OTA5ODc2NTQzMjEwOTg3NjU0MzIxMDk4NzY1NDMyMTA="; // test-only

/**
 * A minimal in-memory stand-in for the pieces of SupabaseClient that
 * audit-chain.ts calls: rpc("reserve_audit_slot"), rpc("commit_audit_hash"),
 * from("logs").insert(...), and from("logs").select("*").order(...).
 * Mirrors the gapless-chain semantics of the real Postgres functions in
 * supabase/migrations/0001_pilot_schema.sql.
 */
function makeFakeDb() {
  const rows: any[] = [];
  let nextIndex = 0;
  let lastHash = "genesis";

  const client = {
    rpc(name: string, args?: Record<string, unknown>) {
      if (name === "reserve_audit_slot") {
        const result = { chain_index: nextIndex, previous_hash: lastHash };
        nextIndex += 1;
        return Promise.resolve({ data: result, error: null });
      }
      if (name === "commit_audit_hash") {
        lastHash = args!.p_hash as string;
        return Promise.resolve({ data: null, error: null });
      }
      throw new Error(`unexpected rpc ${name}`);
    },
    from(table: string) {
      if (table !== "logs") throw new Error(`unexpected table ${table}`);
      return {
        insert(row: any) {
          rows.push(row);
          return Promise.resolve({ error: null });
        },
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
  return client as unknown as SupabaseClient;
}

describe("audit chain", () => {
  it("appends entries with a gapless, hash-linked chain", async () => {
    const db = makeFakeDb();
    for (let i = 0; i < 5; i++) {
      await appendAuditLog(db, INDEX_KEY, {
        userId: null,
        username: `user-${i}`,
        action: "test.action",
        ipAddress: "127.0.0.1",
        geo: "US",
        severity: "info",
        incidentType: null,
        details: `entry ${i}`,
      });
    }
    const result = await verifyAuditChain(db, INDEX_KEY);
    expect(result.ok).toBe(true);
    expect(result.rowCount).toBe(5);
    expect(result.problems).toEqual([]);
  });

  it("detects tampering with a row's details", async () => {
    const db = makeFakeDb();
    await appendAuditLog(db, INDEX_KEY, {
      userId: null,
      username: "user-0",
      action: "test.action",
      ipAddress: "127.0.0.1",
      geo: "US",
      severity: "info",
      incidentType: null,
      details: "original",
    });
    await appendAuditLog(db, INDEX_KEY, {
      userId: null,
      username: "user-1",
      action: "test.action",
      ipAddress: "127.0.0.1",
      geo: "US",
      severity: "info",
      incidentType: null,
      details: "second",
    });

    // Tamper with row 0's details directly, bypassing the append API.
    const { data: rows } = await (db.from("logs").select("*") as any).order();
    rows[0].details = "tampered";
    // Re-seed the fake's internal rows via a fresh insert-free client won't
    // work here since the fake stores rows by reference from insert(); the
    // row object above *is* the stored row, so mutating it is sufficient.

    const result = await verifyAuditChain(db, INDEX_KEY);
    expect(result.ok).toBe(false);
    expect(result.problems.some((p) => p.includes("hash mismatch"))).toBe(true);
  });
});
