-- The four self-serve sign-up tracks (functions/_lib/signup.ts), ported from
-- the PHP app's auth/register.php and auth/verify_register.php. Additive
-- only: existing rows keep their values and every new column is nullable.

-- What the PHP app's create_company() stores for a self-serve company.
alter table companies
  add column if not exists account_type text,            -- client_org | client_individual | full_org | solo_enterprise
  add column if not exists size_band text,               -- core/onboarding.php COMPANY_SIZES key
  add column if not exists contract_ref text,            -- client_company only, at most 60 characters
  add column if not exists leave_policy jsonb,           -- enterprise_full only
  add column if not exists owner_user_id uuid references users(id),
  add column if not exists it_manager_id uuid references users(id);

-- One company per name, compared without case, as find_company_by_name()
-- does: a second sign-up for the same organization is told to ask its admin.
create unique index if not exists companies_name_lower_key on companies (lower(name));

alter table users
  add column if not exists phone text,                   -- encrypted (hastra:v1:), E.164
  add column if not exists country text,                 -- country name, client_individual and enterprise_solo
  add column if not exists client_role text,             -- it_manager for the client who registers a company
  add column if not exists terms_accepted_at timestamptz,
  add column if not exists terms_version text,
  -- The chosen track and company details, held from sign-up until the
  -- emailed code is accepted (PHP keeps these in pending_registrations).
  -- The company and the role are only created then; cleared afterwards.
  add column if not exists signup jsonb;
