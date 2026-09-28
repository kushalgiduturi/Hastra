import type { Env } from "./env";

export async function verifyRecaptcha(env: Env, token: string, remoteIp: string | null): Promise<boolean> {
  if (!token) return false;
  const body = new URLSearchParams({ secret: env.HASTRA_RECAPTCHA_SECRET, response: token });
  if (remoteIp) body.set("remoteip", remoteIp);
  const res = await fetch("https://www.google.com/recaptcha/api/siteverify", { method: "POST", body });
  const data = (await res.json()) as { success: boolean };
  return data.success === true;
}
