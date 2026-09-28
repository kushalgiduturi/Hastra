-- Hastra edge pilot: schema for companies/users/sessions/logs.
-- Ported from docker/schema.sql + config/migrations/*.php (subset needed for
-- auth + the sysadmin portal). Encrypted columns store the "hastra:v1:"
-- envelope as text; blind-index columns store a base64 HMAC for equality
-- lookups (see functions/_lib/crypto.ts).

create extension if not exists pgcrypto;

create table if not exists companies (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  created_at timestamptz not null default now()
);

create table if not exists users (
  id uuid primary key default gen_random_uuid(),
  company_id uuid references companies(id),
  company_name text,
  name text not null,              -- encrypted (hastra:v1:)
  email text not null,             -- encrypted (hastra:v1:)
  email_bindex text not null,      -- HMAC blind index, unique
  google_id_bindex text,           -- HMAC blind index, unique when present
  password_hash text,              -- Argon2id encoded hash, null for Google-only accounts
  role text not null default 'pending_employee',
  status text not null default 'pending_verification', -- pending_verification | active | disabled
  otp text,
  otp_expiry timestamptz,
  otp_attempts int not null default 0,
  created_at timestamptz not null default now()
);
create unique index if not exists users_email_bindex_key on users (email_bindex);
create unique index if not exists users_google_id_bindex_key on users (google_id_bindex) where google_id_bindex is not null;

create table if not exists sessions (
  id uuid primary key default gen_random_uuid(),
  token_hash text not null,
  user_id uuid not null references users(id) on delete cascade,
  fingerprint text not null,
  created_at timestamptz not null default now(),
  last_seen_at timestamptz not null default now()
);
create unique index if not exists sessions_token_hash_key on sessions (token_hash);

-- Audit chain: chain_index must be gapless and previous_hash must chain to
-- the prior row. reserve_audit_slot() serializes concurrent appends the way
-- MySQL's GET_LOCK did in the PHP version.
create table if not exists logs (
  chain_index bigint primary key,
  previous_hash text not null,
  current_hash text not null,
  user_id uuid references users(id),
  username text,
  action text not null,
  ip_address text,
  timestamp timestamptz not null,
  geo text,
  severity text not null default 'info',
  incident_type text,
  details text
);

create table if not exists audit_chain_cursor (
  id boolean primary key default true check (id),
  next_index bigint not null default 0,
  last_hash text not null default 'genesis'
);
insert into audit_chain_cursor (id, next_index, last_hash)
  values (true, 0, 'genesis')
  on conflict (id) do nothing;

-- Atomically reserves the next chain_index + previous_hash. The caller
-- computes current_hash (needs the HMAC key, which stays out of the
-- database) and inserts the row; SELECT ... FOR UPDATE on the single cursor
-- row serializes concurrent callers so chain_index never gaps or races.
create or replace function reserve_audit_slot()
returns table (chain_index bigint, previous_hash text)
language plpgsql
as $$
declare
  v_index bigint;
  v_prev text;
begin
  select next_index, last_hash into v_index, v_prev
    from audit_chain_cursor where id = true for update;

  update audit_chain_cursor set next_index = v_index + 1 where id = true;

  chain_index := v_index;
  previous_hash := v_prev;
  return next;
end;
$$;

-- After inserting a row into `logs`, advance last_hash so the next
-- reservation chains to it. Called by the application right after the
-- insert succeeds (kept separate from reserve_audit_slot so a failed
-- insert doesn't advance the cursor's hash without a matching row).
create or replace function commit_audit_hash(p_chain_index bigint, p_hash text)
returns void
language sql
as $$
  update audit_chain_cursor set last_hash = p_hash
    where id = true and next_index = p_chain_index + 1;
$$;
