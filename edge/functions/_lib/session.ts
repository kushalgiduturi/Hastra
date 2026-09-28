// Port of core/db.php::secure_session_start() + core/session_guard.php.
// PHP used server-side $_SESSION; here a session is a row in `sessions`
// (id = opaque random token, hashed before storage) plus an httpOnly cookie
// carrying the raw token. The fingerprint guard binds a session to the
// browser's UA + Accept-Language + a /24 (v4) or /64 (v6) IP network, so
// stealing the cookie alone isn't enough to hijack the session from a
// different network/browser.

import type { SupabaseClient } from "@supabase/supabase-js";

const SESSION_COOKIE = "hastra_session";
const IDLE_TIMEOUT_MS = 30 * 60 * 1000;

function bytesToHex(bytes: Uint8Array): string {
  return [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("");
}

async function sha256Hex(input: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(input));
  return bytesToHex(new Uint8Array(digest));
}

async function hmacHex(keyB64: string, message: string): Promise<string> {
  const keyBytes = Uint8Array.from(atob(keyB64), (c) => c.charCodeAt(0));
  const key = await crypto.subtle.importKey("raw", keyBytes, { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const sig = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(message));
  return bytesToHex(new Uint8Array(sig));
}

function networkOf(ip: string): string {
  if (ip.includes(":")) return ip.split(":").slice(0, 4).join(":"); // /64-ish
  return ip.split(".").slice(0, 3).join("."); // /24
}

export async function fingerprintOf(request: Request, sessionKeyB64: string): Promise<string> {
  const ua = request.headers.get("User-Agent") ?? "";
  const lang = request.headers.get("Accept-Language") ?? "";
  const ip = request.headers.get("CF-Connecting-IP") ?? "";
  return hmacHex(sessionKeyB64, `${ua}|${lang}|${networkOf(ip)}`);
}

export async function createSession(
  db: SupabaseClient,
  env: { HASTRA_SESSION_KEY: string },
  request: Request,
  userId: string,
): Promise<{ cookie: string; token: string }> {
  const token = bytesToHex(crypto.getRandomValues(new Uint8Array(32)));
  const tokenHash = await sha256Hex(token);
  const fingerprint = await fingerprintOf(request, env.HASTRA_SESSION_KEY);

  const { error } = await db.from("sessions").insert({
    token_hash: tokenHash,
    user_id: userId,
    fingerprint,
    created_at: new Date().toISOString(),
    last_seen_at: new Date().toISOString(),
  });
  if (error) throw error;

  const cookie = `${SESSION_COOKIE}=${token}; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=1800`;
  return { cookie, token };
}

export interface SessionUser {
  userId: string;
  sessionId: string;
}

/**
 * Validates the session cookie: exists, not expired (30 min idle timeout),
 * and its fingerprint matches this request. Returns null (never throws) on
 * any failure so callers can uniformly respond 401 — this mirrors PHP's
 * hijack killswitch, which silently destroys the session rather than
 * revealing why.
 */
export async function requireSession(
  request: Request,
  db: SupabaseClient,
  env: { HASTRA_SESSION_KEY: string },
): Promise<SessionUser | null> {
  const cookieHeader = request.headers.get("Cookie") ?? "";
  const match = cookieHeader.match(new RegExp(`${SESSION_COOKIE}=([^;]+)`));
  if (!match) return null;
  const token = match[1];
  const tokenHash = await sha256Hex(token);

  const { data: session } = await db.from("sessions").select("*").eq("token_hash", tokenHash).single();
  if (!session) return null;

  const lastSeen = new Date(session.last_seen_at).getTime();
  if (Date.now() - lastSeen > IDLE_TIMEOUT_MS) {
    await db.from("sessions").delete().eq("token_hash", tokenHash);
    return null;
  }

  const expectedFingerprint = await fingerprintOf(request, env.HASTRA_SESSION_KEY);
  if (expectedFingerprint !== session.fingerprint) {
    // Possible hijack: fingerprint moved to a different browser/network.
    await db.from("sessions").delete().eq("token_hash", tokenHash);
    return null;
  }

  await db.from("sessions").update({ last_seen_at: new Date().toISOString() }).eq("token_hash", tokenHash);
  return { userId: session.user_id, sessionId: session.id };
}

export async function destroySession(request: Request, db: SupabaseClient): Promise<string> {
  const cookieHeader = request.headers.get("Cookie") ?? "";
  const match = cookieHeader.match(new RegExp(`${SESSION_COOKIE}=([^;]+)`));
  if (match) {
    const tokenHash = await sha256Hex(match[1]);
    await db.from("sessions").delete().eq("token_hash", tokenHash);
  }
  return `${SESSION_COOKIE}=; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=0`;
}
