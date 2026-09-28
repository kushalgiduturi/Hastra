export interface Env {
  SUPABASE_URL: string;
  SUPABASE_SERVICE_ROLE_KEY: string;
  HASTRA_DB_ENC_KEY: string;
  HASTRA_DB_INDEX_KEY: string;
  HASTRA_SESSION_KEY: string;
  HASTRA_RECAPTCHA_SECRET: string;
  HASTRA_GOOGLE_CLIENT_ID: string;
  HASTRA_GOOGLE_CLIENT_SECRET: string;
  // Workers can't speak raw SMTP, so mail goes through a provider's HTTP API
  // (Brevo's transactional email API by default — see functions/_lib/mail.ts).
  HASTRA_MAIL_API_KEY: string;
  HASTRA_MAIL_FROM: string;
  HASTRA_MAIL_NAME: string;
}
