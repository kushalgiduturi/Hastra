-- Replaces reserve_audit_slot()/commit_audit_hash() with a crash-safe design.
--
-- The original two-RPC-plus-insert design (reserve a slot, insert the row,
-- commit the hash) advances audit_chain_cursor.next_index as soon as
-- reserve_audit_slot() is called. If anything between that call and the
-- final commit fails — a network blip, the calling Worker erroring out, the
-- insert itself failing — the cursor is left pointing past a chain_index
-- that was never written, and no code can ever repair the gap. The
-- verify_audit_chain() walk treats any gap as evidence of tampering, so a
-- transient failure becomes a permanent, indistinguishable-from-an-attack
-- false positive. This happened during pilot testing (an early request
-- failed after reserving chain_index 0, permanently orphaning it).
--
-- New design: the caller first PEEKs the cursor (read-only, no mutation) to
-- learn the chain_index/previous_hash it needs to compute current_hash (the
-- HMAC key stays out of the database, so hashing must happen in the
-- caller). It then calls commit_audit_log() with the values it peeked; the
-- function re-locks the cursor, and if it still matches what the caller
-- expected, inserts the row and advances the cursor in the SAME
-- transaction. If another request committed in between, the expected/actual
-- values won't match and the function raises — nothing is written, the
-- cursor doesn't move, and the caller can safely retry the whole
-- peek-hash-commit sequence. A failure at any point now leaves the chain
-- exactly as it was; there is no window where the cursor can move without a
-- matching row.

drop function if exists reserve_audit_slot();
drop function if exists commit_audit_hash(bigint, text);

create or replace function peek_audit_cursor()
returns table (chain_index bigint, previous_hash text)
language sql
stable
as $$
  select next_index, last_hash from audit_chain_cursor where id = true;
$$;

create or replace function commit_audit_log(
  p_expected_index bigint,
  p_expected_prev text,
  p_user_id uuid,
  p_username text,
  p_action text,
  p_ip_address text,
  p_timestamp timestamptz,
  p_geo text,
  p_severity text,
  p_incident_type text,
  p_details text,
  p_current_hash text
)
returns bigint
language plpgsql
as $$
declare
  v_index bigint;
  v_prev text;
begin
  select next_index, last_hash into v_index, v_prev
    from audit_chain_cursor where id = true for update;

  if v_index is distinct from p_expected_index or v_prev is distinct from p_expected_prev then
    raise exception 'audit_chain_conflict: cursor moved (expected %/%, found %/%)',
      p_expected_index, p_expected_prev, v_index, v_prev
      using errcode = '40001';
  end if;

  insert into logs (
    chain_index, previous_hash, current_hash, user_id, username, action,
    ip_address, timestamp, geo, severity, incident_type, details
  ) values (
    v_index, v_prev, p_current_hash, p_user_id, p_username, p_action,
    p_ip_address, p_timestamp, p_geo, p_severity, p_incident_type, p_details
  );

  update audit_chain_cursor set next_index = v_index + 1, last_hash = p_current_hash where id = true;

  return v_index;
end;
$$;
