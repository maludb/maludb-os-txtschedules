-- 013: a kernel site becomes a restaurant here (ts_site_materialise), and the mcp_* visibility views — the only
-- thing the two read roles see (memory.md §1). Every WHERE clause IS the row rule; the servers never filter in
-- Python. Caller checks are uncorrelated sets (ts_held_scope_ids(), ts_scopes_with_right(right)) tested once per
-- statement, never a function per row (the kernel's db/160 lesson).
--
-- The rule, in one place (the design §3):
--   a site's data      is seen by whoever holds a role there (ts_held_scope_ids()); drafts, templates, overrides and
--                      other people's profiles, availability and requests need schedule.build / requests.approve there;
--   a person's own     shifts, profile, availability, requests, balances, claims are always theirs to see;
--   pay                (wage rates, cost, budgets) needs labor.view at the position's site — or it is their own wage;
--   other people's     email and phone are never shown (NF-3).
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- A kernel scope → a restaurant. New: its settings from the business defaults, two day-parts, three time-off
-- types and the generic rule preset. Known: name, address and time zone follow the kernel; removed → closed.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ts_site_materialise(p_scope bigint, p_location bigint, p_name text, p_address text,
                                               p_timezone text, p_removed_at timestamptz) RETURNS boolean
    LANGUAGE plpgsql AS $$
DECLARE
    v_new boolean;
    v_tz  text := CASE WHEN p_timezone IS NOT NULL AND EXISTS (SELECT 1 FROM pg_timezone_names WHERE name = p_timezone)
                       THEN p_timezone ELSE 'UTC' END;
BEGIN
    v_new := NOT EXISTS (SELECT 1 FROM sites WHERE scope_id = p_scope);
    INSERT INTO sites (scope_id, location_id, name, address, timezone, removed_at, synced_at)
    VALUES (p_scope, p_location, p_name, p_address, v_tz, p_removed_at, now())
    ON CONFLICT (scope_id) DO UPDATE SET location_id = EXCLUDED.location_id, name = EXCLUDED.name, address = EXCLUDED.address,
        timezone = EXCLUDED.timezone, removed_at = EXCLUDED.removed_at, synced_at = now();
    IF p_removed_at IS NOT NULL THEN
        DELETE FROM member_site_roles WHERE scope_id = p_scope;         -- closed: nobody reaches it; its history stays
    END IF;
    IF v_new THEN
        INSERT INTO site_settings (scope_id, week_start, currency)
        SELECT p_scope, a.default_week_start, a.default_currency FROM app_settings a WHERE a.id = 1;
        INSERT INTO day_parts (scope_id, key, name, starts_at, ends_at, sort_order) VALUES
            (p_scope, 'lunch', 'Lunch', '11:00', '15:00', 1),
            (p_scope, 'dinner', 'Dinner', '17:00', '22:00', 2);
        INSERT INTO time_off_types (scope_id, key, name, paid, tracks_balance, sort_order) VALUES
            (p_scope, 'vacation', 'Vacation / PTO', true, true, 1),
            (p_scope, 'sick', 'Sick', true, true, 2),
            (p_scope, 'unpaid', 'Unpaid', false, false, 3);
        INSERT INTO site_rules (scope_id, rule_key, severity, params)
        SELECT p_scope, e.key, e.value->>'severity', COALESCE(e.value->'params', '{}'::jsonb)
          FROM rule_presets rp, jsonb_each(rp.rules) e WHERE rp.key = 'generic';
    END IF;
    RETURN v_new;
END$$;

-- The first site a person is granted at becomes their main restaurant (D4) until a manager says otherwise.
CREATE OR REPLACE FUNCTION ts_ensure_staff_profile(p_member bigint, p_scope bigint) RETURNS void
    LANGUAGE plpgsql AS $$
BEGIN
    INSERT INTO staff_profiles (member_id, main_scope_id) VALUES (p_member, p_scope) ON CONFLICT (member_id) DO NOTHING;
END$$;

GRANT EXECUTE ON FUNCTION ts_site_materialise(bigint, bigint, text, text, text, timestamptz), ts_ensure_staff_profile(bigint, bigint)
    TO txtschedules_rw;

-- ---------------------------------------------------------------------------------------------
-- The views.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_sites WITH (security_barrier = true) AS
SELECT s.scope_id AS site_id, s.location_id, s.name, s.address, s.timezone,
       ss.week_start, ss.currency, ss.allow_offer, ss.allow_pickup, ss.allow_swap, ss.allow_give,
       ss.approval_pickup, ss.approval_swap, ss.approval_give, ss.cutoff_minutes, ss.shift_lead_approves_same_day,
       ss.claim_mode, ss.offer_expires, ss.availability_needs_approval, ss.reminder_minutes_before,
       ss.overtime_weekly_hours, ss.overtime_multiplier, ss.rule_preset,
       r.role_key AS my_role, r.roles AS my_roles
  FROM sites s
  JOIN site_settings ss ON ss.scope_id = s.scope_id
  JOIN member_site_roles r ON r.scope_id = s.scope_id AND r.member_id = app_current_member_id()
 WHERE s.removed_at IS NULL AND s.scope_id IN (SELECT ts_held_scope_ids());

-- People who work at a site the caller holds (and the caller). No email or phone but one's own.
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.status,
       CASE WHEN m.id = app_current_member_id() THEN m.email::text END AS email,
       sp.main_scope_id AS main_site_id, sp.active AS on_schedule,
       (SELECT array_agg(DISTINCT r.scope_id) FROM member_site_roles r WHERE r.member_id = m.id
           AND r.scope_id IN (SELECT ts_held_scope_ids())) AS site_ids
  FROM members m
  LEFT JOIN staff_profiles sp ON sp.member_id = m.id
 WHERE m.status = 'active'
   AND (m.id = app_current_member_id()
        OR EXISTS (SELECT 1 FROM member_site_roles r WHERE r.member_id = m.id AND r.scope_id IN (SELECT ts_held_scope_ids())));

CREATE OR REPLACE VIEW mcp_member_site_roles WITH (security_barrier = true) AS
SELECT r.member_id, r.scope_id AS site_id, r.role_key, r.roles, r.capability
  FROM member_site_roles r
 WHERE r.scope_id IN (SELECT ts_held_scope_ids());

CREATE OR REPLACE VIEW mcp_positions WITH (security_barrier = true) AS
SELECT p.id AS position_id, p.scope_id AS site_id, p.name, p.color, p.area, p.sort_order, p.archived_at,
       CASE WHEN p.scope_id IN (SELECT ts_scopes_with_right('labor.view')) THEN p.default_wage_rate END AS default_wage_rate
  FROM positions p
 WHERE p.scope_id IN (SELECT ts_held_scope_ids());

CREATE OR REPLACE VIEW mcp_staff WITH (security_barrier = true) AS
SELECT sp.member_id, m.display_name, sp.main_scope_id AS main_site_id, sp.max_hours_week, sp.is_minor, sp.minor_until,
       sp.active,
       CASE WHEN sp.main_scope_id IN (SELECT ts_scopes_with_right('schedule.build')) THEN sp.notes END AS notes
  FROM staff_profiles sp
  JOIN members m ON m.id = sp.member_id
 WHERE sp.member_id = app_current_member_id()
    OR EXISTS (SELECT 1 FROM member_site_roles r WHERE r.member_id = sp.member_id
                 AND r.scope_id IN (SELECT ts_scopes_with_right('schedule.build')));

-- The wage: one's own, or with labor.view at the position's site. Anyone else sees the position without it.
-- wage_rate is the EFFECTIVE rate (the person's own when set, else the position's default); wage_override is the
-- person's own rate; wage_source says which applied.
CREATE OR REPLACE VIEW mcp_staff_positions WITH (security_barrier = true) AS
SELECT sp.member_id, sp.position_id, p.scope_id AS site_id, p.name AS position_name, sp.is_primary,
       CASE WHEN sp.member_id = app_current_member_id() OR p.scope_id IN (SELECT ts_scopes_with_right('labor.view'))
            THEN COALESCE(sp.wage_override, p.default_wage_rate) END AS wage_rate,
       CASE WHEN sp.member_id = app_current_member_id() OR p.scope_id IN (SELECT ts_scopes_with_right('labor.view'))
            THEN sp.wage_override END AS wage_override,
       CASE WHEN sp.member_id = app_current_member_id() OR p.scope_id IN (SELECT ts_scopes_with_right('labor.view'))
            THEN CASE WHEN sp.wage_override IS NOT NULL THEN 'override' WHEN p.default_wage_rate IS NOT NULL THEN 'default' END END AS wage_source
  FROM staff_positions sp
  JOIN positions p ON p.id = sp.position_id
 WHERE p.scope_id IN (SELECT ts_held_scope_ids());

CREATE OR REPLACE VIEW mcp_certifications WITH (security_barrier = true) AS
SELECT c.id AS certification_id, c.member_id, k.key AS kind, k.name AS kind_name, c.issued_on, c.expires_on,
       (c.expires_on IS NOT NULL AND c.expires_on < current_date) AS expired
  FROM certifications c
  JOIN certification_kinds k ON k.id = c.kind_id
 WHERE c.removed_at IS NULL
   AND (c.member_id = app_current_member_id()
        OR EXISTS (SELECT 1 FROM member_site_roles r WHERE r.member_id = c.member_id
                     AND r.scope_id IN (SELECT ts_scopes_with_right('schedule.build'))));

CREATE OR REPLACE VIEW mcp_availability WITH (security_barrier = true) AS
SELECT a.id AS availability_id, a.member_id, a.scope_id AS site_id, a.weekday, a.starts_at, a.ends_at, a.kind,
       a.effective_from, a.effective_to, a.status, a.decided_by, a.decided_at
  FROM availability_rules a
 WHERE a.status IN ('pending', 'approved')
   AND (a.member_id = app_current_member_id()
        OR EXISTS (SELECT 1 FROM member_site_roles r WHERE r.member_id = a.member_id
                     AND (a.scope_id IS NULL OR r.scope_id = a.scope_id)
                     AND r.scope_id IN (SELECT ts_scopes_with_right('schedule.build'))));

CREATE OR REPLACE VIEW mcp_time_off_types WITH (security_barrier = true) AS
SELECT t.id AS type_id, t.scope_id AS site_id, t.key, t.name, t.paid, t.tracks_balance, t.allow_negative, t.archived_at
  FROM time_off_types t
 WHERE t.scope_id IN (SELECT ts_held_scope_ids());

CREATE OR REPLACE VIEW mcp_time_off_balances WITH (security_barrier = true) AS
SELECT b.member_id, b.type_id, t.scope_id AS site_id, t.name AS type_name, b.balance_hours, b.updated_at
  FROM time_off_balances b
  JOIN time_off_types t ON t.id = b.type_id
 WHERE b.member_id = app_current_member_id()
    OR t.scope_id IN (SELECT ts_scopes_with_right('requests.approve'));

CREATE OR REPLACE VIEW mcp_time_off_requests WITH (security_barrier = true) AS
SELECT q.id AS request_id, q.member_id, m.display_name AS member_name, q.scope_id AS site_id, q.type_id, t.name AS type_name,
       q.starts_at, q.ends_at, q.hours, q.note, q.status, q.decided_by, q.decided_at, q.decision_note, q.created_at
  FROM time_off_requests q
  JOIN members m ON m.id = q.member_id
  JOIN time_off_types t ON t.id = q.type_id
 WHERE q.member_id = app_current_member_id()
    OR q.scope_id IN (SELECT ts_scopes_with_right('requests.approve'))
    OR q.scope_id IN (SELECT ts_scopes_with_right('schedule.build'));

CREATE OR REPLACE VIEW mcp_blackout_dates WITH (security_barrier = true) AS
SELECT b.id AS blackout_id, b.scope_id AS site_id, b.on_date, b.reason
  FROM blackout_dates b
 WHERE b.scope_id IN (SELECT ts_held_scope_ids());

CREATE OR REPLACE VIEW mcp_schedule_weeks WITH (security_barrier = true) AS
SELECT w.id AS week_id, w.scope_id AS site_id, w.week_start, w.status, w.published_at, w.published_by
  FROM schedule_weeks w
 WHERE w.scope_id IN (SELECT ts_held_scope_ids())
   AND (w.status = 'published' OR w.scope_id IN (SELECT ts_scopes_with_right('schedule.build')));

-- A shift: at a site the caller holds, published — or a draft, with schedule.build there — or their own.
-- Hours are paid hours (break off); cost = paid hours x the EFFECTIVE rate (the person's own, else the position's
-- default), only with labor.view and only for an assigned shift — an open one has no person to price (overtime not
-- applied per shift: the week's report does).
CREATE OR REPLACE VIEW mcp_shifts WITH (security_barrier = true) AS
SELECT s.id AS shift_id, s.scope_id AS site_id, s.week_id, s.position_id, p.name AS position_name, p.color AS position_color,
       s.starts_at, s.ends_at, s.break_minutes,
       round(EXTRACT(EPOCH FROM (s.ends_at - s.starts_at)) / 3600 - s.break_minutes / 60.0, 2) AS paid_hours,
       s.assignee_member_id, m.display_name AS assignee_name, (s.assignee_member_id IS NULL) AS is_open,
       s.status, s.note, s.published_at, s.changed_after_publish_at, s.cancelled_at, s.cancel_reason,
       CASE WHEN s.assignee_member_id IS NOT NULL AND s.scope_id IN (SELECT ts_scopes_with_right('labor.view'))
            THEN round((EXTRACT(EPOCH FROM (s.ends_at - s.starts_at)) / 3600 - s.break_minutes / 60.0) * COALESCE(sp.wage_override, p.default_wage_rate), 2) END AS cost
  FROM shifts s
  JOIN positions p ON p.id = s.position_id
  LEFT JOIN members m ON m.id = s.assignee_member_id
  LEFT JOIN staff_positions sp ON sp.member_id = s.assignee_member_id AND sp.position_id = s.position_id
 WHERE s.assignee_member_id = app_current_member_id()
    OR (s.scope_id IN (SELECT ts_scopes_with_right('schedule.view_own')) AND s.published_at IS NOT NULL)
    OR s.scope_id IN (SELECT ts_scopes_with_right('schedule.build'));

CREATE OR REPLACE VIEW mcp_templates WITH (security_barrier = true) AS
SELECT t.id AS template_id, t.scope_id AS site_id, t.name, t.from_week_id, t.created_by, t.created_at,
       (SELECT count(*) FROM template_shifts x WHERE x.template_id = t.id) AS shift_count
  FROM schedule_templates t
 WHERE t.archived_at IS NULL AND t.scope_id IN (SELECT ts_scopes_with_right('schedule.build'));

CREATE OR REPLACE VIEW mcp_template_shifts WITH (security_barrier = true) AS
SELECT x.id AS template_shift_id, x.template_id, t.scope_id AS site_id, x.position_id, x.weekday, x.starts_at, x.ends_at,
       x.break_minutes, x.assignee_member_id
  FROM template_shifts x
  JOIN schedule_templates t ON t.id = x.template_id
 WHERE t.scope_id IN (SELECT ts_scopes_with_right('schedule.build'));

-- An exchange: its people (holder, named colleague, claimants, invitees), its approvers, and — while open — the
-- staff whose MAIN restaurant it is (the marketplace, D4).
CREATE OR REPLACE VIEW mcp_exchanges WITH (security_barrier = true) AS
SELECT x.id AS exchange_id, x.scope_id AS site_id, x.kind, x.shift_id, x.from_member_id, fm.display_name AS from_name,
       x.to_member_id, tm.display_name AS to_name, x.swap_shift_id, x.status, x.needs_approval, x.warnings, x.note,
       x.expires_at, x.decided_by, x.decided_at, x.decision_note, x.created_at,
       s.starts_at AS shift_starts_at, s.ends_at AS shift_ends_at, s.position_id,
       (SELECT count(*) FROM exchange_claims c WHERE c.exchange_id = x.id AND c.status IN ('pending', 'won')) AS claims
  FROM exchanges x
  JOIN shifts s ON s.id = x.shift_id
  LEFT JOIN members fm ON fm.id = x.from_member_id
  LEFT JOIN members tm ON tm.id = x.to_member_id
 WHERE x.from_member_id = app_current_member_id()
    OR x.to_member_id = app_current_member_id()
    OR EXISTS (SELECT 1 FROM exchange_claims c WHERE c.exchange_id = x.id AND c.member_id = app_current_member_id())
    OR EXISTS (SELECT 1 FROM exchange_invitees i WHERE i.exchange_id = x.id AND i.member_id = app_current_member_id())
    OR x.scope_id IN (SELECT ts_scopes_with_right('requests.approve'))
    OR x.scope_id IN (SELECT ts_scopes_with_right('market.approve_day'))
    OR (x.status = 'open' AND x.kind IN ('offer', 'open')
        AND x.scope_id IN (SELECT ts_scopes_with_right('market.trade'))
        AND x.scope_id = (SELECT sp.main_scope_id FROM staff_profiles sp WHERE sp.member_id = app_current_member_id()));

CREATE OR REPLACE VIEW mcp_exchange_claims WITH (security_barrier = true) AS
SELECT c.id AS claim_id, c.exchange_id, c.member_id, m.display_name AS member_name, c.status, c.warnings, c.created_at
  FROM exchange_claims c
  JOIN exchanges x ON x.id = c.exchange_id
  JOIN members m ON m.id = c.member_id
 WHERE c.member_id = app_current_member_id()
    OR x.from_member_id = app_current_member_id()
    OR x.scope_id IN (SELECT ts_scopes_with_right('requests.approve'));

CREATE OR REPLACE VIEW mcp_site_rules WITH (security_barrier = true) AS
SELECT r.scope_id AS site_id, r.rule_key, k.name, k.explains, r.severity, r.params, r.updated_at
  FROM site_rules r
  JOIN rule_kinds k ON k.key = r.rule_key
 WHERE r.scope_id IN (SELECT ts_held_scope_ids());

CREATE OR REPLACE VIEW mcp_rule_overrides WITH (security_barrier = true) AS
SELECT o.id AS override_id, o.scope_id AS site_id, o.shift_id, o.member_id, o.rule_key, o.message, o.reason,
       o.overridden_by, o.context, o.created_at
  FROM rule_overrides o
 WHERE o.scope_id IN (SELECT ts_scopes_with_right('schedule.build'));

CREATE OR REPLACE VIEW mcp_labor_budgets WITH (security_barrier = true) AS
SELECT b.id AS budget_id, b.scope_id AS site_id, b.week_start, b.area, b.budget_hours, b.budget_amount, b.updated_at
  FROM labor_budgets b
 WHERE b.scope_id IN (SELECT ts_scopes_with_right('labor.view'));

CREATE OR REPLACE VIEW mcp_day_parts WITH (security_barrier = true) AS
SELECT d.id AS day_part_id, d.scope_id AS site_id, d.key, d.name, d.starts_at, d.ends_at, d.sort_order
  FROM day_parts d
 WHERE d.archived_at IS NULL AND d.scope_id IN (SELECT ts_held_scope_ids());

CREATE OR REPLACE VIEW mcp_forecast_covers WITH (security_barrier = true) AS
SELECT f.scope_id AS site_id, f.on_date, f.day_part_id, f.expected_covers, f.source, f.updated_at
  FROM forecast_covers f
 WHERE f.scope_id IN (SELECT ts_scopes_with_right('schedule.build'));

CREATE OR REPLACE VIEW mcp_staffing_ratios WITH (security_barrier = true) AS
SELECT r.scope_id AS site_id, r.position_id, r.covers_per_staff, r.min_staff
  FROM staffing_ratios r
 WHERE r.scope_id IN (SELECT ts_scopes_with_right('schedule.build'));

-- An announcement reaches its audience at a site the caller holds; the poster's side (announce.post) sees them all.
CREATE OR REPLACE VIEW mcp_announcements WITH (security_barrier = true) AS
SELECT a.id AS announcement_id, a.scope_id AS site_id, a.title, a.body, a.audience, a.position_id, a.pinned_until,
       a.posted_by, pm.display_name AS posted_by_name, a.created_at,
       EXISTS (SELECT 1 FROM announcement_reads rd WHERE rd.announcement_id = a.id AND rd.member_id = app_current_member_id()) AS read_by_me,
       CASE WHEN a.scope_id IN (SELECT ts_scopes_with_right('announce.post'))
            THEN (SELECT count(*) FROM announcement_reads rd WHERE rd.announcement_id = a.id) END AS read_count
  FROM announcements a
  LEFT JOIN members pm ON pm.id = a.posted_by
 WHERE a.removed_at IS NULL
   AND a.scope_id IN (SELECT ts_held_scope_ids())
   AND (a.scope_id IN (SELECT ts_scopes_with_right('announce.post'))
        OR a.audience = 'site'
        OR (a.audience = 'position' AND EXISTS (SELECT 1 FROM staff_positions sp WHERE sp.member_id = app_current_member_id()
                                                  AND sp.position_id = a.position_id))
        OR (a.audience = 'people' AND app_current_member_id() = ANY (a.member_ids)));

-- The trail, for the activity role: what happened at the sites where the caller builds schedules, and what they did.
-- after/before never carry a wage (the handlers' rule).
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT l.id AS activity_id, l.occurred_at, l.actor_member_id, am.display_name AS actor_name, l.source, l.action,
       l.entity_type, l.entity_id, l.scope_id AS site_id, l.before, l.after, l.agent_run_id, l.request_id
  FROM activity_log l
  LEFT JOIN members am ON am.id = l.actor_member_id
 WHERE l.actor_member_id = app_current_member_id()
    OR l.scope_id IN (SELECT ts_scopes_with_right('schedule.build'));

-- Scheduled labor per site, week and area (and 'all'), against the budget — labor.view at the site only. Hours are
-- paid hours of scheduled shifts, open ones included; cost is the assigned ones at the EFFECTIVE rate (overtime not
-- applied). A budget with no shifts still shows; the week's start is the schedule week's.
CREATE OR REPLACE VIEW mcp_labor_weekly WITH (security_barrier = true) AS
WITH s AS (
    SELECT sh.scope_id, w.week_start, p.area, sh.assignee_member_id,
           EXTRACT(EPOCH FROM (sh.ends_at - sh.starts_at)) / 3600 - sh.break_minutes / 60.0 AS hours,
           (EXTRACT(EPOCH FROM (sh.ends_at - sh.starts_at)) / 3600 - sh.break_minutes / 60.0)
               * COALESCE(sp.wage_override, p.default_wage_rate) AS cost
      FROM shifts sh
      JOIN schedule_weeks w ON w.id = sh.week_id
      JOIN positions p ON p.id = sh.position_id
      LEFT JOIN staff_positions sp ON sp.member_id = sh.assignee_member_id AND sp.position_id = sh.position_id
     WHERE sh.status = 'scheduled' AND sh.scope_id IN (SELECT ts_scopes_with_right('labor.view'))
), keys AS (
    SELECT scope_id, week_start, area FROM s
    UNION SELECT scope_id, week_start, 'all' FROM s
    UNION SELECT scope_id, week_start, area FROM labor_budgets WHERE scope_id IN (SELECT ts_scopes_with_right('labor.view'))
)
SELECT k.scope_id AS site_id, k.week_start, k.area,
       round(COALESCE(sum(s.hours), 0)::numeric, 2) AS scheduled_hours,
       round(COALESCE(sum(s.cost) FILTER (WHERE s.assignee_member_id IS NOT NULL), 0)::numeric, 2) AS scheduled_cost,
       b.budget_hours, b.budget_amount
  FROM keys k
  LEFT JOIN s ON s.scope_id = k.scope_id AND s.week_start = k.week_start AND (k.area = 'all' OR s.area = k.area)
  LEFT JOIN labor_budgets b ON b.scope_id = k.scope_id AND b.week_start = k.week_start AND b.area = k.area
 GROUP BY k.scope_id, k.week_start, k.area, b.budget_hours, b.budget_amount;

GRANT SELECT ON mcp_sites, mcp_members, mcp_member_site_roles, mcp_positions, mcp_staff, mcp_staff_positions, mcp_certifications,
    mcp_availability, mcp_time_off_types, mcp_time_off_balances, mcp_time_off_requests, mcp_blackout_dates, mcp_schedule_weeks,
    mcp_shifts, mcp_templates, mcp_template_shifts, mcp_exchanges, mcp_exchange_claims, mcp_site_rules, mcp_rule_overrides,
    mcp_labor_budgets, mcp_labor_weekly, mcp_day_parts, mcp_forecast_covers, mcp_staffing_ratios, mcp_announcements
TO txtschedules_records_ro, txtschedules_rw;
GRANT SELECT ON mcp_activity_log TO txtschedules_activity_ro, txtschedules_rw;
-- The views read base tables as their owner; the security_barrier WHERE clauses decide the rows.

COMMIT;
