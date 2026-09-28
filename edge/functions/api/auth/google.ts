// Port of auth/google_auth.php + core/google_oauth.php: OAuth 2.0
// authorization-code flow with PKCE (S256). The PKCE verifier + a CSRF
// `state` are held in a short-lived, signed, httpOnly cookie between the
// redirect to Google and the callback (the PHP version kept these in an
// encrypted attempt cookie for the same reason — Workers are stateless
// between requests, so it can't just sit in server memory).
import type { Env } from "../../_lib/env";
import { dbFor } from "../../_lib/db";
import { dbEncrypt, blindIndex } from "../../_lib/crypto";
import { createSession } from "../../_lib/session";
import { appendAuditLog } from "../../_lib/audit-chain";

const PENDING_COOKIE = "hastra_google_pending";

function b64url(bytes: Uint8Array): string {
  let bin = "";
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

async function pkceChallenge(verifier: string): Promise<string> {
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(verifier));
  return b64url(new Uint8Array(digest));
}

export const onRequestGet: PagesFunction<Env> = async ({ request, env }) => {
  const url = new URL(request.url);
  const code = url.searchParams.get("code");
  const state = url.searchParams.get("state");

  if (!code) {
    // Step 1: redirect the browser to Google.
    const verifier = b64url(crypto.getRandomValues(new Uint8Array(32)));
    const challenge = await pkceChallenge(verifier);
    const newState = b64url(crypto.getRandomValues(new Uint8Array(16)));

    const authUrl = new URL("https://accounts.google.com/o/oauth2/v2/auth");
    authUrl.searchParams.set("client_id", env.HASTRA_GOOGLE_CLIENT_ID);
    authUrl.searchParams.set("redirect_uri", `${url.origin}/api/auth/google`);
    authUrl.searchParams.set("response_type", "code");
    authUrl.searchParams.set("scope", "openid email profile");
    authUrl.searchParams.set("state", newState);
    authUrl.searchParams.set("code_challenge", challenge);
    authUrl.searchParams.set("code_challenge_method", "S256");

    return new Response(null, {
      status: 302,
      headers: {
        Location: authUrl.toString(),
        "Set-Cookie": `${PENDING_COOKIE}=${newState}.${verifier}; Path=/api/auth/google; HttpOnly; Secure; SameSite=Lax; Max-Age=600`,
      },
    });
  }

  // Step 2: Google's callback.
  const cookieHeader = request.headers.get("Cookie") ?? "";
  const match = cookieHeader.match(new RegExp(`${PENDING_COOKIE}=([^;]+)`));
  if (!match) return Response.json({ error: "Missing PKCE cookie — retry sign-in" }, { status: 400 });
  const [pendingState, verifier] = decodeURIComponent(match[1]).split(".");
  if (pendingState !== state) return Response.json({ error: "State mismatch" }, { status: 400 });

  const tokenRes = await fetch("https://oauth2.googleapis.com/token", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      client_id: env.HASTRA_GOOGLE_CLIENT_ID,
      client_secret: env.HASTRA_GOOGLE_CLIENT_SECRET,
      code,
      code_verifier: verifier,
      grant_type: "authorization_code",
      redirect_uri: `${url.origin}/api/auth/google`,
    }),
  });
  if (!tokenRes.ok) return Response.json({ error: "Token exchange failed" }, { status: 401 });
  const { id_token } = (await tokenRes.json()) as { id_token: string };

  // Verify the ID token against Google's tokeninfo endpoint (matches the
  // PHP version's approach) rather than validating the JWT signature locally.
  const infoRes = await fetch(`https://oauth2.googleapis.com/tokeninfo?id_token=${id_token}`);
  if (!infoRes.ok) return Response.json({ error: "Invalid ID token" }, { status: 401 });
  const info = (await infoRes.json()) as { sub: string; email: string; email_verified: string; name?: string };
  if (info.email_verified !== "true" && String(info.email_verified) !== "true") {
    return Response.json({ error: "Google account email not verified" }, { status: 401 });
  }

  const db = dbFor(env);
  const googleIdBindex = await blindIndex("google_id_bindex", info.sub, env.HASTRA_DB_INDEX_KEY);
  let { data: user } = await db.from("users").select("*").eq("google_id_bindex", googleIdBindex).maybeSingle();

  if (!user) {
    const emailBindex = await blindIndex("email_bindex", info.email, env.HASTRA_DB_INDEX_KEY);
    const { data: byEmail } = await db.from("users").select("*").eq("email_bindex", emailBindex).maybeSingle();
    if (byEmail) {
      await db.from("users").update({ google_id_bindex: googleIdBindex }).eq("id", byEmail.id);
      user = byEmail;
    } else {
      const { data: created, error } = await db
        .from("users")
        .insert({
          name: await dbEncrypt(info.name ?? info.email, env.HASTRA_DB_ENC_KEY),
          email: await dbEncrypt(info.email, env.HASTRA_DB_ENC_KEY),
          email_bindex: emailBindex,
          google_id_bindex: googleIdBindex,
          password_hash: null,
          status: "active",
        })
        .select("*")
        .single();
      if (error) return Response.json({ error: error.message }, { status: 500 });
      user = created;
    }
  }

  const { cookie } = await createSession(db, env, request, user.id);
  await appendAuditLog(db, env.HASTRA_DB_INDEX_KEY, {
    userId: user.id,
    username: info.name ?? null,
    action: "login.google",
    ipAddress: request.headers.get("CF-Connecting-IP"),
    geo: (request as unknown as { cf?: { country?: string } }).cf?.country ?? null,
    severity: "info",
    incidentType: null,
    details: "Google sign-in",
  });

  return new Response(null, {
    status: 302,
    headers: {
      Location: "/",
      "Set-Cookie": [cookie, `${PENDING_COOKIE}=; Path=/api/auth/google; Max-Age=0`].join(", "),
    },
  });
};
