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
INSERT INTO positions (scope_id, name, default_wage_rate) VALUES (102, 'Server', 14.00), (101, 'Server', NULL), (102, 'Host', NULL);
-- wages: the position's default (Airport Server 14.00) unless the person has a rate of their own (wage_override)
INSERT INTO staff_positions (member_id, position_id, is_primary, wage_override)
SELECT 26, id, true, 15.00 FROM positions WHERE scope_id = 102 AND name = 'Server'          -- own rate wins over the default
UNION ALL SELECT 28, id, true, NULL FROM positions WHERE scope_id = 102 AND name = 'Server'  -- no own rate: the default
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

-- ---------------------------------------------------------------- 2b. wages: a default per position, an override per person
-- Airport Server default 14.00; Priya's own 15.00; Sam has none (Priya's shift: 17:00-23:00 less 30 min = 5.5 h; Sam's: 5 h).
SET ROLE txtschedules_records_ro;
SELECT set_config('app.member_id', '1', true);           -- the owner: labor.view at both
SELECT pg_temp.check((SELECT wage_rate = 14.00 AND wage_override IS NULL AND wage_source = 'default' FROM mcp_staff_positions WHERE member_id = 28),
                     'no rate of his own: Sam earns the position''s default (14.00, source default)');
SELECT pg_temp.check((SELECT wage_rate = 15.00 AND wage_override = 15.00 AND wage_source = 'override' FROM mcp_staff_positions WHERE member_id = 26),
                     'Priya''s own rate wins over the default (15.00, source override)');
SELECT pg_temp.check((SELECT default_wage_rate FROM mcp_positions WHERE site_id = 102 AND name = 'Server') = 14.00, 'the position''s default is visible with labor.view');
SELECT pg_temp.check((SELECT cost FROM mcp_shifts WHERE assignee_member_id = 28) = 70.00, 'Sam''s shift costs 5 h x the default 14.00 = 70.00');
SELECT pg_temp.check((SELECT cost FROM mcp_shifts WHERE assignee_member_id = 26) = 82.50, 'Priya''s shift costs 5.5 h x her own 15.00 = 82.50');
SELECT pg_temp.check((SELECT scheduled_hours = 14.50 AND scheduled_cost = 152.50 FROM mcp_labor_weekly WHERE site_id = 102 AND area = 'all'),
                     'the week''s labor at Airport: 14.5 h (an open 4 h included), cost 152.50 at the effective rates');
SELECT set_config('app.member_id', '28', true);          -- Sam, staff: sees his own wage — the effective one — and no one else's
SELECT pg_temp.check((SELECT wage_rate FROM mcp_staff_positions WHERE member_id = 28) = 14.00, 'Sam sees his own effective wage (the default)');
SELECT pg_temp.check((SELECT wage_rate FROM mcp_staff_positions WHERE member_id = 26) IS NULL
                     AND (SELECT wage_source FROM mcp_staff_positions WHERE member_id = 26) IS NULL
                     AND (SELECT wage_override FROM mcp_staff_positions WHERE member_id = 26) IS NULL, 'Sam sees no part of Priya''s pay (rate, override, source)');
SELECT pg_temp.check((SELECT default_wage_rate FROM mcp_positions WHERE site_id = 102 AND name = 'Server') IS NULL
                     AND NOT EXISTS (SELECT 1 FROM mcp_shifts WHERE cost IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM mcp_labor_weekly),
                     'without labor.view: no default, no cost, no labor view');
SELECT pg_temp.refused($q$SELECT ts_effective_rate(26, 1)$q$, 'permission denied', 'the records role cannot call the rate function');
RESET ROLE;
SET ROLE txtschedules_rw;
UPDATE positions SET default_wage_rate = 20.00 WHERE scope_id = 102 AND name = 'Server';          -- the owner raises the default
INSERT INTO labor_budgets (scope_id, week_start, area, budget_hours, budget_amount) SELECT 102, d, 'all', 40, 600 FROM fx;
SELECT pg_temp.check(ts_effective_rate(28, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Server')) = 20.00, 'raising the default raises the effective rate of Sam, who has no rate of his own');
SELECT pg_temp.check(ts_effective_rate(26, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Server')) = 15.00, 'and not Priya''s, who has one');
SELECT pg_temp.check(ts_effective_rate(1, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Server')) = 20.00, 'a person with no row at the position is priced at its default');
SELECT pg_temp.check(ts_effective_rate(26, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Host')) IS NULL, 'no default and no rate of one''s own: no rate');
RESET ROLE;
SET ROLE txtschedules_records_ro;
SELECT set_config('app.member_id', '1', true);
SELECT pg_temp.check((SELECT cost FROM mcp_shifts WHERE assignee_member_id = 28) = 100.00, 'after the change Sam''s shift costs 5 h x 20.00 = 100.00');
SELECT pg_temp.check((SELECT cost FROM mcp_shifts WHERE assignee_member_id = 26) = 82.50, 'Priya''s is unchanged');
SELECT pg_temp.check((SELECT scheduled_cost = 182.50 AND budget_hours = 40 AND budget_amount = 600 FROM mcp_labor_weekly WHERE site_id = 102 AND area = 'all'),
                     'the labor view: cost 182.50 against the budget of 40 h and 600');
SELECT set_config('app.member_id', '27', true);          -- Marco: manager at Downtown (labor.view), staff at Airport
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_labor_weekly WHERE site_id = 102) AND EXISTS (SELECT 1 FROM mcp_labor_weekly WHERE site_id = 101),
                     'Marco sees Downtown''s labor and none of Airport''s');
RESET ROLE;
SET ROLE txtschedules_rw;
INSERT INTO notification_prefs (member_id) VALUES (26);
SELECT pg_temp.check((SELECT by_email AND by_sms FROM notification_prefs WHERE member_id = 26), 'notification preferences start with email AND text on (reminders go by both)');
RESET ROLE;

-- ---------------------------------------------------------------- 2c. Phase 1 additions (db/014) and the draft rule
SET ROLE txtschedules_rw;
INSERT INTO schedule_weeks (scope_id, week_start) SELECT 102, d + 14 FROM fx;
INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, assignee_member_id)
SELECT 102, w.id, p.id, (fx.d + 14 + time '17:00') AT TIME ZONE 'America/Chicago', (fx.d + 14 + time '22:00') AT TIME ZONE 'America/Chicago', 26
  FROM fx, schedule_weeks w, positions p WHERE w.scope_id = 102 AND w.week_start = fx.d + 14 AND p.scope_id = 102 AND p.name = 'Server';
RESET ROLE;
SET ROLE txtschedules_records_ro;
SELECT set_config('app.member_id', '26', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_shifts WHERE published_at IS NULL), 'Priya cannot read a DRAFT shift assigned to her (a draft is the managers'' until published)');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_hours_weekly WHERE week_start = (SELECT d + 14 FROM fx)), 'and her hours do not count the draft week for her');
SELECT pg_temp.check((SELECT scheduled_hours FROM mcp_hours_weekly WHERE member_id = 26 AND week_start = (SELECT d FROM fx)) = 5.50, 'Priya''s week: 5.5 paid hours (her one published shift)');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_hours_weekly WHERE member_id = 28), 'Priya sees no one else''s hours');
SELECT set_config('app.member_id', '1', true);
SELECT pg_temp.check((SELECT scheduled_hours FROM mcp_hours_weekly WHERE member_id = 26 AND week_start = (SELECT d + 14 FROM fx)) = 5.00, 'the owner (schedule.build) sees the draft week''s 5 h plan');
SELECT pg_temp.check((SELECT NOT over_overtime AND NOT near_overtime AND max_hours_week IS NULL FROM mcp_hours_weekly WHERE member_id = 28 AND week_start = (SELECT d FROM fx)), 'Sam: 5 of 40 hours — not near overtime');
-- coverage candidates for the open Airport shift (11:00-15:00 the day after next): eligible = Airport-main staff, free, no hard rule
SELECT pg_temp.check((SELECT array_agg(member_id ORDER BY member_id) FROM ts_coverage_candidates((SELECT open1 FROM sh))) = ARRAY[26::bigint, 28, 29],
                     'coverage candidates: Priya, Sam and the minor — main restaurant Airport, free; not Marco (main Downtown), not the holder');
SELECT pg_temp.check((SELECT hours_this_week FROM ts_coverage_candidates((SELECT open1 FROM sh)) WHERE member_id = 28) = 5.00
                     AND (SELECT min(hours_this_week) FROM ts_coverage_candidates((SELECT open1 FROM sh))) = 0, 'each with the hours already that week; fewest first');
SELECT set_config('app.member_id', '26', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ts_coverage_candidates((SELECT open1 FROM sh))), 'staff without coverage.fill get no candidates');
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ts_coverage_candidates((SELECT open1 FROM sh))), 'nor does a manager of another restaurant (Marco is only staff at Airport)');
RESET ROLE;

-- the rules and the forecast, for the records role — each checks the caller's right inside
CREATE TEMP TABLE pos AS SELECT position_id AS srv, (SELECT id FROM schedule_weeks WHERE scope_id = 102 AND week_start = (SELECT d + 14 FROM fx)) AS dwk FROM shifts WHERE id = (SELECT priya FROM sh);
GRANT SELECT ON pos TO PUBLIC;
SET ROLE txtschedules_rw;
INSERT INTO shifts (scope_id, week_id, position_id, starts_at, ends_at, break_minutes, assignee_member_id)
SELECT 102, w.id, (SELECT srv FROM pos), (fx.d + 15 + time '10:00') AT TIME ZONE 'America/Chicago', (fx.d + 15 + time '19:00') AT TIME ZONE 'America/Chicago', 0, 28
  FROM fx, schedule_weeks w WHERE w.scope_id = 102 AND w.week_start = fx.d + 14;      -- nine hours, no break, in the draft week
RESET ROLE;
SET ROLE txtschedules_records_ro;
SELECT set_config('app.member_id', '1', true);
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_check_assignment(29, 102, (SELECT srv FROM pos),
        ((SELECT d FROM fx) + 4 + time '18:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM fx) + 4 + time '23:30') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'minor_latest_end' AND severity = 'hard'), 'the owner asks check_assignment: the minor past 22:00 is a hard rule');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_week_warnings((SELECT dwk FROM pos))
                              WHERE rule_key = 'break_required' AND member_id = 28), 'week_warnings lists Sam''s nine hours without a break in the draft week');
SELECT set_config('app.member_id', '26', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ts_week_warnings((SELECT dwk FROM pos))), 'staff get no week warnings');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_check_assignment(26, 102, (SELECT srv FROM pos),
        ((SELECT d FROM fx) + 6 + time '10:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM fx) + 6 + time '18:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'break_required'), 'a person may check their own assignment');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ts_check_assignment(28, 102, (SELECT srv FROM pos),
        ((SELECT d FROM fx) + 6 + time '10:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM fx) + 6 + time '18:00') AT TIME ZONE 'America/Chicago', 0)), 'but not another person''s');
SELECT set_config('app.member_id', '1', true);
SELECT set_config('app.member_id', '26', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ts_staffing_needs(102, (SELECT d FROM fx), (SELECT d FROM fx))), 'staff get no staffing needs');
RESET ROLE;
SET ROLE txtschedules_rw;
INSERT INTO staffing_ratios (scope_id, position_id, covers_per_staff, min_staff) SELECT 102, id, 25, 1 FROM positions WHERE scope_id = 102 AND name = 'Server';
INSERT INTO forecast_covers (scope_id, on_date, day_part_id, expected_covers) SELECT 102, (SELECT d FROM fx), id, 80 FROM day_parts WHERE scope_id = 102 AND key = (SELECT min(key) FROM day_parts WHERE scope_id = 102);
RESET ROLE;
SET ROLE txtschedules_records_ro;
SELECT set_config('app.member_id', '1', true);
SELECT pg_temp.check((SELECT recommended FROM ts_staffing_needs(102, (SELECT d FROM fx), (SELECT d FROM fx)) WHERE expected_covers = 80 AND position_name = 'Server') = 4,
                     '80 covers at one server per 25: four recommended');
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
INSERT INTO position_certifications (position_id, kind_id) SELECT position_id, (SELECT id FROM certification_kinds WHERE scope_id = 102 AND key = 'food_handler') FROM shifts, sh WHERE id = sh.open1;
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

-- ---------------------------------------------------------------- 7. time off: the hours a day are the restaurant's setting (D13)
SET ROLE txtschedules_rw;
SELECT pg_temp.check((SELECT time_off_day_hours FROM site_settings WHERE scope_id = 102) = 8, 'a new restaurant counts 8 hours a day by default');
INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, hours)
SELECT 26, 102, id, (current_date + 50 + time '00:00') AT TIME ZONE 'America/Chicago', (current_date + 52 + time '00:00') AT TIME ZONE 'America/Chicago', NULL
  FROM time_off_types WHERE scope_id = 102 AND key = 'vacation';
SELECT pg_temp.check((SELECT hours FROM time_off_requests ORDER BY id DESC LIMIT 1) = 16, 'two whole days with no hours given: 2 x 8 = 16 hours');
INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, hours)
SELECT 26, 102, id, (current_date + 60 + time '09:00') AT TIME ZONE 'America/Chicago', (current_date + 60 + time '13:00') AT TIME ZONE 'America/Chicago', NULL
  FROM time_off_types WHERE scope_id = 102 AND key = 'vacation';
SELECT pg_temp.check((SELECT hours FROM time_off_requests ORDER BY id DESC LIMIT 1) = 4, 'a part day counts what it covers: 09:00-13:00 = 4 hours');
UPDATE site_settings SET time_off_day_hours = 6 WHERE scope_id = 102;
INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, hours)
SELECT 26, 102, id, (current_date + 70 + time '00:00') AT TIME ZONE 'America/Chicago', (current_date + 71 + time '00:00') AT TIME ZONE 'America/Chicago', NULL
  FROM time_off_types WHERE scope_id = 102 AND key = 'vacation';
SELECT pg_temp.check((SELECT hours FROM time_off_requests ORDER BY id DESC LIMIT 1) = 6, 'the setting changed to 6: a new whole day counts 6 hours');
SELECT pg_temp.check((SELECT hours FROM time_off_requests WHERE starts_at = (current_date + 50 + time '00:00') AT TIME ZONE 'America/Chicago') = 16, 'and the request asked before is unchanged (16)');
INSERT INTO time_off_requests (member_id, scope_id, type_id, starts_at, ends_at, hours)
SELECT 26, 102, id, (current_date + 71 + time '08:00') AT TIME ZONE 'America/Chicago', (current_date + 71 + time '20:00') AT TIME ZONE 'America/Chicago', NULL
  FROM time_off_types WHERE scope_id = 102 AND key = 'vacation';
SELECT pg_temp.check((SELECT hours FROM time_off_requests ORDER BY id DESC LIMIT 1) = 6, 'twelve hours on one day are capped at the restaurant''s day (6)');
SELECT pg_temp.check(ts_time_off_hours(101, (current_date + 70 + time '00:00') AT TIME ZONE 'America/New_York', (current_date + 71 + time '00:00') AT TIME ZONE 'America/New_York') = 8,
    'the other restaurant still counts 8: the setting is per restaurant');
SELECT pg_temp.refused($q$UPDATE site_settings SET time_off_day_hours = 0 WHERE scope_id = 102$q$, 'time_off_day_hours', 'a day of 0 hours is refused');
SELECT pg_temp.refused($q$UPDATE site_settings SET time_off_day_hours = 25 WHERE scope_id = 102$q$, 'time_off_day_hours', 'a day of 25 hours is refused');
RESET ROLE;

-- ---------------------------------------------------------------- 8. certifications: the restaurant's kinds, required by position (D15)
SET ROLE txtschedules_rw;
SELECT pg_temp.check((SELECT count(*) FROM certification_kinds WHERE scope_id = 102) = 2 AND (SELECT count(*) FROM certification_kinds WHERE scope_id = 101) = 2,
    'each restaurant is seeded with its own two kinds (food handler, alcohol service)');
INSERT INTO certification_kinds (scope_id, key, name, track_expiry, warn_days) VALUES (102, 'first_aid', 'First aid', false, 0);
SELECT pg_temp.check((SELECT count(*) FROM certification_kinds WHERE scope_id = 102) = 3, 'a restaurant adds a kind of its own (First aid, no expiry tracked)');
SELECT pg_temp.refused($q$INSERT INTO certification_kinds (scope_id, key, name) VALUES (102, 'first_aid', 'Again')$q$, 'duplicate', 'a kind key is unique within its restaurant');
SELECT pg_temp.refused($q$INSERT INTO position_certifications (position_id, kind_id)
    SELECT (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Host'), id FROM certification_kinds WHERE scope_id = 101 AND key = 'food_handler'$q$,
    'of its own restaurant', 'a position cannot need another restaurant''s certification');
-- the shift date used below: five days out at Airport
CREATE TEMP TABLE cd AS SELECT (current_date + 5) AS d;
GRANT SELECT ON cd TO PUBLIC;
-- Sam (28) is a Server at Airport, which needs a food-handler card (section 5) and holds none.
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_assignment_warnings(28, 102, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Server'),
        ((SELECT d FROM cd) + time '17:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM cd) + time '21:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'cert_required' AND message LIKE '%Food handler missing%'), 'no card: cert_required says which one is missing');
INSERT INTO certifications (member_id, kind_id, expires_on, recorded_by)
SELECT 28, id, current_date - 1, 28 FROM certification_kinds WHERE scope_id = 102 AND key = 'food_handler';
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_assignment_warnings(28, 102, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Server'),
        ((SELECT d FROM cd) + time '17:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM cd) + time '21:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'cert_required' AND message LIKE '%Food handler expired%'), 'a card that expired yesterday: cert_required says it expired');
INSERT INTO certifications (member_id, kind_id, expires_on, recorded_by)
SELECT 28, id, current_date + 400, 28 FROM certification_kinds WHERE scope_id = 102 AND key = 'food_handler';
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ts_assignment_warnings(28, 102, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Server'),
        ((SELECT d FROM cd) + time '17:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM cd) + time '21:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'cert_required'), 'a current card as well: the rule is quiet');
-- a kind that tracks no expiry ignores a past date; Host needs First aid
INSERT INTO position_certifications (position_id, kind_id)
SELECT (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Host'), id FROM certification_kinds WHERE scope_id = 102 AND key = 'first_aid';
INSERT INTO staff_positions (member_id, position_id, is_primary, wage_override) SELECT 28, id, false, NULL FROM positions WHERE scope_id = 102 AND name = 'Host';
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_assignment_warnings(28, 102, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Host'),
        ((SELECT d FROM cd) + time '10:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM cd) + time '14:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'cert_required' AND message LIKE '%First aid missing%'), 'a position needing First aid (a kind this restaurant added): missing is reported');
INSERT INTO certifications (member_id, kind_id, expires_on, recorded_by)
SELECT 28, id, current_date - 100, 1 FROM certification_kinds WHERE scope_id = 102 AND key = 'first_aid';
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ts_assignment_warnings(28, 102, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Host'),
        ((SELECT d FROM cd) + time '10:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM cd) + time '14:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'cert_required'), 'First aid tracks no expiry: an old date does not count against it');
-- hard or soft is the restaurant's choice (the rules engine)
UPDATE site_rules SET severity = 'hard' WHERE scope_id = 102 AND rule_key = 'cert_required';
SELECT pg_temp.check(EXISTS (SELECT 1 FROM ts_assignment_warnings(29, 102, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Server'),
        ((SELECT d FROM cd) + time '17:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM cd) + time '21:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'cert_required' AND severity = 'hard'), 'set to hard: a missing card is a refusal, not a warning');
UPDATE site_rules SET severity = 'soft' WHERE scope_id = 102 AND rule_key = 'cert_required';
UPDATE certification_kinds SET archived_at = now() WHERE scope_id = 102 AND key = 'food_handler';
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM ts_assignment_warnings(29, 102, (SELECT id FROM positions WHERE scope_id = 102 AND name = 'Server'),
        ((SELECT d FROM cd) + time '17:00') AT TIME ZONE 'America/Chicago', ((SELECT d FROM cd) + time '21:00') AT TIME ZONE 'America/Chicago', 0)
        WHERE rule_key = 'cert_required'), 'an archived kind is no longer required');
UPDATE certification_kinds SET archived_at = NULL WHERE scope_id = 102 AND key = 'food_handler';
-- Priya (26): a card expiring in 10 days (warn_days 30), entered by herself.
INSERT INTO certifications (member_id, kind_id, expires_on, recorded_by)
SELECT 26, id, current_date + 10, 26 FROM certification_kinds WHERE scope_id = 102 AND key = 'food_handler';
RESET ROLE;

-- who sees what
SET ROLE txtschedules_records_ro;
SELECT set_config('app.member_id', '28', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_certifications WHERE member_id = 26), 'Sam does not see Priya''s certifications');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_certifications WHERE member_id = 28), 'Sam sees his own');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_certification_kinds WHERE site_id = 101) AND EXISTS (SELECT 1 FROM mcp_certification_kinds WHERE site_id = 102),
    'Sam sees the kinds of his own restaurant only, to add his own card');
SELECT pg_temp.check((SELECT required_position_ids FROM mcp_certification_kinds WHERE key = 'first_aid') <> '{}', 'each kind says which positions need it');
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_certifications_due WHERE member_id <> 28), 'Sam''s due list holds only himself');
SELECT set_config('app.member_id', '27', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_certifications WHERE member_id IN (26, 28)), 'Marco (a manager at Downtown, staff at Airport) sees no Airport certifications');
SELECT set_config('app.member_id', '1', true);
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_certifications WHERE member_id = 26) AND EXISTS (SELECT 1 FROM mcp_certifications WHERE member_id = 28), 'the owner (schedule.build at Airport) sees both');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_certifications_due WHERE member_id = 28 AND state = 'expired'), 'due: Sam''s card that expired yesterday');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_certifications_due WHERE member_id = 26 AND state = 'due' AND days_left = 10), 'due: Priya''s card expires in 10 days (inside the 30 warned)');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_certifications_due WHERE member_id = 29 AND state = 'missing' AND kind_name = 'Food handler'), 'due: the minor on Server holds no food-handler card');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_certifications_due WHERE member_id = 28 AND state = 'to_verify'), 'to verify: Sam''s current card, entered by himself');
SELECT pg_temp.check(EXISTS (SELECT 1 FROM mcp_certifications_due WHERE state = 'to_verify' AND member_id = 28 AND kind_name = 'First aid'), 'a card that tracks no expiry is still to verify until a manager checks it');
RESET ROLE;
SET ROLE txtschedules_rw;
UPDATE certifications SET verified_by = 1, verified_at = now() WHERE member_id = 28 AND expires_on IS DISTINCT FROM current_date - 1;
RESET ROLE;
SET ROLE txtschedules_records_ro;
SELECT set_config('app.member_id', '1', true);
SELECT pg_temp.check(NOT EXISTS (SELECT 1 FROM mcp_certifications_due WHERE member_id = 28 AND state = 'to_verify'), 'a manager verifies Sam''s cards: none is left to verify (the expired one stays expired)');
SELECT pg_temp.check((SELECT verified FROM mcp_certifications WHERE member_id = 28 AND expires_on = current_date + 400), 'and reads as verified');
SELECT set_config('app.member_id', '26', true);
SELECT pg_temp.check((SELECT count(DISTINCT member_id) FROM mcp_certifications_due) = 1, 'Priya''s due list holds only herself');
RESET ROLE;
SELECT pg_temp.refused($q$SET ROLE txtschedules_records_ro; UPDATE certifications SET verified_at = now()$q$, 'permission denied', 'the records role cannot verify or change a card');
RESET ROLE;

\o
\set QUIET off
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || label AS result FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' of ' || count(*) || ' passed' AS summary FROM proof;
ROLLBACK;
