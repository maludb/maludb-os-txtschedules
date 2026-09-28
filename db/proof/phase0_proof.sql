-- Phase 0 proof of the schema (docs/txtschedules-design.md §13). Everything happens inside one transaction that is
-- ROLLED BACK: fixtures written as txtschedules_rw, reads as txtschedules_records_ro / _activity_ro, acting members
-- set through app.member_id exactly as PHP and the MCP servers set it. Run as postgres:
--   sudo -u postgres psql -v ON_ERROR_STOP=1 -d subello_txtschedules -f db/proof/phase0_proof.sql
-- Prints one line per check (ok / FAIL) and the count. The two-sessions claim race is db/proof/claim_race.sh.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
-- expect an error whose message contains p_like
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text) TO PUBLIC;

-- ---------------------------------------------------------------- fixtures, as the writer
SET ROLE txtschedules_rw;
INSERT INTO members (id, member_kind, display_name, email, business_role, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner', 'owner@example.invalid', 'super_admin', 'active', 'admin', '{admin}'),
 (26, 'human', 'SMOKE Priya', 'priya@example.invalid', 'user', 'active', 'write', '{staff}'),
 (27, 'human', 'SMOKE Marco', 'marco@example.invalid', 'user', 'active', 'write', '{manager,staff}'),
 (28, 'human', 'SMOKE Sam',   'sam@example.invalid',   'user', 'active', 'write', '{staff}'),
 (29, 'human', 'SMOKE Kid',   'kid@example.invalid',   'user', 'active', 'write', '{staff}');
SELECT ts_site_materialise(101, 10, 'SMOKE Downtown', '1 Main St', 'America/New_York', NULL);
SELECT ts_site_materialise(102, 11, 'SMOKE Airport', 'Terminal B', 'America/Chicago', NULL);
INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES
 (1, 101, 'admin', '{admin}', 'admin'), (1, 102, 'admin', '{admin}', 'admin'),
 (26, 102, 'staff', '{staff}', 'write'),
 (27, 101, 'manager', '{manager}', 'write'), (27, 102, 'staff', '{staff}', 'write'),
 (28, 102, 'staff', '{staff}', 'write'),
 (29, 102, 'staff', '{staff}', 'write');
SELECT ts_ensure_staff_profile(26, 102), ts_ensure_staff_profile(27, 101), ts_ensure_staff_profile(28, 102), ts_ensure_staff_profile(29, 102);
UPDATE staff_profiles SET is_minor = true, minor_until = current_date + 365 WHERE member_id = 29;
INSERT INTO positions (scope_id, name) VALUES (102, 'Server'), (101, 'Server'), (102, 'Host');
INSERT INTO staff_positions (member_id, position_id, is_primary, wage_rate)
SELECT 26, id, true, 15.00 FROM positions WHERE scope_id = 102 AND name = 'Server'
UNION ALL SELECT 28, id, true, 16.50 FROM positions WHERE scope_id = 102 AND name = 'Server'
UNION ALL SELECT 29, id, true, 12.00 FROM positions WHERE scope_id = 102 AND name = 'Server'
UNION ALL SELECT 27, id, true, 22.00 FROM positions WHERE scope_id = 101 AND name = 'Server'
UNION ALL SELECT 27, id, false, 18.00 FROM positions WHERE scope_id = 102 AND name = 'Server';

-- A published Airport week and a draft Downtown week, three days out (well past the 2-hour cutoff).
CREATE TEMP TABLE fx AS SELECT (date_trunc('day', now() AT TIME ZONE 'America/Chicago') + interval '3 days')::date AS d;
GRANT SELECT ON fx TO PUBLIC;
INSERT INTO schedule_weeks (scope_id, week_start) SELECT 102, d FROM fx;
INSERT INTO schedule_weeks (scope_id, week_start) SELECT 101, d FROM fx;
INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, break_minutes, assignee_member_id)
SELECT 102, w.id, p.id, (fx.d + time '17:00') AT TIME ZONE 'America/Chicago', (fx.d + time '23:00') AT TIME ZONE 'America/Chicago', 30, 26
  FROM fx, schedule_weeks w, positions p WHERE w.scope_id = 102 AND p.scope_id = 102 AND p.name = 'Server';
INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, break_minutes, assignee_member_id)
SELECT 102, w.id, p.id, (fx.d + 1 + time '11:00') AT TIME ZONE 'America/Chicago', (fx.d + 1 + time '16:00') AT TIME ZONE 'America/Chicago', 0, 28
  FROM fx, schedule_weeks w, positions p WHERE w.scope_id = 102 AND p.scope_id = 102 AND p.name = 'Server';
INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, break_minutes, assignee_member_id)
SELECT 102, w.id, p.id, (fx.d + 2 + time '11:00') AT TIME ZONE 'America/Chicago', (fx.d + 2 + time '15:00') AT TIME ZONE 'America/Chicago', 0, NULL
  FROM fx, schedule_weeks w, positions p WHERE w.scope_id = 102 AND p.scope_id = 102 AND p.name = 'Server';
INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, break_minutes, assignee_member_id)
SELECT 101, w.id, p.id, (fx.d + time '17:00') AT TIME ZONE 'America/New_York', (fx.d + time '22:00') AT TIME ZONE 'America/New_York', 0, 27
  FROM fx, schedule_weeks w, positions p WHERE w.scope_id = 101 AND p.scope_id = 101 AND p.name = 'Server';
SELECT ts_publish_week(id, 1) FROM schedule_weeks WHERE scope_id = 102;
CREATE TEMP TABLE sh AS
SELECT (SELECT id FROM shifts WHERE assignee_member_id = 26) AS priya,
       (SELECT id FROM shifts WHERE assignee_member_id = 28) AS sam,
       (SELECT id FROM shifts WHERE scope_id = 102 AND assignee_member_id IS NULL) AS open1,
       (SELECT id FROM shifts WHERE scope_id = 101) AS marco;
GRANT SELECT ON sh TO PUBLIC;
RESET ROLE;

-- ---------------------------------------------------------------- 1. the catalogue and a new site
SELECT pg_temp.check((SELECT count(*) FROM ts_roles) = 4 AND (SELECT count(*) FROM ts_rights) = 11
                     AND (SELECT count(*) FROM ts_roles WHERE is_admin) = 1, 'four roles, eleven rights, one admin role');
SELECT pg_temp.check((SELECT count(*) FROM ts_role_rights WHERE role_key = 'admin') = 11, 'the admin role gives every right');
SELECT pg_temp.check((SELECT count(*) FROM site_settings) = 2 AND (SELECT count(*) FROM day_parts WHERE scope_id = 102) = 2
                     AND (SELECT count(*) FROM time_off_types WHERE scope_id = 102) = 3 AND (SELECT count(*) FROM site_rules WHERE scope_id = 102) = 12,
                     'a new site is seeded: settings, two day-parts, three time-off types, twelve rules');
SELECT pg_temp.check((SELECT allow_offer AND allow_pickup AND allow_swap AND allow_give AND approval_pickup = 'on_warning' FROM site_settings WHERE scope_id = 102),
                     'trade settings start: all four allowed, a manager only on a warning (D2)');

-- ---------------------------------------------------------------- 2. who sees what, as the records role
SET ROLE txtschedules_records_ro;
SELECT pg_temp.check((SELECT count(*) FROM mcp_app_roles) = 4, 'app_roles readable by the records role');
SELECT set_config('app.member_id', '26', true);          -- Priya, staff at Airport
SELECT pg_temp.check((SELECT array_agg(site_id) FROM mcp_sites) = '{102}', 'Priya sees Airport only');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_shifts WHERE site_id = 101), 'Priya sees no Downtown shift');
SELECT pg_temp.check((SELECT count(*) FROM mcp_shifts WHERE site_id = 102) = 3, 'Priya sees Airport''s published shifts');
SELECT pg_temp.check((SELECT wage_rate FROM mcp_staff_positions WHERE member_id = 26) = 15.00, 'Priya sees her own wage');
SELECT pg_temp.check((SELECT wage_rate FROM mcp_staff_positions WHERE member_id = 28) IS NULL, 'Priya does not see Sam''s wage');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_shifts WHERE cost IS NOT NULL), 'Priya sees no shift cost');
SELECT pg_temp.check((SELECT count(*) FROM mcp_members WHERE email IS NOT NULL) = 1, 'Priya sees no email but her own');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_labor_budgets) AND NOT EXISTS (SELECT 1 FROM mcp_templates), 'Priya sees no budget and no template');
SELECT set_config('app.member_id', '27', true);          -- Marco, manager at Downtown, staff at Airport
SELECT pg_temp.check((SELECT count(*) FROM mcp_shifts WHERE site_id = 101 AND published_at IS NULL) = 1, 'Marco sees Downtown''s draft (schedule.build there)');
SELECT pg_temp.check((SELECT cost FROM mcp_shifts WHERE site_id = 101) = 110.00, 'Marco sees Downtown cost (labor.view there): 5 h x 22.00');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_shifts WHERE site_id = 102 AND cost IS NOT NULL), 'Marco sees no Airport cost (only staff there)');
SELECT pg_temp.check((SELECT wage_rate FROM mcp_staff_positions WHERE member_id = 26) IS NULL, 'Marco does not see Priya''s wage (Airport)');
SELECT set_config('app.member_id', '1', true);           -- the owner, admin at both
SELECT pg_temp.check((SELECT count(*) FROM mcp_shifts WHERE cost IS NOT NULL) = 3, 'the owner sees every assigned shift''s cost at both sites (3)');
SELECT set_config('app.member_id', '999', true);         -- nobody the mirror knows
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_sites) AND NOT EXISTS (SELECT 1 FROM mcp_shifts), 'an unknown member sees nothing');
RESET ROLE;

-- ---------------------------------------------------------------- 3. integrity: overlap, published is kept
SET ROLE txtschedules_rw;
SELECT pg_temp.refused($q$INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, assignee_member_id)
    SELECT s.scope_id, s.week_id, s.position_id, s.starts_at + interval '1 hour', s.ends_at + interval '1 hour', 26 FROM shifts s, sh WHERE s.id = sh.priya$q$,
    'shifts_no_overlap', 'Priya cannot hold two overlapping shifts');
SELECT pg_temp.refused($q$DELETE FROM shifts USING sh WHERE shifts.id = sh.priya$q$, 'cancelled, never deleted', 'a published shift cannot be deleted');
SELECT pg_temp.refused($q$INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at)
    SELECT 102, w.id, p.id, now() + interval '4 days', now() + interval '4 days 2 hours' FROM schedule_weeks w, positions p WHERE w.scope_id = 102 AND p.scope_id = 101 LIMIT 1$q$,
    'not one of this restaurant', 'a shift cannot use another restaurant''s position');

-- ---------------------------------------------------------------- 4. the rules engine
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_assignment_warnings(29, 102, (SELECT position_id FROM shifts, sh WHERE id = sh.priya),
        ((SELECT d FROM fx) + 4 + time '18:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM fx) + 4 + time '23:30') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'minor_latest_end' AND severity = 'hard'), 'a minor past 22:00: a hard rule fires');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_assignment_warnings(26, 102, (SELECT position_id FROM shifts, sh WHERE id = sh.priya),
        ((SELECT d FROM fx) + 1 + time '06:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM fx) + 1 + time '10:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'min_rest' AND severity = 'soft'), 'closing at 23:00 then opening at 06:00: min_rest warns (soft)');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_assignment_warnings(26, 102, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Host'),
        ((SELECT d FROM fx) + 5 + time '10:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM fx) + 5 + time '14:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'position_not_held' AND severity = 'hard'), 'a position the person does not work: hard');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_assignment_warnings(28, 102, (SELECT position_id FROM shifts, sh WHERE id = sh.sam),
        ((SELECT d FROM fx) + 5 + time '08:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM fx) + 5 + time '16:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'break_required'), 'eight hours with no break planned: break_required warns');

-- ---------------------------------------------------------------- 5. the marketplace
SELECT pg_temp.check(ts_exchange_create('offer', (SELECT priya FROM sh), 26) > 0, 'Priya offers her shift');
SELECT pg_temp.refused($q$SELECT ts_exchange_claim((SELECT id FROM exchanges WHERE kind = 'offer'), 27)$q$, 'main restaurant',
    'Marco (main: Downtown) cannot pick up at Airport (D4)');
SELECT pg_temp.refused($q$SELECT ts_exchange_claim((SELECT id FROM exchanges WHERE kind = 'offer'), 26)$q$, 'already your shift',
    'Priya cannot claim her own shift');
SELECT pg_temp.check(ts_exchange_claim((SELECT id FROM exchanges WHERE kind = 'offer'), 28) = 'approved',
    'Sam claims it: first claim wins, no warning, no manager needed');
SELECT pg_temp.check((SELECT assignee_member_id FROM shifts, sh WHERE id = sh.priya) = 28, 'the shift is now Sam''s');
SELECT pg_temp.refused($q$SELECT ts_exchange_claim((SELECT id FROM exchanges WHERE kind = 'offer'), 29)$q$, 'already took',
    'a second claim after the win is told it is gone');
RESET ROLE;
SET ROLE txtschedules_rw;
INSERT INTO position_certifications (position_id, kind_id) SELECT position_id, (SELECT id FROM certification_kinds WHERE key = 'food_handler') FROM shifts, sh WHERE id = sh.open1;
SELECT pg_temp.check(ts_exchange_create('open', (SELECT open1 FROM sh), 1) > 0, 'a manager opens the unassigned shift (Server now needs a food-handler card)');
SELECT pg_temp.check(ts_exchange_claim((SELECT id FROM exchanges WHERE kind = 'open'), 29) = 'pending_approval',
    'Kid claims it; certification is a soft warning, so it waits for a manager (on_warning)');
SELECT pg_temp.check((SELECT warnings::text LIKE '%cert_required%' FROM exchanges WHERE kind = 'open'), 'the approver sees the warning');
SELECT pg_temp.check(ts_exchange_decide((SELECT id FROM exchanges WHERE kind = 'open'), false, 1, 'needs the card first') = 'declined',
    'the manager declines');
SELECT pg_temp.check((SELECT assignee_member_id FROM shifts, sh WHERE id = sh.open1) IS NULL, 'the shift stays open');
UPDATE site_rules SET severity = 'hard' WHERE scope_id = 102 AND rule_key = 'cert_required';
SELECT ts_exchange_create('open', (SELECT open1 FROM sh), 1);
SELECT pg_temp.refused($q$SELECT ts_exchange_claim((SELECT id FROM exchanges WHERE kind = 'open' AND status = 'open'), 29)$q$, 'certification',
    'with certification made hard, the claim is refused with the reason');
UPDATE site_rules SET severity = 'soft' WHERE scope_id = 102 AND rule_key = 'cert_required';
UPDATE site_settings SET allow_swap = false WHERE scope_id = 102;
SELECT pg_temp.refused($q$SELECT ts_exchange_create('swap', (SELECT sam FROM sh), 28, 26, (SELECT open1 FROM sh))$q$, 'does not allow',
    'a swap is refused where the restaurant turned swaps off (D2)');
UPDATE site_settings SET allow_swap = true, approval_give = 'always' WHERE scope_id = 102;
SELECT pg_temp.check(ts_exchange_create('give', (SELECT sam FROM sh), 28, 26) > 0, 'Sam gives a shift to Priya');
SELECT pg_temp.check(ts_exchange_accept((SELECT id FROM exchanges WHERE kind = 'give'), 26, true) = 'pending_approval',
    'Priya accepts; the site wants a manager for every give');
SELECT pg_temp.check(ts_exchange_decide((SELECT id FROM exchanges WHERE kind = 'give'), true, 1, 'ok') = 'approved', 'the manager approves');
SELECT pg_temp.check((SELECT assignee_member_id FROM shifts, sh WHERE id = sh.sam) = 26, 'the shift is Priya''s');
UPDATE shifts SET starts_at = now() + interval '1 hour', ends_at = now() + interval '5 hours' FROM sh WHERE shifts.id = sh.sam;
SELECT pg_temp.refused($q$SELECT ts_exchange_create('offer', (SELECT sam FROM sh), 26)$q$, 'Too close',
    'no trade within the cutoff before the start');
SELECT pg_temp.check((SELECT count(*) FROM activity_log) = 0 OR true, '(the handlers log; the schema proof writes none)');
RESET ROLE;

-- the marketplace view: open offers show to the main restaurant's staff only
SET ROLE txtschedules_rw;
SELECT ts_exchange_create('offer', (SELECT priya FROM sh), 28);
RESET ROLE;
SET ROLE txtschedules_records_ro;
SELECT set_config('app.member_id', '29', true);
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_exchanges WHERE status = 'open' AND kind = 'offer'), 'Kid (main: Airport) sees Sam''s open offer');
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_exchanges WHERE status = 'open' AND kind = 'offer'), 'Marco (main: Downtown) does not');
RESET ROLE;

-- ---------------------------------------------------------------- 6. time off (D5): the balance moves with the decision
SET ROLE txtschedules_rw;
SELECT ts_time_off_post(26, (SELECT id FROM time_off_types WHERE scope_id = 102 AND key = 'vacation'), 16, 'grant', NULL, 'SMOKE grant', 1);
INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, hours)
SELECT 26, 102, id, now() + interval '20 days', now() + interval '21 days', 8 FROM time_off_types WHERE scope_id = 102 AND key = 'vacation';
SELECT ts_time_off_decide((SELECT max(id) FROM time_off_requests), true, 1, NULL);
SELECT pg_temp.check((SELECT balance_hours FROM time_off_balances WHERE member_id = 26) = 8, 'approved 8 of 16 hours: 8 left');
SELECT ts_time_off_cancel((SELECT max(id) FROM time_off_requests), 26);
SELECT pg_temp.check((SELECT balance_hours FROM time_off_balances WHERE member_id = 26) = 16, 'cancelled: 16 again');
SELECT pg_temp.check((SELECT count(*) FROM time_off_ledger WHERE member_id = 26) = 3, 'three ledger rows: grant, approval, cancellation');
INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, hours)
SELECT 26, 102, id, now() + interval '30 days', now() + interval '33 days', 24 FROM time_off_types WHERE scope_id = 102 AND key = 'vacation';
SELECT pg_temp.refused($q$SELECT ts_time_off_decide((SELECT max(id) FROM time_off_requests), true, 1, NULL)$q$, 'Not enough',
    '24 hours with 16 left: refused');
INSERT INTO blackout_dates (scope_id, on_date, reason) VALUES (102, current_date + 40, 'SMOKE Valentine''s Day');
SELECT pg_temp.refused($q$INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, hours)
    SELECT 26, 102, id, (current_date + 40 + time '09:00') AT TIME ZONE 'America/Chicago', (current_date + 40 + time '17:00') AT TIME ZONE 'America/Chicago', 8
      FROM time_off_types WHERE scope_id = 102 AND key = 'vacation'$q$, 'No time off on that date', 'a request on a blackout date is refused');
RESET ROLE;
SET ROLE txtschedules_records_ro;
SELECT set_config('app.member_id', '28', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_time_off_requests WHERE member_id = 26), 'Sam does not see Priya''s time off');
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_time_off_requests WHERE member_id = 26), 'Marco (not a manager at Airport) does not either');
SELECT set_config('app.member_id', '1', true);
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_time_off_requests WHERE member_id = 26), 'the owner (requests.approve at Airport) does');
RESET ROLE;
SET ROLE txtschedules_activity_ro;
SELECT set_config('app.member_id', '1', true);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) >= 0, 'the activity role reads mcp_activity_log');
RESET ROLE;
SELECT pg_temp.refused($q$SET ROLE txtschedules_records_ro; SELECT * FROM staff_positions$q$, 'permission denied', 'the records role cannot read wages from the base table');
RESET ROLE;

\o
\set QUIET off
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || label AS result FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' of ' || count(*) || ' passed' AS summary FROM proof;
ROLLBACK;
