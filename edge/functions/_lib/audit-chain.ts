// Port of core/audit_chain.php: an HMAC-chained, tamper-evident activity log.
// Each row's current_hash = HMAC-SHA256(indexKey, canonical JSON of
// {chain_index, previous_hash, user_id, username, action, ip_address, timestamp,
//  geo, severity, incident_type, details}). Appends are serialized through a
// Postgres function (see supabase/migrations/0001_pilot_schema.sql,
// append_audit_log) so chain_index stays gapless under concurrent writers —
// the equivalent of MySQL's GET_LOCK in the PHP version.

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

/** Appends one entry to the chain via the atomic `append_audit_log` RPC. */
export async function appendAuditLog(db: SupabaseClient, indexKeyB64: string, entry: AuditEntryInput) {
  const timestamp = new Date().toISOString();

  // Reserve the next chain_index + previous_hash atomically in Postgres,
  // compute the hash here (keeps the HMAC key out of the database), then
  // commit the row. Two round-trips, but the reservation step is what
  // prevents a gap/race, not the hash computation.
  const { data: reservation, error: reserveErr } = await db.rpc("reserve_audit_slot");
  if (reserveErr) throw reserveErr;
  const { chain_index, previous_hash } = reservation as { chain_index: number; previous_hash: string };

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

  const { error: insertErr } = await db.from("logs").insert({ ...row, current_hash });
  if (insertErr) throw insertErr;

  // Advance the cursor's last_hash now that the row is durably committed.
  const { error: commitErr } = await db.rpc("commit_audit_hash", { p_chain_index: chain_index, p_hash: current_hash });
  if (commitErr) throw commitErr;

  return { chain_index, current_hash };
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
