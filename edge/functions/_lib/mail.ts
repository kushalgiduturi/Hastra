// Cloudflare Workers have no raw TCP/SMTP support, so email goes through
// Brevo's transactional HTTP API (the same provider suggested for the VPS
// path in DEPLOY.md, just over HTTP instead of SMTP here). Swap the fetch
// call below for another provider's API if you're not using Brevo.
import type { Env } from "./env";

export async function sendMail(env: Env, to: string, subject: string, text: string): Promise<void> {
  const res = await fetch("https://api.brevo.com/v3/smtp/email", {
    method: "POST",
    headers: {
      "api-key": env.HASTRA_MAIL_API_KEY,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({
      sender: { email: env.HASTRA_MAIL_FROM, name: env.HASTRA_MAIL_NAME },
      to: [{ email: to }],
      subject,
      textContent: text,
    }),
  });
  if (!res.ok) {
    throw new Error(`sendMail: provider returned ${res.status}: ${await res.text()}`);
  }
}
