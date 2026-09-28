-- 014: what the Phase 1 tool surface (docs/txtschedules-mcp-tool-surface.md) needed the schema to hold — found in the
-- documents' review, as Projects' Phase 1 did. Additive: four functions and a view (db/011's ts_staffing_needs also became
-- a gated SECURITY DEFINER function for the records role). (db/013 also lost a leak found in the same
-- review: a staff member could read a DRAFT shift assigned to them; a draft is the managers' until published.)
BEGIN;

-- Hours per person per week and site (question 8, hours_this_week): scheduled paid hours against the person's own
-- limit and the site's overtime threshold. Seen by the person themself and by schedule.build at the site. The week's
-- start is the schedule week's; cancelled shifts and open ones do not count; a draft's hours count for a manager
-- (the plan) and are the person's only once published.
CREATE OR REPLACE VIEW mcp_hours_weekly WITH (security_barrier = true) AS
WITH h AS (
    SELECT sh.scope_id, w.week_start, sh.assignee_member_id AS member_id,
           count(*) AS shifts,
           sum(EXTRACT(EPOCH FROM (sh.ends_at - sh.starts_at)) / 3600 - sh.break_minutes / 60.0) AS hours
      FROM shifts sh
      JOIN schedule_weeks w ON w.id = sh.week_id
     WHERE sh.status = 'scheduled' AND sh.assignee_member_id IS NOT NULL
       AND ((sh.assignee_member_id = app_current_member_id() AND sh.published_at IS NOT NULL)
            OR sh.scope_id IN (SELECT ts_scopes_with_right('schedule.build')))
     GROUP BY sh.scope_id, w.week_start, sh.assignee_member_id
)
SELECT h.scope_id AS site_id, h.week_start, h.member_id, m.display_name, h.shifts::int AS shifts,
       round(h.hours::numeric, 2) AS scheduled_hours,
       sp.max_hours_week, ss.overtime_weekly_hours,
       (h.hours > ss.overtime_weekly_hours) AS over_overtime,
       (h.hours >= ss.overtime_weekly_hours * 0.9 AND h.hours <= ss.overtime_weekly_hours) AS near_overtime,
       (sp.max_hours_week IS NOT NULL AND h.hours > sp.max_hours_week) AS over_own_limit
  FROM h
  JOIN members m ON m.id = h.member_id
  JOIN site_settings ss ON ss.scope_id = h.scope_id
  LEFT JOIN staff_profiles sp ON sp.member_id = h.member_id;

-- Who could cover this shift (question 5, coverage_candidates; FR-S9): staff whose MAIN restaurant is the shift's (D4,
-- D12 — never elsewhere), active, not the holder, free (no overlapping scheduled shift), with no HARD rule broken;
-- the soft warnings travel with the row; fewest hours that week first. Asked by whoever holds coverage.fill (or
-- schedule.build) at the shift's site — the check is inside, because the function reads base tables as its owner.
CREATE OR REPLACE FUNCTION ts_coverage_candidates(p_shift bigint)
    RETURNS TABLE (member_id bigint, display_name text, hours_this_week numeric, warnings jsonb)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE s shifts%ROWTYPE;
BEGIN
    SELECT * INTO s FROM shifts WHERE id = p_shift;
    IF NOT FOUND OR NOT (ts_has_right('coverage.fill', s.scope_id) OR ts_has_right('schedule.build', s.scope_id)) THEN
        RETURN;
    END IF;
    RETURN QUERY
    SELECT c.member_id, c.display_name, c.hrs, c.warn
      FROM (
        SELECT m.id AS member_id, m.display_name,
               COALESCE((SELECT round(sum(EXTRACT(EPOCH FROM (x.ends_at - x.starts_at)) / 3600 - x.break_minutes / 60.0)::numeric, 2)
                           FROM shifts x WHERE x.assignee_member_id = m.id AND x.week_id = s.week_id AND x.status = 'scheduled'), 0) AS hrs,
               ts_warnings_json(m.id, s.id) AS warn
          FROM staff_profiles sp
          JOIN members m ON m.id = sp.member_id AND m.status = 'active'
         WHERE sp.main_scope_id = s.scope_id AND sp.active
           AND m.id IS DISTINCT FROM s.assignee_member_id
           AND NOT EXISTS (SELECT 1 FROM shifts o WHERE o.assignee_member_id = m.id AND o.status = 'scheduled'
                              AND tstzrange(o.starts_at, o.ends_at) && tstzrange(s.starts_at, s.ends_at))
      ) c
     WHERE NOT EXISTS (SELECT 1 FROM jsonb_array_elements(c.warn) w WHERE w->>'severity' = 'hard')
     ORDER BY c.hrs, c.display_name;
END$$;
REVOKE ALL ON FUNCTION ts_coverage_candidates(bigint) FROM PUBLIC;

-- What do the rules say about giving this person this shift (question 6, check_assignment) — for a shift that exists or
-- one being planned (site, position, times). Asked by the person themself, or by whoever holds schedule.build or
-- coverage.fill at the site; anyone else gets nothing. The engine is ts_assignment_warnings() (db/009).
CREATE OR REPLACE FUNCTION ts_check_assignment(p_member bigint, p_scope bigint, p_position bigint, p_starts timestamptz,
                                               p_ends timestamptz, p_break integer DEFAULT 0, p_ignore_shift bigint DEFAULT NULL)
    RETURNS TABLE (rule_key text, severity text, message text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT (p_member = app_current_member_id() OR ts_has_right('schedule.build', p_scope) OR ts_has_right('coverage.fill', p_scope)) THEN
        RETURN;
    END IF;
    RETURN QUERY SELECT w.rule_key, w.severity, w.message
                   FROM ts_assignment_warnings(p_member, p_scope, p_position, p_starts, p_ends, p_break, p_ignore_shift) w;
END$$;
REVOKE ALL ON FUNCTION ts_check_assignment(bigint, bigint, bigint, timestamptz, timestamptz, integer, bigint) FROM PUBLIC;

-- Every warning a week has (question 7, week_warnings): each assigned scheduled shift against the rules, for
-- schedule.build at the week's site. Overrides already recorded are in mcp_rule_overrides.
CREATE OR REPLACE FUNCTION ts_week_warnings(p_week bigint)
    RETURNS TABLE (shift_id bigint, member_id bigint, display_name text, rule_key text, severity text, message text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v_scope bigint;
BEGIN
    SELECT scope_id INTO v_scope FROM schedule_weeks WHERE id = p_week;
    IF v_scope IS NULL OR NOT ts_has_right('schedule.build', v_scope) THEN
        RETURN;
    END IF;
    RETURN QUERY
    SELECT s.id, s.assignee_member_id, m.display_name, w.rule_key, w.severity, w.message
      FROM shifts s
      JOIN members m ON m.id = s.assignee_member_id
      CROSS JOIN LATERAL ts_shift_warnings(s.id, s.assignee_member_id) w
     WHERE s.week_id = p_week AND s.status = 'scheduled' AND s.assignee_member_id IS NOT NULL
     ORDER BY s.starts_at, m.display_name;
END$$;
REVOKE ALL ON FUNCTION ts_week_warnings(bigint) FROM PUBLIC;

GRANT SELECT ON mcp_hours_weekly TO txtschedules_records_ro, txtschedules_rw;
GRANT EXECUTE ON FUNCTION ts_coverage_candidates(bigint), ts_week_warnings(bigint),
    ts_check_assignment(bigint, bigint, bigint, timestamptz, timestamptz, integer, bigint)
    TO txtschedules_records_ro, txtschedules_rw;

COMMIT;
