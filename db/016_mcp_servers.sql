-- 016: what the two MCP servers (Phase 4) need the schema to hold. Additive — nothing is replaced. Apply by hand on the installed
-- database (deploy/ROOT_STEPS.sh step 1b): the installer never re-runs migrations on a database that exists.
--   * mcp_admit_agent()    — the mirror learns of an agent only through the directory; a run the kernel vouches for is admitted at its
--                            first contact (Projects' db/012). It touches nothing but the capability of an active AGENT row that has none.
--                            What an agent may then SEE is still decided by member_site_roles (no site role = no site).
--   * mcp_member_kind()   — who a signed action or run token speaks for, ONLY when that member is active and admitted (capability set):
--                            the servers use it to decide 'known' vs 'first contact' (the read roles see mcp_members for the caller
--                            even before admission, so the view cannot tell).
--   * ts_time_off_taken()  — the `time_off_taken` share (D5, D11): approved time off per person at ONE restaurant for a period, for HR
--                            through the kernel. Callable only when NO member is acting (the kernel-token path sets none): a person
--                            or an agent — who always have app.member_id set — get nothing. Never a reason, a note, a balance or a rate.
--   * mcp_time_off_ledger  — the ledger behind a balance (time_off_balances tool, ledger=true): own rows, or requests.approve at the site.
--   * mcp_exchange_invitees — who a coverage request went to: one's own invitation, or the approvers'/leads' view of all.
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO txtschedules_records_ro, txtschedules_activity_ro, txtschedules_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO txtschedules_records_ro, txtschedules_activity_ro, txtschedules_rw;

-- Approved, non-cancelled time off touching [p_from, p_to] (dates in the restaurant's zone), one row per request, people only
-- (humans). `days` = the calendar days the request touches in the restaurant's zone. `member_id` is the KERNEL's member id
-- (members.id is the kernel's).
CREATE OR REPLACE FUNCTION ts_time_off_taken(p_scope bigint, p_from date, p_to date)
    RETURNS TABLE (member_id bigint, type_key text, type_name text, paid boolean, starts_at timestamptz, ends_at timestamptz,
                   hours numeric, days integer, timezone text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v_tz text;
BEGIN
    IF app_current_member_id() IS NOT NULL THEN
        RETURN;                                   -- a person or an agent is acting: this door is the kernel's alone
    END IF;
    SELECT s.timezone INTO v_tz FROM sites s WHERE s.scope_id = p_scope AND s.removed_at IS NULL;
    IF v_tz IS NULL OR p_from IS NULL OR p_to IS NULL OR p_to < p_from THEN
        RETURN;
    END IF;
    RETURN QUERY
    SELECT q.member_id, t.key, t.name, t.paid, q.starts_at, q.ends_at, q.hours,
           ((((q.ends_at AT TIME ZONE v_tz) - interval '1 microsecond')::date) - (q.starts_at AT TIME ZONE v_tz)::date + 1)::int,
           v_tz
      FROM time_off_requests q
      JOIN time_off_types t ON t.id = q.type_id
      JOIN members m ON m.id = q.member_id AND m.member_kind = 'human'
     WHERE q.scope_id = p_scope AND q.status = 'approved'
       AND (q.starts_at AT TIME ZONE v_tz)::date <= p_to
       AND ((q.ends_at AT TIME ZONE v_tz) - interval '1 microsecond')::date >= p_from
     ORDER BY q.member_id, q.starts_at;
END$$;
REVOKE ALL ON FUNCTION ts_time_off_taken(bigint, date, date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ts_time_off_taken(bigint, date, date) TO txtschedules_records_ro, txtschedules_rw;

CREATE OR REPLACE VIEW mcp_time_off_ledger WITH (security_barrier = true) AS
SELECT l.id AS ledger_id, l.member_id, l.type_id, t.scope_id AS site_id, t.name AS type_name, l.delta_hours, l.reason,
       l.request_id, l.note, l.recorded_by, l.created_at
  FROM time_off_ledger l
  JOIN time_off_types t ON t.id = l.type_id
 WHERE l.member_id = app_current_member_id()
    OR t.scope_id IN (SELECT ts_scopes_with_right('requests.approve'));

CREATE OR REPLACE VIEW mcp_exchange_invitees WITH (security_barrier = true) AS
SELECT i.exchange_id, i.member_id, x.scope_id AS site_id
  FROM exchange_invitees i
  JOIN exchanges x ON x.id = i.exchange_id
 WHERE i.member_id = app_current_member_id()
    OR x.scope_id IN (SELECT ts_scopes_with_right('requests.approve'))
    OR x.scope_id IN (SELECT ts_scopes_with_right('coverage.fill'));

GRANT SELECT ON mcp_time_off_ledger, mcp_exchange_invitees TO txtschedules_records_ro, txtschedules_rw;
COMMIT;
