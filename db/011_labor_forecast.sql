-- 011: the labor budget (FR-L1–L3) and the forecast (D9, FR-F1): a manager enters the covers expected per day
-- and day-part (or copies last week's), and staffing ratios per position turn them into a recommended headcount
-- beside the scheduled one. Reservations' booked covers fill the expectation later, through the kernel's
-- application read (K7: reservations.covers_by_service) — source 'reservations'. Cost is paid hours × the person's
-- EFFECTIVE rate for the shift's position (their own rate, else the position's default); it is read only where the
-- caller holds labor.view (db/013: mcp_shifts.cost, mcp_labor_weekly).
BEGIN;

CREATE TABLE labor_budgets (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id       bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    week_start     date NOT NULL,
    area           text NOT NULL DEFAULT 'all' CHECK (area IN ('all', 'front', 'kitchen', 'bar', 'management', 'other')),
    budget_hours   numeric(8,2) CHECK (budget_hours IS NULL OR budget_hours >= 0),
    budget_amount  numeric(12,2) CHECK (budget_amount IS NULL OR budget_amount >= 0),
    updated_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_at     timestamptz NOT NULL DEFAULT now(),
    UNIQUE (scope_id, week_start, area),
    CHECK (budget_hours IS NOT NULL OR budget_amount IS NOT NULL)
);

CREATE TABLE forecast_covers (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id        bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    on_date         date NOT NULL,
    day_part_id     bigint NOT NULL REFERENCES day_parts(id) ON DELETE CASCADE,
    expected_covers integer NOT NULL CHECK (expected_covers >= 0),
    source          text NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'copied', 'reservations')),
    updated_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (scope_id, on_date, day_part_id)
);

CREATE TABLE staffing_ratios (
    scope_id          bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    position_id       bigint NOT NULL REFERENCES positions(id) ON DELETE CASCADE,
    covers_per_staff  numeric(6,2) CHECK (covers_per_staff IS NULL OR covers_per_staff > 0),   -- e.g. one server per 25 covers
    min_staff         integer NOT NULL DEFAULT 0 CHECK (min_staff >= 0),                         -- whatever the covers
    updated_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_at        timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (scope_id, position_id)
);

-- Recommended vs scheduled headcount, per date, day-part and position (FR-F1): ceil(covers / ratio), never below
-- the minimum; scheduled = shifts of that position overlapping the day-part (open shifts counted as a gap to fill).
CREATE OR REPLACE FUNCTION ts_staffing_needs(p_scope bigint, p_from date, p_to date)
    RETURNS TABLE (on_date date, day_part_id bigint, day_part text, position_id bigint, position_name text,
                   expected_covers integer, recommended integer, scheduled integer, open_shifts integer)
    LANGUAGE plpgsql STABLE AS $$
DECLARE v_tz text;
BEGIN
    SELECT timezone INTO v_tz FROM sites WHERE scope_id = p_scope;
    RETURN QUERY
    SELECT d::date, dp.id, dp.name, p.id, p.name, fc.expected_covers,
           GREATEST(sr.min_staff, CASE WHEN sr.covers_per_staff IS NULL OR fc.expected_covers IS NULL THEN 0
                                       ELSE ceil(fc.expected_covers / sr.covers_per_staff)::int END),
           (SELECT count(*)::int FROM shifts s WHERE s.scope_id = p_scope AND s.position_id = p.id AND s.status = 'scheduled'
               AND s.assignee_member_id IS NOT NULL
               AND tstzrange(s.starts_at, s.ends_at) && tstzrange((d::date + dp.starts_at) AT TIME ZONE v_tz,
                   (d::date + dp.ends_at + CASE WHEN dp.ends_at <= dp.starts_at THEN interval '1 day' ELSE interval '0' END) AT TIME ZONE v_tz)),
           (SELECT count(*)::int FROM shifts s WHERE s.scope_id = p_scope AND s.position_id = p.id AND s.status = 'scheduled'
               AND s.assignee_member_id IS NULL
               AND tstzrange(s.starts_at, s.ends_at) && tstzrange((d::date + dp.starts_at) AT TIME ZONE v_tz,
                   (d::date + dp.ends_at + CASE WHEN dp.ends_at <= dp.starts_at THEN interval '1 day' ELSE interval '0' END) AT TIME ZONE v_tz))
      FROM generate_series(p_from, p_to, interval '1 day') d
      JOIN day_parts dp ON dp.scope_id = p_scope AND dp.archived_at IS NULL
      JOIN staffing_ratios sr ON sr.scope_id = p_scope
      JOIN positions p ON p.id = sr.position_id AND p.archived_at IS NULL
      LEFT JOIN forecast_covers fc ON fc.scope_id = p_scope AND fc.on_date = d::date AND fc.day_part_id = dp.id
     ORDER BY 1, dp.sort_order, p.sort_order;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON labor_budgets, forecast_covers, staffing_ratios TO txtschedules_rw;
GRANT EXECUTE ON FUNCTION ts_staffing_needs(bigint, date, date) TO txtschedules_rw;

COMMIT;
