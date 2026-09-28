// Port of core/audit_chain.php: an HMAC-chained, tamper-evident activity log.
// Each row's current_hash = HMAC-SHA256(indexKey, canonical JSON of
// {chain_index, previous_hash, user_id, username, action, ip_address, timestamp,
//  geo, severity, incident_type, details}).
//
// Appends are crash-safe by construction (see supabase/migrations/
// 0003_atomic_audit_commit.sql): peek_audit_cursor() is read-only, and
// commit_audit_log() re-locks the cursor and inserts the row in the same
// Postgres transaction, aborting if the cursor moved since the peek. So
// either both the row and the cursor advance together, or neither does —
// there's no window where a failure between two separate calls can leave
// the cursor pointing past a chain_index nothing was ever written for.

import type { SupabaseClient } from "@supabase/supabase-js";

export interface AuditEntryInput {
  userId: string | null;
  username: string | null;
  action: string;
  ipAddress: string | null;
  geo: string | null;
  severity: "info" | "warning" | "critical";
  incidentType: string | null;
  details: string; // pre-encrypted / already-sanitized, never raw secrets
}

async function hmacHex(keyB64: string, message: string): Promise<string> {
  const keyBytes = Uint8Array.from(atob(keyB64), (c) => c.charCodeAt(0));
  const key = await crypto.subtle.importKey("raw", keyBytes, { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const sig = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(message));
  return [...new Uint8Array(sig)].map((b) => b.toString(16).padStart(2, "0")).join("");
}

function canonicalize(row: {
  chain_index: number;
  previous_hash: string;
  user_id: string | null;
  username: string | null;
  action: string;
  ip_address: string | null;
  timestamp: string;
  geo: string | null;
  severity: string;
  incident_type: string | null;
  details: string;
}): string {
  // Fixed key order — must match the Postgres side exactly for hashes to agree.
  return JSON.stringify({
    chain_index: row.chain_index,
    previous_hash: row.previous_hash,
    user_id: row.user_id,
    username: row.username,
    action: row.action,
    ip_address: row.ip_address,
    timestamp: row.timestamp,
    geo: row.geo,
    severity: row.severity,
    incident_type: row.incident_type,
    details: row.details,
  });
}

const MAX_ATTEMPTS = 3;

/** Appends one entry to the chain, retrying on a concurrent-writer conflict. */
export async function appendAuditLog(db: SupabaseClient, indexKeyB64: string, entry: AuditEntryInput) {
  const timestamp = new Date().toISOString();

  for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
    // PostgREST returns a table-returning function's result as an array of
    // rows, even though it only ever produces exactly one here.
    const { data: peeked, error: peekErr } = await db.rpc("peek_audit_cursor");
    if (peekErr) throw peekErr;
    const [{ chain_index, previous_hash }] = peeked as { chain_index: number; previous_hash: string }[];

    const row = {
      chain_index,
      previous_hash,
      user_id: entry.userId,
      username: entry.username,
      action: entry.action,
      ip_address: entry.ipAddress,
      timestamp,
      geo: entry.geo,
      severity: entry.severity,
      incident_type: entry.incidentType,
      details: entry.details,
    };
    const current_hash = await hmacHex(indexKeyB64, canonicalize(row));

    const { error: commitErr } = await db.rpc("commit_audit_log", {
      p_expected_index: chain_index,
      p_expected_prev: previous_hash,
      p_user_id: entry.userId,
      p_username: entry.username,
      p_action: entry.action,
      p_ip_address: entry.ipAddress,
      p_timestamp: timestamp,
      p_geo: entry.geo,
      p_severity: entry.severity,
      p_incident_type: entry.incidentType,
      p_details: entry.details,
      p_current_hash: current_hash,
    });

    if (!commitErr) return { chain_index, current_hash };
    // Another writer committed between our peek and commit — retry with a
    // fresh peek. Any other error propagates immediately.
    if (!commitErr.message?.includes("audit_chain_conflict") || attempt === MAX_ATTEMPTS) throw commitErr;
  }
  throw new Error("appendAuditLog: exhausted retries under contention");
}

/** Walks the chain and verifies index contiguity + hash agreement. */
export async function verifyAuditChain(db: SupabaseClient, indexKeyB64: string) {
  const { data: rows, error } = await db.from("logs").select("*").order("chain_index", { ascending: true });
  if (error) throw error;

  let expectedIndex = 0;
  let expectedPrev = "genesis";
  const problems: string[] = [];

  for (const row of rows ?? []) {
    if (row.chain_index !== expectedIndex) {
      problems.push(`gap at chain_index ${expectedIndex}: found ${row.chain_index}`);
    }
    if (row.previous_hash !== expectedPrev) {
      problems.push(`chain_index ${row.chain_index}: previous_hash mismatch`);
    }
    const recomputed = await hmacHex(indexKeyB64, canonicalize(row));
    if (recomputed !== row.current_hash) {
      problems.push(`chain_index ${row.chain_index}: hash mismatch (tampered?)`);
    }
    expectedIndex = row.chain_index + 1;
    expectedPrev = row.current_hash;
  }

  return { ok: problems.length === 0, problems, rowCount: rows?.length ?? 0 };
}
